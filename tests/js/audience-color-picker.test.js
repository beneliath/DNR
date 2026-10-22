import test from 'node:test';
import assert from 'node:assert/strict';
import {hexToHsv,hsvToHex} from '../../src/assets/js/audience-color-picker.mjs';
import {extendedTextColors} from '../../src/assets/js/audience-richtext-colors.mjs';
test('Color picker preserves exact brand hex values through HSV conversion',()=>{
  for(const color of ['#000000','#ffffff','#3261b5','#c00000','#007c89','#000080','#aabbcc','#010203','#ff00ff'])assert.equal(hsvToHex(...hexToHsv(color)),color);
});
test('Hue slider covers all primary and secondary colors including its wraparound',()=>{
  for(const [h,color] of [[0,'#ff0000'],[60,'#ffff00'],[120,'#00ff00'],[180,'#00ffff'],[240,'#0000ff'],[300,'#ff00ff'],[360,'#ff0000']])assert.equal(hsvToHex(h,1,1),color);
});
test('Extended classic palette follows the observed Mailchimp row ordering',()=>{
  const colors=extendedTextColors();assert.equal(colors.length,234);assert.deepEqual(colors.slice(0,7),['#000000','#003300','#006600','#009900','#00cc00','#00ff00','#330000']);assert.equal(colors[18],'#000033');assert.equal(colors[108],'#990000');assert.equal(colors[215],'#ffffff');assert.deepEqual(colors.slice(-3),['#eeeeee','#ffffff','#ffffff']);
});
