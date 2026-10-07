const assert=require('node:assert/strict');
const {uploadSize}=require('../public/assets/js/community-forms.js');
assert.deepEqual(uploadSize([{size:4},{size:6}],8,12),{total:10,valid:true});
assert.equal(uploadSize([{size:9}],8,12).valid,false);
assert.equal(uploadSize([{size:7},{size:7}],8,12).valid,false);
assert.equal(uploadSize([{size:0}],8,12).valid,false);
assert.equal(uploadSize([],8,12).valid,true); // native required checks the first image
console.log('Upload per-image and combined limits passed.');
