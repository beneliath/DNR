'use strict';
// Real campaign template and built assets; requests use synthetic recipients only.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const html=execFileSync('php',['tests/browser/audience-campaign-checklist-fixture.php'],{cwd:root,encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage(),errors=[],requests=[];
  let reply={count:1,people:[{id:1,first_name:'Fixture',last_name:'Recipient',email:'fixture@example.test'}]},failure=false;
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('**/*',route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.origin!=='http://checklist.test')return route.abort();
   if(request.method()==='POST'){
    requests.push(request.postData());
    return route.fulfill({status:failure?422:200,contentType:'application/json',body:JSON.stringify(failure?{error:'A selected segment is unavailable.'}:reply)});
   }
   if(url.pathname==='/audience.php')return route.fulfill({contentType:'text/html',body:html});
   const file=path.join(root,'src',url.pathname);
   return route.fulfill({contentType:file.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(file)});
  });
  for(const width of [390,1518]) {
   await page.setViewportSize({width,height:1100});
   await page.goto('http://checklist.test/audience.php?view=campaign&id=13');
   const stages=page.locator('.campaign-progress li');
   assert.deepEqual(await stages.locator('span').allTextContents(),['To','From','Subject','Send time','Content']);
   assert.equal(await stages.nth(1).getAttribute('aria-label'),'From: Complete');
   assert.equal(await stages.nth(0).getAttribute('aria-label'),'To: Incomplete');
   const row=page.locator('.campaign-checklist-item > summary').first();
   await row.hover();
   assert.equal(await row.evaluate(node=>getComputedStyle(node).backgroundColor),'rgb(250, 251, 252)');
   await row.click();
   const include=page.locator('[data-recipient-picker]').nth(0),exclude=page.locator('[data-recipient-picker]').nth(1);
   assert.equal(await include.locator('summary').innerText(),'testSegment');
   assert.equal(await exclude.locator('summary').innerText(),'Staff <internal>');
   await include.locator('summary').click();
   await include.getByRole('checkbox',{name:'Members & friends'}).check();
   await exclude.locator('summary').click();
   assert.equal(await include.getAttribute('open'),null);
   assert.equal(await include.locator('summary').innerText(),'Members & friends, testSegment');
   await exclude.getByRole('checkbox',{name:'testSegment',exact:false}).check();
   await page.getByRole('checkbox',{name:'Personalize the ‘To’ field'}).focus();
   await page.waitForFunction(()=>!document.querySelectorAll('[data-recipient-picker]')[1].open);
   assert.equal(await exclude.locator('summary').innerText(),'Staff <internal>, testSegment');
   const personalize=page.getByRole('checkbox',{name:'Personalize the ‘To’ field'}),merge=page.locator('#campaign-to-merge');
   assert.equal(await merge.isVisible(),false);
   assert.equal(await merge.isDisabled(),true);
   await personalize.check();
   assert.equal(await merge.isVisible(),true);
   assert.equal(await merge.isDisabled(),false);
   assert.deepEqual(await merge.locator('option').evaluateAll(nodes=>nodes.map(node=>node.value)),['FNAME','LNAME','EMAIL','FULLNAME']);
   const metrics=await page.evaluate(()=>{
    const picker=document.querySelector('[data-recipient-picker]'),input=document.querySelector('[name=personalize_to]'),merge=document.querySelector('#campaign-to-merge');
    const label=input.closest('label').getBoundingClientRect(),box=input.getBoundingClientRect();
    const review=document.querySelector('.campaign-recipient-review').getBoundingClientRect(),group=merge.parentElement.getBoundingClientRect();
    return {picker:picker.getBoundingClientRect().width,merge:merge.getBoundingClientRect().width,inputWidth:box.width,inputLeft:box.left,labelLeft:label.left,labelHeight:label.height,inputHeight:box.height,gap:review.top-group.bottom,pageWidth:document.documentElement.scrollWidth,viewport:innerWidth};
   });
   assert(metrics.picker<=560&&metrics.merge<=320,'Dropdown widths are bounded');
   assert(metrics.inputWidth===18&&Math.abs(metrics.inputLeft-metrics.labelLeft)<2,'Personalization checkbox sits at the start of its label');
   assert(metrics.labelHeight<metrics.inputHeight*2,'Checkbox and label share one row');
   assert(metrics.gap>=27,'Recipient guidance is separated from the merge field');
   assert(metrics.pageWidth<=metrics.viewport,'Campaign checklist fits the viewport');
   await page.getByRole('button',{name:'Preview recipients'}).click();
   const preview=page.getByRole('dialog',{name:'Preview recipients'});
   await preview.getByRole('cell',{name:'fixture@example.test'}).waitFor();
   assert.match(requests.at(-1),/include\[\]/);
   assert.match(requests.at(-1),/exclude\[\]/);
   await preview.getByRole('button',{name:'Close',exact:true}).click();
   reply={count:0,people:[]};
   await page.getByRole('button',{name:'Preview recipients'}).click();
   await preview.getByText('No subscribed, active connections match these selections.').waitFor();
   await preview.getByRole('button',{name:'Close',exact:true}).click();
   failure=true;
   await page.getByRole('button',{name:'Preview recipients'}).click();
   await preview.getByRole('status').getByText('A selected segment is unavailable.').waitFor();
   await preview.getByRole('button',{name:'Close',exact:true}).click();
   assert.equal(await page.getByRole('button',{name:'Preview recipients'}).isEnabled(),true);
   failure=false;reply={count:1,people:[{id:1,first_name:'Fixture',last_name:'Recipient',email:'fixture@example.test'}]};
   await page.getByRole('button',{name:'Cancel',exact:true}).first().click();
   await row.click();
   assert.equal(await include.locator('summary').innerText(),'testSegment');
   assert.equal(await merge.isVisible(),false);
   await page.getByRole('button',{name:'Schedule',exact:true}).click();
   const resend=page.getByRole('dialog',{name:'Schedule automatic resend'});
   const overflow=await resend.evaluate(dialog=>{
    const select=dialog.querySelector('select'),rect=dialog.getBoundingClientRect(),control=select.getBoundingClientRect();
    return {inside:control.left>=rect.left&&control.right<=rect.right,client:dialog.clientWidth,scroll:dialog.scrollWidth};
   });
   assert(overflow.inside&&overflow.scroll<=overflow.client+1,'Long segment names remain inside the resend dialog');
   await resend.getByRole('button',{name:'Close resend'}).click();
   if(process.env.DNR_CHECKLIST_OUTPUT){fs.mkdirSync(process.env.DNR_CHECKLIST_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.DNR_CHECKLIST_OUTPUT,`campaign-checklist-${width}.png`),fullPage:true});}
  }
  assert.deepEqual(errors,[]);
  console.log('Campaign checklist passed at desktop/mobile widths: selection names, dismissal, reset, personalization, bounded controls, progress labels, hover, preview success/empty/error and resend overflow.');
 } finally {await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
