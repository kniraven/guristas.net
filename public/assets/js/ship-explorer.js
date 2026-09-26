'use strict';

const COLS=[
['name','Ship','Ship hull name.'],['size','Size','General ship size class.'],['family','Family','Broad hull family: for example Destroyer includes tactical destroyers, interdictors, and other destroyer subclasses.'],['type','Type','Specific ship tree class or hull group.'],['faction','Faction','Ship faction or race.'],['tech','Tech','Underlying technology level (Tech I, II, or III). Faction ships may also be Tech I.'],['variant','Variant','CCP meta group, such as Tech I, Tech II, Faction, or Storyline.'],
['shieldHp','Shield HP','Base shield hit points.'],['shieldRecharge','Shield recharge (s)','Time for shields to recharge, before modifiers.'],['armorHp','Armor HP','Base armor hit points.'],['structureHp','Structure HP','Base hull hit points.'],
['shieldEm','Shield EM %','Base shield EM damage resistance.'],['shieldThermal','Shield thermal %','Base shield thermal damage resistance.'],['shieldKinetic','Shield kinetic %','Base shield kinetic damage resistance.'],['shieldExplosive','Shield explosive %','Base shield explosive damage resistance.'],
['armorEm','Armor EM %','Base armor EM damage resistance.'],['armorThermal','Armor thermal %','Base armor thermal damage resistance.'],['armorKinetic','Armor kinetic %','Base armor kinetic damage resistance.'],['armorExplosive','Armor explosive %','Base armor explosive damage resistance.'],
['structureEm','Hull EM %','Base structure EM damage resistance.'],['structureThermal','Hull thermal %','Base structure thermal damage resistance.'],['structureKinetic','Hull kinetic %','Base structure kinetic damage resistance.'],['structureExplosive','Hull explosive %','Base structure explosive damage resistance.'],
['targetRange','Target range (km)','Maximum base locking range.'],['gravimetric','Gravimetric strength','Gravimetric sensor strength; other sensor types are not represented here.'],['scanResolution','Scan resolution (mm)','Sensor scan resolution; higher generally locks faster.'],['signature','Signature radius (m)','Apparent target size used in weapon application and lock speed.'],['maxTargets','Max locks','Maximum simultaneously locked targets before skills.'],
['velocity','Speed (m/s)','Base maximum velocity without propulsion modules.'],['mass','Mass (kg)','Base ship mass.'],['inertia','Inertia modifier','Lower modifier generally means quicker alignment.'],['warpSpeed','Warp speed (AU/s)','Base warp travel speed.'],['alignTime','Align time (s)','Calculated base time to reach 75% of maximum speed from rest.'],
['droneBandwidth','Drone bandwidth (Mbit/s)','Maximum active drone bandwidth before modifiers.'],['droneRangeBonus','Drone range bonus (m)','Hull specific drone control distance attribute, if present; total control range depends on skills and fit.'],['capacitor','Capacitor (GJ)','Base capacitor capacity.'],['capRecharge','Cap recharge (s)','Base time to recharge capacitor.'],['capPeak','Peak cap regen (GJ/s)','Approximate maximum natural capacitor recharge: 2.5 × capacity ÷ recharge time.']];
const byKey=Object.fromEntries(COLS.map(c=>[c[0],c]));const DEFAULT=COLS.map(c=>c[0]);let ships=[],shown=[],sortKey='name',sortDir=1,order=[...DEFAULT],visible=new Set(DEFAULT.slice(0,12).concat(['targetRange','signature','velocity','alignTime','droneBandwidth','capacitor'])),lockCount=0;
try{const s=JSON.parse(localStorage.getItem('shipExplorerColumns')||'null');if(s&&Array.isArray(s.order)&&Array.isArray(s.visible)){order=[...new Set(s.order.filter(k=>byKey[k]))];for(const k of DEFAULT)if(!order.includes(k))order.push(k);visible=new Set(s.visible.filter(k=>byKey[k]));}if(s&&Number.isInteger(s.lockCount)&&s.lockCount>=0&&s.lockCount<=3)lockCount=s.lockCount;}catch{}
const $=id=>document.getElementById(id);const numeric=k=>!['name','size','family','type','faction','tech','variant'].includes(k);const fmt=(v,k)=>v==null?'—':numeric(k)?Number(v).toLocaleString(undefined,{maximumFractionDigits:3}):String(v);const FACETS=['size','family','type','faction','tech','variant'];
// These are base attributes for which a smaller value is generally advantageous.
// Mass is intentionally excluded: lower mass has tradeoffs depending on ship use.
const LOWER_BETTER=new Set(['shieldRecharge','signature','inertia','alignTime','capRecharge']);
const selection=Object.fromEntries(FACETS.map(k=>[k,new Set()]));
function createFacets(){for(const field of FACETS){const facet=document.querySelector(`[data-field="${field}"]`),box=facet.querySelector('.facet-options');box.replaceChildren();const all=document.createElement('button');all.type='button';all.textContent='Clear selection';all.onclick=()=>{selection[field].clear();box.querySelectorAll('input').forEach(c=>c.checked=false);updateFacetLabel(facet,field);render()};box.append(all);for(const value of [...new Set(ships.map(s=>s[field]))].filter(Boolean).sort((a,b)=>a.localeCompare(b))){const label=document.createElement('label'),check=document.createElement('input');check.type='checkbox';check.value=value;check.checked=selection[field].has(value);check.onchange=()=>{check.checked?selection[field].add(value):selection[field].delete(value);updateFacetLabel(facet,field);render()};label.append(check,document.createTextNode(value));box.append(label)}updateFacetLabel(facet,field)}}
function updateFacetLabel(facet,field){const title=field==='tech'?'Tech':field[0].toUpperCase()+field.slice(1),n=selection[field].size;facet.querySelector('summary').textContent=title+': '+(n?n===1?[...selection[field]][0]:n+' selected':'All')}
document.querySelectorAll('.facet').forEach(el=>el.addEventListener('toggle',()=>{if(el.open)document.querySelectorAll('.facet').forEach(other=>{if(other!==el)other.open=false})}));
document.addEventListener('pointerdown',event=>{if(!event.target.closest('.facet'))document.querySelectorAll('.facet[open]').forEach(el=>el.open=false)});
document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelectorAll('.facet[open]').forEach(el=>el.open=false)});

let syncTimer=0;
function persist(){
 const layout={order,visible:[...visible],lockCount};
 try{localStorage.setItem('shipExplorerColumns',JSON.stringify(layout))}catch{}
 if(window.guristasAccount?.signedIn){
  clearTimeout(syncTimer);
  syncTimer=setTimeout(()=>fetch('/api/account/ship-layout.php',{
   method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':window.guristasAccount.csrf},body:JSON.stringify(layout)
  }).catch(()=>{}),500);
 }
}
function restoreAccountLayout(layout){
 if(!layout||!Array.isArray(layout.order)||!Array.isArray(layout.visible))return;
 order=[...new Set(layout.order.filter(k=>byKey[k]))];for(const k of DEFAULT)if(!order.includes(k))order.push(k);
 visible=new Set(layout.visible.filter(k=>byKey[k]));
 if(Number.isInteger(layout.lockCount)&&layout.lockCount>=0&&layout.lockCount<=3)lockCount=layout.lockCount;
 $('lockColumns').value=String(lockCount);
 try{localStorage.setItem('shipExplorerColumns',JSON.stringify({order,visible:[...visible],lockCount}))}catch{}
}
let lockFrame=0;
function scheduleLockLayout(){if(lockFrame)cancelAnimationFrame(lockFrame);lockFrame=requestAnimationFrame(()=>{lockFrame=0;layoutLockedColumns()})}
function layoutLockedColumns(){
 const table=document.querySelector('.ships-page table'),headers=[...$('head').querySelectorAll('tr:first-child th')];
 if(!table||!headers.length)return;
 const count=Math.min(lockCount,headers.length),offsets=[];let left=0;
 for(let i=0;i<count;i++){offsets.push(left);left+=headers[i].getBoundingClientRect().width}
 for(const row of table.rows){for(let i=0;i<row.cells.length;i++){
  const cell=row.cells[i],locked=i<count;cell.classList.toggle('is-locked',locked);cell.classList.toggle('last-locked',locked&&i===count-1);
  if(locked)cell.style.left=`${offsets[i]}px`;else cell.style.removeProperty('left');
 }}
 const rangeRow=$('head').querySelector('tr.ranges');if(rangeRow)for(const cell of rangeRow.cells)cell.style.top=`${$('head').rows[0].getBoundingClientRect().height}px`;
}
function render(){const q=$('search').value.trim().toLocaleLowerCase();shown=ships.filter(s=>(!q||(s.name+' '+s.faction).toLocaleLowerCase().includes(q))&&FACETS.every(k=>!selection[k].size||selection[k].has(s[k])));const active=order.filter(k=>visible.has(k));shown.sort((a,b)=>{let x=a[sortKey],y=b[sortKey];if(x==null)return y==null?0:1;if(y==null)return -1;return (numeric(sortKey)?x-y:String(x).localeCompare(String(y),undefined,{numeric:true}))*sortDir});$('count').textContent=`${shown.length} of ${ships.length} ships`;
const head=$('head');head.replaceChildren();const headers=document.createElement('tr');const ranges=document.createElement('tr');ranges.className='ranges';for(const k of active){const c=byKey[k],th=document.createElement('th'),btn=document.createElement('button');btn.type='button';btn.textContent=c[1]+(sortKey===k?(sortDir>0?' ▲':' ▼'):'');btn.title=c[2];btn.setAttribute('aria-label',`Sort by ${c[1]}. ${c[2]}`);btn.onclick=()=>{sortDir=sortKey===k?-sortDir:1;sortKey=k;render()};th.append(btn);const hint=document.createElement('span');hint.className='hint';hint.tabIndex=0;hint.title=c[2];hint.setAttribute('aria-label',c[2]);hint.textContent='ⓘ';th.append(hint);if(sortKey===k)th.setAttribute('aria-sort',sortDir>0?'ascending':'descending');headers.append(th);const r=document.createElement('th');if(numeric(k)){const values=shown.map(s=>s[k]).filter(v=>v!=null&&Number.isFinite(v));if(values.length){const low=Math.min(...values),high=Math.max(...values),lowerBetter=LOWER_BETTER.has(k);r.textContent=`${fmt(lowerBetter?high:low,k)} → ${fmt(lowerBetter?low:high,k)}`;r.title=lowerBetter?'Higher to lower; lower is generally better. Current matching ships.':'Low to high among current matching ships.'}else r.textContent='—'}ranges.append(r)}head.append(headers,ranges);
const tbody=$('body');tbody.replaceChildren();const fragment=document.createDocumentFragment();for(const s of shown){const row=document.createElement('tr');for(const k of active){const cell=document.createElement('td');cell.textContent=fmt(s[k],k);row.append(cell)}fragment.append(row)}tbody.append(fragment);scheduleLockLayout()}
function columnDialog(){const list=$('columnList');list.replaceChildren();for(const [index,k] of order.entries()){const item=document.createElement('li');item.draggable=true;item.dataset.key=k;const check=document.createElement('input');check.type='checkbox';check.checked=visible.has(k);check.setAttribute('aria-label',`Show ${byKey[k][1]}`);check.onchange=()=>{check.checked?visible.add(k):visible.delete(k);persist();render()};const label=document.createElement('label');label.textContent=byKey[k][1];label.prepend(check);item.append(label);for(const [symbol,step] of [['↑',-1],['↓',1]]){const b=document.createElement('button');b.type='button';b.textContent=symbol;b.setAttribute('aria-label',`Move ${byKey[k][1]} ${step<0?'up':'down'}`);b.disabled=index+step<0||index+step>=order.length;b.onclick=()=>{[order[index],order[index+step]]=[order[index+step],order[index]];persist();columnDialog();render()};item.append(b)}item.ondragstart=e=>{e.dataTransfer.setData('text/plain',k);item.classList.add('dragging')};item.ondragend=()=>item.classList.remove('dragging');item.ondragover=e=>e.preventDefault();item.ondrop=e=>{e.preventDefault();const from=e.dataTransfer.getData('text/plain');if(!byKey[from]||from===k)return;order.splice(order.indexOf(from),1);order.splice(order.indexOf(k),0,from);persist();columnDialog();render()};list.append(item)}}
$('search').addEventListener('input',render);$('lockColumns').value=String(lockCount);$('lockColumns').addEventListener('change',()=>{lockCount=Number($('lockColumns').value);persist();scheduleLockLayout()});window.addEventListener('resize',scheduleLockLayout);$('columns').onclick=()=>{columnDialog();$('dialog').showModal()};$('close').onclick=()=>$('dialog').close();$('reset').onclick=()=>{$('search').value='';for(const field of FACETS)selection[field].clear();createFacets();order=[...DEFAULT];visible=new Set(DEFAULT.slice(0,12).concat(['targetRange','signature','velocity','alignTime','droneBandwidth','capacitor']));lockCount=0;$('lockColumns').value='0';sortKey='name';sortDir=1;persist();render()};
const accountLayout=window.guristasAccount?.signedIn
 ? fetch('/api/account/ship-layout.php',{credentials:'same-origin'}).then(r=>r.ok?r.json():null).catch(()=>null)
 : Promise.resolve(null);
Promise.all([fetch('/assets/data/ships.json').then(r=>{if(!r.ok)throw Error(`HTTP ${r.status}`);return r.json()}),accountLayout])
 .then(([data,account])=>{ships=data.ships;if(account?.layout)restoreAccountLayout(account.layout);else if(window.guristasAccount?.signedIn)persist();createFacets();$('source').textContent=`${data.source}${data.build?' · build '+data.build:''}`;render()})
 .catch(e=>{$('count').textContent='Unable to load ship data: '+e.message});
