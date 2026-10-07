(() => {
    'use strict';
    function campaignState(payload, now = Date.now()) {
        if (!payload || payload.ok !== true || !Array.isArray(payload.data?.campaigns) || !Array.isArray(payload.data?.systems)) throw new Error('Invalid campaign report');
        const fetched = Date.parse(payload.meta?.last_success_at || '');
        const fresh = !payload.meta?.stale && Number.isFinite(fetched) && now - fetched <= 900000 && fetched <= now + 60000;
        const campaigns = payload.data.campaigns.filter(c => ['ACTIVE', 'FORECAST'].includes(c.state) && !c.ended_at).sort((a, b) => b.id - a.id);
        return { fresh, fetched, campaign: campaigns[0] || null, systems: payload.data.systems, edges: payload.data.edges || [], source: payload.meta?.source_mode === 'everef_fallback' ? 'EVE Ref mirror' : 'EVE Online war report' };
    }
    function filterSystems(systems, query, filter) {
        return systems.filter(s => String(s.name || '').toLowerCase().includes(query.toLowerCase()) && (filter === 'all'
            || (filter === 'push' && Number.isFinite(s.corruption_stage) && Number.isFinite(s.suppression_stage) && s.corruption_stage < 5 && s.suppression_stage < 5)
            || (filter === 'spread' && Number.isFinite(s.corruption_stage) && s.corruption_stage < 3)
            || (filter === 'won' && s.corruption_stage === 5))).sort((a,b) => String(a.name).localeCompare(String(b.name)));
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = { campaignState, filterSystems };
    if (typeof document === 'undefined') return;
    const panel = document.querySelector('[data-campaign]');
    const home = document.querySelector('[data-public-campaign-status]');
    if (!panel && !home) return;
    let state = null;
    let request = null;
    const set = (selector, value) => { const el = document.querySelector(selector); if (el) el.textContent = value; };
    const number = n => Number.isFinite(n) ? String(n) : 'Not reported';
    function drawMap(systems) {
        const container = panel?.querySelector('[data-campaign-map]');
        if (!container) return;
        container.replaceChildren();
        const positions = systems.filter(s => Number.isFinite(s.position?.x) && Number.isFinite(s.position?.z));
        if (positions.length < 2 || positions.length > 100) return;
        const ns = 'http://www.w3.org/2000/svg';
        const make = (tag, attrs) => { const el = document.createElementNS(ns, tag); Object.entries(attrs).forEach(([k,v])=>el.setAttribute(k,String(v))); return el; };
        const svg = make('svg', {viewBox:'0 0 1000 600',class:'campaign-map',role:'img','aria-label':'Reported campaign stargate schematic. System names and stages are also in the table below.'});
        const xs = positions.map(s=>s.position.x), zs = positions.map(s=>s.position.z);
        const xmin=Math.min(...xs), xmax=Math.max(...xs), zmin=Math.min(...zs), zmax=Math.max(...zs);
        if (xmin === xmax && zmin === zmax) return;
        const points=new Map(positions.map(s=>[s.id,[65+(s.position.x-xmin)/(xmax-xmin||1)*820,65+(s.position.z-zmin)/(zmax-zmin||1)*450]]));
        for (const edge of state.edges) { const a=points.get(edge.a),b=points.get(edge.b); if(a&&b) svg.append(make('line',{x1:a[0],y1:a[1],x2:b[0],y2:b[1]})); }
        for (const system of positions) {
            const [x,y]=points.get(system.id); const group=make('g',{class:system.is_fob?'fob':''});
            const title=make('title',{});title.textContent=system.name;group.append(title,make('circle',{cx:x,cy:y,r:7}));
            const label=make('text',{x:x+12,y:y+5});label.textContent=system.name;group.append(label);svg.append(group);
        }
        container.append(svg);
    }
    function renderSystems() {
        if (!panel || !state) return;
        const query=panel.querySelector('[data-campaign-search]').value;
        const filter=panel.querySelector('[data-campaign-filter]').value;
        const systems=filterSystems(state.campaign ? state.systems : [],query,filter);
        const body=panel.querySelector('[data-campaign-systems]'); body.replaceChildren();
        for(const system of systems) {
            const row=document.createElement('tr');
            [system.name || 'Unnamed system',`${number(system.corruption_stage)}${Number.isFinite(system.corruption_percent)?' · '+number(system.corruption_percent)+'%':''}`,`${number(system.suppression_stage)}${Number.isFinite(system.suppression_percent)?' · '+number(system.suppression_percent)+'%':''}`,system.is_fob?'Forward operating base':system.corruption_stage===5?'Corruption stage 5':system.suppression_stage===5?'Suppression stage 5':'Check sites in EVE'].forEach((value,i)=>{
                const cell=document.createElement(i===0?'th':'td'); if(i===0) cell.scope='row';cell.textContent=value;row.append(cell);
            });body.append(row);
        }
        set('[data-campaign-count]',`${systems.length} matching systems${state.fresh?'':' · Previously retrieved report'}.`);
        drawMap(systems);
    }
    async function load() {
        if (request) request.abort();
        const active=new AbortController(); request=active;
        const refresh=panel?.querySelector('[data-campaign-refresh]'); if(refresh) refresh.disabled=true;
        set('[data-campaign-status]','Contacting the warzone relay…');
        const timeout=setTimeout(()=>active.abort(),15000);
        try {
            const response=await fetch('/api/war/guristas-overlay.php',{signal:active.signal,credentials:'omit',headers:{Accept:'application/json'}});
            if(!response.ok) throw new Error('Report unavailable');
            const payload=await response.json(); if(request!==active) return;
            state=campaignState(payload);
            const phase=state.campaign?.state;
            const headline=state.fresh ? phase==='ACTIVE'?'Active Guristas campaign':phase==='FORECAST'?'Guristas forecast':'No active or forecast campaign reported' : 'Previous campaign report · live status unconfirmed';
            set('[data-campaign-status]',headline);set('[data-public-campaign-status]',state.fresh?phase==='ACTIVE'?'Active':phase==='FORECAST'?'Forecast':'Between campaigns':'Stale report');
            set('[data-public-campaign-detail]',`${state.source} · ${Number.isFinite(state.fetched) ? new Date(state.fetched).toUTCString() : 'retrieval time unknown'}`);
            set('[data-campaign-freshness]',`Source: ${state.source}. Retrieved: ${Number.isFinite(state.fetched)?new Date(state.fetched).toUTCString():'unknown'}. ${state.fresh?'Public campaign data, without your location or personal records.':'Do not use this report as a current destination.'}`);
            let detail=state.campaign?`Campaign ${state.campaign.id}. Forward operating base: ${state.campaign.origin_name || 'not reported'}. `:'Prepare, run missions or resupply while waiting for the next official report. ';
            if(phase==='FORECAST') {
                const start=Date.parse(state.campaign.started_at || '');
                detail+=Number.isFinite(start)?`Estimated end of the 48-hour forecast: ${new Date(start+172800000).toUTCString()}. Confirm the live start in EVE.`:'The forecast start time was not reported; confirm the next round in EVE.';
            }
            set('[data-campaign-detail]',detail);renderSystems();
        } catch(error) {
            if(request!==active) return;
            set('[data-campaign-status]',state?'Relay unavailable. Previously retrieved report remains below; live status unconfirmed.':'Relay unavailable. Use the official war report or the Insurgencies window in EVE.');
            if(state) { state.fresh=false;renderSystems(); }
            set('[data-public-campaign-status]','Relay unavailable');set('[data-public-campaign-detail]','Check the War Room or the report in EVE');
        } finally { clearTimeout(timeout);if(request===active&&refresh) refresh.disabled=false; }
    }
    panel?.querySelector('[data-campaign-search]').addEventListener('input',renderSystems);
    panel?.querySelector('[data-campaign-filter]').addEventListener('change',renderSystems);
    panel?.querySelector('[data-campaign-refresh]').addEventListener('click',load);
    load();
})();
