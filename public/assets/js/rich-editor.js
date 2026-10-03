/* Reusable progressive-enhancement editor. Server-side RichText.php is mandatory. */
(() => {
  const allowed = new Set(['P','BR','STRONG','B','EM','I','U','S','UL','OL','LI','BLOCKQUOTE','PRE','CODE','H2','H3','DIV','A']);
  function clean(html) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const fragment = document.createDocumentFragment();
    function walk(node, target) {
      if (node.nodeType === Node.TEXT_NODE) { target.append(document.createTextNode(node.textContent)); return; }
      if (node.nodeType !== Node.ELEMENT_NODE || ['SCRIPT','STYLE','SVG','IFRAME','OBJECT','MATH','TEMPLATE'].includes(node.tagName)) return;
      let el = target;
      if (allowed.has(node.tagName)) {
        el = document.createElement(node.tagName.toLowerCase());
        if (node.tagName === 'A') {
          try { const url = new URL(node.getAttribute('href')); if (['http:','https:'].includes(url.protocol)) { el.href = url.href; el.rel = 'nofollow noopener noreferrer'; } } catch {}
        }
        target.append(el);
      }
      for (const child of node.childNodes) walk(child, el);
    }
    for (const child of doc.body.childNodes) walk(child, fragment);
    return fragment;
  }
  function enhance(wrapper) {
    if (wrapper.dataset.enhanced) return;
    const source = wrapper.querySelector('[data-rich-source]');
    if (!source) return;
    wrapper.dataset.enhanced = '1';
    const toolbar = document.createElement('div'); toolbar.className = 'g-rich-toolbar'; toolbar.setAttribute('role','toolbar'); toolbar.setAttribute('aria-label','Text formatting');
    const area = document.createElement('div'); area.className = 'g-rich-content'; area.contentEditable = 'true'; area.setAttribute('role','textbox'); area.setAttribute('aria-multiline','true'); area.setAttribute('aria-label',wrapper.querySelector('label').textContent); area.id = source.id + '-visual';
    wrapper.querySelector('label').htmlFor = area.id;
    if (source.dataset.format === 'html') area.append(clean(source.value)); else area.textContent = source.value;
    source.hidden = true;
    let range;
    const remember = () => { const selection = getSelection(); if (selection.rangeCount && area.contains(selection.anchorNode)) range = selection.getRangeAt(0).cloneRange(); };
    const restore = () => { area.focus(); if (range) { const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range); } };
    function sync() { source.value = area.innerHTML; }
    const flag = wrapper.querySelector('[data-rich-format]') || document.createElement('input'); flag.type = 'hidden'; flag.name = source.name + '_format'; flag.value = 'html'; if (!flag.parentNode) wrapper.append(flag);
    function command(label, name, value) {
      const button = document.createElement('button'); button.type='button'; button.textContent=label; button.title=label; button.setAttribute('aria-label',label);
      button.addEventListener('mousedown', event => event.preventDefault());
      button.addEventListener('click', () => { restore(); document.execCommand(name,false,value); remember(); sync(); }); toolbar.append(button);
    }
    [['Bold','bold'],['Italic','italic'],['Underline','underline'],['Bullets','insertUnorderedList'],['Numbered list','insertOrderedList'],['Quote','formatBlock','blockquote'],['Heading','formatBlock','h2'],['Paragraph','formatBlock','p'],['Clear formatting','removeFormat'],['Undo','undo'],['Redo','redo']].forEach(args=>command(...args));
    const linkButton = document.createElement('button'); linkButton.type='button'; linkButton.textContent='Link'; toolbar.append(linkButton);
    const linkPanel=document.createElement('div'); linkPanel.className='g-rich-link'; linkPanel.hidden=true;
    const url=document.createElement('input'); url.type='url'; url.placeholder='https://example.com'; url.setAttribute('aria-label','Link URL');
    const apply=document.createElement('button'); apply.type='button'; apply.textContent='Apply link';
    const cancel=document.createElement('button'); cancel.type='button'; cancel.textContent='Cancel';
    linkPanel.append(url,apply,cancel);
    linkButton.addEventListener('mousedown',()=>remember());
    linkButton.addEventListener('click',()=>{ linkPanel.hidden=false; url.focus(); });
    cancel.addEventListener('click',()=>{linkPanel.hidden=true;restore();});
    apply.addEventListener('click',()=>{
      try { const parsed=new URL(url.value); if (!['http:','https:'].includes(parsed.protocol)) throw new Error(); restore();
        if (getSelection().isCollapsed) { const a=document.createElement('a');a.href=parsed.href;a.textContent=parsed.href;document.execCommand('insertHTML',false,a.outerHTML); } else document.execCommand('createLink',false,parsed.href);
        linkPanel.hidden=true;url.value='';remember();sync();
      } catch {url.setCustomValidity('Enter a complete http:// or https:// URL.');url.reportValidity();}
    }); url.addEventListener('input',()=>url.setCustomValidity(''));
    wrapper.insertBefore(toolbar,source); wrapper.insertBefore(linkPanel,source); wrapper.insertBefore(area,source);
    area.addEventListener('input',sync); ['keyup','mouseup','blur'].forEach(event=>area.addEventListener(event,remember));
    area.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));sync();});
    area.addEventListener('drop',event=>event.preventDefault());
    source.form?.addEventListener('submit',sync); sync();
  }
  window.GuristasEditor={init(root=document){root.querySelectorAll('[data-rich-editor]').forEach(enhance);}};
  document.addEventListener('DOMContentLoaded',()=>window.GuristasEditor.init());
})();
