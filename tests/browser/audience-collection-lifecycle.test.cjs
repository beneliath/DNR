'use strict';
// Exercise the real PHP templates and shared unlock UI; all requests use synthetic data.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),output=process.env.DNR_REVIEW_OUTPUT;
const fixture=(role,tab,view='forms')=>execFileSync('php',['tests/browser/audience-collection-lifecycle-fixture.php',role,tab,'locked',view],{cwd:root,encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true]){
 const page=await browser.newPage({viewport:{width:1600,height:1000}});page.setDefaultTimeout(5000);const posts=[],errors=[];let unlocked=false;
 page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
 await page.route('http://forms.test/**',route=>{const request=route.request(),url=new URL(request.url());
  if(['/admin_elevation.php','/admin_unlock_status.php'].includes(url.pathname)){if(request.method()==='POST')unlocked=true;return route.fulfill({contentType:'application/json',body:JSON.stringify({unlocked,csrf_token:unlocked?'rotated-token':'fixture-token',server_now:Date.now()/1000,expires_at:unlocked?Date.now()/1000+300:null})});}
  if(url.pathname==='/audience.php'){if(request.method()==='POST'){posts.push(Object.fromEntries(new URLSearchParams(request.postData())));return route.fulfill({contentType:'text/html',body:'<!doctype html><p>Synthetic request received</p>'});}return route.fulfill({contentType:'text/html',body:fixture(url.searchParams.get('role')||'admin',url.searchParams.get('tab')||'active',url.searchParams.get('view')||'forms')});}
  const file=path.join(root,'src',url.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'image/svg+xml'}):route.fulfill({status:404,body:''});
 });
 const go=async(role='admin',tab='active',view='forms')=>{await page.goto(`http://forms.test/audience.php?role=${role}&tab=${tab}&view=${view}`);await page.locator('.collection-table').waitFor();};
 for(const role of ['admin','editor','reviewer'])for(const tab of ['active','archived']){
  await go(role,tab);assert.equal(await page.locator('[data-collection-lifecycle="archive"]').count(),role!=='reviewer'&&tab==='active'?2:0);assert.equal(await page.locator('[data-collection-lifecycle="delete"]').count(),role==='admin'&&tab==='archived'?2:0);assert.equal(await page.locator('.collection-row-actions [name=operation][value=restore]').count(),role!=='reviewer'&&tab==='archived'?2:0);checks++;
 }
 for(const view of ['forms','landing-pages','surveys','preferences','form-settings'])for(const width of [390,1024,1600]){
  await page.setViewportSize({width,height:1000});await go('admin','archived',view);assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false,view+' keeps wide tables inside their scroller');assert.equal(await page.locator('a[aria-current=page]').filter({hasText:'Archived'}).count(),1);checks++;
 }
 await page.setViewportSize({width:1600,height:1000});await go('editor');
 const archive=page.locator('[data-collection-lifecycle="archive"]').first();await archive.click();const archiveDialog=page.locator('#collection-archive');await archiveDialog.waitFor({state:'visible'});assert.equal(await archiveDialog.locator('[name=id]').inputValue(),'7');await archiveDialog.locator('[data-close-dialog]').last().click();assert.equal(posts.length,0);checks++;
 if(output){fs.mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,'forms-active'+(dark?'-dark':'')+'.png')});}
 await archive.click();await archiveDialog.locator('button[type=submit]').click();await page.waitForFunction(()=>document.body.textContent.includes('Synthetic request received'));assert.equal(posts.at(-1).operation,'archive');assert.equal(posts.at(-1).version,'3');checks++;
 await go('editor','archived');await page.locator('.collection-row-actions form button').first().click();await page.waitForFunction(()=>document.body.textContent.includes('Synthetic request received'));assert.equal(posts.at(-1).operation,'restore');checks++;
 unlocked=false;await go('admin','archived');
 if(output)await page.screenshot({path:path.join(output,'forms-archived'+(dark?'-dark':'')+'.png')});
 const remove=page.locator('[data-collection-lifecycle="delete"]').first(),unlock=page.locator('#admin-unlock-dialog'),confirmation=page.locator('#collection-delete');const before=posts.length;
 await remove.click();await unlock.waitFor({state:'visible'});assert.equal(await confirmation.isVisible(),false);await unlock.locator('[data-unlock-cancel]').click();assert.equal(posts.length,before);checks++;
 await remove.click();await unlock.locator('[name=admin_password]').fill('fixture-password');await unlock.locator('[name=admin_code]').fill('123456');await unlock.locator('[data-unlock-submit]').click();await confirmation.waitFor({state:'visible'});assert.equal(await confirmation.locator('[name=csrf_token]').inputValue(),'rotated-token');assert.equal(await confirmation.locator('[data-collection-lifecycle-name]').textContent(),'Newsletter signup');
 await confirmation.locator('[name=confirm]').fill('DELETE');await confirmation.locator('[data-close-dialog]').last().click();assert.equal(posts.length,before);checks++;
 await remove.click();await confirmation.waitFor({state:'visible'});assert.equal(await confirmation.locator('[name=confirm]').inputValue(),'');await confirmation.locator('button[type=submit]').click();assert.equal(posts.length,before,'Empty confirmation cannot submit');
 if(output)await page.screenshot({path:path.join(output,'forms-delete-confirmation'+(dark?'-dark':'')+'.png')});
 await confirmation.locator('[name=confirm]').fill('DELETE');unlocked=false;await confirmation.locator('button[type=submit]').click();await unlock.waitFor({state:'visible'});assert.equal(posts.length,before,'Expired unlock is rechecked before deletion');await unlock.locator('[data-unlock-cancel]').click();checks++;
 await confirmation.locator('button[type=submit]').click();await unlock.waitFor({state:'visible'});await unlock.locator('[name=admin_password]').fill('fixture-password');await unlock.locator('[name=admin_code]').fill('123456');await unlock.locator('[data-unlock-submit]').click();await page.waitForFunction(()=>document.body.textContent.includes('Synthetic request received'));assert.equal(posts.length,before+1);assert.equal(posts.at(-1).operation,'delete');assert.equal(posts.at(-1).confirm,'DELETE');assert.equal(posts.at(-1).csrf_token,'rotated-token');assert.deepEqual(errors,[]);checks++;
 await page.close();
}console.log('Form lifecycle browser checks passed: '+checks+' (roles, responsive layouts, light/dark, archive/restore/delete and unlock expiry)');}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
