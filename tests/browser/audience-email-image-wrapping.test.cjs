'use strict';
// Test actual emitted email HTML, including a client that inserts block image containers.
const assert=require('node:assert/strict'),{execFileSync}=require('node:child_process'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const fixture=JSON.parse(execFileSync('php',['tests/audience_email_image_wrapping_test.php','--fixture'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}));
(async()=>{const browser=await chromium.launch({headless:true});try{
 for(const width of [390,768,1200])for(const rewritten of [false,true]){
  const page=await browser.newPage({viewport:{width,height:1000}});await page.route('**/*',route=>route.abort());
  await page.setContent(fixture.html);await page.locator('.wrapping-copy img').evaluateAll(images=>Promise.all(images.map(image=>image.decode())));
  if(rewritten)await page.locator('.wrapping-copy img').evaluateAll(images=>images.forEach(image=>{const box=document.createElement('div');box.style.cssText='display:block;clear:both;overflow:hidden;width:100%';image.before(box);box.append(image);}));
  const geometry=await page.evaluate(()=>{
   const block=document.querySelector('.wrapping-copy'),rect=block.getBoundingClientRect();
   const media=[...block.querySelectorAll('table.email-rich-image')].map((table,index)=>{
    const image=table.querySelector('img').getBoundingClientRect(),text=document.getElementById(index?'left-copy':'right-copy'),range=document.createRange();range.selectNodeContents(text);
    const lines=[...range.getClientRects()].filter(rect=>rect.width>0&&rect.height>0),first=lines[0];
    return {image:{top:image.top,bottom:image.bottom,left:image.left,right:image.right},first:{top:first.top,left:first.left,right:first.right},flowsBelow:lines.some(line=>line.top>=image.bottom&&(index?line.left<image.right-50:line.right>image.left+50)),inside:table.getBoundingClientRect().right<=rect.right+1&&table.getBoundingClientRect().left>=rect.left-1};
   });return {media,pageWidth:document.documentElement.scrollWidth,viewport:innerWidth};
  });
  for(const [index,item]of geometry.media.entries()){
   assert(item.first.top<item.image.bottom-20,'Text begins beside the image instead of below it');
   assert(index?item.first.left>=item.image.right+10:item.first.right<=item.image.left-10,'Text respects the image gap');
   assert(item.flowsBelow,'Text expands to the full width below the image');assert(item.inside,'Image table stays inside the email block');
  }
  assert(geometry.pageWidth<=geometry.viewport,'Email never forces horizontal scrolling');
  if(process.env.DNR_WRAPPING_OUTPUT){fs.mkdirSync(process.env.DNR_WRAPPING_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.DNR_WRAPPING_OUTPUT,`email-wrapping-${width}-${rewritten?'client-containers':'standard'}.png`),fullPage:true});}
  if(rewritten){
   // Confirm the simulation reproduces the reported failure with the previous image-only float markup.
   await page.locator('.wrapping-copy').evaluate((block,stored)=>{block.innerHTML=stored;for(const image of block.querySelectorAll('img')){const box=document.createElement('div');box.style.cssText='display:block;clear:both;overflow:hidden;width:100%';image.before(box);box.append(image);}},fixture.stored);
   await page.locator('.wrapping-copy img').evaluateAll(images=>Promise.all(images.map(image=>image.decode())));
   assert(await page.evaluate(()=>{const range=document.createRange();range.selectNodeContents(document.getElementById('right-copy'));return range.getClientRects()[0].top>=document.querySelector('.wrapping-copy img').getBoundingClientRect().bottom;}),'Previous markup falls below the image under client rewriting');
  }
  await page.close();
 }
 const wide=JSON.parse(execFileSync('php',['tests/audience_email_image_wrapping_test.php','--fixture-wide'],{cwd:path.resolve(__dirname,'../..'),encoding:'utf8'}));
 for(const width of [390,768]){
  const page=await browser.newPage({viewport:{width,height:1000}});await page.route('**/*',route=>route.abort());await page.setContent(wide.html);
  const fits=await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth&&[...document.querySelectorAll('.email-rich-image')].every(table=>{const block=table.closest('.email-block').getBoundingClientRect(),rect=table.getBoundingClientRect();return rect.left>=block.left-1&&rect.right<=block.right+1;}));
  assert(fits,'Oversized wrapped images shrink to fit the email column');await page.close();
 }
 console.log('Email wrapping passed at 390/768/1200px: linked/right and unlinked/left images, full-width continuation, original desktop flow, client-added image containers, reproduced previous failure, and oversized-image containment.');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
