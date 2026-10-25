'use strict';
// Run with Node; the real workspace and unlock UI use synthetic records and mocked HTTP only.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const fixtures=new Map();
function fixture(role,tab,unlocked=false){
 const key=[role,tab,unlocked].join(':');
 if(!fixtures.has(key))fixtures.set(key,execFileSync('php',['tests/browser/audience-template-lifecycle-fixture.php',role,tab,unlocked?'unlocked':'locked'],{cwd:root,encoding:'utf8'}));
 return fixtures.get(key);
}
const frame=page=>page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.DNR_BROWSER_EXECUTABLE?{executablePath:process.env.DNR_BROWSER_EXECUTABLE}:{})});
 try{
  const page=await browser.newPage({viewport:{width:1440,height:1000}});page.setDefaultTimeout(5000);
  let unlocked=false;const posts=[],uploads=[],errors=[];
  const status=()=>({unlocked,expires_at:unlocked?Date.now()/1000+300:0,server_now:Date.now()/1000,csrf_token:unlocked?'fixture-rotated-token':'fixture-locked-token'});
  page.on('pageerror',error=>errors.push(error.message));
  await page.route('http://localhost:43201/**',route=>{
   const request=route.request(),url=new URL(request.url());
   if(url.pathname==='/admin_elevation.php'){
    if(request.method()==='POST')unlocked=true;
    return route.fulfill({contentType:'application/json',body:JSON.stringify(status())});
   }
   if(url.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:JSON.stringify(status())});
   if(url.pathname==='/admin_lock.php'){unlocked=false;return route.fulfill({contentType:'application/json',body:JSON.stringify({...status(),locked:true})});}
   if(url.pathname==='/audience.php'){
    if(request.method()==='POST'){
     if((request.headers()['content-type']||'').startsWith('multipart/form-data'))uploads.push(request.postData());
     else posts.push(Object.fromEntries(new URLSearchParams(request.postData())));
     return route.fulfill({contentType:'text/html',body:'<!doctype html><meta charset="utf-8"><p>Fixture request received</p>'});
    }
    let html=fixture(url.searchParams.get('role')||'admin',url.searchParams.get('tab')||'saved',unlocked);
    if(url.searchParams.has('stack')){
     html=html.replace('</head>','<style>body.audience-app{--deployment-banner-height:68px}</style></head>');
     html=html.replace(/(<body[^>]*>)/,'$1<section class="deployment-notice-banner" data-deployment-notice>Deployment notice</section><section class="account-identity-banner"><strong>Example Account</strong><a href="#">Switch Account</a></section><section class="role-preview-banner" data-role-preview-banner><div class="role-preview-banner-copy"><strong>Administrator preview</strong><span>Example role preview</span></div><form class="role-preview-return-form"><button type="button">Exit preview</button></form></section>');
    }
    return route.fulfill({contentType:'text/html',body:html});
   }
   const file=path.join(root,'src',url.pathname);
   const contentType={'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml','.png':'image/png'}[path.extname(file)];
   return fs.existsSync(file)?route.fulfill({contentType:contentType||'application/octet-stream',body:fs.readFileSync(file)}):route.fulfill({status:404,body:''});
  });
  for(const role of ['admin','editor','reviewer'])for(const tab of ['saved','archived']){
   await page.goto(`http://localhost:43201/audience.php?role=${role}&tab=${tab}`);
   assert.equal(await page.locator('[data-admin-unlock]').count(),role==='admin'?1:0,'Only admins receive unlock status');
   assert.equal(await page.locator('[data-template-operation="delete"]').count(),role==='admin'&&tab==='archived'?1:0,'Only archived admin cards expose permanent deletion');
   const lifecycle=page.locator('.template-lifecycle-action');
   assert.equal(await lifecycle.count(),role==='reviewer'?0:(role==='admin'&&tab==='archived'?2:1));
   for(const button of await lifecycle.all())assert.equal(await button.locator('svg').count(),1,'Archive/restore/delete controls have icons');
   assert.equal(await page.locator('[data-tooltip="Export"]').count(),role==='reviewer'?0:1,'Editors and administrators can export active and archived cards');
   assert.equal(await page.locator('[data-open-dialog="template-package-import"]').count(),role==='reviewer'?0:1,'Only editors and administrators can import');
  }
  await page.goto('http://localhost:43201/audience.php?role=editor&tab=saved');
  const rows=await page.locator('.template-card-actions').first().evaluate(node=>{
   const box=node.getBoundingClientRect(),rows=new Map();
   for(const child of node.children){const rect=child.getBoundingClientRect();if(!rect.width||!rect.height)continue;const key=Math.round(rect.top),row=rows.get(key)||[];row.push({left:rect.left,right:rect.right});rows.set(key,row);}
   return [...rows.values()].map(row=>({offset:(row[0].left+row.at(-1).right)/2-(box.left+box.right)/2,gaps:row.slice(1).map((rect,index)=>rect.left-row[index].right)}));
  });
  assert.ok(rows.length>1,'Template actions wrap into multiple rows');
  for(const row of rows){assert.ok(Math.abs(row.offset)<2,'Each row of card actions is centered');for(const gap of row.gaps)assert.ok(Math.abs(gap-16)<1,'Centered actions preserve 16px spacing');}
  await page.locator('[data-open-dialog="template-package-import"]').click();
  await page.locator('#template-package-import').waitFor({state:'visible'});
  await page.locator('[name=template_package]').setInputFiles({name:'exported.mmtemplate',mimeType:'application/octet-stream',buffer:Buffer.from('{"format":"fixture-export"}')});
  assert.ok(await page.locator('#template-package-import').innerText().then(text=>text.includes('exported.mmtemplate')),'File drop shows the selected export filename');
  await page.locator('#template-package-import button[type=submit]').click();
  await page.waitForURL(url=>url.pathname==='/audience.php'&&uploads.length===1);
  assert.ok(uploads[0].includes('name="template_package"; filename="exported.mmtemplate"')&&uploads[0].includes('fixture-export'),'Enhanced file input submits the selected file through multipart upload');
  assert.ok(uploads[0].includes('template_package_import'),'Import form submits its native import action');
  await page.goto('http://localhost:43201/audience.php?role=admin&tab=archived&stack=1');
  const deleteButton=page.locator('[data-template-operation="delete"]');
  assert.equal(await deleteButton.evaluate(node=>getComputedStyle(node).outlineColor),'rgb(199, 91, 0)','Locked deletion uses MOED orange highlighting');
  await deleteButton.click();await page.locator('#admin-unlock-dialog').waitFor({state:'visible'});
  assert.equal(await page.locator('#template-operation').isVisible(),false,'Deletion cannot be confirmed before unlock');
  await page.locator('[data-unlock-cancel]').click();assert.equal(posts.length,0,'Cancel unlock never submits deletion');
  await page.locator('[data-audience-admin-unlock]').click();
  await page.locator('[name=admin_password]').fill('fixture-password');await page.locator('[name=admin_code]').fill('123456');
  await page.locator('[data-unlock-submit]').click();await page.waitForFunction(()=>document.body.classList.contains('admin-unlock-active'));
  assert.equal(await page.locator('[data-admin-unlock]').isVisible(),true,'Shared unlock banner appears');
  for(const width of [1440,768,390]){
   await page.setViewportSize({width,height:1000});await frame(page);
   const boxes=await page.evaluate(()=>['.deployment-notice-banner','.account-identity-banner','.admin-unlock-banner','.role-preview-banner','.audience-topbar'].map(selector=>{const box=document.querySelector(selector).getBoundingClientRect();return {top:box.top,bottom:box.bottom};}));
   for(let i=1;i<boxes.length;i++)assert.ok(Math.abs(boxes[i].top-boxes[i-1].bottom)<2,`${width}px banners stack without gaps or overlap`);
   assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false,'Stacked banners do not create horizontal overflow');
  }
  await page.setViewportSize({width:1440,height:1000});
  await page.locator('[data-admin-lock-button]').click();await page.waitForFunction(()=>!document.body.classList.contains('admin-unlock-active'));
  assert.equal(await page.locator('[data-admin-unlock]').isVisible(),false,'Manual lock removes the shared banner');
  await frame(page);
  assert.ok(await page.evaluate(()=>Math.abs(document.querySelector('.role-preview-banner').getBoundingClientRect().top-document.querySelector('.account-identity-banner').getBoundingClientRect().bottom)<2),'Locking removes the banner gap');
  await page.locator('[data-audience-admin-unlock]').click();
  await page.locator('[name=admin_password]').fill('fixture-password');await page.locator('[name=admin_code]').fill('123456');await page.locator('[data-unlock-submit]').click();
  await page.waitForFunction(()=>document.body.classList.contains('admin-unlock-active'));
  await deleteButton.click();await page.locator('#template-operation').waitFor({state:'visible'});
  assert.equal(await page.locator('[data-template-operation-form] [name=csrf_token]').inputValue(),'fixture-rotated-token','Unlock refreshes confirmation CSRF');
  unlocked=false;await page.evaluate(value=>document.dispatchEvent(new CustomEvent('admin-unlock-changed',{detail:value})),status());
  await page.locator('#template-operation button.primary').click();await page.locator('#admin-unlock-dialog').waitFor({state:'visible'});
  assert.equal(posts.length,0,'Expired unlock does not submit a queued deletion');
  await page.locator('[name=admin_password]').fill('fixture-password');await page.locator('[name=admin_code]').fill('123456');
  await page.locator('[data-unlock-submit]').click();await page.waitForURL(url=>url.pathname==='/audience.php'&&posts.length===1);
  assert.equal(posts[0].operation,'delete');assert.equal(posts[0].id,'7');assert.equal(posts[0].version,'3');assert.equal(posts[0].csrf_token,'fixture-rotated-token');
  assert.deepEqual(errors,[],'Template library and shared unlock scripts have no browser errors');
  console.log('Template lifecycle browser checks passed: transfer visibility/upload, centered action rows, role visibility, unlock cancellation/expiry, rotated CSRF, and stacked desktop/mobile banners.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
