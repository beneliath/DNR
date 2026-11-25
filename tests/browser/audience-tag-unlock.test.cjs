'use strict';
const {expandRowActions, assertActionGeometry}=require('./helpers/responsive-workspace.cjs');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const dark of [false,true])for(const width of [320,390,768,1600])for(const role of ['admin','editor','reviewer']){
  const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(5000);const posts=[],errors=[];let unlocked=false;page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
  await page.route('http://tags.test/**',route=>{const request=route.request(),url=new URL(request.url());
   if(['/admin_elevation.php','/admin_unlock_status.php'].includes(url.pathname)){if(request.method()==='POST')unlocked=true;return route.fulfill({contentType:'application/json',body:JSON.stringify({unlocked,csrf_token:unlocked?'rotated-token':'fixture-token',server_now:Date.now()/1000,expires_at:unlocked?Date.now()/1000+300:null})});}
   if(url.pathname==='/audience.php'){if(request.method()==='POST'){posts.push(Object.fromEntries(new URLSearchParams(request.postData())));return route.fulfill({contentType:'text/html',body:'<!doctype html><p>Fixture deletion submitted</p>'});}return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/browser/audience-tag-actions-fixture.php',role,'active','locked','2',url.searchParams.toString()],{cwd:root,encoding:'utf8'})});}
   const file=path.join(root,'src',url.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  const tagsUrl='http://tags.test/audience.php?view=tags';
  const alphabeticalTags=['Community friends & volunteers with a longer tag name','Newsletter subscribers'];
  if(!dark&&width===1600&&role==='admin'){
   const names=page.locator('.audience-tag-list .tag-label');
   const nameHeader=page.locator('.audience-tag-list th.tag-name');
   await page.goto(tagsUrl);
   assert.deepEqual(await names.allTextContents(),alphabeticalTags);
   assert.equal(await nameHeader.getAttribute('aria-sort'),'ascending');
   assert.equal(await page.locator('#tag-sort').inputValue(),'name');
   await nameHeader.getByRole('link').click();
   assert.deepEqual(await names.allTextContents(),[...alphabeticalTags].reverse());
   await page.getByRole('link',{name:'Clear All',exact:true}).click();
   assert.deepEqual(await names.allTextContents(),alphabeticalTags);
   for(const [status,values] of [['active',['24','128']],['archived',['0','15']]]){
    const header=page.locator(`th.tag-count-${status}`);
    const counts=page.locator(`td.tag-count-${status} a`);
    await header.getByRole('link').click();
    assert.equal(await header.getAttribute('aria-sort'),'ascending');
    assert.deepEqual(await counts.allTextContents(),values);
    await header.getByRole('link').click();
    assert.equal(await header.getAttribute('aria-sort'),'descending');
    assert.deepEqual(await counts.allTextContents(),[...values].reverse());
    assert.equal(await page.locator('#tag-sort').inputValue(),status+'_desc');
   }
  }
  const go=async(expand=true)=>{await page.goto(tagsUrl+'&tag_sort=newest');if(expand)await expandRowActions(page.locator('.audience-tag-actions').first());};await go(false);
  if(role==='admin'){assert.equal(await page.locator('#definition-selection .audience-check').evaluate(n=>getComputedStyle(n).fontWeight),'400');if(width===1600){const boxes=await Promise.all([page.getByLabel('Search Tags',{exact:true}),page.locator('#definition-selection'),page.locator('#tag-sort')].map(n=>n.boundingBox()));assert.ok(Math.max(...boxes.map(b=>b.y+b.height/2))-Math.min(...boxes.map(b=>b.y+b.height/2))<2,'Search, selection and sorting share a toolbar row');}}
  const deletion=page.locator('.audience-tag-actions [data-tag-delete-form]');assert.equal(await deletion.count(),role==='admin'?2:0);assert.equal(await page.locator('[data-definition-item]').count(),role==='admin'?2:0);
  const rowActions=page.locator('.audience-tag-actions').first(),icons=rowActions.locator('.action-icon-button');
  assert.equal(await page.locator('.audience-tag-list tbody tr').count(),2);assert.equal(await icons.count(),role==='admin'?5:role==='editor'?4:0);const mobile=width<861;await expandRowActions(rowActions,true);assert.equal(await icons.filter({visible:true}).count(),await icons.count());await assertActionGeometry(page,icons,mobile);
  const table=page.locator('.audience-tag-list'),firstTag=table.locator('tbody tr').first();
  assert.deepEqual((await table.locator('thead th.tag-count a').allTextContents()).map(text=>text.replace(/\s[↑↓↕]$/,'')),['Active','Archived']);
  assert.equal(await table.locator('thead th').filter({hasText:/Created/}).count(),0);
  assert.deepEqual(await firstTag.locator('.tag-count').allTextContents(),['128','15']);
  const activeCount=firstTag.locator('.tag-count-active a'),archivedCount=firstTag.locator('.tag-count-archived a');
  for(const [link,tagId,archived] of [[activeCount,'7','0'],[archivedCount,'7','1'],[table.locator('tbody tr').nth(1).locator('.tag-count-archived a'),'8','1']]){
   const target=new URL(await link.getAttribute('href'),page.url());
   assert.equal(target.pathname,'/audience.php');
   assert.equal(target.searchParams.get('view'),'contacts');
   assert.equal(target.searchParams.get('tags[0]'),tagId);
   assert.equal(target.searchParams.get('archived'),archived);
  }
  const details=firstTag.getByRole('button',{name:'Details for Newsletter subscribers'});
  if(mobile)await details.click();
  assert.equal(await firstTag.locator('.tag-count:visible').count(),2);
  if(mobile)await details.click();
  assert.equal(await firstTag.locator('.tag-name a').count(),0);
  assert.equal(await firstTag.locator('.tag-label').evaluate(n=>getComputedStyle(n).fontWeight),'400');
  let previous=null;for(const icon of await icons.all()){assert.ok(await icon.getAttribute('aria-label'));assert.ok(await icon.getAttribute('data-tooltip'));const box=await icon.boundingBox();if(previous){if(!mobile)assert.equal(box.y,previous.y,'Desktop action icons share one row');if(box.y===previous.y)assert.ok(box.x-previous.x-previous.width>=(mobile?7.9:15.9),'Action icons preserve the mobile/desktop spacing');}assert(box.x>=0&&box.x+box.width<=width,'Action icons remain reachable on small screens');previous=box;}
  if(role==='admin')assert.equal(await icons.last().getAttribute('data-admin-unlock-required'),'','Delete is the final action');
  if(role==='admin'){const selection=page.locator('#definition-selection');assert.equal(await selection.getByText('Select All on This Page',{exact:true}).isVisible(),true);await selection.locator('[data-definition-all]').check();assert.equal(await page.locator('[data-definition-item]:checked').count(),2);assert.equal(await selection.locator('[data-definition-count]').textContent(),'2 Selected');await selection.getByRole('button',{name:'Clear Selection',exact:true}).click();assert.equal(await page.locator('[data-definition-item]:checked').count(),0);assert.equal(await selection.locator('[data-definition-all]').isVisible(),true);}
  assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);
  if(role!=='reviewer'){
   await (mobile?details:activeCount).focus();await page.keyboard.press('Tab');
   if(!mobile){
    assert.equal(await archivedCount.evaluate(n=>n===document.activeElement),true,'Keyboard focus moves from the Active count to the Archived count');
    await page.keyboard.press('Tab');
   }
   const renameButton=rowActions.locator('[data-tag-edit]');assert.equal(await renameButton.evaluate(n=>n===document.activeElement),true);await page.locator('#'+await renameButton.getAttribute('aria-describedby')).waitFor({state:'visible'});
   await renameButton.click();const rename=page.locator('#tag-editor');await rename.waitFor({state:'visible'});assert.equal(await rename.locator('[name=id]').inputValue(),'7');assert.equal(await rename.locator('[name=version]').inputValue(),'3');await rename.getByRole('button',{name:'Cancel',exact:true}).click();
   for(const type of ['regular','plain-text'])assert.match(await rowActions.locator(`a[href*="email_type=${type}"]`).getAttribute('href'),new RegExp(`target=tag%3A7&email_type=${type}`));
   await icons.first().hover();await page.waitForTimeout(180);assert.equal(await icons.first().evaluate(n=>getComputedStyle(n).backgroundColor),dark?'rgb(19, 78, 74)':'rgb(204, 251, 241)');
   await rowActions.locator('[data-tooltip="Export as ZIP"]').click();await page.getByText('Fixture deletion submitted').waitFor();assert.equal(posts.at(-1).action,'queue_export');assert.equal(posts.at(-1)['tags[0]'],'7');posts.length=0;await go();
  }
  if(role==='admin'){
   const unlock=page.locator('#admin-unlock-dialog'),confirm=page.getByRole('dialog',{name:'Confirm Action'}),remove=deletion.first().locator('button');
   const removeTag=()=>remove.click();
   await removeTag();await unlock.waitFor({state:'visible'});assert.equal(await confirm.isVisible(),false);await unlock.locator('[data-unlock-cancel]').click();assert.equal(posts.length,0);
   // The initial access check sets focus; finish it before entering credentials.
   const enter=async()=>{await unlock.waitFor({state:'visible'});await page.waitForFunction(()=>{const submit=document.querySelector('#admin-unlock-dialog [data-unlock-submit]');return submit&&!submit.disabled&&!submit.hasAttribute('aria-busy');});await unlock.locator('[name=admin_password]').fill('fixture-password');await unlock.locator('[name=admin_code]').fill('123456');await unlock.locator('[data-unlock-submit]').click();};
   await removeTag();await enter();await confirm.waitFor({state:'visible'});assert.match(await confirm.textContent(),/Connections will be kept/);await confirm.getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(posts.length,0);
   await removeTag();await confirm.waitFor({state:'visible'});unlocked=false;await confirm.getByRole('button',{name:'Continue',exact:true}).click();await unlock.waitFor({state:'visible'});assert.equal(posts.length,0,'Expiry is checked again at final submission');await unlock.locator('[data-unlock-cancel]').click();
   await removeTag();await enter();await confirm.waitFor({state:'visible'});await confirm.getByRole('button',{name:'Continue',exact:true}).click();await page.getByText('Fixture deletion submitted').waitFor();assert.equal(posts.length,1);assert.equal(posts[0].action,'delete_tag');assert.equal(posts[0].id,'7');assert.equal(posts[0].version,'3');assert.equal(posts[0].csrf_token,'rotated-token');
   await go();unlocked=false;await page.locator('[data-definition-item]').first().check();const bulk=page.locator('#definition-selection button[type=submit]');await bulk.click();await unlock.waitFor({state:'visible'});await unlock.locator('[data-unlock-cancel]').click();assert.equal(posts.length,1);
   await bulk.click();await enter();await confirm.waitFor({state:'visible'});await confirm.getByRole('button',{name:'Continue',exact:true}).click();await page.getByText('Fixture deletion submitted').waitFor();assert.equal(posts.length,2);assert.equal(posts[1].action,'delete_selected_definitions');assert.equal(posts[1].kind,'tags');assert.equal(posts[1]['selected_ids[]'],'7');assert.equal(posts[1]['versions[7]'],'3');assert.equal(posts[1].csrf_token,'rotated-token');
  }
  if(!mobile && role!=='reviewer'){
   for(const status of ['active','archived']){
    await page.goto(tagsUrl+'&definition_status='+status);
    const table=page.locator('.audience-tag-list');
    assert.deepEqual(await table.locator('.tag-label').allTextContents(),alphabeticalTags);
    const headerRight=await table.locator('th.tag-actions').evaluate(cell=>
     cell.getBoundingClientRect().right-parseFloat(getComputedStyle(cell).paddingInlineEnd));
    const groups=table.locator('td.tag-actions .audience-tag-actions');
    assert.equal(await groups.count(),2);
    for(const group of await groups.all()){
     const last=await group.locator('.action-icon-button').last().boundingBox();
     assert.ok(Math.abs(last.x+last.width-headerRight)<1,'The last '+status+' tag action lines up with the Actions heading');
    }
   }
  }
  assert.deepEqual(errors,[]);checks++;await page.close();
 }
 console.log(`Tag actions browser: ${checks} role/theme/layout checks passed (visible icons, spacing, Delete last, tooltips, keyboard, rename, export, draft links, single/bulk deletion, unlock cancellation, expiry and CSRF rotation)`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
