'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const viewport of [{width:1600,height:1000},{width:1600,height:600},{width:390,height:844}])for(const dark of [false,true]){
  const page=await browser.newPage({viewport});page.setDefaultTimeout(6000);const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(d=>localStorage.setItem('theme',d?'dark':'light'),dark);
  await page.route('**/*',route=>{const url=new URL(route.request().url());if(url.hostname!=='sticky.test')return route.abort();
   if(url.pathname==='/audience.php'){
    let html=execFileSync('php',['tests/browser/audience-counts-fixture.php',url.search.slice(1)],{cwd:root,encoding:'utf8'});
    html=html.replace('<a class="audience-skip"','<section class="account-identity-banner" aria-label="Current Account"><strong>Example account</strong><span>Primary Account</span></section><a class="audience-skip"');
    return route.fulfill({contentType:'text/html',body:html});
   }
   if(url.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
   const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  await page.goto('https://sticky.test/audience.php?fixture_role=admin');
  const scroll=page.getByRole('region',{name:'Connections table',exact:true}),table=page.locator('[data-people-table]');
  if(viewport.width===390){
   // Phone layouts intentionally scroll the document and render connection cards.
   // Desktop keeps the surrounding workspace fixed and scrolls only the table.
   assert.equal(await table.getAttribute('data-mm-card-table'),'');
   assert.equal(await scroll.evaluate(n=>getComputedStyle(n).overflowY),'visible');
   assert.equal(await page.evaluate(()=>document.scrollingElement.scrollHeight>innerHeight),true);
   assert.equal(await table.locator('tbody tr').count(),22);
   assert.equal(await page.locator('.mm-mobile-dock').count(),0);
   const last=table.locator('tbody tr').last();await last.scrollIntoViewIfNeeded();
   assert((await page.evaluate(()=>scrollY))>0,'The phone document scrolls to later connections');
   const lastBox=await last.boundingBox();
   assert(lastBox.y>=0&&lastBox.y+lastBox.height<=viewport.height+1,'The last connection is fully visible without fixed phone navigation');
   assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);
   const selected=table.locator('[data-person-select]').first();await selected.check();
   assert.equal(await page.locator('[data-bulk-toolbar]').isVisible(),true);
   assert.equal(await page.locator('.app-footer').count(),0);
   // Resizing restores the desktop scroll container without losing selection.
   await page.setViewportSize({width:1600,height:600});
   await page.waitForFunction(()=>!document.querySelector('[data-people-table]').hasAttribute('data-mm-card-table'));
   assert.equal(await scroll.evaluate(n=>n.scrollHeight>n.clientHeight),true);
   assert.equal(await selected.isChecked(),true);
   assert.equal(await page.evaluate(()=>scrollY),0);
   assert.deepEqual(errors,[]);checks+=15;await page.close();continue;
  }
  const pinned=['.account-identity-banner','.audience-topbar','.audience-page-heading','.audience-people-toolbar','.audience-counts','.audience-table-tools','[data-people-table] thead'];
  const positions=()=>page.evaluate(selectors=>selectors.map(s=>document.querySelector(s).getBoundingClientRect().top),pinned);
  const start=await positions(),rowTop=await table.locator('tbody tr').first().evaluate(n=>n.getBoundingClientRect().top);
  assert.equal(await scroll.evaluate(n=>n.scrollHeight>n.clientHeight),true);checks++;
  await scroll.evaluate(n=>n.scrollTop=260);await page.waitForFunction(()=>document.querySelector('[data-people-table]').parentElement.scrollTop>0);
  const after=await positions();after.forEach((top,i)=>assert(Math.abs(top-start[i])<2,`${pinned[i]} moved while rows scrolled`));checks+=pinned.length;
  assert(await table.locator('tbody tr').first().evaluate(n=>n.getBoundingClientRect().top)<rowTop-100);assert.equal(await page.evaluate(()=>scrollY),0);checks+=2;
  // Wheel at the bottom of the list cannot move the surrounding workspace.
  await scroll.evaluate(n=>n.scrollTop=n.scrollHeight);await scroll.hover();await page.mouse.wheel(0,1000);await page.waitForTimeout(80);assert.equal(await page.evaluate(()=>scrollY),0);checks++;
  assert.equal(await page.locator('.app-footer').count(),0);checks++;
  const bottom=await page.locator('[data-audience-results]').evaluate(n=>n.getBoundingClientRect().bottom);assert(Math.abs(bottom-viewport.height)<2);checks++;
  await scroll.evaluate(n=>{n.scrollTop=0;n.scrollLeft=200;});
  const alignment=await table.evaluate(n=>{const head=n.querySelector('th[data-column="email"]'),cell=n.querySelector('td[data-column="email"]');return Math.abs(head.getBoundingClientRect().left-cell.getBoundingClientRect().left);});assert(alignment<2);checks++;
  assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);assert((await scroll.boundingBox()).height>=60);checks+=2;
  await page.locator('[popovertarget="filter-status"]').click();assert.equal(await page.locator('#filter-status').evaluate(n=>n.matches(':popover-open')),true);await page.keyboard.press('Escape');checks++;
  await scroll.evaluate(n=>{n.scrollTop=0;n.scrollLeft=0;});await table.locator('[data-person-select]').first().check();assert.equal(await page.locator('[data-bulk-toolbar]').isVisible(),true);checks++;
  const boxes=await page.evaluate(()=>['.audience-table-tools','[data-bulk-toolbar]','[data-people-table] thead'].map(s=>{const r=document.querySelector(s).getBoundingClientRect();return {top:r.top,bottom:r.bottom};}));assert(boxes[1].top>=boxes[0].bottom-1,JSON.stringify({viewport,boxes}));assert(boxes[2].top>=boxes[1].bottom-1,JSON.stringify({viewport,boxes}));checks+=2;
  assert.deepEqual(errors,[]);checks++;
  if(viewport.width===1600&&viewport.height===1000&&dark){await scroll.evaluate(n=>n.scrollTop=300);await page.screenshot({path:path.join(root,'output/ui/connections-sticky.png')});}
  await page.close();
 }
 console.log(`Sticky Connections workspace: ${checks} browser checks passed.`);
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
