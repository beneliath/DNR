'use strict';
// Render the real designer with synthetic templates; all requests stay in this fixture.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const html=execFileSync('php',['tests/browser/audience-image-editing-fixture.php','template-switcher'],{cwd:root,encoding:'utf8',maxBuffer:32*1024*1024});
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  for(const width of [390,1518]){
   const page=await browser.newPage({viewport:{width,height:1000}}),errors=[],saves=[],choices=new Map();
   page.setDefaultTimeout(5000);page.on('pageerror',error=>errors.push(error.message));
   await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!=='http://switcher.test')return route.abort();
    if(url.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
    if(request.method()==='POST'){
     const body=new URLSearchParams(request.postData());
     if(body.get('action')==='design_template_choice'){
      const key=body.get('key');
      assert(!choices.has(key),'Each preview is requested once');
      const payload=execFileSync('php',['tests/browser/audience-image-editing-fixture.php','template-choice',body.get('source'),key,body.get('preview_nonce')],{cwd:root,encoding:'utf8',maxBuffer:8*1024*1024});
      choices.set(key,JSON.parse(payload).design);
      return route.fulfill({contentType:'application/json',body:payload});
     }
     if(body.get('action')==='design_code_validate')return route.fulfill({contentType:'application/json',body:'{"regions":{},"warnings":[]}'});
     if(body.get('action')==='design_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:JSON.parse(body.get('design')).code})});
     assert.equal(body.get('action'),'design_save');saves.push(JSON.parse(body.get('design')));
     return route.fulfill({contentType:'application/json',body:JSON.stringify({version:saves.length+1})});
    }
    if(url.pathname==='/audience.php')return route.fulfill({contentType:'text/html',body:html});
    const file=path.join(root,'src',url.pathname),type={'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml','.woff2':'font/woff2'}[path.extname(file)];
    return fs.existsSync(file)?route.fulfill({contentType:type||'application/octet-stream',body:fs.readFileSync(file)}):route.fulfill({status:404,body:''});
   });
   const open=async()=>{const menu=page.locator('.designer-top-actions .designer-save-menu').nth(1);if(await menu.getAttribute('open')===null)await menu.locator('summary').click();await page.locator('#design-change').click();};
   await page.goto('http://switcher.test/audience.php?view=designer');
   assert.equal(choices.size,0,'Opening the editor requests no template alternatives');
   assert.equal(await page.locator('#design-change-dialog iframe').count(),0);
   assert(!html.includes('starter-banner-image-newsletter'),'The editor does not embed alternative designs');
   await open();const dialog=page.getByRole('dialog',{name:'Choose a template'});
   assert.equal(await dialog.locator('#design-saved .designer-template-card').count(),2);
   const starter=dialog.locator('[data-design-template-key="newsletter"]').locator('..');
   await starter.scrollIntoViewIfNeeded();
   await page.waitForFunction(()=>{
    const card=document.querySelector('[data-design-template-key="newsletter"]').closest('article'),frame=card.querySelector('iframe');
    return frame?.contentDocument?.body?.textContent.trim()&&card.style.height&&card.querySelector('.template-thumbnail').clientHeight>100&&Math.abs(frame.getBoundingClientRect().width-card.querySelector('.template-thumbnail').clientWidth)<2;
   });
   const preview=await starter.evaluate(card=>{
    const box=card.querySelector('.template-thumbnail'),frame=card.querySelector('iframe');
    return {cardWidth:card.clientWidth,cardHeight:card.offsetHeight,previewWidth:box.clientWidth,frameWidth:frame.getBoundingClientRect().width,sandbox:frame.getAttribute('sandbox'),inert:frame.inert};
   });
   assert(Math.abs(preview.previewWidth-preview.frameWidth)<2,'Email preview scales to its card: '+JSON.stringify(preview));
   assert(preview.cardHeight<=preview.cardWidth*2+5,'Preview card height stays bounded');
   assert.equal(preview.sandbox,'allow-same-origin');assert.equal(preview.inert,true);
   assert(!choices.has('101'),'Offscreen saved designs are not fetched when the dialog opens');
   await dialog.locator('#design-saved article').first().scrollIntoViewIfNeeded();
   await page.waitForFunction(()=>document.querySelector('#design-saved article iframe')?.contentDocument?.querySelector('img')?.naturalWidth===600);
   assert.equal(await dialog.locator('#design-saved .designer-template-name').nth(1).innerText(),'Saved code <design>');
   await starter.click({position:{x:30,y:50}});
   const confirm=page.getByRole('dialog',{name:'Change template?'});
   await confirm.waitFor({state:'visible'});assert.equal(saves.length,0,'Choosing a card waits for confirmation');
   await confirm.getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(await dialog.isVisible(),true);
   await starter.locator('[data-design-template-key="newsletter"]').click();await confirm.getByRole('button',{name:'Continue',exact:true}).click();
   await dialog.waitFor({state:'hidden'});await page.waitForFunction(()=>document.querySelector('#design-status').textContent==='Saved');
   assert.deepEqual(saves.at(-1).rows.map(row=>row.id),choices.get('newsletter').rows.map(row=>row.id),'Selected starter is applied');
   await open();const savedCode=dialog.getByRole('button',{name:'Use template: Saved code <design>'});
   await savedCode.scrollIntoViewIfNeeded();await savedCode.focus();await page.keyboard.press('Enter');
   await confirm.getByRole('button',{name:'Continue',exact:true}).click();
   await page.waitForFunction(()=>document.querySelector('#design-status').textContent==='Saved');
   assert.equal(saves.at(-1).mode,'code');assert.match(saves.at(-1).code,/Saved code template/);
   await page.locator('.designer-top-actions .designer-save-menu > summary').nth(1).click();await page.locator('#design-undo').click();
   await page.waitForFunction(()=>document.querySelector('#design-status').textContent==='Saved');
   assert.deepEqual(saves.at(-1).rows.map(row=>row.id),choices.get('newsletter').rows.map(row=>row.id),'Undo restores the previous design');
   await open();assert(await dialog.evaluate(node=>node.scrollWidth<=node.clientWidth+1),'Switcher fits its viewport');
   if(process.env.DNR_SWITCHER_OUTPUT){fs.mkdirSync(process.env.DNR_SWITCHER_OUTPUT,{recursive:true});await dialog.screenshot({path:path.join(process.env.DNR_SWITCHER_OUTPUT,`template-switcher-${width}.png`)});}
   await page.keyboard.press('Escape');assert.equal(await dialog.isVisible(),false);
   assert.deepEqual(errors,[]);await page.close();
  }
  console.log('Template switcher passed on desktop/mobile: rendered starter/saved previews, image loading, whole-card selection, confirmation, keyboard selection, saved HTML, Undo, responsive layout and inert previews.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
