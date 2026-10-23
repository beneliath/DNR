'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const dark of [false,true])for(const width of [390,1600])for(const role of ['admin','editor','reviewer']){
  const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(5000);const posts=[],errors=[];let unlocked=false;page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
  await page.route('http://tags.test/**',route=>{const request=route.request(),url=new URL(request.url());
   if(['/admin_elevation.php','/admin_unlock_status.php'].includes(url.pathname)){if(request.method()==='POST')unlocked=true;return route.fulfill({contentType:'application/json',body:JSON.stringify({unlocked,csrf_token:unlocked?'rotated-token':'fixture-token',server_now:Date.now()/1000,expires_at:unlocked?Date.now()/1000+300:null})});}
   if(url.pathname==='/audience.php'){if(request.method()==='POST'){posts.push(Object.fromEntries(new URLSearchParams(request.postData())));return route.fulfill({contentType:'text/html',body:'<!doctype html><p>Fixture deletion submitted</p>'});}return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/browser/audience-tag-actions-fixture.php',role],{cwd:root,encoding:'utf8'})});}
   const file=path.join(root,'src',url.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  const go=()=>page.goto('http://tags.test/audience.php?view=tags');await go();
  const deletion=page.locator('.audience-tag-actions [data-tag-delete-form]');assert.equal(await deletion.count(),role==='admin'?2:0);assert.equal(await page.locator('[data-definition-item]').count(),role==='admin'?2:0);assert.equal(await page.locator('.audience-definition-actions').first().locator('.action-icon-button').count(),role==='admin'?6:role==='editor'?5:1);
  assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);
  if(role==='admin'){
   const unlock=page.locator('#admin-unlock-dialog'),confirm=page.getByRole('dialog',{name:'Confirm action'}),remove=deletion.first().locator('button');
   await remove.click();await unlock.waitFor({state:'visible'});assert.equal(await confirm.isVisible(),false);await unlock.locator('[data-unlock-cancel]').click();assert.equal(posts.length,0);
   const enter=async()=>{await unlock.waitFor({state:'visible'});await unlock.locator('[name=admin_password]').fill('fixture-password');await unlock.locator('[name=admin_code]').fill('123456');await unlock.locator('[data-unlock-submit]').click();};
   await remove.click();await enter();await confirm.waitFor({state:'visible'});assert.match(await confirm.textContent(),/Connections will be kept/);await confirm.getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(posts.length,0);
   await remove.click();await confirm.waitFor({state:'visible'});unlocked=false;await confirm.getByRole('button',{name:'Continue',exact:true}).click();await unlock.waitFor({state:'visible'});assert.equal(posts.length,0,'Expiry is checked again at final submission');await unlock.locator('[data-unlock-cancel]').click();
   await remove.click();await enter();await confirm.waitFor({state:'visible'});await confirm.getByRole('button',{name:'Continue',exact:true}).click();await page.getByText('Fixture deletion submitted').waitFor();assert.equal(posts.length,1);assert.equal(posts[0].action,'delete_tag');assert.equal(posts[0].id,'7');assert.equal(posts[0].version,'3');assert.equal(posts[0].csrf_token,'rotated-token');
   await go();unlocked=false;await page.locator('[data-definition-item]').first().check();const bulk=page.locator('#definition-selection button[type=submit]');await bulk.click();await unlock.waitFor({state:'visible'});await unlock.locator('[data-unlock-cancel]').click();assert.equal(posts.length,1);
   await bulk.click();await enter();await confirm.waitFor({state:'visible'});await confirm.getByRole('button',{name:'Continue',exact:true}).click();await page.getByText('Fixture deletion submitted').waitFor();assert.equal(posts.length,2);assert.equal(posts[1].action,'delete_selected_definitions');assert.equal(posts[1].kind,'tags');assert.equal(posts[1]['selected_ids[]'],'7');assert.equal(posts[1]['versions[7]'],'3');assert.equal(posts[1].csrf_token,'rotated-token');
  }
  assert.deepEqual(errors,[]);checks++;await page.close();
 }
 console.log(`Tag deletion browser: ${checks} role/theme/layout checks passed (single/bulk, unlock cancellation, confirmation, expiry, CSRF rotation and one submission)`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
