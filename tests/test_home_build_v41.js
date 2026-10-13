const fs = require('node:fs');
const assert = require('node:assert/strict');
const s = fs.readFileSync('public/assets/js/home-command.js', 'utf8');
// v41: show a quoted per-unit price when present, without inventing a quote otherwise.
assert(s.includes('const priceLabel=Number.isFinite(price)&&price>0?'), 'quoted price must be conditional');
assert(s.includes("price.toLocaleString('en-US'"), 'quoted price must be formatted');
assert(s.includes('ISK/UNIT'), 'price must identify unit');
assert(s.includes('BUYER PRICE UNVERIFIED'), 'missing quote must be explicitly identified');
// v41.2: avoid overflowing narrow cards; preserve detail as a tooltip.
assert(s.includes("detail.textContent=[priceLabel,countLabel].filter(Boolean).join"), 'concise card summary missing');
assert(s.includes("detail.title=[detail.textContent,c.detail"), 'detailed evidence title missing');
console.log('PASS v41/v41.2: conditional price, output units, compact card, details preserved');
