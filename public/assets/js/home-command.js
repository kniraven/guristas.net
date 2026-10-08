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
        {title:'Fatal Mistake',description:'Recovered Guristas alternate rock demo.',audio:'/assets/audio/fatal-mistake-demo.mp3',href:'/signals/#radio',link:'Track archive →'},
        {title:'Black Rabbits',description:'A second recovered transmission from Black Rabbit Radio.',audio:'/assets/audio/black-rabbits-demo.mp3',href:'/signals/#radio',link:'Track archive →'},
        {title:'Kniraven',description:'Pirate broadcasts from Kniraven. Connect to check the channel or watch on Twitch.',href:'https://www.twitch.tv/kniraven',link:'Open Twitch channel ↗'},
        {title:'Federation Frontline Report',description:'Intercept the Gallente side of the story. Open the broadcast archive to choose an episode.',href:'/signals/#enemy',link:'Open rival broadcasts →'}
    ];
    const dial=$('#home-frequency'),player=$('[data-home-radio-player]'),play=$('[data-home-radio-play]'),radio=$('.home-radio'),motion=$('[data-home-radio-motion]'),seek=$('#receiver-seek'),volume=$('#receiver-volume'),connect=$('[data-home-load-twitch]'),screen=$('[data-home-twitch-player]');
    const presets=[...document.querySelectorAll('[data-radio-preset]')],bars=[...radio.querySelectorAll('.radio-bars i')];
    const reduced=window.matchMedia('(prefers-reduced-motion: reduce)');
    let channel=-1,context,analyser,samples,raf=0;
    function state(label,message){text('[data-home-radio-state]',label);text('[data-home-radio-status]',message);radio.dataset.state=label.toLowerCase();}
    function stopMeter(){cancelAnimationFrame(raf);raf=0;bars.forEach(b=>b.style.height='4%');}
    function meter(){
        stopMeter();if(!analyser||player.paused||!motion.checked||reduced.matches)return;
        function draw(){analyser.getByteFrequencyData(samples);bars.forEach((b,i)=>{const start=Math.floor(i*samples.length/bars.length),end=Math.floor((i+1)*samples.length/bars.length);let sum=0;for(let j=start;j<end;j++)sum+=samples[j];b.style.height=Math.max(4,sum/(end-start)/255*100)+'%';});raf=requestAnimationFrame(draw);}
        draw();
    }
    async function enableMeter(){
        const Audio=window.AudioContext||window.webkitAudioContext;
        if(!Audio)return;
        try{if(!context){context=new Audio();analyser=context.createAnalyser();analyser.fftSize=128;samples=new Uint8Array(analyser.frequencyBinCount);const source=context.createMediaElementSource(player);source.connect(analyser);analyser.connect(context.destination);}if(context.state==='suspended')await context.resume();}catch(error){/* Playback remains available without a meter. */}
    }
    function clock(value){if(!Number.isFinite(value))return '0:00';return `${Math.floor(value/60)}:${String(Math.floor(value%60)).padStart(2,'0')}`;}
    function progress(){const valid=Number.isFinite(player.duration)&&player.duration>0;seek.disabled=!valid;seek.value=valid?player.currentTime/player.duration*100:0;text('[data-radio-time]',`${clock(player.currentTime)} / ${clock(player.duration)}`);seek.setAttribute('aria-valuetext',clock(player.currentTime));}
    function tune(){
        const next=Number(dial.value);if(next===channel)return;player.pause();screen.replaceChildren();channel=next;const c=channels[channel];
        stopMeter();radio.style.setProperty('--dial-angle',(-135+channel*90)+'deg');
        text('#home-frequency-output',String(channel+1).padStart(2,'0'));text('[data-home-radio-title]',c.title);text('[data-home-radio-description]',c.description);dial.setAttribute('aria-valuetext',c.title);
        presets.forEach((b,i)=>b.setAttribute('aria-pressed',String(i===channel)));
        play.hidden=!c.audio;connect.hidden=channel!==2;connect.textContent='Connect to Twitch';volume.closest('label').hidden=!c.audio;motion.closest('label').hidden=!c.audio;seek.hidden=!c.audio;$('.receiver-timeline').hidden=!c.audio;$('.receiver-meter').hidden=!c.audio;
        if(c.audio){player.src=c.audio;play.textContent='Play transmission';}else player.removeAttribute('src');player.load();progress();
        const link=$('[data-home-radio-link]');link.href=c.href;link.textContent=c.link;if(c.href.startsWith('https:')){link.target='_blank';link.rel='noopener noreferrer';}else{link.removeAttribute('target');link.removeAttribute('rel');}
        state('SELECTED',c.audio?'Channel selected. Press Play to listen.':channel===2?'Ready to connect. Twitch reports its own broadcast status.':'Archive channel selected. Open rival broadcasts to listen.');
    }
    function step(amount){dial.value=String((channel+amount+channels.length)%channels.length);tune();}
    dial.addEventListener('input',tune);$('[data-home-radio-next]').addEventListener('click',()=>step(1));$('[data-radio-prev]').addEventListener('click',()=>step(-1));presets.forEach(b=>b.addEventListener('click',()=>{dial.value=b.dataset.radioPreset;tune();}));
    // Native range supplies keyboard support; vertical dragging turns the dial.
    let drag;
    dial.addEventListener('pointerdown',event=>{if(event.button!==0)return;drag={y:event.clientY,value:channel};dial.setPointerCapture(event.pointerId);dial.focus();event.preventDefault();});
    dial.addEventListener('pointermove',event=>{if(!drag)return;dial.value=String(Math.max(0,Math.min(3,drag.value+Math.round((drag.y-event.clientY)/28))));tune();});
    for(const name of ['pointerup','pointercancel','lostpointercapture'])dial.addEventListener(name,()=>{drag=null;});
    play.addEventListener('click',async()=>{if(!player.paused){player.pause();return;}const selected=channel;state('LOADING','Opening audio transmission…');try{await enableMeter();if(selected!==channel)return;await player.play();}catch(error){if(selected===channel)state('UNAVAILABLE','Audio could not play. Retry Play or open the track archive.');}});
    player.addEventListener('playing',()=>{play.textContent='Pause transmission';state('PLAYING',`Playing: ${channels[channel].title}`);meter();});
    player.addEventListener('pause',()=>{play.textContent='Play transmission';stopMeter();if(channels[channel]?.audio&&['playing','buffering'].includes(radio.dataset.state))state('PAUSED','Transmission paused. Press Play to resume.');});
    player.addEventListener('waiting',()=>{if(channels[channel]?.audio&&!player.paused){stopMeter();state('BUFFERING','Receiving audio…');}});
    player.addEventListener('ended',()=>{play.textContent='Replay transmission';state('COMPLETE','Transmission complete. Replay or tune another channel.');stopMeter();});
    player.addEventListener('error',()=>{if(channels[channel]?.audio)state('UNAVAILABLE','Audio unavailable. Retry Play or open the track archive.');stopMeter();});
    for(const event of ['timeupdate','loadedmetadata','durationchange','emptied'])player.addEventListener(event,progress);
    seek.addEventListener('input',()=>{if(Number.isFinite(player.duration)&&player.duration>0)player.currentTime=Number(seek.value)/100*player.duration;});
    player.volume=Number(volume.value);volume.addEventListener('input',()=>{player.volume=Number(volume.value);});motion.addEventListener('change',meter);reduced.addEventListener('change',meter);
    connect.addEventListener('click',()=>{if(channel!==2)return;player.pause();const frame=document.createElement('iframe');const url=new URL('https://player.twitch.tv/');url.searchParams.set('channel','kniraven');url.searchParams.set('parent',location.hostname);url.searchParams.set('autoplay','false');frame.src=url.href;frame.title='Kniraven Twitch broadcast';frame.allowFullscreen=true;frame.allow='fullscreen';frame.className='home-twitch-frame';screen.replaceChildren(frame);state('PLAYER OPEN','Twitch player requested. If blocked, use Open Twitch channel below.');connect.textContent='Reconnect to Twitch';});
    tune();
})();
