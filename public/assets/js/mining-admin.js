(()=>{'use strict';
const $=id=>document.getElementById(id),form=$('catalog-form'),msg=$('form-message');
const initial=JSON.parse($('initial-catalog').textContent);let entries=initial.resources,revision=initial.revision,selected=null,dirty=false,busy=false,sightings=new Set();
const allowed=initial.allowed.slice().sort((a,b)=>a.name.localeCompare(b.name)), systems=initial.systems;
const fields=['site','source','basis','status'];
function showHarvestSkill(category,name=''){const skill=category==='gas'?'Gas Cloud Harvesting I':category==='ice'?'Ice Harvesting I':category==='ore'&&/mercoxit/i.test(name)?'Deep Core Mining I':category==='ore'?'Mining I':'Choose a resource';$('harvest-skill').value=skill;}
const siteTypes={ore:['Asteroid belt','Ore anomaly'],ice:['Ice anomaly'],gas:['Gas cosmic signature']};
function populateSites(category,preferred=''){const el=$('site'),previous=preferred||el.value,options=siteTypes[category]||[];el.replaceChildren(new Option('Select site type',''));for(const name of options)el.add(new Option(name,name));if(options.includes(previous))el.value=previous;return options.includes(previous);}

function message(t,error=false){msg.textContent=t;msg.classList.toggle('mine-error',error);}
function markDirty(){dirty=true;}
function guard(){return !dirty||confirm('Discard unsaved changes?');}
function render(){const q=$('resource-filter').value.trim().toLowerCase(),cat=$('category-filter').value,status=$('status-filter').value,sort=$('sort').value;
const rows=entries.filter(r=>(!q||[r.name,r.site,r.basis].some(v=>String(v||'').toLowerCase().includes(q)))&&(!cat||r.category===cat)&&(!status||r.status===status)).slice();
rows.sort((a,b)=>sort==='reverse'?b.name.localeCompare(a.name):sort==='category'?a.category.localeCompare(b.category)||a.name.localeCompare(b.name):sort==='status'?a.status.localeCompare(b.status)||a.name.localeCompare(b.name):a.name.localeCompare(b.name));
const body=$('resource-rows');body.replaceChildren();for(const r of rows){const tr=document.createElement('tr');for(const val of [r.name,r.category,r.status,String((r.confirmed_systems||[]).length)]){const td=document.createElement('td');td.textContent=val;tr.append(td);}const td=document.createElement('td'),btn=document.createElement('button');btn.type='button';btn.textContent='Edit';btn.addEventListener('click',()=>edit(r));td.append(btn);tr.append(td);body.append(tr);} $('catalog-count').textContent=`${rows.length} of ${entries.length} resources`;
}
function oreFamily(name){return name.replace(/\s+(?:I|II|III|IV|V)-Grade$/i,'');}
function resourceGroup(row){return row.category==='ore'?`ORE / ${oreFamily(row.name)}`:row.category==='gas'?'GAS / Harvestable clouds':'ICE / Ice resources';}
function listItems(){
 const q=$('find').value.trim().toLowerCase(),picker=$('item-picker'),old=picker.value;
 const rows=allowed.filter(r=>r.name.toLowerCase().includes(q));
 picker.replaceChildren(new Option('Select ore, gas, or ice',''));
 const groups=new Map();
 for(const r of rows){const label=resourceGroup(r);if(!groups.has(label))groups.set(label,[]);groups.get(label).push(r);}
 // Keep categories together, then sort families and item variants alphabetically.
 const groupOrder=label=>label.startsWith('ORE /')?0:label.startsWith('GAS /')?1:2;
 for(const [label,members] of [...groups].sort((a,b)=>groupOrder(a[0])-groupOrder(b[0])||a[0].localeCompare(b[0]))){
   const group=document.createElement('optgroup');group.label=label;
   members.sort((a,b)=>a.name.localeCompare(b.name));
   for(const r of members){const opt=new Option(`${r.name} (${r.type_id})`,String(r.type_id));opt.dataset.category=r.category;group.append(opt);}
   picker.append(group);
 }
 if(rows.some(r=>String(r.type_id)===old))picker.value=old;
 $('search-status').textContent=`${rows.length} resources · ore grades grouped by family`;
}
function setItem(id){const record=allowed.find(r=>r.type_id===Number(id));if(!record)return;$('find').value='';listItems();$('item-picker').value=String(id);$('type_id').value=String(id);$('category').value=record.category;populateSites(record.category);showHarvestSkill(record.category,record.name);}
function renderSystems(){const q=$('system-filter').value.toLowerCase(),list=$('system-list');list.replaceChildren();for(const name of systems){if(!name.toLowerCase().includes(q))continue;const label=document.createElement('label'),check=document.createElement('input');check.type='checkbox';check.checked=sightings.has(name);check.addEventListener('change',()=>{if(check.checked)sightings.add(name);else sightings.delete(name);syncSystems();markDirty();});label.append(check,document.createTextNode(' '+name));list.append(label);}syncSystems();}
function syncSystems(){$('confirmed_systems').value=JSON.stringify([...sightings].sort());$('system-count').textContent=`${sightings.size} confirmed systems selected`}
function edit(r){if(!guard())return;selected=r.type_id;setItem(r.type_id);for(const key of fields){let value=r[key]??(key==='skill_level'?0:'');const el=$(key);if(key==='site'){const compatible=populateSites(r.category,value);if(!compatible&&value){message('Saved site description is outdated. Select a compatible site type before saving.',true);}continue;}el.value=value;}sightings=new Set(r.confirmed_systems||[]);renderSystems();$('delete').disabled=false;dirty=false;message(`Editing ${r.name}`);$('editor').scrollIntoView({behavior:'smooth',block:'start'});}
function reset(force=false){if(!force&&!guard())return;selected=null;dirty=false;form.reset();$('find').value='';$('type_id').value='';$('category').value='';populateSites('');showHarvestSkill('');$('delete').disabled=true;sightings.clear();renderSystems();listItems();message('');}
async function reload(){if(!guard())return;const response=await fetch('?api=catalog',{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw Error('Catalog reload failed');const data=await response.json();entries=data.resources;revision=data.revision;$('revision').value=revision;reset(true);render();message('Catalog refreshed');}
for(const id of ['resource-filter','category-filter','status-filter','sort'])$(id).addEventListener(id==='resource-filter'?'input':'change',render);
$('find').addEventListener('input',listItems);$('system-filter').addEventListener('input',renderSystems);$('item-picker').addEventListener('change',()=>{const opt=$('item-picker').selectedOptions[0];$('type_id').value=opt.value;$('category').value=opt.dataset.category||'';populateSites($('category').value);showHarvestSkill($('category').value,opt.textContent);markDirty();});
$('add-new').addEventListener('click',()=>{if(!guard())return;reset(true);$('editor').scrollIntoView({behavior:'smooth'});});$('cancel').addEventListener('click',()=>reset());$('reload-catalog').addEventListener('click',()=>reload().catch(e=>message(e.message,true)));
for(const id of fields)$(id).addEventListener('change',markDirty);for(const id of ['site','source','basis'])$(id).addEventListener('input',markDirty);
async function mutate(action){if(busy)return;if(action==='save'&&(!form.reportValidity()||!$('type_id').value)){message('Select a resource and complete its required fields.',true);return;}
const data=new FormData(form);data.set('action',action);data.set('revision',revision);data.set('confirmed_systems',JSON.stringify([...sightings]));if(action==='delete'){const record=entries.find(r=>String(r.type_id)===$('type_id').value);if(!record||!confirm(`Remove ${record.name} from Venal mining recommendations?`))return;data.set('confirm_name',record.name);}
busy=true;$('save').disabled=true;$('delete').disabled=true;message('Saving…');try{const resp=await fetch(location.pathname,{method:'POST',credentials:'same-origin',body:data,headers:{Accept:'application/json'}});const result=await resp.json();if(!resp.ok||!result.ok)throw Error(result.error||'Save failed');entries=result.resources;revision=result.revision;$('revision').value=revision;reset(true);render();message(result.message||'Saved.');}catch(err){message(err.message,true);}finally{busy=false;$('save').disabled=false;$('delete').disabled=!$('type_id').value||!entries.some(r=>String(r.type_id)===$('type_id').value);}}
form.addEventListener('submit',e=>{e.preventDefault();mutate('save');});$('delete').addEventListener('click',()=>mutate('delete'));
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
populateSites('');listItems();renderSystems();render();
})();
