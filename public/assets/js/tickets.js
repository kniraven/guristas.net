document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-discussion-tabs]').forEach(tablist=>{
    const tabs=[...tablist.querySelectorAll('[data-discussion-tab]')];
    const activate=(tab,focus=false)=>{
      tabs.forEach(item=>{
        const selected=item===tab;
        item.setAttribute('aria-selected',String(selected));item.tabIndex=selected?0:-1;
        const panel=document.getElementById(item.getAttribute('aria-controls'));
        if(panel)panel.hidden=!selected;
      });
      if(focus)tab.focus();
    };
    tabs.forEach((tab,index)=>{
      tab.addEventListener('click',event=>{event.preventDefault();activate(tab);});
      tab.addEventListener('keydown',event=>{
        let next;
        if(event.key==='ArrowRight')next=(index+1)%tabs.length;
        else if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;
        else if(event.key==='Home')next=0;
        else if(event.key==='End')next=tabs.length-1;
        else return;
        event.preventDefault();activate(tabs[next],true);
      });
    });
    activate(tabs.find(tab=>tab.getAttribute('aria-selected')==='true')||tabs[0]);
  });
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
  let imageDialog;
  document.addEventListener('click',event=>{
    const trigger=event.target.closest('[data-image-preview]');if(!trigger)return;
    event.preventDefault();
    if(!imageDialog){
      imageDialog=document.createElement('dialog');imageDialog.className='ticket-image-dialog';imageDialog.setAttribute('aria-label','Image preview');
      const close=document.createElement('button');close.type='button';close.textContent='Close preview';close.className='button';close.addEventListener('click',()=>imageDialog.close());
      const img=document.createElement('img');imageDialog.append(close,img);document.body.append(imageDialog);
    }
    const img=imageDialog.querySelector('img');img.src=trigger.dataset.imagePreview;img.alt=trigger.getAttribute('aria-label')||'Attachment preview';imageDialog.showModal();
  });
  document.querySelectorAll('[data-attachments]').forEach(input=>{
    const output=document.createElement('div');output.className='ticket-file-list';output.setAttribute('aria-live','polite');input.after(output);
    let objectUrls=[];
    input.addEventListener('change',()=>{
      objectUrls.forEach(url=>URL.revokeObjectURL(url));objectUrls=[];
      input.setCustomValidity('');output.replaceChildren();const files=[...input.files];
      if(files.length>5||files.some(f=>f.size>10*1024*1024)||files.reduce((n,f)=>n+f.size,0)>25*1024*1024)input.setCustomValidity('Maximum five files, 10 MB each, 25 MB total.');
      files.forEach(file=>{
        const item=document.createElement('div');item.className='ticket-selected-file';
        if(['image/png','image/jpeg','image/gif'].includes(file.type)&&file.size<=10*1024*1024){
          const src=URL.createObjectURL(file);objectUrls.push(src);
          const button=document.createElement('button');button.type='button';button.className='ticket-image-button';button.dataset.imagePreview=src;button.setAttribute('aria-label','Preview '+file.name);
          const img=document.createElement('img');img.src=src;img.alt=file.name;button.append(img);item.append(button);
        }
        const line=document.createElement('p');line.textContent=`${file.name} · ${(file.size/1024/1024).toFixed(2)} MB`;item.append(line);output.append(item);
      });input.reportValidity();
    });
  });
  queueMicrotask(()=>document.querySelectorAll('form[method="post"]').forEach(form=>window.GuristasDrafts?.watch(form)));
});

/* Filter tickets without navigating away from the page. */
document.addEventListener('DOMContentLoaded', () => {
  const form = document.querySelector('form.ticket-toolbar[method="get"]');
  if (!form) return;
  let summary = form.nextElementSibling;
  let results = summary?.nextElementSibling;
  if (!summary?.matches('.ticket-meta') || !results?.matches('.ticket-board, .ticket-list')) return;

  const message = document.createElement('p');
  message.className = 'ticket-meta';
  message.setAttribute('role', 'status');
  message.setAttribute('aria-live', 'polite');
  message.hidden = true;
  form.after(message);
  form.querySelector('button[type="submit"], button:not([type])')?.remove();
  let controller, timer, requestNumber = 0;

  function currentURL() {
    const url = new URL(form.action || location.href);
    url.search = new URLSearchParams(new FormData(form)).toString();
    url.hash = '';
    return url;
  }

  async function update() {
    clearTimeout(timer);
    controller?.abort();
    controller = new AbortController();
    const number = ++requestNumber;
    const url = currentURL();
    message.hidden = false;
    message.className = 'ticket-meta';
    message.textContent = 'Updating tickets…';
    results.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, {
        signal: controller.signal,
        credentials: 'same-origin',
        cache: 'no-store'
      });
      if (!response.ok || response.redirected) throw new Error('Request failed');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const nextForm = page.querySelector('form.ticket-toolbar[method="get"]');
      const nextSummary = nextForm?.nextElementSibling;
      const nextResults = nextSummary?.nextElementSibling;
      if (!nextSummary?.matches('.ticket-meta') || !nextResults?.matches('.ticket-board, .ticket-list')) {
        throw new Error('Ticket results missing');
      }
      if (number !== requestNumber) return;
      summary.replaceWith(nextSummary);
      results.replaceWith(nextResults);
      summary = nextSummary;
      results = nextResults;
      history.replaceState(history.state, '', url);
      message.textContent = summary.textContent;
    } catch (error) {
      if (error.name === 'AbortError' || number !== requestNumber) return;
      message.className = 'ticket-error';
      message.textContent = 'Could not update tickets. Previous results are still shown. Change a filter or press Enter to retry.';
    } finally {
      if (number === requestNumber) results.removeAttribute('aria-busy');
    }
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    update();
  });
  form.addEventListener('change', event => {
    if (event.target.matches('select')) update();
  });
  form.querySelector('[name="q"]')?.addEventListener('input', () => {
    clearTimeout(timer);
    // Invalidate an older response immediately, even while the search debounce runs.
    controller?.abort();
    ++requestNumber;
    timer = setTimeout(update, 250);
  });
  form.querySelector('a')?.addEventListener('click', event => {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    for (const name of ['q', 'status', 'priority', 'assigned']) form.elements.namedItem(name).value = '';
    update();
  });
});
