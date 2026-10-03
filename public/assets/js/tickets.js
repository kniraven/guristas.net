document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-subtasks]').forEach(box=>{
    const source=box.querySelector('textarea');let items=[];
    try {items=JSON.parse(box.dataset.items);}catch{}
    const list=document.createElement('div');list.className='ticket-subtask-list';
    const progress=document.createElement('p');progress.className='ticket-meta';progress.setAttribute('aria-live','polite');
    const bar=document.createElement('progress');bar.max=100;bar.setAttribute('aria-label','Subtask completion');
    const add=document.createElement('button');add.type='button';add.className='button';add.textContent='+ Add subtask';
    const rows=[];
    const sync=()=>{source.value=JSON.stringify(rows.filter(r=>r.text.value.trim()).map(r=>({text:r.text.value.trim(),done:r.check.checked})));const active=rows.filter(r=>r.text.value.trim());const done=active.filter(r=>r.check.checked).length;progress.textContent=`${done} of ${active.length} complete · Save ticket to keep changes`;bar.value=active.length?100*done/active.length:0;};
    const make=(item={text:'',done:false},focus=false)=>{
      if(rows.length>=100)return;
      const row=document.createElement('div');row.className='ticket-subtask';
      const check=document.createElement('input');check.type='checkbox';check.checked=!!item.done;check.setAttribute('aria-label','Mark subtask complete');
      const text=document.createElement('input');text.type='text';text.value=item.text;text.maxLength=300;text.placeholder='What needs to be done?';text.setAttribute('aria-label','Subtask description');
      const remove=document.createElement('button');remove.type='button';remove.textContent='Remove';remove.className='button';
      const entry={row,check,text};rows.push(entry);row.append(check,text,remove);list.append(row);
      function change(){row.classList.toggle('is-done',check.checked);sync();} check.addEventListener('change',change);text.addEventListener('input',change);
      text.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();make(undefined,true);}});
      remove.addEventListener('click',()=>{const index=rows.indexOf(entry);rows.splice(index,1);row.remove();sync();(rows[index]?.text||rows[index-1]?.text||add).focus();});change();if(focus)text.focus();
    };
    const field=document.createElement('input');field.type='hidden';field.name='subtasks_format';field.value='json';box.append(field);
    source.hidden=true;box.append(progress,bar,list,add);items.forEach(item=>make(item));sync();
    add.addEventListener('click',()=>make(undefined,true));source.form.addEventListener('submit',sync);
  });
  document.querySelectorAll('[data-attachments]').forEach(input=>{
    const output=document.createElement('div');output.className='ticket-file-list';output.setAttribute('aria-live','polite');input.after(output);
    input.addEventListener('change',()=>{
      input.setCustomValidity('');output.replaceChildren();const files=[...input.files];
      if(files.length>5||files.some(f=>f.size>10*1024*1024)||files.reduce((n,f)=>n+f.size,0)>25*1024*1024)input.setCustomValidity('Maximum five files, 10 MB each, 25 MB total.');
      files.forEach(file=>{const line=document.createElement('p');line.textContent=`${file.name} · ${(file.size/1024/1024).toFixed(2)} MB`;output.append(line);});input.reportValidity();
    });
  });
});
