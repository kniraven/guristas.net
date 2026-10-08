(() => {
    'use strict';
    if (!document.body.classList.contains('home-refresh')) return;
    const $ = selector => document.querySelector(selector);
    const text = (selector, value) => { const node = $(selector); if (node) node.textContent = value; };
    const directives = {
        raid: ['01', 'RAID // CORRUPT THE WARZONE', 'Find the Guristas campaign, inspect reported system conditions and prepare your next sortie.', '/war/guristas/', 'Open War Room →', '/join/', 'How to enter pirate warfare'],
        trade: ['02', 'TRADE // MAKE YOUR CUT', 'Compare station orders, value Commando Guri LP and investigate opportunities to supply The Fulcrum.', '/industry/?view=trade', 'Compare station markets →', '/industry/?view=lp', 'Calculate LP value'],
        build: ['03', 'BUILD // ARM THE NETWORK', 'Choose a Guristas blueprint, calculate materials and price the complete job before committing your ISK.', '/industry/', 'Open production console →', '/industry/?view=fulcrum', 'Check Fulcrum stock'],
        lore: ['04', 'INVESTIGATE // OPEN THE DOSSIERS', 'Follow Fatal and the Rabbit from the Caldari Navy to Venal. Trace the deals that brought the pirates to Zarzakh.', '/lore/', 'Enter the archives →', '/lore/?dossier=fatal', 'Start with Fatal'],
        signals: ['05', 'INTERCEPT // TUNE THE NETWORK', 'Lock onto Guristas music, connect to Kniraven’s broadcast desk or tune into the Gallente opposition.', '#signals', 'Tune Black Rabbit Radio →', '/community/#broadcast', 'Explore recordings'],
        join: ['06', 'JOIN // FIND YOUR PEOPLE', 'Explore Guristas enlistment, meet Cozen Corp or find a posted operation. Choose how you want to get involved.', '/join/', 'Explore ways to join →', '/join/#cozen', 'Meet Kniraven’s Cozen Corp']
    };
    function brief(key) {
        const d = directives[key] || directives.raid;
        text('[data-directive-code]', d[0]);text('#selectedOperationLabel', d[1]);text('[data-directive-copy]', d[2]);
        const action=$('[data-directive-action]'), help=$('[data-directive-help]');
        action.href=d[3];action.textContent=d[4];help.href=d[5];help.textContent=d[6];
    }
    document.addEventListener('guristas:directive-selected', event => brief(event.detail.key));
    brief(document.documentElement.dataset.operation);

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
        if(campaignMeta&&!fresh(campaignMeta,'last_success_at')) {
            text('[data-home-campaign-title]','Previous report · current status unknown');systemBrief();
        }
    }
    async function loadCampaign() {
        const button=$('[data-home-campaign-refresh]');if(button.disabled)return;button.disabled=true;
        text('[data-home-campaign-summary]','Contacting the warzone relay…');
        const control=new AbortController(), timer=setTimeout(()=>control.abort(),15000);
        try {
            const p=await json('/api/war/guristas-overlay.php',control.signal);
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
        } finally {clearTimeout(timer);button.disabled=false;}
    }
    systemSelect.addEventListener('change',systemBrief);$('[data-home-campaign-refresh]').addEventListener('click',loadCampaign);loadCampaign();

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
        {title:'Fatal Mistake',description:'Guristas alternate rock demo.',audio:'/assets/audio/fatal-mistake-demo.mp3',href:'/signals/#radio',link:'Track archive →'},
        {title:'Black Rabbits',description:'A second recovered Guristas alternate rock demo.',audio:'/assets/audio/black-rabbits-demo.mp3',href:'/signals/#radio',link:'Track archive →'},
        {title:'Kniraven // Broadcast desk',description:'Open the channel player for current status, or browse recent broadcasts.',href:'https://www.twitch.tv/kniraven',link:'Open Twitch channel ↗'},
        {title:'Federation Frontline Report',description:'The Gallente side of the story. Open their broadcast archive.',href:'/signals/#enemy',link:'Open rival broadcasts →'}
    ];
    const dial=$('#home-frequency'),player=$('[data-home-radio-player]'),play=$('[data-home-radio-play]'),radio=$('.home-radio'),motion=$('[data-home-radio-motion]');let channel=-1;
    const prefersReduced=window.matchMedia('(prefers-reduced-motion: reduce)');
    function visual(){radio.classList.toggle('is-playing',!player.paused&&motion.checked&&!prefersReduced.matches);}
    function tune(){
        const next=Number(dial.value);if(next===channel)return;player.pause();channel=next;
        const c=channels[channel];text('#home-frequency-output',`${String(channel+1).padStart(2,'0')} / 04`);text('[data-home-radio-title]',c.title);text('[data-home-radio-description]',c.description);
        dial.setAttribute('aria-valuetext',c.title+(c.audio?', music demo':', broadcast link'));
        text('[data-home-radio-state]','CHANNEL LOCKED');text('[data-home-radio-status]',c.audio?'Channel selected. Press Play to listen.':'Broadcast selected. Open the channel to watch or listen.');
        player.hidden=!c.audio;play.hidden=!c.audio;motion.closest('label').hidden=!c.audio;
        if(c.audio){player.src=c.audio;play.textContent='Play transmission';}else{player.removeAttribute('src');player.load();}
        const a=$('[data-home-radio-link]');a.href=c.href;a.textContent=c.link;if(c.href.startsWith('https:')){a.target='_blank';a.rel='noopener noreferrer';}else{a.removeAttribute('target');a.removeAttribute('rel');}
        visual();
        document.dispatchEvent(new CustomEvent('guristas:signalscan',{detail:{frequency:channel*33,signalPanel:radio,frequencyOutput:$('#home-frequency-output'),signalMessage:$('[data-home-radio-description]')}}));
    }
    dial.addEventListener('input',tune);$('[data-home-radio-next]').addEventListener('click',()=>{dial.value=String((channel+1)%channels.length);tune();});
    play.addEventListener('click',async()=>{if(!player.paused){player.pause();return;}const selected=channel;try{await player.play();}catch(error){if(channel===selected)text('[data-home-radio-status]','Playback unavailable. Try the native player or open the track archive.');}});
    player.addEventListener('play',()=>{play.textContent='Pause transmission';text('[data-home-radio-state]','PLAYING');text('[data-home-radio-status]',`Playing: ${channels[channel].title}`);visual();});
    player.addEventListener('pause',()=>{play.textContent='Play transmission';text('[data-home-radio-state]','PAUSED');text('[data-home-radio-status]',`Paused: ${channels[channel]?.title||'Transmission'}`);visual();});
    player.addEventListener('ended',()=>{text('[data-home-radio-state]','COMPLETE');text('[data-home-radio-status]','Transmission complete. Scan another channel or replay.');visual();});
    player.addEventListener('error',()=>{if(channels[channel]?.audio){text('[data-home-radio-state]','RELAY ERROR');text('[data-home-radio-status]','Audio unavailable. Open the track archive or retry Play.');}visual();});
    motion.addEventListener('change',visual);prefersReduced.addEventListener('change',visual);tune();
    $('[data-home-load-twitch]').addEventListener('click',()=>{
        player.pause();const frame=document.createElement('iframe');const url=new URL('https://player.twitch.tv/');
        url.searchParams.set('channel','kniraven');url.searchParams.set('parent',location.hostname);url.searchParams.set('autoplay','false');
        frame.src=url.href;frame.title='Kniraven Twitch broadcast';frame.allowFullscreen=true;frame.allow='fullscreen';frame.className='home-twitch-frame';
        $('[data-home-twitch-player]').replaceChildren(frame);text('[data-home-twitch-status]','Twitch connection requested. If its player cannot load, use Open Twitch channel.');
        text('[data-home-load-twitch]','Reconnect to Twitch');
    });
})();
