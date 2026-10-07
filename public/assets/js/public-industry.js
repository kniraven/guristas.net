(() => {
    'use strict';
    function materials(bp, runs, me, facility) {
        if (!Number.isInteger(runs) || runs < 1 || runs > 10000 || ![me,facility].every(Number.isFinite) || me < 0 || me > 10 || facility < 0 || facility > 90) throw new Error('Check the run count and efficiency values.');
        return bp.materials.map(row=>({type:row.typeID,quantity:Math.max(runs,Math.ceil(Math.round(runs*row.quantity*(1-me/100)*(1-facility/100)*100)/100))}));
    }
    function conversion(proceeds, iskCost, requiredCost, otherCost, lp) {
        if (![proceeds,iskCost,requiredCost,otherCost,lp].every(Number.isFinite) || [proceeds,iskCost,requiredCost,otherCost].some(n=>n<0) || lp<=0) throw new Error('Enter complete, nonnegative costs and a positive LP cost.');
        const value=proceeds-iskCost-requiredCost-otherCost;
        return {value,perLP:value/lp};
    }
    if(typeof module!=='undefined'&&module.exports) module.exports={materials,conversion};
    if(typeof document==='undefined') return;
    const source=document.querySelector('#industry-reference'); if(!source) return;
    const ref=JSON.parse(source.textContent);
    const money=n=>Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' ISK';
    const name=id=>ref.types[id]?.name || `EVE type ${id}`;
    const find=(root,key)=>root.querySelector(`[data-${key}]`);
    const value=(root,key)=>{ const raw=find(root,key).value.trim(); if(raw==='') return NaN;return Number(raw); };
    const valid=(n,min,max)=>Number.isFinite(n)&&n>=min&&n<=max;
    function node(tag,text) { const el=document.createElement(tag);if(text!==undefined) el.textContent=text;return el; }
    async function json(url) {
        const control=new AbortController(), timeout=setTimeout(()=>control.abort(),20000);
        try { const r=await fetch(url,{credentials:'omit',headers:{Accept:'application/json'},signal:control.signal}); const p=await r.json(); if(!r.ok||p.ok!==true) throw new Error(p.message||'Relay unavailable'); return p.data; }
        finally { clearTimeout(timeout); }
    }
    const build=document.querySelector('[data-build]');
    if(build) {
        let rows=[];let bp=null;const prices=new Map();let revision=0;
        function result() {
            let total=0,missing=0;
            for(const row of rows) { const raw=row.input.value.trim();const price=raw===''?NaN:Number(raw);if(!valid(price,0,1e15)) missing++;else total+=price*row.quantity;row.cost.textContent=Number.isFinite(price)?money(price*row.quantity):'Enter price'; }
            const other=value(build,'build-other'),sale=value(build,'build-sale'),fees=value(build,'build-fees'),haul=value(build,'build-haul');
            if(missing || ![other,haul].every(n=>valid(n,0,1e18)) || !valid(fees,0,100) || !bp) { find(build,'build-result').textContent='Enter a valid price for every material and all batch costs.';return; }
            const units=value(build,'build-runs')*bp.product.quantity;
            const cost=total+other+haul,net=sale*units*(1-fees/100)-cost;
            find(build,'build-result').textContent=`Batch cost: ${money(cost)}. Cost per finished unit: ${money(cost/units)}. ${valid(sale,0,1e18)?'Estimated batch surplus after entered costs: '+money(net)+'.':'Enter a valid sale price to compare surplus.'} ${[other,fees,haul].some(n=>n===0)?'Some fees or other costs are zero; this may be a gross estimate.':''}`;
        }
        function recipe() {
            revision++;
            for(const row of rows) prices.set(row.type,row.input.value);
            const body=find(build,'build-materials');body.replaceChildren();rows=[];bp=ref.blueprints[find(build,'build-blueprint').value];
            try {
                const runs=value(build,'build-runs'),me=value(build,'build-me'),facility=value(build,'build-facility'),te=value(build,'build-te'),industry=value(build,'build-industry'),advanced=value(build,'build-advanced'),time=value(build,'build-time');
                if(!valid(te,0,20)||!valid(time,0,90)||![industry,advanced].every(n=>Number.isInteger(n)&&valid(n,0,5))) throw new Error('Check TE and skill values.');
                const needed=materials(bp,runs,me,facility);
                const seconds=Math.ceil(bp.time*runs*(1-te/100)*(1-.04*industry)*(1-.03*advanced)*(1-time/100));
                find(build,'build-summary').textContent=`${name(bp.product.typeID)}: ${runs*bp.product.quantity} finished units. Estimated job time: ${(seconds/3600).toFixed(2)} hours. Verify available BPC runs in EVE; the static maximum is ${bp.max_runs}.`;
                find(build,'build-market').href=`/industry/?view=trade&type=${bp.product.typeID}`;
                for(const row of needed) {
                    const tr=node('tr'),label=node('th',name(row.type));label.scope='row';tr.append(label,node('td',row.quantity.toLocaleString()));
                    const td=node('td'),input=node('input');input.type='number';input.min='0';input.step='0.01';input.placeholder='Required';input.setAttribute('aria-label',`ISK per unit of ${name(row.type)}`);input.value=prices.get(row.type)||'';input.addEventListener('input',result);td.append(input);tr.append(td);
                    const cost=node('td');tr.append(cost);body.append(tr);rows.push({...row,input,cost});
                }
                result();
            } catch(error) { bp=null;find(build,'build-summary').textContent=error.message;find(build,'build-result').textContent='Correct the production inputs before pricing this batch.'; }
        }
        ['blueprint','runs','me','facility','te','industry','advanced','time'].forEach(key=>find(build,'build-'+key).addEventListener('input',recipe));
        ['other','sale','fees','haul'].forEach(key=>find(build,'build-'+key).addEventListener('input',result));
        find(build,'build-copy').addEventListener('click',async()=>{
            try { if(!bp) throw new Error();await navigator.clipboard.writeText(rows.map(row=>`${name(row.type)}\t${row.quantity}`).join('\n'));find(build,'build-copy-status').textContent='Material shopping list copied.'; }
            catch(error) { find(build,'build-copy-status').textContent='Copy unavailable. Select the material rows in the table instead.'; }
        });
        find(build,'build-price').addEventListener('click',async()=>{
            if(!bp) return;const button=find(build,'build-price');button.disabled=true;const current=revision,hub=find(build,'build-hub').value;let index=0,failed=0,old=0;
            find(build,'build-price-status').textContent='Pricing the complete material quantities at the selected station…';
            const worker=async()=>{while(index<rows.length&&current===revision){const row=rows[index++];try{const quote=await json(`/api/industry/quote.php?hub=${encodeURIComponent(hub)}&type=${row.type}&quantity=${row.quantity}`);if(current!==revision) return;if(!quote.buy.complete){failed++;row.input.value='';}else{row.input.value=String(quote.buy.average);if(quote.meta.stale)old++;}}catch(error){failed++;if(current===revision)row.input.value='';}}};
            await Promise.all([worker(),worker()]);button.disabled=false;
            if(current!==revision){find(build,'build-price-status').textContent='Recipe changed. Refresh prices for the new recipe.';return;}
            find(build,'build-price-status').textContent=`Prices checked at ${ref.hubs[hub].name} at ${new Date().toUTCString()}. ${failed} material quotes incomplete or unavailable; enter those prices manually. ${old} quotes use old cached data. Orders can change; verify before buying.`;result();
        });
        recipe();
    }
    const lp=document.querySelector('[data-lp]');
    if(lp) {
        let offers=ref.offers_snapshot;
        const select=find(lp,'lp-offer');
        function calculate() {
            const offer=offers.find(o=>String(o.offer_id)===select.value);
            if(!offer){find(lp,'lp-result').textContent='No matching offer.';return;}
            if(offer.ak_cost>0){find(lp,'lp-result').textContent=`This offer also needs ${offer.ak_cost} units of another currency. The ledger does not value that currency; compare it in EVE.`;return;}
            try{const output=conversion(value(lp,'lp-proceeds'),offer.isk_cost,value(lp,'lp-required'),value(lp,'lp-other'),offer.lp_cost);find(lp,'lp-result').textContent=`Value remaining after entered costs: ${money(output.value)}. Net conversion estimate: ${output.perLP.toFixed(2)} ISK per LP.`;}
            catch(error){find(lp,'lp-result').textContent='Enter net sale proceeds, required-item replacement cost and all other conversion costs.';}
        }
        function details() {
            const host=find(lp,'lp-details');host.replaceChildren();const offer=offers.find(o=>String(o.offer_id)===select.value);if(!offer){calculate();return;}
            host.append(node('h3',`${offer.quantity} × ${name(offer.type_id)}`),node('p',`${offer.lp_cost.toLocaleString()} LP + ${money(offer.isk_cost)}${offer.ak_cost>0?` + ${offer.ak_cost} additional currency`:''}`));
            const list=node('ul');for(const item of offer.required_items||[])list.append(node('li',`${item.quantity.toLocaleString()} × ${name(item.type_id)}`));host.append(list);
            if(!offer.required_items?.length){host.append(node('p','No additional items reported.'));find(lp,'lp-required').value='0';}else find(lp,'lp-required').value='';
            find(lp,'lp-proceeds').value='';find(lp,'lp-other').value='';
            if(ref.blueprints[offer.type_id]) {host.append(node('p','Blueprint offer: manufacture the finished product or find a buyer for the copy. Do not use finished-ship revenue without adding production costs.'));const a=node('a','Open this production recipe →');a.href=`/industry/?view=build&bp=${offer.type_id}`;host.append(a);}
            else {const a=node('a','Compare station orders →');a.href=`/industry/?view=trade&type=${offer.type_id}`;host.append(a);}
            calculate();
        }
        function options() {
            const previous=select.value,q=find(lp,'offer-search').value.toLowerCase();select.replaceChildren();
            offers.filter(o=>name(o.type_id).toLowerCase().includes(q)).sort((a,b)=>name(a.type_id).localeCompare(name(b.type_id))).forEach(o=>{const option=node('option',`${name(o.type_id)} · ${o.lp_cost.toLocaleString()} LP`);option.value=o.offer_id;select.append(option);});
            if([...select.options].some(o=>o.value===previous)) select.value=previous;details();
        }
        find(lp,'offer-search').addEventListener('input',options);select.addEventListener('change',details);
        ['proceeds','required','other'].forEach(key=>find(lp,'lp-'+key).addEventListener('input',calculate));
        find(lp,'offer-refresh').addEventListener('click',async()=>{
            const button=find(lp,'offer-refresh');button.disabled=true;
            try{const result=await json('/api/industry/offers.php');if(!Array.isArray(result.data))throw new Error('Invalid offers');offers=result.data;find(lp,'offer-freshness').textContent=`${result.meta.stale?'Dated reference, current offers unconfirmed':'Offer report from EVE'}. Retrieved ${result.meta.fetched_at || 'unknown'}.`;options();}
            catch(error){find(lp,'offer-freshness').textContent='Offer relay unavailable. Existing dated reference remains; verify the offer in EVE.';}finally{button.disabled=false;}
        });
        options();
    }
    const market=document.querySelector('[data-market]');
    if(market) {
        let quotes=new Map();let generation=0;
        function estimate() {
            if(market.dataset.marketMode!=='trade')return;
            const buy=quotes.get(find(market,'trade-buy').value),sell=quotes.get(find(market,'trade-sell').value),tax=value(market,'trade-tax'),cost=value(market,'trade-cost');
            if(!buy?.buy.complete||!sell?.sell.complete||buy.meta.stale||sell.meta.stale||!valid(tax,0,100)||!valid(cost,0,1e18)){find(market,'trade-result').textContent='A complete, fresh quote at both stations and valid batch costs are required. Thin or old orders are not a profit opportunity.';return;}
            const net=sell.sell.value*(1-tax/100)-buy.buy.value-cost;
            find(market,'trade-result').textContent=`Estimated surplus for this batch: ${money(net)}. ${tax===0||cost===0?'Some fees or travel costs are zero. ':''}Verify availability and your route before buying.`;
        }
        function clear(){generation++;quotes=new Map();find(market,'market-rows').replaceChildren();find(market,'market-status').textContent='Batch changed. Check station orders again.';estimate();}
        ['type','quantity'].forEach(key=>find(market,'market-'+key).addEventListener('input',clear));
        if(market.dataset.marketMode==='trade') ['buy','sell','tax','cost'].forEach(key=>find(market,'trade-'+key).addEventListener('input',estimate));
        find(market,'market-refresh').addEventListener('click',async()=>{
            const quantity=value(market,'market-quantity'),type=Number(find(market,'market-type').value);if(!Number.isInteger(quantity)||!valid(quantity,1,1e9)){find(market,'market-status').textContent='Choose a positive whole batch quantity.';return;}
            const current=++generation,button=find(market,'market-refresh');button.disabled=true;quotes=new Map();const body=find(market,'market-rows');body.replaceChildren();
            const keys=market.dataset.marketMode==='fulcrum'?['fulcrum']:Object.keys(ref.hubs);let failures=0;
            find(market,'market-status').textContent='Contacting station markets…';
            for(const hub of keys) {
                const row=node('tr');row.append(node('th',ref.hubs[hub].name));body.append(row);
                try{
                    const quote=await json(`/api/industry/quote.php?hub=${hub}&type=${type}&quantity=${quantity}&history=1`);if(current!==generation)break;quotes.set(hub,quote);
                    const fill=q=>q.complete?money(q.value):`Only ${q.filled.toLocaleString()} / ${q.requested.toLocaleString()} can fill`;
                    row.append(node('td',fill(quote.buy)),node('td',fill(quote.sell)),node('td',`${quote.buy.listed_volume.toLocaleString()} / ${quote.sell.listed_volume.toLocaleString()}`),node('td',`${quote.meta.stale?'Old report':'Retrieved'} ${quote.meta.fetched_at||'unknown'}`));
                    if(quote.history?.average_volume!==null&&quote.history?.average_volume!==undefined){const h=node('small',`Region: ${Math.round(quote.history.average_volume).toLocaleString()} units/day across ${quote.history.days} reported recent days${quote.history.stale?' · old history':''}.`);row.children[3].append(h);}
                }catch(error){failures++;const cell=node('td','Relay unavailable; use the market in EVE.');cell.colSpan=4;row.append(cell);}
            }
            button.disabled=false;if(current===generation){let summary=`Batch checked: ${quantity.toLocaleString()} × ${name(type)}. ${failures} station reports unavailable. `;
                const usable=[...quotes.values()].filter(q=>!q.meta.stale),buys=usable.filter(q=>q.buy.complete).sort((a,b)=>a.buy.value-b.buy.value),sells=usable.filter(q=>q.sell.complete).sort((a,b)=>b.sell.value-a.sell.value);
                if(buys.length) summary+=`Lowest complete purchase quote: ${buys[0].hub.name}. `;
                if(sells.length) summary+=`Highest complete immediate sale quote: ${sells[0].hub.name}. `;
                find(market,'market-status').textContent=summary+'Compare fees and travel before choosing. Listed volume is not a guarantee of demand.';estimate();}
        });
    }
})();
