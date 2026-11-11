import test from 'node:test';
import assert from 'node:assert/strict';
import {hexToHsv,hsvToHex} from '../../src/assets/js/audience-color-picker.mjs';
import {colorPalette} from '../../src/assets/js/audience-color-values.mjs';
test('Color picker preserves exact brand hex values through HSV conversion',()=>{
  for(const color of ['#000000','#ffffff','#3261b5','#c00000','#007c89','#000080','#aabbcc','#010203','#ff00ff'])assert.equal(hsvToHex(...hexToHsv(color)),color);
});
test('Hue slider covers all primary and secondary colors including its wraparound',()=>{
  for(const [h,color] of [[0,'#ff0000'],[60,'#ffff00'],[120,'#00ff00'],[180,'#00ffff'],[240,'#0000ff'],[300,'#ff00ff'],[360,'#ff0000']])assert.equal(hsvToHex(h,1,1),color);
});
test('Brand Kit colors preserve their role labels without duplicate white swatches',()=>{
  assert.deepEqual(colorPalette([{label:'Content Background',value:'#ffffff'},{label:'Button Text',value:'#FFFFFF'},{label:'Link Color',value:'#3261b5'}]),[{label:'Content Background / Button Text',value:'#ffffff'},{label:'Link Color',value:'#3261b5'}]);
});
