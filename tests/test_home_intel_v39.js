'use strict';
const fs=require('node:fs');
const assert=require('node:assert/strict');
const src=fs.readFileSync('public/assets/js/home-command.js','utf8');
const checks={
 'RAID explicit action':"ACTION: Contest eligible insurgency complexes",
 'RAID candidate evidence':"viable systems assessed; priority score",
 'RAID no prediction claim':"not a reward or success probability",
 'TRADE conditional profit':"conditionalReturn?'POTENTIAL / '",
 'TRADE LP yield qualification':"'CONDITIONAL LP / '",
 'TRADE source bid fill risk':"'SOURCE BID MUST FILL'",
 'TRADE sell-order risk':"'SALE NOT GUARANTEED'",
 'BUILD mining availability warning':"'DEPOSIT UNVERIFIED'",
 'BUILD manufacturing cost warning':"'JOB + HAUL UNPRICED'",
 'BUILD job duration evidence':"addLine('RECIPE TIME'",
 'BUILD run limit evidence':"addLine('BLUEPRINT LIMIT'",
 'Existing 3 BUILD slots':"['MANUFACTURE','MINE','PLANETARY'][i]",
 'Existing 3 TRADE slots':"report.orders.length!==3"
};
for(const [name,token] of Object.entries(checks)){
 assert.ok(src.includes(token),`Missing ${name}`);
 console.log('PASS',name);
}
console.log('PASS v39: 13 homepage intelligence checks');
