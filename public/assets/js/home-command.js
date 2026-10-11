(() => {
    'use strict';
    if (!document.body.classList.contains('home-refresh')) return;
    const $ = selector => document.querySelector(selector);
    const text = (selector, value) => { const node = $(selector); if (node) node.textContent = value; };
    const directives = {
        raid: ['01', 'RAID // CORRUPT THE WARZONE', 'Wait for verified insurgency orders before committing your ship.', '/war/guristas/', 'Open War Room →', '/join/', 'Pirate warfare briefing'],
        trade: ['02', 'TRADE // PROFIT FROM CHAOS', 'The Fulcrum needs supplies, capsuleer. Compare station orders, value your Commando Guri LP, then move cargo only when the margin justifies the risk.', '/industry/?view=trade', 'Inspect trade routes →', '/industry/?view=lp', 'Review LP opportunities'],
        build: ['03', 'BUILD // ARM THE PIRATES', 'The fleet runs on hulls and ammunition. Select a Guristas blueprint, source the components, and calculate costs before you start the production job.', '/industry/', 'Open fabrication console →', '/industry/?view=fulcrum', 'Inspect Fulcrum supplies'],
        lore: ['04', 'INVESTIGATE // STEAL THEIR SECRETS', 'Every empire has a version of history. Read the recovered dossiers on Fatal, the Rabbit, Venal, Crielere and the Deathless. Know who you fly for.', '/lore/', 'Open classified dossiers →', '/lore/?dossier=fatal', 'Begin with Fatal'],
        signals: ['05', 'INTERCEPT // HIJACK THE AIRWAVES', 'Our receivers are tuned for pirate transmissions. Scan Black Rabbit Radio, monitor live capsuleer broadcasts and intercept the opposition.', '#signals', 'Access radio receiver →', '/community/#broadcast', 'Inspect captured media'],
        join: ['06', 'JOIN // REPORT TO THE CREW', 'There is work to be done. Learn how to enlist with Guristas, find allies, or put in for a berth with Cozen Corp at The Fulcrum.', '/join/', 'Get enlistment orders →', '/join/#cozen', 'Contact Cozen Corp']
    };
    function brief(key) {
        const d = directives[key] || directives.raid;
        text('[data-directive-code]', d[0]);text('#selectedOperationLabel', d[1]);text('[data-directive-copy]', d[2]);
        const action=$('[data-directive-action]'), help=$('[data-directive-help]');
        action.href=d[3];action.textContent=d[4];help.href=d[5];help.textContent=d[6];
        const raid=key==='raid', trade=key==='trade';
        const console=$('[data-directive-raid]'), mission=$('[data-directive-mission]');
        if(console)console.hidden=!raid;
        const tradePane=$('[data-directive-trade]');if(tradePane)tradePane.hidden=!trade;
        if(trade)loadTrade();
        if(mission)mission.hidden=raid||trade;
        const categories={trade:['MARKET INTELLIGENCE','◇'],build:['INDUSTRIAL OPERATIONS','⬡'],lore:['ARCHIVE ACCESS','✧'],signals:['SIGNAL INTERCEPTION','◈'],join:['PERSONNEL RECRUITMENT','✦']};
        const info=categories[key] || ['WARFARE COMMAND','◆'];
        text('[data-directive-category]',info[0]);text('[data-directive-symbol]',info[1]);
        const pane=$('#directive-briefing');if(pane)pane.dataset.mode=raid?'raid':trade?'trade':'mission';
    }
    // The public trade endpoint shares a server-side market snapshot between visitors.
    let tradeLoading=false, tradeLastCheck=0, tradeLastSuccess=0;
    async function loadTrade() {
        if(tradeLoading || Date.now()-tradeLastSuccess<300000)return;
        tradeLoading=true;
        const host=$('[data-trade-orders]');
        const control=new AbortController(), timer=setTimeout(()=>control.abort(),12000);
        try {
            const payload=await json('/api/industry/trade-orders.php',control.signal);
            const report=payload.data;
            if(!Array.isArray(report?.orders)||report.orders.length!==4)throw new Error('Invalid market intelligence');
            host.replaceChildren();
            report.orders.forEach((order,i)=>{
                const card=document.createElement('article');card.className='trade-order-card';card.dataset.tradeStatus=order.status||'standby';card.dataset.tradeSlot=String(i+1);
                const add=(tag,className,value)=>{const el=document.createElement(tag);el.className=className;el.textContent=value||'';card.appendChild(el);};
                const heading=document.createElement('div');heading.className='trade-order-heading';
                const kind=document.createElement('span');kind.className='trade-order-kind';kind.textContent=String(i+1).padStart(2,'0')+' // '+(i===3?'SPECULATIVE LISTING':(order.category||'ORDER'));
                const assumptions=document.createElement('button');assumptions.type='button';assumptions.className='trade-order-assumptions';
                assumptions.textContent='INTEL ▸';
                assumptions.setAttribute('aria-label','View order basis and assumptions for '+(order.item||'trade order'));
                // The dossier flips over this card, leaving the command console unchanged.
                const dossier=document.createElement('section');dossier.className='trade-order-dossier';
                dossier.hidden=true;dossier.setAttribute('aria-label','Trade intelligence for '+(order.item||'order'));
                const dossierHead=document.createElement('div');dossierHead.className='trade-order-dossier-head';
                const dossierName=document.createElement('strong');dossierName.textContent='INTEL // '+(order.item||'ORDER');
                const dismiss=document.createElement('button');dismiss.type='button';dismiss.textContent='RETURN ↩';
                const dossierText=document.createElement('div');dossierText.className='trade-order-dossier-text';
                const line=(label,value)=>{const p=document.createElement('p');const lead=document.createElement('strong');lead.textContent=label+' // ';p.append(lead,document.createTextNode(value));dossierText.appendChild(p);};
                const listing=Boolean(order.listing);
                if(listing){card.title='CONDITIONAL: Regional sales history and local bids support this price. Station sales and fill time cannot be verified.';}else if(order.status!=='standby'){card.title='FIRM BID SNAPSHOT: Buyer order observed; verify it remains available before hauling.';}
                line('CONFIDENCE',listing?'CONDITIONAL. Regional sales and local buy orders support this possible listing. A sale at this station is not confirmed.':(order.status==='standby'?'No qualifying order has been verified.':'An actual destination buy order was observed. It could change before you arrive.'));
                line('THE PLAY',listing?'We can post an asking price, but no buyer has promised to pay it. This is a gamble, not money in the hold.':'A buyer has posted an order for these goods. Buy at the source, haul them to the destination, then fill that order before someone else does.');
                const isk=n=>Number(n).toLocaleString('en-US',{maximumFractionDigits:0})+' ISK';
                if(Number.isFinite(Number(order.cost_isk)))line('SUPPLY COST',isk(order.cost_isk));
                if(Number.isFinite(Number(order.revenue_isk)))line('TARGET SALE',isk(order.revenue_isk));
                if(Number.isFinite(Number(order.sales_tax_isk)))line('SALES TAX',isk(order.sales_tax_isk)+' (base Accounting 0 estimate)');
                if(Number(order.broker_fee_isk)>0)line('LISTING FEE',isk(order.broker_fee_isk)+' estimated');
                if(Number(order.lp_required)>0)line('LP SPENT',Number(order.lp_required).toLocaleString('en-US')+' LP, valued at 1,000 ISK per LP');
                if(listing && order.market_evidence){
                    const e=order.market_evidence;
                    line('REGIONAL SALES',Number(e.regional_14d_units||0).toLocaleString('en-US')+' units in 14 days across '+Number(e.regional_14d_active_days||0)+' trading days.');
                    line('LOCAL MARKET',Number(e.local_bid_units||0).toLocaleString('en-US')+' units in posted bids; '+Number(e.competing_ask_units||0).toLocaleString('en-US')+' units listed near our price.');
                    line('OUR LIMIT','CCP confirms trades in this region, not at this station. Neither a bid nor a vanished listing proves a future sale.');
                }
                if(order.status==='conditional'&&!listing)line('VERIFY BEFORE MOVING','The return or required work has extra uncertainty. Read the quote and check quantities in EVE.');
                if(Number(order.roi_ratio)>10)line('EXTREME RETURN','ROI is unusually high. One thin or temporary order can create misleading profits. Treat this as unconfirmed.');
                line('THE CATCH','Pre-haul profit excludes travel costs, ambushes, cargo losses and market changes. The selection bonus for Fulcrum/Venal NEVER increases the displayed ISK profit.');
                dossierHead.append(dossierName,dismiss);dossier.append(dossierHead,dossierText);
                const setExpanded=open=>{dossier.hidden=!open;assumptions.setAttribute('aria-expanded',String(open));assumptions.textContent=open?'INTEL ▴':'INTEL ▸';};
                assumptions.setAttribute('aria-expanded','false');
                assumptions.addEventListener('click',()=>setExpanded(dossier.hidden));
                dismiss.addEventListener('click',()=>{setExpanded(false);assumptions.focus();});
                card.addEventListener('keydown',event=>{if(event.key==='Escape'&&!dossier.hidden){event.stopPropagation();setExpanded(false);assumptions.focus();}});
                heading.append(kind,assumptions);card.appendChild(heading);
                if(order.status==='standby'){add('strong','trade-order-item','NO VERIFIED SELL LISTING');add('p','trade-order-standby-note',order.route||'Evidence is not strong enough yet.');card.appendChild(dossier);host.appendChild(card);return;}
                add('strong','trade-order-item',order.item||'ITEM NAME UNAVAILABLE');
                const route=document.createElement('div');route.className='trade-order-steps';
                const step=(verb,station,system,region,note='')=>{
                    const line=document.createElement('div');line.className='trade-order-step';
                    const key=document.createElement('span');key.className='trade-order-verb';key.textContent=verb;
                    const location=document.createElement('div');location.className='trade-order-location';
                    const place=document.createElement('span');place.className='trade-order-place';place.textContent=station||'Station not verified';
                    const geography=document.createElement('span');geography.className='trade-order-system';
                    geography.textContent=note||(system&&region?`${system} · ${region}`:'System / region not verified');
                    location.append(place,geography);line.append(key,location);route.appendChild(line);
                };
                const method=String(order.method||'').toUpperCase();
                if(method.includes('MANUFACTURE'))step('BUILD','Guristas LP blueprint + manufacturing',null,null,'Production facility / region not specified');
                else if(method.includes('REDEEM'))step('REDEEM','Commando Guri LP store',null,null,'Redemption station / region not specified');
                else step('BUY',order.source_station,order.source_system,order.source_region);
                step(order.listing?'CREATE SELL ORDER':'FILL BUY ORDER',order.destination_station,order.destination_system,order.destination_region);
                card.appendChild(route);
                // Unit prices derive from the same gross purchase and target-sale totals
                // used by the server; never modify the calculated profit here.
                const units=Number(order.unit_count||String(order.quantity||'').match(/QTY\s*\/\/\s*([\d,]+)/i)?.[1]?.replace(/,/g,''));
                if(Number.isFinite(units)&&units>0){
                    const formatISK=n=>{
                        const v=Number(n);
                        if(!Number.isFinite(v))return 'N/A';
                        if(Math.abs(v)>=1e9)return (v/1e9).toFixed(2)+'B';
                        if(Math.abs(v)>=1e6)return (v/1e6).toFixed(2)+'M';
                        if(Math.abs(v)>=1e4)return (v/1e3).toFixed(1)+'K';
                        return v.toLocaleString('en-US',{maximumFractionDigits:2});
                    };
                    const priceRow=document.createElement('div');priceRow.className='trade-order-prices';
                    const methodLabel=method.includes('REDEEM')||method.includes('MANUFACTURE')?'INPUTS + LP':'BUY';
                    const addPrice=(label,amount)=>{
                        const part=document.createElement('span');
                        const key=document.createElement('small');key.textContent=label;
                        const value=document.createElement('strong');value.textContent=formatISK(amount)+' ISK';
                        value.title=Number(amount).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+' ISK per unit';
                        part.append(key,value);priceRow.appendChild(part);
                    };
                    addPrice(methodLabel,Number(order.cost_isk)/units);
                    addPrice(order.listing?'ASK':'SELL',Number(order.revenue_isk)/units);
                    card.appendChild(priceRow);
                }
                const metrics=document.createElement('div');metrics.className='trade-order-metric-row';
                const profitLine=document.createElement('span');profitLine.className='trade-profit-line';
                profitLine.textContent=String(order.margin||'').replace(' // ',' / ');
                const statsLine=document.createElement('span');statsLine.className='trade-stats-line';
                const roi=Number(order.roi_ratio)*100;
                statsLine.textContent='QTY '+units.toLocaleString('en-US')+' · ROI '+(Number.isFinite(roi)?roi.toLocaleString('en-US',{minimumFractionDigits:1,maximumFractionDigits:1})+'%':'N/A');
                metrics.append(profitLine,statsLine);
                card.appendChild(metrics);
                card.appendChild(dossier);
                host.appendChild(card);
            });
            const stamp=Date.parse(report.as_of||'');
            const at=Number.isFinite(stamp)?new Date(stamp).toISOString().slice(5,16).replace('T',' ')+' EVE':'UNVERIFIED';
            text('[data-trade-freshness]',`CCP ESI // OLDEST ORDER SNAPSHOT ${at}${report.stale?' · STALE':''} · ${report.coverage?.candidate_items||0} PIRATE DEMAND ITEMS · ${report.coverage?.fresh_regions||0}/${report.coverage?.region_count||0} REGIONS FRESH`);
            if(!report.stale)tradeLastSuccess=Date.now();
        }catch(error){
            host.replaceChildren();
            ['FULCRUM DEMAND','VENAL DEMAND','GURISTAS LP ADVANTAGE','SPECULATIVE // EVIDENCE REQUIRED'].forEach((category,i)=>{
                const card=document.createElement('article');card.className='trade-order-card';card.dataset.tradeStatus='standby';
                const heading=document.createElement('span');heading.className='trade-order-kind';heading.textContent=`0${i+1} // ${category}`;
                const title=document.createElement('strong');title.textContent='HOLD CARGO';
                const note=document.createElement('p');note.textContent='Market intelligence unavailable. Verify live orders in EVE.';
                card.append(heading,title,note);host.appendChild(card);
            });
            text('[data-trade-freshness]','CCP ESI // INTELLIGENCE UNAVAILABLE');
        }finally{clearTimeout(timer);tradeLoading=false;}
    }
    document.addEventListener('guristas:directive-selected', event => brief(event.detail.key));
    brief(document.documentElement.dataset.operation);
    // Shared published intelligence: polling does not trigger CCP ESI scans.
    setInterval(()=>{if(document.visibilityState==='visible' && $('#directive-briefing')?.dataset.mode==='trade')loadTrade();},60000);

    async function json(url, signal) {
        const response=await fetch(url,{signal,credentials:'omit',headers:{Accept:'application/json'}});
        if(!response.ok) throw new Error('Relay unavailable');
        const payload=await response.json();if(payload.ok!==true) throw new Error('Invalid report');return payload;
    }
    const timestamp = value => {const t=Date.parse(value || '');return Number.isFinite(t)?t:null;};
    const fresh = (meta,key) => {const t=timestamp(meta?.[key]);return Boolean(meta) && !meta.stale && t!==null && Date.now()-t<=900000 && t<=Date.now()+60000;};
    const date = value => {const t=timestamp(value);return t===null?'Unknown retrieval time':new Date(t).toUTCString();};
    const stage = n => Number.isInteger(n)&&n>=0&&n<=5?String(n):'Unknown';
    const percent = n => Number.isFinite(n)&&n>=0&&n<=100?` · ${n.toFixed(1)}%`:'';
    let campaign=null, campaignMeta=null, campaignFailed=false;
    // The occupier describes current control; owner is the original empire faction.
    const factionName = id => ({500001:'Caldari',500004:'Gallente',500010:'Guristas'})[Number(id)] || (Number.isInteger(id) && id >= 0 ? 'Neutral' : null);
    function fobFaction(selected, systems) {
        if (!selected || !Number.isInteger(selected.origin_id) || selected.origin_id <= 0) return 'FACTION // UNCONFIRMED';
        const origin = systems.find(s => s.id === selected.origin_id);
        if (!origin) return 'FACTION // UNCONFIRMED';
        const controlling = factionName(origin.occupier_faction_id);
        const owner = factionName(origin.owner_faction_id);
        if (controlling) return `FACTION // ${controlling.toUpperCase()}${owner && owner !== controlling ? ` · ${owner.toUpperCase()} ZONE` : ''}`;
        return owner ? `ZONE // ${owner.toUpperCase()} · CONTROL UNKNOWN` : 'FACTION // UNCONFIRMED';
    }
    const countdown = milliseconds => {
        const seconds = Math.max(0, Math.ceil(milliseconds / 1000));
        const h = Math.floor(seconds / 3600);
        return `${String(h).padStart(2,'0')}:${String(Math.floor(seconds % 3600 / 60)).padStart(2,'0')}:${String(seconds % 60).padStart(2,'0')}`;
    };
    function forecastLaunch(data) {
        const forecasts = (Array.isArray(data?.campaigns) ? data.campaigns : [])
            .filter(c => ['FORECAST','FORECASTING'].includes(c.state) && !c.ended_at)
            .sort((a,b) => b.id-a.id);
        const selected = forecasts[0];
        if (!selected) return null;
        const explicit = timestamp(selected.launch_at || selected.launchDateTime || selected.combatStartDateTime);
        if (explicit !== null) return explicit;
        const started = timestamp(selected.started_at);
        // An estimate, not a guaranteed transition. Never derive from a future forecast start.
        return started !== null && started <= Date.now() ? started + 48 * 3600000 : null;
    }
    function raidRecommendation(data, meta, failed) {
        if (!failed && fresh(meta,'last_success_at') && data?.server_recommendation?.title && data?.server_recommendation?.detail) return data.server_recommendation;
        if (failed || !fresh(meta, 'last_success_at')) return {title:'NO VERIFIED ORDERS',detail:'Campaign report is stale or unavailable. Check EVE before deploying.'};
        const campaigns = Array.isArray(data?.campaigns) ? data.campaigns : [];
        const running = campaigns.filter(c=>c.state==='ACTIVE' && !c.ended_at);
        if (running.length!==1 || campaigns.length!==1) return {title:'HOLD POSITION',detail:'No confirmed active insurgency. Stage your ship and await the next target assignment.'};
        const systems = Array.isArray(data?.systems) ? data.systems : [];
        // Only recommend verified active campaign systems. Do not suggest Stage 5 targets.
        const candidates=systems.filter(s=>Number.isInteger(s?.id) && Number.isInteger(s.corruption_stage) && s.corruption_stage>=0 && s.corruption_stage<5);
        if(!candidates.length) return {title:'HOLD POSITION',detail:'All reported targets are at Stage 5, or no eligible target was verified.'};
        // Calculate minimum in-campaign jumps to a system with a verified high-sec gate.
        const distances=new Map(), queue=[];
        for(const s of systems){if(s.highsec_adjacent===true){distances.set(s.id,0);queue.push(s.id);}}
        const adj=new Map(systems.map(s=>[s.id,[]]));
        for(const edge of (Array.isArray(data.edges)?data.edges:[])){
            if(adj.has(edge.a)&&adj.has(edge.b)){adj.get(edge.a).push(edge.b);adj.get(edge.b).push(edge.a);}
        }
        for(let i=0;i<queue.length;i++)for(const next of adj.get(queue[i])||[])if(!distances.has(next)){
            distances.set(next,distances.get(queue[i])+1);queue.push(next);
        }
        candidates.sort((a,b)=> b.corruption_stage-a.corruption_stage
            || (Number.isFinite(b.corruption_percent)?b.corruption_percent:-1)-(Number.isFinite(a.corruption_percent)?a.corruption_percent:-1)
            || (distances.get(a.id)??Infinity)-(distances.get(b.id)??Infinity)
            || String(a.name||'').localeCompare(String(b.name||'')));
        const winner=candidates[0];
        const distance=distances.get(winner.id);
        const highsec=distance===0?'Direct high-sec gate':Number.isInteger(distance)?`${distance} insurgency jump${distance===1?'':'s'} to a high-sec gate`:'High-sec proximity unverified';
        const pct=Number.isFinite(winner.corruption_percent)?` · ${winner.corruption_percent.toFixed(1)}%` : '';
        return {title:`DEPLOY TO ${winner.name||'SYSTEM '+winner.id} // RAISE CORRUPTION`,detail:`Current corruption: Stage ${winner.corruption_stage}/5${pct}. ${highsec}. Target chosen for the highest unfinished corruption progress.`};
    }
    function renderRaidRecommendation(){
        const r=raidRecommendation(campaign,campaignMeta,campaignFailed);
        text('[data-gc-recommendation]',r.title);
        text('[data-gc-recommendation-reason]',r.detail);
        const order=$('.gc-recommendation');if(order)order.dataset.order= r.title.startsWith('DEPLOY TO')?'deploy':'standby';
    }
    const intelligenceSource = meta => meta?.source_mode==='everef_fallback'?'EVE Ref (mirror)':meta?.source_mode==='eve_online_frontlines'?'CCP Frontlines':'Source unconfirmed';
    const intelligenceStamp = (meta, current) => {
        const t=timestamp(meta?.last_success_at);
        if(t===null) return 'SOURCE // UNAVAILABLE';
        const d=new Date(t);
        const time=`${String(d.getUTCDate()).padStart(2,'0')} ${d.toLocaleString('en-US',{month:'short',timeZone:'UTC'}).toUpperCase()} ${d.getUTCFullYear()} ${String(d.getUTCHours()).padStart(2,'0')}:${String(d.getUTCMinutes()).padStart(2,'0')} EVE`;
        return `${current?'DATA REFRESHED':'LAST DATA REFRESH'} // ${time} · SOURCE: ${intelligenceSource(meta)}`;
    };
    // GC-01: derive campaign instruments from the existing shared request.
    function consoleReport(data, meta, failed, now = Date.now()) {
        const fetched = Date.parse(meta?.last_success_at || '');
        const current = !failed && !meta?.stale && Number.isFinite(fetched) && now-fetched <= 900000 && fetched <= now+60000;
        const campaigns = Array.isArray(data?.campaigns) ? data.campaigns : [];
        const open = campaigns.filter(c => ['ACTIVE','FORECAST','FORECASTING'].includes(c.state) && !c.ended_at);
        const selected = [...open].sort((a,b) => b.id-a.id)[0];
        // Existing API merges systems across campaigns. Never count an ambiguous merged list.
        const unambiguous = campaigns.length === 1 && open.length === 1;
        const systems = Array.isArray(data?.systems) ? [...new Map(data.systems.filter(s=>s && Number.isInteger(s.id)).map(s=>[s.id,s])).values()] : [];
        const active = selected?.state === 'ACTIVE';
        const forecast = selected && !active;
        const metrics = {};
        for (const key of ['corruption','suppression']) {
            const target = Number.isInteger(selected?.[`${key}_target`]) && selected[`${key}_target`] > 0 ? selected[`${key}_target`] : null;
            const complete = unambiguous && active && systems.length > 0 && systems.every(s=>Number.isInteger(s[`${key}_stage`]) && s[`${key}_stage`] >= 0 && s[`${key}_stage`] <= 5);
            metrics[key] = {target, count:complete ? systems.filter(s=>s[`${key}_stage`] === 5).length : null, ambition:target >= 5 && target <= 10 ? target-5 : null};
        }
        const s = metrics.suppression;
        let defense = 'Defense status unknown';
        if(current && unambiguous && forecast) defense = 'PROTECTED · forecast phase';
        if(current && unambiguous && active && s.count !== null && s.target !== null) defense = s.count >= s.target ? 'ATTACKS UNLOCKED · timer unknown' : 'PROTECTED · suppression below target';
        const occupied = systems.filter(s=>s.occupier_faction_id === 500010).map(s=>s.name || `System ${s.id}`);
        let occupation = 'Territorial control unconfirmed';
        if(occupied.length) occupation = `${current?'GURISTAS OCCUPANCY':'LAST REPORTED OCCUPANCY'}: ${occupied.join(', ')}`;
        else if(current && systems.length && systems.every(s=>Number.isInteger(s.occupier_faction_id))) occupation = 'No Guristas occupancy reported in these systems';
        const start = Date.parse(selected?.started_at || '');
        const utc = Number.isFinite(start) ? new Date(start).toISOString().slice(0,16).replace('T',' ')+' EVE' : null;
        let timing = 'Next deployment not announced in this report';
        // A forecast's startDateTime is not documented as the launch timestamp.
        // Only countdown when the feed explicitly supplies a separate launch field.
        const explicitLaunch = timestamp(selected?.launch_at || selected?.launchDateTime || selected?.combatStartDateTime);
        // A forecast that began in the past can be estimated using CCP's 48h forecast window.
        const estimatedLaunch = forecast && Number.isFinite(start) && start <= now ? start + 48*3600000 : null;
        const launch = explicitLaunch !== null ? explicitLaunch : estimatedLaunch;
        if (selected) timing = forecast && current && launch !== null && launch > now
            ? `${explicitLaunch === null ? 'EST. ' : ''}Next Insurgency Starts in ${countdown(launch - now)}`
            : forecast && current && launch !== null && launch <= now ? 'Launch window reached · verifying campaign status'
            : forecast ? 'Next insurgency: launch time unconfirmed'
            : utc ? `Reported start: ${utc}` : 'Start time unreported';
        return {state:!data?(failed?'unavailable':'loading'):!current?(failed?'unavailable':'stale'):active?'active':forecast?'forecast':'idle',phase:!data?(failed?'INTEL UNAVAILABLE':'ACQUIRING INTEL'):!current?'CURRENT STATUS UNKNOWN':active?'INSURGENCY ACTIVE':forecast?'DEPLOYMENT PENDING':'NO ACTIVE CAMPAIGN REPORTED',base:selected?.origin_name || (current?'No FOB reported':'Location unconfirmed'),faction:fobFaction(selected,systems),timing,defense,occupation,metrics,current,forecastStamp:forecast&&utc?`Feed forecast timestamp: ${utc}. Exact launch time is not confirmed.`:''};
    }
    function renderConsole() {
        const root = $('[data-guri-console]'); if(!root) return;
        const r = consoleReport(campaign,campaignMeta,campaignFailed);
        root.dataset.state = r.state;
        text('[data-gc-phase]',r.phase);text('[data-gc-base]',r.base);text('[data-gc-faction]',r.faction);text('[data-gc-time]',r.timing);
        $('[data-gc-time]').title = r.forecastStamp;
        text('[data-gc-defense]',r.defense);text('[data-gc-occupation]',r.occupation);
        root.querySelector('.gc-base').dataset.threat=String(r.current && r.defense.startsWith('ATTACKS'));
        for(const key of ['corruption','suppression']) {
            const m=r.metrics[key]; text(`[data-gc-${key}]`,`${m.count ?? '?'}/${m.target ?? '?'}`);
            const cells=$(`[data-gc-cells="${key}"]`);cells.replaceChildren();
            for(let i=0;i<Math.min(m.target || 5,20);i++){const cell=document.createElement('i');cell.dataset.lit=String(m.count!==null && i<m.count);cells.append(cell);}
            text(`[data-gc-ambition="${key}"]`,`${key==='corruption'?'Our':'Empire'} ambition: ${m.ambition===null?'unknown':m.ambition?'+'+m.ambition:'0'}`);
        }
        text('[data-gc-freshness]',campaignMeta?intelligenceStamp(campaignMeta,r.current):'SOURCE // PENDING');
        renderRaidRecommendation();
    }
    const systemSelect=$('#home-system');
    function systemBrief() {
        const s=campaign?.systems?.find(item=>String(item.id)===systemSelect.value);
        if(!s) return;
        const current=!campaignFailed&&fresh(campaignMeta,'last_success_at');
        const lines=[s.is_fob?'Forward operating base.':'Reported campaign system.', `Corruption ${stage(s.corruption_stage)}/5${percent(s.corruption_percent)}. Suppression ${stage(s.suppression_stage)}/5${percent(s.suppression_percent)}.`];
        lines.push(current?'Check site restrictions, local activity and your exit in EVE before committing.':'Previous report only. Refresh before using this system as a destination.');
        text('[data-home-system-brief]',lines.join(' '));
    }
    function expireCampaign() {
        renderConsole();
        if(campaignMeta&&!fresh(campaignMeta,'last_success_at')) {
            text('[data-home-campaign-title]','Previous report · current status unknown');systemBrief();
        }
    }
    let campaignLoading = false;
    let lastCampaignCheck = 0;
    let nextCampaignCheck = 0;
    let transitionKey = null;
    let transitionUntil = 0;
    const NORMAL_POLL = 300000, TRANSITION_POLL = 30000;
    function pollDelay(now) {
        const launch = forecastLaunch(campaign);
        if (launch === null) return NORMAL_POLL;
        if (launch > now && launch - now <= 120000) return TRANSITION_POLL;
        if (launch <= now && now < transitionUntil) return TRANSITION_POLL;
        return NORMAL_POLL;
    }
    async function loadCampaign() {
        if (campaignLoading) return;
        campaignLoading = true;
        lastCampaignCheck = Date.now();
        text('[data-home-campaign-summary]','Contacting the warzone relay…');
        const control=new AbortController(), timer=setTimeout(()=>control.abort(),15000);
        try {
            const p=await json('/api/war/raid-orders.php',control.signal);
            if(!Array.isArray(p.data?.campaigns)||!Array.isArray(p.data?.systems))throw new Error('Invalid campaign');
            campaign=p.data;campaignMeta=p.meta;campaignFailed=false;
            const active=campaign.campaigns.filter(c=>['ACTIVE','FORECAST'].includes(c.state)&&!c.ended_at).sort((a,b)=>b.id-a.id)[0];
            const current=fresh(p.meta,'last_success_at');
            text('[data-home-campaign-title]',!current?'Previous report · current status unknown':active?.state==='ACTIVE'?'Guristas insurgency active':active?'Next insurgency forecast':'Between reported campaigns');
            text('[data-home-campaign-summary]',active?`${active.origin_name||'Base location unreported'} · ${campaign.systems.length} reported systems. ${current?'Select a system for its briefing.':'Historical conditions, not a current destination.'}`:'No active or forecast campaign in this report. Explore missions, train or resupply.');
            const previous=systemSelect.value;systemSelect.replaceChildren();
            const systems=active?campaign.systems.filter(s=>s&&s.id&&typeof s.name==='string').sort((a,b)=>Number(b.is_fob)-Number(a.is_fob)||a.name.localeCompare(b.name)):[];
            for(const s of systems){const option=document.createElement('option');option.value=String(s.id);option.textContent=s.name+(s.is_fob?' // FOB':'');systemSelect.append(option);}
            systemSelect.disabled=!systems.length;
            if(systems.some(s=>String(s.id)===previous))systemSelect.value=previous;
            if(systems.length)systemBrief();else{const option=document.createElement('option');option.textContent='No systems available';systemSelect.append(option);text('[data-home-system-brief]','Use the War Room for campaign guidance and source links.');}
            text('[data-home-campaign-source]',`${p.meta?.source_mode==='everef_fallback'?'EVE Ref mirror':'EVE Online war report'} · ${date(p.meta?.last_success_at)}`);
        } catch(error) {
            campaignFailed=true;text('[data-home-campaign-title]','Campaign relay unavailable');
            text('[data-home-campaign-summary]','Retry the relay or open the War Room. Current campaign status is unknown.');
            if(campaign)systemBrief();else{systemSelect.disabled=true;systemSelect.options[0].textContent='Report unavailable';text('[data-home-system-brief]','Check the Insurgencies window in EVE for current conditions.');text('[data-home-campaign-source]','No verified report received.');}
        } finally {
            clearTimeout(timer);
            campaignLoading = false;
            nextCampaignCheck = Date.now() + pollDelay(Date.now());
            renderConsole();
        }
    }
    // One browser-side clock drives countdown and polling. No request is made per second.
    // The server's upstream cache remains authoritative, even during a phase transition.
    setInterval(() => {
        if (document.hidden) return;
        const now = Date.now();
        const launch = forecastLaunch(campaign);
        if (launch !== null && now >= launch) {
            const key = String(launch);
            if (transitionKey !== key) {
                transitionKey = key;
                transitionUntil = now + 600000; // Check every 30s for up to 10 minutes.
                nextCampaignCheck = now; // Verify immediately at zero.
            }
        }
        if (launch !== null && launch > now && launch-now <= 120000 && nextCampaignCheck > now + TRANSITION_POLL) {
            nextCampaignCheck = now + TRANSITION_POLL;
        }
        if (campaign) renderConsole();
        if (!campaignLoading && now >= nextCampaignCheck) loadCampaign();
    }, 1000);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) return;
        renderConsole();
        if (!campaignLoading && Date.now() - lastCampaignCheck >= NORMAL_POLL) loadCampaign();
    });
    systemSelect.addEventListener('change',systemBrief);
    loadCampaign();

    let marketControl=null, marketMeta=null;
    const marketType=$('#home-market-type'), marketButton=$('[data-home-market-refresh]');
    function clearQuote() {
        marketControl?.abort();marketControl=null;marketMeta=null;marketButton.disabled=false;
        $('[data-home-market-link]').href=`/industry/?view=fulcrum&type=${marketType.value}`;
        text('[data-home-market-result]',`Ready to check ${marketType.selectedOptions[0].textContent} orders. Press Check stock.`);
        text('[data-home-market-source]','Low stock does not establish demand. Prices exclude fees and travel.');
    }
    async function loadMarket() {
        if(marketButton.disabled)return;
        const selected=marketType.value, name=marketType.selectedOptions[0].textContent;
        const control=new AbortController();marketControl=control;marketButton.disabled=true;
        text('[data-home-market-result]',`Contacting The Fulcrum market for ${name}…`);
        const timer=setTimeout(()=>control.abort(),20000);
        try {
            const {data:q}=await json(`/api/industry/quote.php?hub=fulcrum&type=${selected}&quantity=1`,control.signal);
            if(marketControl!==control)return;
            if(q.type_id!==Number(selected)||!q.buy||!q.sell||!q.meta||!Number.isFinite(q.buy.listed_volume)||!Number.isFinite(q.sell.listed_volume))throw new Error('Invalid quote');
            marketMeta=q.meta;
            const price=(order)=>order.complete&&Number.isFinite(order.average)?Number(order.average).toLocaleString(undefined,{maximumFractionDigits:0})+' ISK':'No fillable order';
            text('[data-home-market-result]',`${name}: ${q.buy.listed_volume.toLocaleString()} listed for sale. Buy one: ${price(q.buy)}. Immediate sale: ${price(q.sell)}. Listed buy volume: ${q.sell.listed_volume.toLocaleString()}.`);
            text('[data-home-market-source]',`${fresh(q.meta,'fetched_at')?'ESI station orders':'STALE / UNVERIFIED SNAPSHOT'} · ${date(q.meta.fetched_at)}. Excludes fees and travel. Availability can change.`);
        } catch(error) {
            if(marketControl!==control)return;marketMeta=null;
            text('[data-home-market-result]','Market relay unavailable. Retry, open the market console or check orders in EVE.');
            text('[data-home-market-source]','No verified stock or price returned. Missing data is not zero stock.');
        } finally {clearTimeout(timer);if(marketControl===control)marketButton.disabled=false;}
    }
    marketType.addEventListener('change',clearQuote);marketButton.addEventListener('click',loadMarket);
    setInterval(()=>{expireCampaign();if(marketMeta&&!fresh(marketMeta,'fetched_at'))text('[data-home-market-source]',`STALE SNAPSHOT · ${date(marketMeta.fetched_at)}. Refresh before planning a trade.`);},30000);

    const blueprints={'17930':'17931','78367':'78393','17715':'17716','78366':'78368','17918':'17919'};
    const build=$('#home-build-hull');
    function selectBuild(){const name=build.selectedOptions[0].textContent;const a=$('[data-home-build-link]');a.href=`/industry/?bp=${blueprints[build.value]}`;a.textContent=`Calculate ${name} production →`;text('[data-home-build-summary]',`${name} blueprint loaded. Enter runs, efficiencies and costs in the production console.`);}
    build.addEventListener('change',selectBuild);selectBuild();

    const channels=[
        {title:'Fatal Mistake',description:'Recovered Guristas alternate rock demo.',audio:'/assets/audio/fatal-mistake-demo.mp3'},
        {title:'Black Rabbits',description:'A second recovered transmission from Black Rabbit Radio.',audio:'/assets/audio/black-rabbits-demo.mp3'},
        {title:'Kniraven',description:'Pirate broadcasts from Kniraven.',twitch:'kniraven'},
        {title:'Federation Frontline Report',description:'Intercept the Gallente opposition.',twitch:'federationfrontlinereport'},
        {title:'Myriad Nova',description:'Intercepted transmissions from Myriad Nova.',twitch:'myriad_nova'}
    ];
    const dial=$('#home-frequency'),player=$('[data-home-radio-player]'),play=$('[data-home-radio-play]'),pause=$('[data-home-radio-pause]'),radio=$('.home-radio'),volumeBar=$('#receiver-volume-bar'),volume=$('#receiver-volume'),screen=$('[data-home-twitch-player]');
    const bars=[...radio.querySelectorAll('.radio-bars i')];
    const reduced=window.matchMedia('(prefers-reduced-motion: reduce)');
    let channel=-1,context,analyser,samples,raf=0,activeTwitch=null,embedRequest=0,twitchReady=false;
    function state(label,message){text('[data-home-radio-state]',label);text('[data-home-radio-status]',message);radio.dataset.state=label.toLowerCase();}
    function transport(isPlaying,available=true){
        play.setAttribute('aria-pressed',String(isPlaying));
        pause.setAttribute('aria-pressed',String(!isPlaying&&available));
        play.disabled=!available;
        pause.disabled=!available;
    }
    function stopMeter(){cancelAnimationFrame(raf);raf=0;bars.forEach(b=>b.style.height='4%');}
    function meter(){
        stopMeter();if(!analyser||player.paused||reduced.matches)return;
        function draw(){analyser.getByteFrequencyData(samples);bars.forEach((b,i)=>{const start=Math.floor(i*samples.length/bars.length),end=Math.floor((i+1)*samples.length/bars.length);let sum=0;for(let j=start;j<end;j++)sum+=samples[j];b.style.height=Math.max(4,sum/(end-start)/255*100)+'%';});raf=requestAnimationFrame(draw);}
        draw();
    }
    async function enableMeter(){
        const Audio=window.AudioContext||window.webkitAudioContext;
        if(!Audio)return;
        try{if(!context){context=new Audio();analyser=context.createAnalyser();analyser.fftSize=128;samples=new Uint8Array(analyser.frequencyBinCount);const source=context.createMediaElementSource(player);source.connect(analyser);analyser.connect(context.destination);}if(context.state==='suspended')await context.resume();}catch(error){/* Playback remains available without a meter. */}
    }
    // Keep only the Twitch video above the global CRT layers.
    let twitchPortal=null, portalFrame=0;
    const receiverGlass=screen.closest('.receiver-screen');
    function positionTwitchPortal(){
        portalFrame=0;
        if(!twitchPortal||!receiverGlass)return;
        const rect=receiverGlass.getBoundingClientRect();
        const header=document.querySelector('.site-header');
        const headerRect=header?.getBoundingClientRect();
        const topLimit=headerRect&&headerRect.top<=0?Math.max(0,headerRect.bottom):0;
        const clippedTop=Math.max(0,topLimit-rect.top);
        const clippedBottom=Math.max(0,rect.bottom-window.innerHeight);
        const clippedLeft=Math.max(0,-rect.left);
        const clippedRight=Math.max(0,rect.right-window.innerWidth);
        const visible=rect.width>0&&rect.height>0&&clippedTop+clippedBottom<rect.height&&clippedLeft+clippedRight<rect.width;
        twitchPortal.style.visibility=visible?'visible':'hidden';
        twitchPortal.style.left=rect.left+'px';
        twitchPortal.style.top=rect.top+'px';
        twitchPortal.style.width=rect.width+'px';
        twitchPortal.style.height=rect.height+'px';
        twitchPortal.style.clipPath=`inset(${clippedTop}px ${clippedRight}px ${clippedBottom}px ${clippedLeft}px)`;
        portalFrame=requestAnimationFrame(positionTwitchPortal);
    }
    function createTwitchPortal(){
        if(twitchPortal)return twitchPortal;
        twitchPortal=document.createElement('div');
        twitchPortal.className='receiver-twitch-portal';
        twitchPortal.setAttribute('aria-label','Live Twitch video');
        document.body.appendChild(twitchPortal);
        positionTwitchPortal();
        return twitchPortal;
    }
    function removeTwitchPortal(){
        cancelAnimationFrame(portalFrame);portalFrame=0;
        twitchPortal?.remove();twitchPortal=null;
    }
    // Keep one Twitch SDK instance for the lifetime of this page.
    // Removing its iframe and constructing another instance can accumulate SDK listeners.
    let playerChannel = null, statusController = null;
    function hideTwitch(){
        if(twitchPortal)twitchPortal.style.display='none';
        screen.parentElement?.classList.remove('receiver-twitch-unobstructed');
    }
    function stopTwitch(){
        ++embedRequest;
        statusController?.abort();statusController=null;
        if(activeTwitch){try{activeTwitch.pause();}catch(error){}}
        twitchReady=false;
        hideTwitch();
        screen.replaceChildren();screen.hidden=true;delete screen.dataset.live;
    }
    function loadTwitchLibrary(){
        if(window.Twitch&&window.Twitch.Player)return Promise.resolve();
        if(window.rxTwitchLibrary)return window.rxTwitchLibrary;
        window.rxTwitchLibrary=new Promise((resolve,reject)=>{
            const tag=document.createElement('script');tag.src='https://player.twitch.tv/js/embed/v1.js';tag.async=true;
            tag.onload=()=>window.Twitch?.Player?resolve():reject(new Error('Twitch player unavailable'));
            tag.onerror=()=>reject(new Error('Unable to load Twitch player'));
            document.head.appendChild(tag);
        }).catch(error=>{window.rxTwitchLibrary=null;throw error;});
        return window.rxTwitchLibrary;
    }
    function fallbackMessage(message){
        const fallback=document.createElement('div');fallback.className='receiver-video-fallback';
        const heading=document.createElement('strong');heading.textContent=message;
        const detail=document.createElement('span');detail.textContent='Awaiting broadcaster signal.';
        fallback.append(heading,detail);screen.appendChild(fallback);
    }
    function markTwitchOffline(c){
        hideTwitch();screen.dataset.live='offline';
        const heading=screen.querySelector('.receiver-video-fallback strong');
        if(heading)heading.textContent='CURRENTLY OFFLINE';
        state('OFFLINE',`${c.title} is not live on Twitch.`);
        transport(false,false);
    }
    // Optional authenticated PHP preflight. If credentials are not configured,
    // the receiver falls back to the SDK's own ONLINE/OFFLINE events.
    async function preflightTwitch(name,request){
        const controller=new AbortController();statusController=controller;
        const timeout=setTimeout(()=>controller.abort(),6500);
        try{
            const response=await fetch(`/api/twitch/status.php?channel=${encodeURIComponent(name)}`,{signal:controller.signal,credentials:'same-origin',headers:{Accept:'application/json'}});
            if(!response.ok)return 'unknown';
            const payload=await response.json();
            if(request!==embedRequest)return 'stale';
            return payload.ok===true&&['online','offline'].includes(payload.status)?payload.status:'unknown';
        }catch(error){return request!==embedRequest?'stale':'unknown';}
        finally{clearTimeout(timeout);if(statusController===controller)statusController=null;}
    }
    function bindTwitchEvents(instance){
        instance.addEventListener(Twitch.Player.READY,()=>{
            if(!channels[channel]?.twitch)return;
            twitchReady=true;
            try{instance.setVolume(Number(volume.value));}catch(error){}
        });
        instance.addEventListener(Twitch.Player.PLAYBACK_BLOCKED,()=>{
            if(!channels[channel]?.twitch||screen.dataset.live!=='online')return;
            state('BLOCKED','Twitch requires a direct click on the video player to start playback.');
            transport(false,true);
        });
        instance.addEventListener(Twitch.Player.ONLINE,()=>{
            if(!channels[channel]?.twitch||playerChannel!==channels[channel].twitch)return;
            screen.dataset.live='online';
            if(twitchPortal)twitchPortal.style.display='block';
            screen.parentElement?.classList.add('receiver-twitch-unobstructed');
            state('LIVE','Live broadcast available. Press Play to watch.');
            transport(false,true);
        });
        instance.addEventListener(Twitch.Player.OFFLINE,()=>{
            if(!channels[channel]?.twitch||playerChannel!==channels[channel].twitch)return;
            markTwitchOffline(channels[channel]);
        });
        instance.addEventListener(Twitch.Player.PLAYING,()=>{
            if(!channels[channel]?.twitch||screen.dataset.live!=='online')return;
            state('PLAYING',`Receiving ${channels[channel].title} live.`);
            transport(true);
        });
        if(Twitch.Player.PAUSE)instance.addEventListener(Twitch.Player.PAUSE,()=>{
            if(!channels[channel]?.twitch||screen.dataset.live!=='online')return;
            state('PAUSED','Live transmission paused.');transport(false);
        });
    }
    async function connectTwitch(c,request){
        hideTwitch();screen.hidden=false;screen.dataset.live='checking';
        fallbackMessage('ACQUIRING SIGNAL');
        state('CHECKING','Checking Twitch broadcast status…');transport(false,false);
        const status=await preflightTwitch(c.twitch,request);
        if(request!==embedRequest||status==='stale')return;
        if(status==='offline'){markTwitchOffline(c);return;}
        try{
            await loadTwitchLibrary();
            if(request!==embedRequest)return;
            const portal=createTwitchPortal();portal.style.display='block';
            if(!activeTwitch){
                const host=document.createElement('div');host.className='receiver-video-host';
                host.id='receiver-twitch-persistent';portal.appendChild(host);
                activeTwitch=new Twitch.Player(host.id,{channel:c.twitch,width:'100%',height:'100%',parent:[location.hostname],autoplay:false,muted:false});
                playerChannel=c.twitch;
                bindTwitchEvents(activeTwitch);
            }else if(playerChannel!==c.twitch){
                playerChannel=c.twitch;
                activeTwitch.setChannel(c.twitch);
            }else{
                // Same channel: existing SDK state is reused instead of adding listeners.
                screen.dataset.live=status==='online'?'online':'checking';
                if(status==='online'){
                    screen.parentElement?.classList.add('receiver-twitch-unobstructed');
                    state('LIVE','Live broadcast available. Press Play to watch.');
                    transport(false,true);
                }
            }
            if(status==='online'){
                screen.dataset.live='online';
                screen.parentElement?.classList.add('receiver-twitch-unobstructed');
                state('LIVE','Live broadcast available. Press Play to watch.');
                transport(false,true);
            }
            twitchReady=true;
            try{activeTwitch.setVolume(Number(volume.value));}catch(error){}
        }catch(error){
            if(request!==embedRequest)return;
            hideTwitch();screen.dataset.live='offline';
            const heading=screen.querySelector('.receiver-video-fallback strong');
            if(heading)heading.textContent='SIGNAL UNAVAILABLE';
            state('UNAVAILABLE','Could not verify Twitch broadcast status.');
            transport(false,false);
        }
    }
    function tune(){
        const next=Number(dial.value);if(next===channel)return;
        player.pause();stopTwitch();channel=next;const c=channels[channel];
        stopMeter();radio.style.setProperty('--dial-angle',(-135+channel*(270/(channels.length-1)))+'deg');
        text('#home-frequency-output',String(channel+1).padStart(2,'0'));text('[data-home-radio-title]',c.title);text('[data-home-radio-description]',c.description);dial.setAttribute('aria-valuetext',c.title);
        transport(false,!!c.audio);
        if(c.audio){player.src=c.audio;}else player.removeAttribute('src');player.load();
        if(c.twitch)connectTwitch(c,embedRequest);else state('SELECTED','Channel selected. Press Play to listen.');
    }
    function step(amount){dial.value=String((channel+amount+channels.length)%channels.length);tune();}
    dial.addEventListener('input',tune);$('[data-home-radio-next]').addEventListener('click',()=>step(1));$('[data-radio-prev]').addEventListener('click',()=>step(-1));
    // Native range supplies keyboard support; vertical dragging turns the dial.
    let drag;
    dial.addEventListener('pointerdown',event=>{if(event.button!==0)return;drag={y:event.clientY,value:channel};dial.setPointerCapture(event.pointerId);dial.focus();event.preventDefault();});
    dial.addEventListener('pointermove',event=>{if(!drag)return;dial.value=String(Math.max(0,Math.min(channels.length-1,drag.value+Math.round((drag.y-event.clientY)/28))));tune();});
    for(const name of ['pointerup','pointercancel','lostpointercapture'])dial.addEventListener(name,()=>{drag=null;});
    play.addEventListener('click',async()=>{
        if(channels[channel]?.twitch){
            if(!activeTwitch||screen.dataset.live!=='online')return;
            if(!twitchReady){state('LOADING','Twitch player is still initializing. Retry Play shortly.');return;}
            state('LOADING','Requesting Twitch playback…');
            try{activeTwitch.play();}catch(error){console.error('Twitch playback failed:',error);state('BLOCKED','Use the Play button inside the Twitch video player.');transport(false,true);}
            return;
        }
        if(!player.paused)return;
        const selected=channel;state('LOADING','Opening audio transmission…');
        try{await enableMeter();if(selected!==channel)return;await player.play();}
        catch(error){if(selected===channel)state('UNAVAILABLE','Audio could not play. Retry Play.');}
    });
    pause.addEventListener('click',()=>{
        if(channels[channel]?.twitch){
            if(!activeTwitch||screen.dataset.live!=='online')return;
            try{activeTwitch.pause();state('PAUSED','Live transmission paused.');transport(false);}catch(error){}
        }else if(!player.paused)player.pause();
    });
    player.addEventListener('playing',()=>{state('PLAYING',`Playing: ${channels[channel].title}`);transport(true);meter();});
    player.addEventListener('pause',()=>{stopMeter();if(channels[channel]?.audio){transport(false);if(['playing','buffering'].includes(radio.dataset.state))state('PAUSED','Transmission paused. Press Play to resume.');}});
    player.addEventListener('waiting',()=>{if(channels[channel]?.audio&&!player.paused){stopMeter();state('BUFFERING','Receiving audio…');}});
    player.addEventListener('ended',()=>{state('COMPLETE','Transmission complete. Replay or tune another channel.');transport(false);stopMeter();});
    player.addEventListener('error',()=>{if(channels[channel]?.audio)state('UNAVAILABLE','Audio unavailable. Retry Play.');transport(false);stopMeter();});
    function setVolume(){
        const level=Number(volume.value);
        player.volume=level;
        radio.style.setProperty('--volume-angle',(-135+level*270)+'deg');
        const percent=Math.round(level*100);
        volumeBar.value=String(percent);
        text('[data-radio-volume]',percent+'%');
        text('[data-radio-volume-bar]',percent+'%');
        if(activeTwitch&&typeof activeTwitch.setVolume==='function'){
            try{activeTwitch.setVolume(level);}catch(error){}
        }
    }
    let volumeDrag=null;
    const volumeKnob=$('.receiver-volume-knob');
    volume.addEventListener('pointerdown',event=>{
        if(event.button!==0)return;
        volumeDrag={y:event.clientY,value:Number(volume.value)};
        volume.setPointerCapture(event.pointerId);volume.focus();event.preventDefault();
    });
    volume.addEventListener('pointermove',event=>{
        if(!volumeDrag)return;
        volume.value=String(Math.max(0,Math.min(1,volumeDrag.value+(volumeDrag.y-event.clientY)/160)));
        setVolume();
    });
    for(const name of ['pointerup','pointercancel','lostpointercapture'])volume.addEventListener(name,()=>{volumeDrag=null;});
    volume.addEventListener('input',setVolume);
    volumeBar.addEventListener('input',()=>{volume.value=String(Number(volumeBar.value)/100);setVolume();});
    reduced.addEventListener('change',meter);
    setVolume();
    tune();
})();
