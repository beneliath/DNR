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
  let releaseSend;
  const sendGate=new Promise(resolve=>{releaseSend=resolve;});
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',async route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.origin!=='http://send-preview.test')return route.abort();
   if(request.method()==='POST'){posts.push(request.postData());await sendGate;return route.fulfill({contentType:'text/html',body:'Send accepted'});}
   if(url.pathname==='/audience.php')return route.fulfill({contentType:'text/html',body:html});
   const file=path.join(root,'src',url.pathname);
   return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  await page.goto('http://send-preview.test/audience.php?view=campaign-send&id=13');
  const url=page.url();
  const recipientLink=page.getByRole('link',{name:'Preview your recipients'}),recipientDialog=page.getByRole('dialog',{name:'Preview your recipients'});
  await recipientLink.click();await recipientDialog.waitFor({state:'visible'});assert.equal(page.url(),url);
  assert.equal(await recipientDialog.locator('tbody tr').count(),2);
  assert.match(await recipientDialog.innerText(),/Ada Example/);
  assert.match(await recipientDialog.innerText(),/<script> Recipient/);
  assert.equal(await recipientDialog.locator('script').count(),0);
  await recipientDialog.getByRole('button',{name:'Close',exact:true}).click();await recipientDialog.waitFor({state:'hidden'});
  assert.equal(await recipientLink.evaluate(node=>node===document.activeElement),true);
  const link=page.getByRole('link',{name:'Preview your email'}),dialog=page.getByRole('dialog',{name:'Email preview',exact:true});
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
  assert.equal(page.url(),url);assert.deepEqual(posts,[]);
  await page.locator('.campaign-send-review [data-open-dialog="campaign-send-confirm"]').click();
  const confirmation=page.locator('#campaign-send-confirm'),sendButton=confirmation.locator('[data-campaign-dispatch-submit]');
  await sendButton.evaluate(button=>{button.click();button.closest('form').requestSubmit();});
  await page.waitForFunction(()=>document.querySelector('[data-campaign-dispatch-submit]')?.classList.contains('campaign-dispatch-submitting'));
  assert.equal(await sendButton.innerText(),'Sending…');
  assert.equal(await sendButton.isDisabled(),true);
  assert.equal(await confirmation.locator('[data-campaign-dispatch-status]').isVisible(),true);
  assert.equal(await sendButton.evaluate(button=>getComputedStyle(button,'::before').animationName),'campaign-dispatch-spin');
  assert(await confirmation.evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Pending send fits the confirmation dialog');
  await page.waitForTimeout(150);assert.equal(posts.length,1,'One send request is made while progress is visible');
  releaseSend();await page.getByText('Send accepted').waitFor();
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Send-review previews and send progress passed for HTML/plain text on desktop/mobile.');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
