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
        let active=false;if(mode==='edit')try{active=command==='quote'?!!quoteAt(getSelection()?.anchorNode):command==='formatBlock'?String(document.queryCommandValue(command)).replace(/[<>]/g,'').toLowerCase()===value:document.queryCommandState(command);}catch{}
        button.setAttribute('aria-pressed',String(active));
      });
    }
    function quoteAt(node){const el=node?.nodeType===Node.ELEMENT_NODE?node:node?.parentElement;const quote=el?.closest('blockquote');return quote&&area.contains(quote)?quote:null;}
    function toggleQuote(){
      const selection=getSelection(),range=selection.getRangeAt(0).cloneRange();
      const collapsed=range.collapsed;
      // Temporary boundary markers keep the exact caret/selection through DOM moves.
      const start=document.createElement('span'),end=document.createElement('span');
      start.dataset.editorBoundary='start';end.dataset.editorBoundary='end';
      if(!collapsed){const tail=range.cloneRange();tail.collapse(false);tail.insertNode(end);}
      const head=range.cloneRange();head.collapse(true);head.insertNode(start);
      const marked=document.createRange();marked.setStartAfter(start);
      if(collapsed)marked.collapse(true);else marked.setEndBefore(end);
      const quotes=[...area.querySelectorAll('blockquote')].filter(q=>q.contains(start)||(!collapsed&&marked.intersectsNode(q)));
      if(quotes.length){
        for(const q of quotes.reverse())q.replaceWith(...q.childNodes);
      }else{
        let first=start;while(first.parentNode!==area)first=first.parentNode;
        let last=collapsed?first:end;while(last.parentNode!==area)last=last.parentNode;
        const isBlock=node=>node.nodeType===Node.ELEMENT_NODE&&['P','DIV','UL','OL','BLOCKQUOTE','H2','H3','PRE'].includes(node.tagName);
        // Raw text at the editor root is one line/block too. Include its inline siblings.
        if(!isBlock(first))while(first.previousSibling&&!isBlock(first.previousSibling))first=first.previousSibling;
        if(!isBlock(last))while(last.nextSibling&&!isBlock(last.nextSibling))last=last.nextSibling;
        const blocks=[];for(let node=first;node;node=node.nextSibling){blocks.push(node);if(node===last)break;}
        const quote=document.createElement('blockquote');first.before(quote);quote.append(...blocks);
      }
      const restored=document.createRange();restored.setStartBefore(start);
      if(collapsed)restored.collapse(true);else restored.setEndBefore(end);
      start.remove();end.remove();selection.removeAllRanges();selection.addRange(restored);
    }
    function run(command,value){if(mode!=='edit')return;restore();if(command==='quote')toggleQuote();else document.execCommand(command,false,value);remember();sync();states();}
    function preserve(event){remember();event.preventDefault();}
    function button(label,command,value,toggle=false){
      const b=document.createElement('button');b.type='button';b.textContent=label;b.title=label;b.setAttribute('aria-label',label);if(toggle)b.setAttribute('aria-pressed','false');
      b.addEventListener('mousedown',preserve);b.addEventListener('click',()=>run(command,value));toolbar.append(b);controls.push({button:b,command,value,toggle});return b;
    }
    button('Bold','bold',null,true);button('Italic','italic',null,true);button('Underline','underline',null,true);
    button('Bullets','insertUnorderedList',null,true);button('Numbered list','insertOrderedList',null,true);
    button('Quote','quote',null,true);button('Heading','formatBlock','h2',true);button('Paragraph','formatBlock','p',true);
    const clear=button('Clear formatting','removeFormat');clear.addEventListener('click',()=>{run('unlink');});
    button('Undo','undo');button('Redo','redo');
    const linkButton=document.createElement('button');linkButton.type='button';linkButton.textContent='Link';toolbar.append(linkButton);controls.push({button:linkButton});linkButton.addEventListener('mousedown',preserve);
    const linkPanel=document.createElement('div');linkPanel.className='g-rich-link';linkPanel.hidden=true;
    const url=document.createElement('input');url.type='text';url.inputMode='url';url.placeholder='https://example.com';url.setAttribute('aria-label','Link URL');url.disabled=true;
    const apply=document.createElement('button');apply.type='button';apply.textContent='Apply link';
    const cancel=document.createElement('button');cancel.type='button';cancel.textContent='Cancel';
    const linkError=document.createElement('span');linkError.className='g-rich-link-error';linkError.setAttribute('role','alert');
    const linkText=document.createElement('input');linkText.type='text';linkText.placeholder='Text to display';linkText.setAttribute('aria-label','Link text');linkText.hidden=true;linkText.disabled=true;
    let needsLinkText=false;
    linkPanel.append(url,linkText,apply,cancel,linkError);
    function closeLink(){linkText.value='';linkText.disabled=true;linkText.hidden=true;url.value='';url.setCustomValidity('');url.disabled=true;linkError.textContent='';linkPanel.hidden=true;}
    linkButton.addEventListener('click',()=>{restore();remember();needsLinkText=getSelection().isCollapsed;linkText.hidden=!needsLinkText;linkText.disabled=!needsLinkText;linkPanel.hidden=false;url.disabled=false;linkError.textContent='';url.focus();});
    cancel.addEventListener('click',()=>{closeLink();restore();states();});
    apply.addEventListener('click',()=>{
      let parsed;try{parsed=new URL(url.value.trim());if(!['http:','https:'].includes(parsed.protocol))throw new Error();}catch{linkError.textContent='Enter a complete http:// or https:// URL.';url.focus();return;}
      if(needsLinkText&&!linkText.value.trim()){linkError.textContent='Enter the text to display for this link.';linkText.focus();return;}
      restore();if(needsLinkText){const a=document.createElement('a');a.href=parsed.href;a.textContent=linkText.value.trim();document.execCommand('insertHTML',false,a.outerHTML);}else document.execCommand('createLink',false,parsed.href);
      closeLink();remember();sync();states();
    });
    [url,linkText].forEach(input=>input.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();cancel.click();}else if(event.key==='Enter'){event.preventDefault();apply.click();}}));
    const modes=document.createElement('div');modes.className='g-rich-modes';modes.setAttribute('role','group');modes.setAttribute('aria-label','Editor view');
    function setMode(next){
      if(next===mode)return;sync();closeLink();
      if(mode==='html'&&next==='edit'){area.replaceChildren(clean(source.value));source.value=area.innerHTML;savedRange=null;}
      mode=next;area.hidden=mode!=='edit';source.hidden=mode!=='html';
      wrapper.querySelector('label').htmlFor=mode==='html'?source.id:area.id;
      modeButtons.forEach(({button,key})=>button.setAttribute('aria-pressed',String(key===mode)));states();
    }
    // Apply HTML changes when returning to Edit.
    const originalSetMode=setMode;
    [['Edit','edit'],['HTML','html']].forEach(([label,key])=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.setAttribute('aria-pressed',String(key==='edit'));b.addEventListener('click',()=>{
      if(key==='edit'&&mode!=='edit'){area.replaceChildren(clean(source.value));savedRange=null;}
      originalSetMode(key);
    });modeButtons.push({button:b,key});modes.append(b);});
    wrapper.insertBefore(modes,source);wrapper.insertBefore(toolbar,source);wrapper.insertBefore(linkPanel,source);wrapper.insertBefore(area,source);wrapper.querySelector('label').htmlFor=area.id;
    document.addEventListener('selectionchange',()=>{if(mode==='edit'&&selectionInside(getSelection())){remember();states();}});
    area.addEventListener('keydown',event=>{
      if(event.key!=='Enter'||event.shiftKey||event.isComposing)return;
      const selection=getSelection();if(!selectionInside(selection)||!selection.isCollapsed)return;
      const quote=quoteAt(selection.anchorNode);if(!quote)return;
      let block=selection.anchorNode.nodeType===Node.ELEMENT_NODE?selection.anchorNode:selection.anchorNode.parentElement;
      while(block!==quote&&!['P','DIV','LI'].includes(block.tagName))block=block.parentElement;
      if(block.textContent.replace(/\u200b/g,'').trim())return;
      event.preventDefault();const paragraph=document.createElement('p');paragraph.append(document.createElement('br'));
      // Exit all quote levels, so legacy nested quotes cannot trap the caret.
      let outer=quote;while(quoteAt(outer.parentElement))outer=quoteAt(outer.parentElement);
      outer.after(paragraph);if(block!==outer)block.remove();
      for(const list of outer.querySelectorAll('ul,ol'))if(!list.children.length)list.remove();
      if(!outer.textContent.trim())outer.remove();
      const caret=document.createRange();caret.selectNodeContents(paragraph);caret.collapse(true);selection.removeAllRanges();selection.addRange(caret);remember();sync();states();
    });
    area.addEventListener('input',()=>{remember();sync();states();});['keyup','mouseup'].forEach(event=>area.addEventListener(event,()=>{remember();states();}));
    area.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));remember();sync();states();});
    area.addEventListener('drop',event=>event.preventDefault());source.form?.addEventListener('submit',sync);sync();states();
    setTimeout(()=>watch(source.form),0);
  }
  window.GuristasEditor={init(root=document){root.querySelectorAll('[data-rich-editor]').forEach(enhance);}};
  document.addEventListener('DOMContentLoaded',()=>window.GuristasEditor.init());
})();