"use strict";

import * as THREE from "three";
import { CSS2DObject, CSS2DRenderer } from "three/addons/renderers/CSS2DRenderer.js";

const API_URL = "/api/zarzakh/overlay.php";
const REFRESH_INTERVAL_MS = 5 * 60 * 1000;
const ROTATION_RADIANS_PER_SECOND = 0.07;
const MAP_SPAN = 150;
const ORANGE = new THREE.Color("#ff6a00");
const RED = new THREE.Color("#ff1515");

const container = document.querySelector("#zarzakhOverlay");
const scene = new THREE.Scene();
scene.background = null;
const root = new THREE.Group();
scene.add(root);

const camera = new THREE.PerspectiveCamera(43, Math.max(1,innerWidth)/Math.max(1,innerHeight), .1, 3000);
const renderer = new THREE.WebGLRenderer({alpha:true,antialias:true,premultipliedAlpha:true,powerPreference:"high-performance"});
renderer.setClearColor(0x000000,0);
renderer.setPixelRatio(Math.min(devicePixelRatio||1,1.5));
renderer.setSize(innerWidth,innerHeight,false);
renderer.outputColorSpace=THREE.SRGBColorSpace;
renderer.sortObjects=false;
container.appendChild(renderer.domElement);
const labels = new CSS2DRenderer();
labels.setSize(innerWidth,innerHeight);
labels.domElement.className="overlay-label-layer";
container.appendChild(labels.domElement);

const clock=new THREE.Clock();
let built=false;
let originMesh=null;
let killBadge=null;
let topologySignature="";
let refreshing=false;

function normalize(objects){
  const meaningful=objects.filter(o=>o.type!=="star");
  const raw=meaningful.map(o=>new THREE.Vector3(Number(o.position.x),Number(o.position.y),-Number(o.position.z)));
  if(!raw.length) return new Map([["system-origin",new THREE.Vector3(0,0,0)]]);
  const box=new THREE.Box3().setFromPoints(raw); const center=box.getCenter(new THREE.Vector3());
  const size=box.getSize(new THREE.Vector3()); const largest=Math.max(size.x,size.y,size.z)||1; const scale=MAP_SPAN/largest;
  const result=new Map();
  result.set("system-origin",new THREE.Vector3(0,0,0));
  meaningful.forEach((o,i)=>result.set(String(o.id),raw[i].sub(center).multiplyScalar(scale)));
  // Re-center physical objects around their collective center but keep the star/system
  // origin at the visual center so the whole structure reads cleanly on stream.
  return result;
}
function addLabel(text,pos,kind){
  const a=document.createElement("div");a.className="z-label-anchor";
  const s=document.createElement("span");s.className=`z-label ${kind||""}`;s.textContent=text;a.appendChild(s);
  const o=new CSS2DObject(a);o.position.copy(pos);root.add(o);return s;
}
function disposeRoot(){
  while(root.children.length){const o=root.children.pop(); if(o.geometry)o.geometry.dispose(); if(o.material){if(Array.isArray(o.material))o.material.forEach(m=>m.dispose());else o.material.dispose();}}
  originMesh=null;killBadge=null;
}
function shortStationName(name){return /fulcrum/i.test(name)?"THE FULCRUM":name;}
function build(payload){
  disposeRoot();
  const objects=payload.data.objects||[]; const pos=normalize(objects);
  topologySignature=objects.map(o=>`${o.id}:${o.name}`).join("|");
  for(const obj of objects){
    const p=pos.get(String(obj.id))||new THREE.Vector3();
    if(obj.type==="star"){
      const g=new THREE.SphereGeometry(3.2,18,12);const m=new THREE.MeshBasicMaterial({color:ORANGE});originMesh=new THREE.Mesh(g,m);originMesh.position.copy(p);root.add(originMesh);
      addLabel("ZARZAKH",p,"is-origin");
      const ba=document.createElement("div");ba.className="z-kill-anchor";const b=document.createElement("span");b.className="z-kill-badge";b.hidden=true;ba.appendChild(b);const bo=new CSS2DObject(ba);bo.position.copy(p);root.add(bo);killBadge=b;
    } else if(obj.type==="station"){
      const g=new THREE.OctahedronGeometry(2.5,0);const m=new THREE.MeshBasicMaterial({color:0xffa248,wireframe:false});const mesh=new THREE.Mesh(g,m);mesh.position.copy(p);root.add(mesh);addLabel(shortStationName(obj.name),p,"is-station");
    } else if(obj.type==="stargate"){
      const g=new THREE.TorusGeometry(1.8,.34,8,20);const m=new THREE.MeshBasicMaterial({color:0xff6a00});const mesh=new THREE.Mesh(g,m);mesh.position.copy(p);mesh.lookAt(0,0,0);root.add(mesh);addLabel(obj.destination_system_name||obj.name,p,"is-gate");
    }
  }
  // Subtle geometry spokes help the viewer understand the 3D distribution of
  // the in-system objects. These are orientation guides, not stargate links.
  const verts=[];for(const obj of objects){if(obj.type==="star")continue;const p=pos.get(String(obj.id));if(!p)continue;verts.push(0,0,0,p.x,p.y,p.z)}
  if(verts.length){const g=new THREE.BufferGeometry();g.setAttribute("position",new THREE.Float32BufferAttribute(verts,3));const m=new THREE.LineBasicMaterial({color:0xff6a00,transparent:true,opacity:.22});root.add(new THREE.LineSegments(g,m));}
  updateActivity(payload); camera.position.set(0,95,225);camera.lookAt(0,0,0);built=true;
}
function updateActivity(payload){
  const kills=Math.max(0,Number(payload.data.activity?.ship_kills||0));const intensity=1-Math.exp(-kills/7);const color=ORANGE.clone().lerp(RED,THREE.MathUtils.clamp(intensity,0,1));
  if(originMesh){originMesh.material.color.copy(color);const scale=1+Math.min(1.4,intensity*1.4);originMesh.scale.setScalar(scale)}
  if(killBadge){killBadge.hidden=kills<=0;if(kills>0){killBadge.textContent=kills.toLocaleString("en-US");killBadge.style.setProperty("--kill-size",`${Math.round(30+intensity*18)}px`);killBadge.style.setProperty("--kill-color",`#${color.getHexString()}`)}}
}
async function fetchData(){const r=await fetch(API_URL,{cache:"no-store",headers:{Accept:"application/json"}});const p=await r.json();if(!r.ok||!p?.ok||!p?.data)throw new Error(p?.error?.message||`HTTP ${r.status}`);return p}
async function refresh(){if(refreshing)return;refreshing=true;try{const p=await fetchData();const sig=(p.data.objects||[]).map(o=>`${o.id}:${o.name}`).join("|");if(!built||sig!==topologySignature)build(p);else updateActivity(p)}catch(e){console.error("Unable to refresh Zarzakh overlay.",e)}finally{refreshing=false}}
function resize(){const w=Math.max(1,innerWidth),h=Math.max(1,innerHeight);camera.aspect=w/h;camera.updateProjectionMatrix();renderer.setSize(w,h,false);labels.setSize(w,h)}
function animate(){requestAnimationFrame(animate);root.rotation.y+=ROTATION_RADIANS_PER_SECOND*Math.min(clock.getDelta(),.1);renderer.render(scene,camera);labels.render(scene,camera)}
addEventListener("resize",resize,{passive:true});await refresh();setInterval(refresh,REFRESH_INTERVAL_MS);animate();
