const test=require('node:test'),assert=require('node:assert/strict');
const crop=import('../../src/assets/js/audience-image-crop.mjs');
test('zoom midpoint preserves size with matching shrink and enlargement travel',async()=>{
 const {imageCropZoom}=await crop;
 assert.deepEqual([-100,-50,0,50,100].map(imageCropZoom),[0.25,0.5,1,2,4]);
});
test('original image fills the frame and disables position controls',async()=>{
 const {imageCropLayout}=await crop,r=imageCropLayout(600,400,1.5,1);
 assert.deepEqual([r.x,r.y,r.drawnWidth,r.drawnHeight,r.canMoveX,r.canMoveY],[0,0,600,400,false,false]);
});
test('zooming out creates centered transparent margins and usable positioning',async()=>{
 const {imageCropLayout}=await crop,r=imageCropLayout(600,400,1.5,0.5);
 assert.deepEqual([r.x,r.y,r.drawnWidth,r.drawnHeight,r.canMoveX,r.canMoveY],[150,100,300,200,true,true]);
 const left=imageCropLayout(600,400,1.5,0.5,0,0),right=imageCropLayout(600,400,1.5,0.5,100,100);
 assert.deepEqual([left.x,left.y,right.x,right.y],[0,0,300,200]);
});
test('position sliders move enlarged images in the indicated direction',async()=>{
 const {imageCropLayout}=await crop,left=imageCropLayout(600,400,1.5,2,0,0),right=imageCropLayout(600,400,1.5,2,100,100);
 assert.deepEqual([left.x,left.y,right.x,right.y],[-600,-400,0,0]);
 const square=imageCropLayout(600,400,1,1);assert.equal(square.canMoveX,true);assert.equal(square.canMoveY,false);
});
