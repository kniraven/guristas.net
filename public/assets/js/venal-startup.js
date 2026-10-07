(function(){
    'use strict';
    const script=document.querySelector('[data-venal-module]');if(!script)return;
    const status=document.querySelector('#status');
    const timer=setTimeout(()=>{if(status?.textContent.includes('Establishing'))status.textContent='The 3D map is still starting. Use the system directory if it does not load.';},15000);
    import(script.dataset.venalModule).catch(error=>{
        clearTimeout(timer);
        if(status)status.textContent='The 3D map could not start. Open the system directory below.';
        const box=document.querySelector('#errorBox'),detail=document.querySelector('#errorDetail');
        if(detail)detail.textContent='Your browser may not support WebGL, or the graphics module could not load. The system directory needs neither.';
        if(box)box.hidden=false;
        console.error('Venal graphics startup failed',error);
    });
})();
