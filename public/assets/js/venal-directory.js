(function(root){
    'use strict';
    function activityState(payload,now=Date.now()) {
        const date=payload?.meta?.activity_updated_at,stamp=Date.parse(date);
        if(!payload?.ok || !Array.isArray(payload?.data?.systems))throw new Error('Invalid activity report');
        return {date:date||'unknown',stale:!Number.isFinite(stamp)||stamp>now+60000||now-stamp>90*60*1000};
    }
    if(typeof module!=='undefined')module.exports={activityState};
    if(!root.document)return;
    const board=document.querySelector('[data-venal-directory]');if(!board)return;
    const button=board.querySelector('[data-venal-refresh]'),status=board.querySelector('[data-venal-list-status]');
    button.addEventListener('click',async()=>{
        button.disabled=true;status.textContent='Requesting the Venal activity relay…';
        board.querySelectorAll('[data-activity]').forEach(cell=>cell.textContent='Not loaded');
        const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
        try {
            const response=await fetch('/api/venal/map.php',{headers:{Accept:'application/json'},signal:controller.signal});if(!response.ok)throw new Error('Relay unavailable');
            const payload=await response.json(),state=activityState(payload),byId=new Map(payload.data.systems.map(s=>[String(s.id),s.activity]));
            board.querySelectorAll('[data-system-id]').forEach(row=>{const activity=byId.get(row.dataset.systemId);row.querySelectorAll('[data-activity]').forEach(cell=>{const value=activity?.[cell.dataset.activity];cell.textContent=Number.isSafeInteger(value)&&value>=0?String(value):'Unavailable';});});
            status.textContent=`${state.stale?'Old or undated report':'Hourly report'}: ${state.date}. Counts are historical, not live pilot positions. Verify travel in EVE.`;
        }catch(error){status.textContent='Activity relay unavailable. The static system directory remains usable. Try again later or use the in-game map.';}
        finally{clearTimeout(timer);button.disabled=false;}
    });
})(typeof window==='undefined'?{}:window);
