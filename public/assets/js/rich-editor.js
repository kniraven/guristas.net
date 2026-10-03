/* Reusable editor: explicit selection preservation, sanitized preview, draft protection. */
(() => {
  const allowed=new Set(['P','BR','STRONG','B','EM','I','U','S','UL','OL','LI','BLOCKQUOTE','PRE','CODE','H2','H3','DIV','A']);
  function clean(html){
    const doc=new DOMParser().parseFromString(html,'text/html'),fragment=document.createDocumentFragment();
    function walk(node,target){
      if(node.nodeType===Node.TEXT_NODE){target.append(document.createTextNode(node.textContent));return;}
      if(node.nodeType!==Node.ELEMENT_NODE||['SCRIPT','STYLE','SVG','IFRAME','OBJECT','MATH','TEMPLATE'].includes(node.tagName))return;
      let el=target;
      if(allowed.has(node.tagName)){
        el=document.createElement(node.tagName.toLowerCase());
        if(node.tagName==='A')try{const url=new URL(node.getAttribute('href'));if(['http:','https:'].includes(url.protocol)){el.href=url.href;el.rel='nofollow noopener noreferrer';}}catch{}
        target.append(el);
      }
      for(const child of node.childNodes)walk(child,el);
    }
    for(const child of doc.body.childNodes)walk(child,fragment);return fragment;
  }
  const drafts=new Map();let submitting=false;
  function snapshot(form){return JSON.stringify([...form.elements].filter(e=>e.name&&!['submit','button'].includes(e.type)).map(e=>[e.name,e.type==='file'?[...e.files].map(f=>[f.name,f.size,f.lastModified]):['checkbox','radio'].includes(e.type)?[e.value,e.checked]:e.value]));}
  const dirty=form=>drafts.has(form)&&snapshot(form)!==drafts.get(form);
  function watch(form){
    if(!form||drafts.has(form))return;drafts.set(form,snapshot(form));
    form.addEventListener('submit',event=>{
      // All editors sync on input; leave protection also covers other forms on the page.
      if([...drafts.keys()].some(other=>other!==form&&dirty(other))&&!window.confirm('You have unsaved changes elsewhere on this page. Continue and discard those changes?')){event.preventDefault();return;}
      submitting=true;
    });
  }
  window.addEventListener('beforeunload',event=>{if(!submitting&&[...drafts.keys()].some(dirty)){event.preventDefault();event.returnValue='';}});
  window.GuristasDrafts={watch,dirty};
  function enhance(wrapper){
    if(wrapper.dataset.enhanced)return;const source=wrapper.querySelector('[data-rich-source]');if(!source)return;wrapper.dataset.enhanced='1';
    const toolbar=document.createElement('div');toolbar.className='g-rich-toolbar';toolbar.setAttribute('role','toolbar');toolbar.setAttribute('aria-label','Text formatting');
    const area=document.createElement('div');area.className='g-rich-content';area.contentEditable='true';area.setAttribute('role','textbox');area.setAttribute('aria-multiline','true');area.setAttribute('aria-label',wrapper.querySelector('label').textContent);area.id=source.id+'-visual';
    const preview=document.createElement('div');preview.className='g-rich-content g-rich-render';preview.hidden=true;preview.setAttribute('aria-label','Formatted text preview');
    if(source.dataset.format==='html')area.append(clean(source.value));else area.textContent=source.value;
    source.hidden=true;source.classList.add('g-rich-source');
    const flag=wrapper.querySelector('[data-rich-format]')||document.createElement('input');flag.type='hidden';flag.name=source.name+'_format';flag.value='html';if(!flag.parentNode)wrapper.append(flag);
    let savedRange=null,mode='edit';const controls=[],modeButtons=[];
    const selectionInside=selection=>selection&&selection.rangeCount&&area.contains(selection.anchorNode)&&area.contains(selection.focusNode);
    function remember(){const sel=getSelection();if(mode==='edit'&&selectionInside(sel))savedRange=sel.getRangeAt(0).cloneRange();}
    function restore(){
      // Copy BEFORE focus: focusing contenteditable can alter the live selection.
      const range=savedRange?.cloneRange();area.focus({preventScroll:true});const sel=getSelection();
      if(range&&area.contains(range.commonAncestorContainer)){sel.removeAllRanges();sel.addRange(range);}
      else{const end=document.createRange();end.selectNodeContents(area);end.collapse(false);sel.removeAllRanges();sel.addRange(end);}
    }
    function sync(){if(mode==='edit')source.value=area.innerHTML;}
    function states(){
      controls.forEach(({button,command,value,toggle})=>{
        button.disabled=mode!=='edit';if(!toggle)return;
        let active=false;if(mode==='edit')try{active=command==='formatBlock'?String(document.queryCommandValue(command)).replace(/[<>]/g,'').toLowerCase()===value:document.queryCommandState(command);}catch{}
        button.setAttribute('aria-pressed',String(active));
      });
    }
    function run(command,value){if(mode!=='edit')return;restore();document.execCommand(command,false,value);remember();sync();states();}
    function preserve(event){remember();event.preventDefault();}
    function button(label,command,value,toggle=false){
      const b=document.createElement('button');b.type='button';b.textContent=label;b.title=label;b.setAttribute('aria-label',label);if(toggle)b.setAttribute('aria-pressed','false');
      b.addEventListener('mousedown',preserve);b.addEventListener('click',()=>run(command,value));toolbar.append(b);controls.push({button:b,command,value,toggle});return b;
    }
    button('Bold','bold',null,true);button('Italic','italic',null,true);button('Underline','underline',null,true);
    button('Bullets','insertUnorderedList',null,true);button('Numbered list','insertOrderedList',null,true);
    button('Quote','formatBlock','blockquote',true);button('Heading','formatBlock','h2',true);button('Paragraph','formatBlock','p',true);
    const clear=button('Clear formatting','removeFormat');clear.addEventListener('click',()=>{run('unlink');});
    button('Undo','undo');button('Redo','redo');
    const linkButton=document.createElement('button');linkButton.type='button';linkButton.textContent='Link';toolbar.append(linkButton);controls.push({button:linkButton});linkButton.addEventListener('mousedown',preserve);
    const linkPanel=document.createElement('div');linkPanel.className='g-rich-link';linkPanel.hidden=true;
    const url=document.createElement('input');url.type='text';url.inputMode='url';url.placeholder='https://example.com';url.setAttribute('aria-label','Link URL');url.disabled=true;
    const apply=document.createElement('button');apply.type='button';apply.textContent='Apply link';
    const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';
    const linkError=document.createElement('span');linkError.className='g-rich-link-error';linkError.setAttribute('role','alert');
    linkPanel.append(url,apply,cancel,linkError);
    function closeLink(){url.value='';url.setCustomValidity('');url.disabled=true;linkError.textContent='';linkPanel.hidden=true;}
    linkButton.addEventListener('click',()=>{linkPanel.hidden=false;url.disabled=false;linkError.textContent='';url.focus();});
    cancel.addEventListener('click',()=>{closeLink();restore();states();});
    apply.addEventListener('click',()=>{
      let parsed;try{parsed=new URL(url.value.trim());if(!['http:','https:'].includes(parsed.protocol))throw new Error();}catch{linkError.textContent='Enter a complete http:// or https:// URL.';url.focus();return;}
      restore();if(getSelection().isCollapsed){const a=document.createElement('a');a.href=parsed.href;a.textContent=parsed.href;document.execCommand('insertHTML',false,a.outerHTML);}else document.execCommand('createLink',false,parsed.href);
      closeLink();remember();sync();states();
    });
    url.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();cancel.click();}else if(event.key==='Enter'){event.preventDefault();apply.click();}});
    const modes=document.createElement('div');modes.className='g-rich-modes';modes.setAttribute('role','group');modes.setAttribute('aria-label','Editor view');
    function setMode(next){
      if(next===mode)return;sync();closeLink();
      if(mode==='html'&&next==='edit'){area.replaceChildren(clean(source.value));source.value=area.innerHTML;savedRange=null;}
      mode=next;area.hidden=mode!=='edit';source.hidden=mode!=='html';preview.hidden=mode!=='preview';
      if(mode==='preview')preview.replaceChildren(clean(source.value));
      wrapper.querySelector('label').htmlFor=mode==='html'?source.id:area.id;
      modeButtons.forEach(({button,key})=>button.setAttribute('aria-pressed',String(key===mode)));states();
    }
    // If returning through preview to edit, always apply current source changes.
    const originalSetMode=setMode;
    [['Edit','edit'],['HTML','html'],['Preview','preview']].forEach(([label,key])=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.setAttribute('aria-pressed',String(key==='edit'));b.addEventListener('click',()=>{
      if(key==='edit'&&mode!=='edit'){area.replaceChildren(clean(source.value));savedRange=null;}
      originalSetMode(key);
    });modeButtons.push({button:b,key});modes.append(b);});
    wrapper.insertBefore(modes,source);wrapper.insertBefore(toolbar,source);wrapper.insertBefore(linkPanel,source);wrapper.insertBefore(area,source);wrapper.insertBefore(preview,source);wrapper.querySelector('label').htmlFor=area.id;
    document.addEventListener('selectionchange',()=>{if(mode==='edit'&&selectionInside(getSelection())){remember();states();}});
    area.addEventListener('input',()=>{remember();sync();states();});['keyup','mouseup'].forEach(event=>area.addEventListener(event,()=>{remember();states();}));
    area.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));remember();sync();states();});
    area.addEventListener('drop',event=>event.preventDefault());source.form?.addEventListener('submit',sync);sync();states();
    setTimeout(()=>watch(source.form),0);
  }
  window.GuristasEditor={init(root=document){root.querySelectorAll('[data-rich-editor]').forEach(enhance);}};
  document.addEventListener('DOMContentLoaded',()=>window.GuristasEditor.init());
})();
