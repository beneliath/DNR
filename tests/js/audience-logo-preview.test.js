'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const modulePromise=import('../../src/assets/js/audience-logo-preview.mjs');
function pixels(width,height,rect,background=[0,0,0,0],ink=[10,80,160,255]) {
 const data=new Uint8ClampedArray(width*height*4);
 for(let y=0;y<height;y++)for(let x=0;x<width;x++)data.set(x>=rect.x&&x<rect.x+rect.width&&y>=rect.y&&y<rect.y+rect.height?ink:background,(y*width+x)*4);
 return data;
}
test('logo preview bounds ignore transparent padding without changing proportions',async()=>{
 const {logoArtworkBounds,logoPreviewLayout}=await modulePromise,rect={x:4,y:2,width:8,height:4};
 assert.deepEqual(logoArtworkBounds(pixels(20,10,rect),20,10),rect);
 const layout=logoPreviewLayout(20,10,rect,225,112.5),scale=layout.width/20;
 assert.equal(layout.height/10,scale);assert.equal(rect.height*scale,112.5);
 assert.equal(layout.left+(rect.x+rect.width/2)*scale,112.5);assert.equal(layout.top+(rect.y+rect.height/2)*scale,56.25);
});
test('opaque logo margins can be fitted while white artwork on transparency remains visible',async()=>{
 const {logoArtworkBounds}=await modulePromise,rect={x:3,y:2,width:4,height:5};
 assert.deepEqual(logoArtworkBounds(pixels(12,10,rect,[255,255,255,255]),12,10),rect);
 assert.deepEqual(logoArtworkBounds(pixels(12,10,rect,[0,0,0,0],[255,255,255,255]),12,10),rect);
});
test('empty and single-color logos retain the complete image as a safe fallback',async()=>{
 const {logoArtworkBounds}=await modulePromise;
 assert.deepEqual(logoArtworkBounds(new Uint8ClampedArray(8*6*4),8,6),{x:0,y:0,width:8,height:6});
 assert.deepEqual(logoArtworkBounds(pixels(8,6,{x:0,y:0,width:8,height:6}),8,6),{x:0,y:0,width:8,height:6});
});
test('wide and tall artwork fill the available axis and extreme padding cannot cause unbounded zoom',async()=>{
 const {logoPreviewLayout}=await modulePromise;
 const wide=logoPreviewLayout(100,100,{x:0,y:40,width:100,height:20},225,112.5);assert.equal(wide.width,225);
 const tall=logoPreviewLayout(100,100,{x:40,y:0,width:20,height:100},225,112.5);assert.equal(tall.height,112.5);
 const tiny=logoPreviewLayout(1000,1000,{x:500,y:500,width:1,height:1},225,112.5);assert(tiny.height<=8*112.5);
});
