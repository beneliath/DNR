'use strict';
// Preview the real send-review template without dispatching or modifying a campaign.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});try{
 for(const mode of ['send-html','send-text'])for(const width of [390,1518]){
  const html=execFileSync('php',['tests/browser/audience-campaign-checklist-fixture.php',mode],{cwd:root,encoding:'utf8',maxBuffer:8*1024*1024});
  const page=await browser.newPage({viewport:{width,height:1000}}),errors=[],posts=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.origin!=='http://send-preview.test')return route.abort();
   if(request.method()==='POST'){posts.push(request.postData());return route.abort();}
   if(url.pathname==='/audience.php')return route.fulfill({contentType:'text/html',body:html});
   const file=path.join(root,'src',url.pathname);
   return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  await page.goto('http://send-preview.test/audience.php?view=campaign-send&id=13');
  const url=page.url(),link=page.getByRole('link',{name:'Preview your email'}),dialog=page.getByRole('dialog',{name:'Email preview',exact:true});
  await link.click();await dialog.waitFor({state:'visible'});assert.equal(page.url(),url);
  if(mode==='send-html'){
   const frame=dialog.locator('iframe');assert.equal(await frame.getAttribute('sandbox'),'');
   await frame.contentFrame().locator('body').waitFor();assert((await frame.contentFrame().locator('body').innerText()).trim().length>20,'HTML content is rendered');
  }else{
   assert.match(await dialog.locator('pre').innerText(),/Hello recipient,\n\n/);
   assert.match(await dialog.locator('pre').innerText(),/<script>literal text<\/script>/);assert.equal(await dialog.locator('script').count(),0);
  }
  assert(await dialog.evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Preview fits its dialog');
  await dialog.getByRole('button',{name:'Close',exact:true}).click();await dialog.waitFor({state:'hidden'});assert.equal(await link.evaluate(node=>node===document.activeElement),true);
  await link.focus();await page.keyboard.press('Enter');await dialog.waitFor({state:'visible'});await page.keyboard.press('Escape');await dialog.waitFor({state:'hidden'});
  assert.equal(page.url(),url);assert.deepEqual(posts,[]);assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Send-review preview passed for HTML/plain text on desktop/mobile: modal rendering, unchanged URL, close/focus restoration, keyboard open/Escape and no campaign requests.');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
