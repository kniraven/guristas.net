(function(){
    'use strict';
    function uploadSize(files, fileLimit, totalLimit) {
        const total=files.reduce((sum,file)=>sum+file.size,0);
        return {total,valid:files.every(file=>file.size>0&&file.size<=fileLimit)&&total<=totalLimit};
    }
    if(typeof module!=='undefined'&&module.exports)module.exports={uploadSize};
    if(typeof document==='undefined')return;
    const form=document.querySelector('[data-community-editor]');
    if(form){const select=form.querySelector('[name=type]');const update=()=>form.querySelectorAll('[data-entry-types]').forEach(group=>{const active=group.dataset.entryTypes.split(' ').includes(select.value);group.hidden=!active;group.querySelectorAll('input,select,textarea').forEach(input=>input.disabled=!active);});select.addEventListener('change',update);update();}
    const upload=document.querySelector('[data-community-upload]');
    if(upload){
        const select=upload.querySelector('[name=type]'),summary=upload.querySelector('[data-upload-summary]');
        const inputs=Array.from(upload.querySelectorAll('input[type=file]'));
        function sizes(){
            const active=inputs.filter(input=>!input.disabled),files=active.flatMap(input=>Array.from(input.files||[]));
            const result=uploadSize(files,Number(upload.dataset.fileLimit),Number(upload.dataset.totalLimit));
            active.forEach(input=>input.setCustomValidity(result.valid?'':'Use fewer or smaller images to fit the upload limits.'));
            summary.textContent=files.length?`${files.length} image${files.length===1?'':'s'} · ${(result.total/1048576).toFixed(2)} MiB selected. ${result.valid?'Within the size limits.':'Too large or empty. Use fewer or smaller images.'}`:'Select your images to check the upload size.';
            inputs.forEach(input=>{const transcript=input.closest('fieldset').querySelector('textarea');transcript.required=!input.disabled&&(input.required||input.files.length>0);});
        }
        function update(){
            const comic=select.value==='comic';
            upload.querySelectorAll('[data-comic-page]').forEach(group=>{group.hidden=!comic;group.querySelectorAll('input,textarea').forEach(input=>{input.disabled=!comic;if(!comic&&input.setCustomValidity)input.setCustomValidity('');});});
            const category=upload.querySelector('[data-art-category]');category.hidden=comic;category.querySelector('select').disabled=comic;
            sizes();
        }
        select.addEventListener('change',update);inputs.forEach(input=>input.addEventListener('change',sizes));upload.addEventListener('submit',event=>{sizes();if(!upload.reportValidity())event.preventDefault();});update();
    }
})();
