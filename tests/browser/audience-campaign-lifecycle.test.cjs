const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
const open=async({dark=false,width=1600,role='admin',tab='active',mode='',view='campaigns'}={})=>{
 const page=await browser.newPage({viewport:{width,height:1050}});page.setDefaultTimeout(6000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(dark=>{localStorage.setItem('theme',dark?'dark':'light');window.submissions=[];document.addEventListener('submit',event=>{if(!event.defaultPrevented&&event.target.method!=='dialog'){event.preventDefault();window.submissions.push(Object.fromEntries(new FormData(event.target)));}});},dark);
 const html=execFileSync('php',[path.join(__dirname,'audience-campaign-lifecycle-fixture.php'),role,tab,mode,view],{encoding:'utf8'});
 assert.ok(!/(?:Warning|Fatal error):/.test(html));
 await page.route('http://campaigns.test/**',route=>{const u=new URL(route.request().url());if(u.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:JSON.stringify({unlocked:false,csrf_token:'fixture-token',server_now:Date.now()/1000})});if(u.pathname.startsWith('/assets/')){const file=path.join(root,'src',u.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'image/svg+xml'}):route.fulfill({status:404,body:''});}return route.fulfill({contentType:'text/html',body:html});});
 await page.goto(`http://campaigns.test/audience.php?view=${view}&trash=${tab==='archived'?'1':'0'}`);return {page,errors};
};
try{
 for(const dark of [false,true])for(const width of [390,1600])for(const role of ['admin','editor','reviewer'])for(const tab of ['active','archived']){
  const {page,errors}=await open({dark,width,role,tab});const row=page.locator('.campaign-row-actions').first();await row.scrollIntoViewIfNeeded();
  assert.equal(await row.locator('[data-campaign-op=delete]').count(),role==='admin'&&tab==='archived'?1:0);
  assert.equal(await row.locator('[data-campaign-op=archive]').count(),role!=='reviewer'&&tab==='active'?1:0);
  assert.equal(await row.locator('[data-campaign-op=restore]').count(),role!=='reviewer'&&tab==='archived'?1:0);
  assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);
  for(const button of await row.locator('a,button').all()){assert.ok(await button.getAttribute('aria-label'));assert.ok(await button.getAttribute('data-tooltip'));assert.equal(await button.locator('svg').count(),1);const box=await button.boundingBox();assert.equal(box.height,44);assert.equal(box.width,44);await button.hover();await page.waitForTimeout(180);assert.equal(await button.evaluate(n=>getComputedStyle(n).backgroundColor),dark?'rgb(19, 78, 74)':'rgb(204, 251, 241)');}
  if(tab==='active'){
   const edit=await row.locator('[data-campaign-action=edit]').boundingBox(),report=await page.locator('.campaign-row-actions').nth(2).locator('[data-campaign-action=report]').boundingBox();assert.equal(edit.x-report.x,52,'Report is one icon column left of Edit');
  }
  if(role!=='reviewer'){
   await page.locator('[data-campaign-select-item][value="8"]').check();
   const operation=tab==='active'?'archive':'restore';await row.locator(`[data-campaign-op=${operation}]`).click();const dialog=page.locator(`#campaign-${operation}`);await dialog.waitFor({state:'visible'});assert.equal(await dialog.locator('[name="selected[]"]').inputValue(),'7');assert.equal(await dialog.locator('[name="versions[7]"]').inputValue(),'3');await dialog.locator('[data-close-dialog]').last().click();assert.equal(await page.evaluate(()=>window.submissions.length),0);
   await row.locator(`[data-campaign-op=${operation}]`).click();await dialog.locator('button.primary').click();assert.equal(await page.evaluate(()=>window.submissions.at(-1).operation),operation);await dialog.locator('[data-close-dialog]').last().click();assert.equal(await page.locator('[data-campaign-select-item][value="8"]').isChecked(),true);
   await page.locator(`[data-campaign-lifecycle-selected=${operation}]`).click();assert.equal(await dialog.locator('[name="selected[]"]').inputValue(),'8');assert.match(await dialog.locator('[data-campaign-lifecycle-summary]').textContent(),/1 selected/);await dialog.locator('[data-close-dialog]').last().click();
  }
  if(role==='admin'&&tab==='archived'){
   const dialog=page.locator('#campaign-delete'),button=row.locator('[data-campaign-op=delete]');assert.equal(await button.getAttribute('data-admin-unlock-required'),'');
   await page.evaluate(()=>{window.unlockAllowed=false;window.unlockCalls=0;window.DnrAdminUnlock={request:async()=>{await new Promise(resolve=>setTimeout(resolve,1));window.unlockCalls++;if(window.unlockAllowed)document.querySelectorAll('[name=csrf_token]').forEach(n=>n.value='rotated-token');return window.unlockAllowed;}};});
   await button.click();await page.waitForFunction(()=>window.unlockCalls===1);assert.equal(await dialog.isVisible(),false);await page.evaluate(()=>window.unlockAllowed=true);await button.click();await dialog.waitFor({state:'visible'});assert.equal(await dialog.locator('[name=confirm]').inputValue(),'');
   const before=await page.evaluate(()=>window.submissions.length);await dialog.locator('[name=confirm]').fill('wrong');await dialog.getByRole('button',{name:'Permanently delete',exact:true}).click();assert.equal(await page.evaluate(()=>window.submissions.length),before);
   await dialog.locator('[name=confirm]').fill('DELETE');await page.evaluate(()=>window.unlockAllowed=false);const deniedCalls=await page.evaluate(()=>window.unlockCalls);await dialog.getByRole('button',{name:'Permanently delete',exact:true}).click();await page.waitForFunction(calls=>window.unlockCalls>calls,deniedCalls);assert.equal(await page.evaluate(()=>window.submissions.length),before);
   await page.evaluate(()=>window.unlockAllowed=true);await dialog.getByRole('button',{name:'Permanently delete',exact:true}).click();await page.waitForFunction(before=>window.submissions.length===before+1,before);assert.equal(await page.evaluate(()=>window.submissions.at(-1).csrf_token),'rotated-token');assert.equal(await page.evaluate(()=>window.submissions.at(-1).operation),'delete');assert.equal(await page.evaluate(()=>window.submissions.at(-1)['selected[]']),'7');await dialog.locator('[data-close-dialog]').last().click();
   await button.click();await dialog.waitFor({state:'visible'});assert.equal(await dialog.locator('[name=confirm]').inputValue(),'');await dialog.locator('[data-close-dialog]').last().click();
  }
  const nav=page.getByRole('navigation',{name:'Campaign pages'});assert.equal(await nav.locator('.mm-pagination-arrow:disabled').count(),2);assert.equal(await nav.locator('.mm-pagination-range').textContent(),'1–3 of 3');
  if(process.env.DNR_BROWSER_SCREENSHOT_DIR&&role==='admin'&&width===1600){fs.mkdirSync(process.env.DNR_BROWSER_SCREENSHOT_DIR,{recursive:true});await page.mouse.move(1,1);await page.screenshot({path:path.join(process.env.DNR_BROWSER_SCREENSHOT_DIR,`campaigns-${tab}${dark?'-dark':''}.png`),fullPage:true});}assert.deepEqual(errors,[]);checks++;await page.close();
 }
 for(const dark of [false,true])for(const width of [390,1600]){
  const {page,errors}=await open({dark,width,tab:'archived',mode:'middle'});const nav=page.getByRole('navigation',{name:'Campaign pages'});assert.equal(await nav.locator('.mm-pagination-range').textContent(),'26–50 of 70');const links=nav.locator('a');assert.equal(await links.count(),2);for(const link of await links.all()){assert.match(await link.getAttribute('href'),/trash=1/);assert.match(await link.textContent(),/[←→]/);const box=await link.boundingBox();assert.equal(box.height,40);}
  const count=await nav.locator('.mm-pagination-range').boundingBox(),control=await links.first().boundingBox();if(width===1600)assert.equal(count.y+count.height/2,control.y+control.height/2);assert.deepEqual(errors,[]);checks++;await page.close();
  const {page:empty}=await open({dark,width,mode:'empty'});assert.equal(await empty.locator('.mm-pagination-range').textContent(),'0 of 0');assert.equal(await empty.locator('.mm-pagination-arrow:disabled').count(),2);checks++;await empty.close();
  const {page:home,errors:homeErrors}=await open({dark,width,view:'home'});const buttons=home.locator('.audience-home-hover,.analytics-quick-actions>.audience-button');assert.equal(await buttons.count(),5);for(const button of await buttons.all()){await button.hover();await home.waitForTimeout(180);assert.equal(await button.evaluate(n=>getComputedStyle(n).backgroundColor),dark?'rgb(19, 78, 74)':'rgb(204, 251, 241)');await button.focus();assert.equal(await button.evaluate(n=>getComputedStyle(n).backgroundColor),dark?'rgb(19, 78, 74)':'rgb(204, 251, 241)');}assert.deepEqual(homeErrors,[]);checks++;await home.close();
 }
 console.log(`Campaign lifecycle/browser checks passed: ${checks} layouts (roles, archive/restore, delete unlock and expiry, context, report alignment, shared pagination and Home hovers)`);
}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
