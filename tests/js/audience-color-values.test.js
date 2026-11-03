const test=require('node:test');
const assert=require('node:assert/strict');
test('Color studio ships inside the versioned Audience stylesheet',()=>{
  const css=require('node:fs').readFileSync(require('node:path').resolve(__dirname,'../../src/assets/css/pages/audience.min.css'),'utf8');
  assert(css.includes('.audience-color-studio'));
  assert(!/@import[^;]*audience-color-studio/.test(css),'Picker styling must not use a separately cached unversioned import');
});
test('Color studio validates exact opaque colors and groups duplicate brand values',async()=>{
  const {normalizeColor,colorPalette,colorContrast,hexToHsv,hsvToHex}=await import('../../src/assets/js/audience-color-values.mjs');
  assert.equal(normalizeColor('3261B5'),'#3261b5');assert.equal(normalizeColor('rgb(193, 3, 4)'),'#c10304');
  for(const value of ['red','#123','rgb(256,0,0)','rgba(0,0,0,0)',null,{},'url(javascript:evil)'])assert.equal(normalizeColor(value),null);
  assert.deepEqual(colorPalette([{label:'Content',value:'#ffffff'},{label:'Button text',value:'#FFFFFF'},{label:'Invalid',value:'red'}]),[{value:'#ffffff',label:'Content / Button text'}]);
  for(const hex of ['#3261b5','#c10304','#ffffff','#000000','#24292d'])assert.equal(hsvToHex(...hexToHsv(hex)),hex);
  assert.equal(colorContrast('#ffffff','#000000'),21);assert.equal(colorContrast('#ffffff','#ffffff'),1);
});
