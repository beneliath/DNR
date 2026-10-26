'use strict';
// Synthetic sessions and intercepted requests exercise real management templates.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');const root=path.resolve(__dirname,'../..');
const fixture=(view,role)=>execFileSync('php',['tests/browser/audience-tag-actions-fixture.php',role,'active','locked',view==='add-person'?'27':'2','','',view],{cwd:root,encoding:'utf8'});
let activeCase='';
const actions={groups:'delete_group',fields:'delete_field',jobs:'delete_export_history',job:'undo_import'};
const views=process.argv.slice(2);if(views.some(view=>!Object.hasOwn(actions,view)))throw new Error('Unknown management view.');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const dark of [false,true])for(const width of [390,1600])for(const view of (views.length?views:Object.keys(actions))){
  activeCase=`${view} ${width}px ${dark?'dark':'light'}`;
  const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(6000);const posts=[],errors=[];let unlocked=false;
  page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
  await page.route('**/*',route=>{
   const request=route.request(),url=new URL(request.url());if(url.origin!=='https://management.test')return route.abort();
   if(['/admin_elevation.php','/admin_unlock_status.php'].includes(url.pathname)){
    if(request.method()==='POST')unlocked=true;
    return route.fulfill({contentType:'application/json',body:JSON.stringify({unlocked,csrf_token:unlocked?'rotated-token':'fixture-token',server_now:Date.now()/1000,expires_at:unlocked?Date.now()/1000+300:null})});
   }
   if(url.pathname==='/audience.php'){
    if(request.method()==='POST'){posts.push(Object.fromEntries(new URLSearchParams(request.postData())));return route.fulfill({contentType:'text/html',body:'<!doctype html><p>Protected fixture submitted</p>'});}
    return route.fulfill({contentType:'text/html',body:fixture(url.searchParams.get('view')||view,url.searchParams.get('role')||'admin')});
   }
   if(url.pathname==='/campaign-fixture.php')return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/browser/audience-campaign-lifecycle-fixture.php','admin'],{cwd:root,encoding:'utf8'})});
   const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  await page.goto('https://management.test/audience.php?view='+view);
  if(view==='groups'){
   assert.equal(await page.locator('.audience-group-grid article').count(),3);assert.equal(await page.locator('.audience-group-grid tbody tr:visible').count(),6);
   const boxes=await page.locator('.audience-group-grid article').evaluateAll(nodes=>nodes.map(n=>({x:n.getBoundingClientRect().x,y:n.getBoundingClientRect().y})));
   const widths=await page.locator('.audience-group-grid article').evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().width));assert(widths.every(value=>value<=400.1));
   const originalWidth=widths[0];await page.locator('.audience-group-grid article').evaluateAll(nodes=>nodes.slice(1).forEach(n=>n.remove()));assert(Math.abs((await page.locator('.audience-group-grid article').first().boundingBox()).width-originalWidth)<1);checks+=2;
   assert.equal(boxes[0].y===boxes[1].y,width===1600);assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);checks+=3;
   if(process.env.DNR_REVIEW_OUTPUT){fs.mkdirSync(process.env.DNR_REVIEW_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,`groups-${width}-${dark?'dark':'light'}.png`)});}
  }
  const remove=()=>view==='groups'?page.locator('[data-audience-protected-form] button').first():view==='fields'?page.locator('[data-audience-protected-form] button'):view==='jobs'?page.locator('[data-export-delete]'):page.getByRole('button',{name:'Undo import',exact:true});
  if(view==='fields'){
   assert.equal(await page.locator('[popovertarget^="field-actions-"]').count(),0);assert.equal(await remove().isVisible(),true);
   const edit=page.getByRole('button',{name:'Edit Interest',exact:true});assert.equal(await edit.getAttribute('data-tooltip'),'Edit');assert.equal(await remove().getAttribute('data-tooltip'),'Delete');
   await edit.click();const editor=page.locator('#field-editor');await editor.waitFor({state:'visible'});assert.equal(await editor.locator('[name=label]').inputValue(),'Interest');await editor.getByRole('button',{name:'Cancel',exact:true}).click();checks+=5;
  }
  const triggerRemove=async()=>{await remove().click();};
  const unlock=page.locator('#admin-unlock-dialog'),confirmation=page.getByRole('dialog',{name:'Confirm action',exact:true});
  const authenticate=async()=>{await unlock.locator('[name=admin_password]').fill('fixture-password');await unlock.locator('[name=admin_code]').fill('123456');await unlock.locator('[data-unlock-submit]').click();};
  await triggerRemove();await unlock.waitFor({state:'visible'});assert.equal(posts.length,0);await unlock.locator('[data-unlock-cancel]').click();checks++;
  await triggerRemove();await authenticate();await confirmation.waitFor({state:'visible'});unlocked=false;await confirmation.getByRole('button',{name:'Continue',exact:true}).click();await unlock.waitFor({state:'visible'});assert.equal(posts.length,0);await unlock.locator('[data-unlock-cancel]').click();checks++;
  await triggerRemove();await authenticate();await confirmation.waitFor({state:'visible'});await confirmation.getByRole('button',{name:'Continue',exact:true}).click();await page.getByText('Protected fixture submitted',{exact:true}).waitFor();assert.equal(posts.length,1);assert.equal(posts[0].action,actions[view]);assert.equal(posts[0].csrf_token,'rotated-token');assert.deepEqual(errors,[]);checks+=4;
  if(view==='groups'){
   for(const role of ['editor','reviewer']){await page.goto(`https://management.test/audience.php?view=groups&role=${role}`);assert.equal(await page.locator('[data-audience-protected-form]').count(),0);assert.equal(await page.locator('.audience-group-grid [data-group-edit]').count(),role==='editor'?3:0);checks++;}
   await page.goto('https://management.test/audience.php?view=tags');await page.locator('#unsubscribe-emails').evaluate(el=>el.showModal());
   assert.equal(await page.locator('#unsubscribe-emails [name=action]').inputValue(),'unsubscribe_emails');assert.equal(await page.locator('#unsubscribe-emails [name=emails]').count(),1);assert.equal(await page.getByRole('button',{name:'Unsubscribe',exact:true}).isVisible(),true);checks++;
   await page.goto('https://management.test/audience.php?view=add-person');await page.locator('#add-person').evaluate(el=>el.showModal());await page.locator('.additional-person-details>summary').click();
   const tags=page.locator('.audience-contact-tags');await tags.scrollIntoViewIfNeeded();const tagBoxes=await tags.locator('label').evaluateAll(nodes=>nodes.slice(0,2).map(n=>({x:n.getBoundingClientRect().x,y:n.getBoundingClientRect().y})));assert.equal(tagBoxes[0].x===tagBoxes[1].x,width===390);assert.equal(await tags.evaluate(el=>el.scrollWidth>el.clientWidth),false);await tags.locator('input').first().check();assert.equal(await tags.locator('input').first().isChecked(),true);checks+=3;
   if(process.env.DNR_REVIEW_OUTPUT)await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,`connection-tags-${width}-${dark?'dark':'light'}.png`)});
   await page.goto('https://management.test/campaign-fixture.php');await page.locator('.campaign-list-create').click();const back=page.getByRole('button',{name:'Exit campaign creation'});const arrow=await back.boundingBox();assert(arrow&&arrow.x>=0&&arrow.x+arrow.width<=width);await back.click();assert.equal(await page.locator('#campaign-create').isVisible(),false);assert.equal(await page.locator('.campaign-list-create').evaluate(el=>el===document.activeElement),true);checks+=3;
  }
  await page.close();
 }
 console.log('Audience management/browser unlock checks passed: '+checks);
}finally{await browser.close();}})().catch(error=>{console.error(activeCase,error);process.exitCode=1;});
