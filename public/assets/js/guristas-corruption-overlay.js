"use strict";

import * as THREE from "three";
import { CSS2DObject, CSS2DRenderer } from "three/addons/renderers/CSS2DRenderer.js";

const API_URL="/api/war/guristas-overlay.php";
const REFRESH_INTERVAL_MS=5*60*1000;
const ROTATION_RADIANS_PER_SECOND=.055;
const MAP_SPAN=175;
const ORANGE=new THREE.Color("#ff6a00");
const RED=new THREE.Color("#ff1515");
const container=document.querySelector("#guristasCorruptionOverlay");
const scene=new THREE.Scene();scene.background=null;const root=new THREE.Group();scene.add(root);
const camera=new THREE.PerspectiveCamera(44,Math.max(1,innerWidth)/Math.max(1,innerHeight),.1,3000);
const renderer=new THREE.WebGLRenderer({alpha:true,antialias:true,premultipliedAlpha:true,powerPreference:"high-performance"});renderer.setClearColor(0x000000,0);renderer.setPixelRatio(Math.min(devicePixelRatio||1,1.5));renderer.setSize(innerWidth,innerHeight,false);renderer.outputColorSpace=THREE.SRGBColorSpace;renderer.sortObjects=false;container.appendChild(renderer.domElement);
const labelRenderer=new CSS2DRenderer();labelRenderer.setSize(innerWidth,innerHeight);labelRenderer.domElement.className="overlay-label-layer";container.appendChild(labelRenderer.domElement);
const clock=new THREE.Clock();const matrix=new THREE.Matrix4();const scaleVec=new THREE.Vector3();const quat=new THREE.Quaternion();
const state={mesh:null,positions:new Map(),indices:new Map(),labels:new Map(),badges:new Map(),signature:"",refreshing:false};
function reset(){while(root.children.length){const o=root.children.pop();if(o.geometry)o.geometry.dispose();if(o.material){if(Array.isArray(o.material))o.material.forEach(m=>m.dispose());else o.material.dispose()}}state.mesh=null;state.positions.clear();state.indices.clear();state.labels.clear();state.badges.clear()}
function normalize(systems){const raw=systems.map(s=>new THREE.Vector3(Number(s.position.x),Number(s.position.y),-Number(s.position.z)));const box=new THREE.Box3().setFromPoints(raw);const c=box.getCenter(new THREE.Vector3());const sz=box.getSize(new THREE.Vector3());const k=MAP_SPAN/(Math.max(sz.x,sz.y,sz.z)||1);systems.forEach((s,i)=>state.positions.set(Number(s.id),raw[i].sub(c).multiplyScalar(k)))}
function intensity(system){const p=Number(system.corruption_percent);if(Number.isFinite(p))return THREE.MathUtils.clamp(p/100,0,1);const st=Number(system.corruption_stage);if(Number.isFinite(st))return THREE.MathUtils.clamp(st/5,0,1);return 0}
function badgeText(system){const st=Number(system.corruption_stage);if(Number.isFinite(st))return `C${Math.max(0,Math.min(5,Math.round(st)))}`;const p=Number(system.corruption_percent);if(Number.isFinite(p))return `${Math.round(p)}%`;return "C?"}
function build(payload){reset();const systems=payload.data.systems||[],edges=payload.data.edges||[];state.signature=systems.map(s=>Number(s.id)).sort((a,b)=>a-b).join(",");normalize(systems);const geo=new THREE.SphereGeometry(1,12,8);const mat=new THREE.MeshBasicMaterial({color:0xffffff});state.mesh=new THREE.InstancedMesh(geo,mat,systems.length);state.mesh.instanceMatrix.setUsage(THREE.DynamicDrawUsage);root.add(state.mesh);
 systems.forEach((s,i)=>{const id=Number(s.id),p=state.positions.get(id);state.indices.set(id,i);const a=document.createElement("div");a.className="c-label-anchor";const l=document.createElement("span");l.className="c-label has-badge"+(s.is_fob?" is-fob":"");l.textContent=s.is_fob?`${s.name} // FOB`:s.name;a.appendChild(l);const lo=new CSS2DObject(a);lo.position.copy(p);root.add(lo);state.labels.set(id,l);const ba=document.createElement("div");ba.className="c-badge-anchor";const b=document.createElement("span");b.className="c-badge"+(s.is_fob?" is-fob":"");ba.appendChild(b);const bo=new CSS2DObject(ba);bo.position.copy(p);root.add(bo);state.badges.set(id,b)});
 const verts=[];for(const e of edges){const a=state.positions.get(Number(e.a)),b=state.positions.get(Number(e.b));if(a&&b)verts.push(a.x,a.y,a.z,b.x,b.y,b.z)}if(verts.length){const g=new THREE.BufferGeometry();g.setAttribute("position",new THREE.Float32BufferAttribute(verts,3));const m=new THREE.LineBasicMaterial({color:ORANGE,transparent:true,opacity:.7});root.add(new THREE.LineSegments(g,m))}update(payload);camera.position.set(0,110,245);camera.lookAt(0,0,0)}
function update(payload){for(const s of payload.data.systems||[]){const id=Number(s.id),i=state.indices.get(id),p=state.positions.get(id);if(i===undefined||!p||!state.mesh)continue;const x=intensity(s),color=ORANGE.clone().lerp(RED,x),r=(.92+x*1.25)*(s.is_fob?1.22:1);scaleVec.set(r,r,r);matrix.compose(p,quat,scaleVec);state.mesh.setMatrixAt(i,matrix);state.mesh.setColorAt(i,color);const b=state.badges.get(id);if(b){b.textContent=badgeText(s);b.style.setProperty("--node-size",`${Math.round(22+x*17+(s.is_fob?4:0))}px`);b.style.setProperty("--node-color",`#${color.getHexString()}`)}}state.mesh.instanceMatrix.needsUpdate=true;if(state.mesh.instanceColor)state.mesh.instanceColor.needsUpdate=true}
async function fetchData(){const r=await fetch(API_URL,{cache:"no-store",headers:{Accept:"application/json"}});const p=await r.json();if(!r.ok||!p?.ok||!p?.data)throw new Error(p?.error?.message||`HTTP ${r.status}`);return p}
async function refresh(){if(state.refreshing)return;state.refreshing=true;try{const p=await fetchData();const sig=(p.data.systems||[]).map(s=>Number(s.id)).sort((a,b)=>a-b).join(",");if(!state.mesh||sig!==state.signature)build(p);else update(p)}catch(e){console.error("Unable to refresh Guristas corruption overlay.",e)}finally{state.refreshing=false}}
function resize(){const w=Math.max(1,innerWidth),h=Math.max(1,innerHeight);camera.aspect=w/h;camera.updateProjectionMatrix();renderer.setSize(w,h,false);labelRenderer.setSize(w,h)}
function animate(){requestAnimationFrame(animate);root.rotation.y+=ROTATION_RADIANS_PER_SECOND*Math.min(clock.getDelta(),.1);renderer.render(scene,camera);labelRenderer.render(scene,camera)}
addEventListener("resize",resize,{passive:true});await refresh();setInterval(refresh,REFRESH_INTERVAL_MS);animate();
