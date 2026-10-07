(() => {
    'use strict';
    const radio=document.querySelector('[data-radio]');
    if(radio) {
        const player=radio.querySelector('[data-radio-player]');
        const status=radio.querySelector('[data-radio-status]');
        const tracks=[...radio.querySelectorAll('[data-radio-track]')];
        let title='Fatal Mistake · alternate rock demo';
        const motion=radio.querySelector('[data-radio-motion]');
        function visual() { radio.classList.toggle('radio-playing',!player.paused && motion.checked); }
        tracks.forEach((button,index)=>{
            button.setAttribute('aria-pressed',String(index===0));
            button.addEventListener('click',async()=>{
                player.pause();title=button.dataset.trackTitle;player.src=button.dataset.radioTrack;
                tracks.forEach(track=>track.setAttribute('aria-pressed',String(track===button)));
                status.textContent=`Selected: ${title}`;
                try { await player.play(); } catch(error) { status.textContent=`Selected: ${title}. Use the play control or download link if playback is unavailable.`; }
            });
        });
        player.addEventListener('play',()=>{status.textContent=`Playing: ${title}`;visual();});
        player.addEventListener('pause',()=>{status.textContent=`Paused: ${title}`;visual();});
        player.addEventListener('ended',()=>{status.textContent=`Transmission complete: ${title}. Choose another track when ready.`;visual();});
        player.addEventListener('error',()=>{status.textContent='Audio relay unavailable. Try the download links or return later.';visual();});
        motion.addEventListener('change',visual);
    }
    const load=document.querySelector('[data-load-twitch]');
    load?.addEventListener('click',()=>{
        const host=document.querySelector('[data-twitch-player]');
        const frame=document.createElement('iframe');
        const url=new URL('https://player.twitch.tv/');
        url.searchParams.set('channel','kniraven');url.searchParams.set('parent',location.hostname);url.searchParams.set('autoplay','false');
        frame.src=url.href;frame.title='Kniraven Twitch player';frame.allowFullscreen=true;
        frame.setAttribute('allow','fullscreen');frame.className='twitch-frame';
        host.replaceChildren(frame);load.disabled=true;load.textContent='Twitch player loaded';
    });
})();
