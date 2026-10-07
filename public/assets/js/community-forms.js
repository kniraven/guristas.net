(function(){
    'use strict';
    const form=document.querySelector('[data-community-editor]');
    if(form){const select=form.querySelector('[name=type]');const update=()=>form.querySelectorAll('[data-entry-types]').forEach(group=>{const active=group.dataset.entryTypes.split(' ').includes(select.value);group.hidden=!active;group.querySelectorAll('input,select,textarea').forEach(input=>input.disabled=!active);});select.addEventListener('change',update);update();}
    const upload=document.querySelector('[data-community-upload]');
    if(upload){const select=upload.querySelector('[name=type]');const update=()=>upload.querySelectorAll('[data-comic-page]').forEach(group=>{const active=select.value==='comic';group.hidden=!active;group.querySelectorAll('input,textarea').forEach(input=>input.disabled=!active);});select.addEventListener('change',update);update();}
})();
