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
        {title:'Fatal Mistake',description:'Recovered Guristas alternate rock demo.',audio:'/assets/audio/fatal-mistake-demo.mp3'},
        {title:'Black Rabbits',description:'A second recovered transmission from Black Rabbit Radio.',audio:'/assets/audio/black-rabbits-demo.mp3'},
        {title:'Kniraven',description:'Pirate broadcasts from Kniraven.',twitch:'kniraven'},
        {title:'Federation Frontline Report',description:'Intercept the Gallente opposition.',twitch:'federationfrontlinereport'},
        {title:'Myriad Nova',description:'Intercepted transmissions from Myriad Nova.',twitch:'myriad_nova'}
    ];
    const dial=$('#home-frequency'),player=$('[data-home-radio-player]'),play=$('[data-home-radio-play]'),pause=$('[data-home-radio-pause]'),radio=$('.home-radio'),volumeBar=$('#receiver-volume-bar'),volume=$('#receiver-volume'),screen=$('[data-home-twitch-player]');
    const bars=[...radio.querySelectorAll('.radio-bars i')];
    const embedStatus=$('[data-receiver-embed-status]');
    function showEmbedStatus(message=''){if(!embedStatus)return;embedStatus.textContent=message;embedStatus.hidden=!message;}
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
    function stopTwitch(){
        ++embedRequest;
        if(activeTwitch){try{activeTwitch.pause();}catch(error){}activeTwitch=null;}
        twitchReady=false;
        screen.replaceChildren();screen.hidden=true;delete screen.dataset.live;showEmbedStatus();
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
    async function connectTwitch(c,request){
        screen.hidden=false;screen.dataset.live='checking';
        fallbackMessage('ACQUIRING SIGNAL');
        state('CHECKING','Checking Twitch broadcast status…');showEmbedStatus('Checking Twitch signal…');transport(false,false);
        try{
            await loadTwitchLibrary();
            if(request!==embedRequest)return;
            const host=document.createElement('div');host.className='receiver-video-host';
            host.id='receiver-twitch-'+request;screen.prepend(host);
            const instance=new Twitch.Player(host.id,{channel:c.twitch,width:'100%',height:'100%',parent:[location.hostname],autoplay:false,muted:false});
            activeTwitch=instance;
            instance.addEventListener(Twitch.Player.READY,()=>{
                if(request!==embedRequest)return;
                twitchReady=true;
                try{instance.setVolume(Number(volume.value));}catch(error){}
            });
            instance.addEventListener(Twitch.Player.PLAYBACK_BLOCKED,()=>{
                if(request!==embedRequest)return;
                state('BLOCKED','Twitch requires a direct click on the video player to start playback.');
                showEmbedStatus('Twitch blocked playback. Click Play inside the video.');
                transport(false,true);
            });
            try{instance.setVolume(Number(volume.value));}catch(error){}
            instance.addEventListener(Twitch.Player.ONLINE,()=>{
                if(request!==embedRequest)return;
                screen.dataset.live='online';
                state('LIVE','Live broadcast available. Press Play to watch.');showEmbedStatus('LIVE · Press Play to receive.');transport(false,true);
            });
            instance.addEventListener(Twitch.Player.OFFLINE,()=>{
                if(request!==embedRequest)return;
                screen.dataset.live='offline';
                screen.querySelector('.receiver-video-fallback strong').textContent='CURRENTLY OFFLINE';
                state('OFFLINE',`${c.title} is not live on Twitch.`);showEmbedStatus('OFFLINE · Awaiting broadcaster signal.');transport(false,false);
            });
            instance.addEventListener(Twitch.Player.PLAYING,()=>{if(request===embedRequest){state('PLAYING',`Receiving ${c.title} live.`);showEmbedStatus('LIVE · Receiving transmission.');transport(true);}});
            if(Twitch.Player.PAUSE)instance.addEventListener(Twitch.Player.PAUSE,()=>{if(request===embedRequest){state('PAUSED','Live transmission paused.');showEmbedStatus('LIVE · Transmission paused.');transport(false);}});
        }catch(error){
            if(request!==embedRequest)return;
            screen.dataset.live='offline';
            screen.querySelector('.receiver-video-fallback strong').textContent='SIGNAL UNAVAILABLE';
            state('UNAVAILABLE','Could not verify Twitch broadcast status.');showEmbedStatus('Unable to acquire Twitch signal.');transport(false,false);
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
            if(!twitchReady){state('LOADING','Twitch player is still initializing. Retry Play shortly.');showEmbedStatus('Initializing player…');return;}
            state('LOADING','Requesting Twitch playback…');showEmbedStatus('Requesting Twitch playback…');
            try{activeTwitch.play();}catch(error){state('BLOCKED','Use the Play button inside the Twitch video player.');showEmbedStatus('Click Play inside the Twitch video.');transport(false,true);}
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
            try{activeTwitch.pause();state('PAUSED','Live transmission paused.');showEmbedStatus('LIVE · Transmission paused.');transport(false);}catch(error){}
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
