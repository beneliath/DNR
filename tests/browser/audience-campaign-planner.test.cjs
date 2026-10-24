'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),output=process.env.DNR_PLANNER_SCREENSHOTS;
const fixture=(role,empty,layout,archived)=>execFileSync('php',['tests/browser/audience-campaign-lifecycle-fixture.php',role,'active',empty?'empty':'','campaign-manager',layout,archived?'archive':'active'],{cwd:root,encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{
 for(const dark of [false,true])for(const width of [390,1600,2200]){
  const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(6000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.addInitScript(dark=>{localStorage.setItem('theme',dark?'dark':'light');window.plannerSubmissions=[];document.addEventListener('submit',event=>{if(!event.defaultPrevented&&event.target.method!=='dialog'){event.preventDefault();window.plannerSubmissions.push(Object.fromEntries(new FormData(event.target)));}});},dark);
  let role='admin',empty=false,layout='agenda',archived=false;
  await page.route('http://planner.test/**',route=>{const u=new URL(route.request().url());
   if(u.pathname.startsWith('/assets/')){const file=path.join(root,'src',u.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'image/svg+xml'}):route.fulfill({status:404,body:''});}
   if(route.request().resourceType()==='image')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><circle cx="20" cy="20" r="20" fill="#e4ebef"/><text x="20" y="25" text-anchor="middle" fill="#007c89" font-family="Arial" font-size="14">EU</text></svg>'});
   if(u.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
   return route.fulfill({contentType:'text/html',body:fixture(role,empty,layout,archived)});
  });
  const open=()=>page.goto('http://planner.test/audience.php?view=campaign-manager&planner_layout='+layout);
  await open();assert.equal(await page.locator('.campaign-agenda-card').count(),6);checks++;
  const widths=await page.locator('.campaign-agenda-card').evaluateAll(cards=>cards.map(card=>card.getBoundingClientRect().width));
  assert(widths.every(w=>width===390?w<=360:w===360));assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);checks+=2;
  assert.equal(await page.locator('.campaign-agenda-actions').first().evaluate(el=>getComputedStyle(el).gap),'16px');checks++;
  assert.deepEqual(await page.locator('.campaign-agenda-actions').nth(1).locator('button,a').evaluateAll(items=>items.map(item=>item.dataset.tooltip)),['Edit','Archive','View email']);checks++;
  const edits=page.locator('.campaign-agenda-actions [data-touchpoint-edit]');assert.equal(await edits.count(),6);assert.equal(await page.locator('.campaign-agenda-actions a').count(),2);checks+=2;
  for(const button of await edits.all()){assert.match(await button.getAttribute('aria-label'),/^Edit /);assert.equal(await button.getAttribute('data-tooltip'),'Edit');assert.equal(await button.locator('svg').count(),1);checks+=3;}
  await edits.first().hover();let tooltip=page.locator('#'+await edits.first().getAttribute('aria-describedby'));await tooltip.waitFor({state:'visible'});assert.equal(await tooltip.textContent(),'Edit');assert.deepEqual(await tooltip.evaluate(el=>({bg:getComputedStyle(el).backgroundColor,color:getComputedStyle(el).color})),{bg:'rgb(0, 0, 0)',color:'rgb(255, 255, 255)'});checks+=2;
  await edits.first().click();const dialog=page.locator('#campaign-touchpoint-editor');await dialog.waitFor({state:'visible'});assert.equal(await dialog.locator('[name=name]').inputValue(),'Review newsletter copy');assert.equal(await dialog.locator('[name=id]').first().inputValue(),'21');checks+=2;
  const touchpointDelete=dialog.locator('[data-touchpoint-delete]');assert.equal(await touchpointDelete.getAttribute('data-admin-unlock-required'),'');checks++;
  await page.evaluate(()=>{window.touchpointUnlockChecks=0;window.DnrAdminUnlock={request:async()=>{window.touchpointUnlockChecks++;return false;}};});
  await touchpointDelete.click();await page.waitForFunction(()=>window.touchpointUnlockChecks===1);assert.equal(await page.locator('#audience-confirm-title').evaluate(el=>el.closest('dialog').open),false);assert.equal(await page.evaluate(()=>window.plannerSubmissions.length),0);checks+=2;
  await dialog.locator('[data-close-dialog]').first().click();await page.mouse.move(0,0);
  if(width>=1600){const nav=page.locator('#audience-sidebar [data-audience-tooltip]').first();await nav.hover();const help=page.locator('#'+await nav.getAttribute('aria-describedby'));await help.waitFor({state:'visible'});assert((await help.textContent()).length>0);checks++;await page.mouse.move(0,0);await nav.focus();await help.waitFor({state:'visible'});checks++;await nav.blur();await page.mouse.move(0,0);}
  if(output&&!dark&&width===1600){fs.mkdirSync(output,{recursive:true});await page.screenshot({path:path.join(output,'campaign-manager-agenda.png'),fullPage:true});}
  empty=true;await open();const alignment=await page.locator('.campaign-agenda-empty').evaluate(el=>{const outer=el.getBoundingClientRect(),text=el.querySelector('p').getBoundingClientRect(),style=getComputedStyle(el);return {height:outer.height,inset:text.left-outer.left,offset:Math.abs((text.top+text.bottom)-(outer.top+outer.bottom)),padding:style.padding};});
  assert(alignment.height>=100);assert(alignment.inset>=24);assert(alignment.offset<2);checks+=3;
  if(output&&!dark&&width===1600)await page.screenshot({path:path.join(output,'campaign-manager-empty.png'),fullPage:true});
  empty=false;role='reviewer';await open();assert.equal(await page.locator('.campaign-agenda-actions button').count(),0);assert.equal(await page.locator('.campaign-agenda-actions a').count(),2);checks+=2;
  role='admin';layout='calendar';await open();for(const selector of ['.campaign-calendar th','.campaign-calendar td']){const color=await page.locator(selector).first().evaluate(el=>getComputedStyle(el).borderTopColor);assert.equal(color,dark?'rgb(113, 129, 139)':'rgb(165, 175, 180)');checks++;}
  if(output&&!dark&&width===1600)await page.screenshot({path:path.join(output,'campaign-manager-calendar.png'),fullPage:true});
  if(!dark&&width===1600){
   layout='list';
   for(archived of [false,true])for(role of ['admin','editor','reviewer']){
    await open();assert.equal(await page.locator('.campaign-plan-lifecycle-tabs [aria-current=page]').count(),1);assert.equal(await page.locator('.campaign-plan-lifecycle-tabs [aria-current=page]').textContent(),archived?'Archive':'Active');checks+=2;
    const actions=page.locator('.campaign-plan-row-actions').first();
    assert.equal(await actions.locator('[data-tooltip="Manage campaign"]').count(),archived?0:1);
    assert.equal(await actions.locator('[data-tooltip="Archive"]').count(),!archived&&role!=='reviewer'?1:0);
    assert.equal(await actions.locator('[data-tooltip="Restore"]').count(),archived&&role!=='reviewer'?1:0);
    assert.equal(await actions.locator('[data-tooltip="Delete"]').count(),archived&&role==='admin'?1:0);checks+=4;
    if(role==='admin'){
     assert.equal(await actions.evaluate(el=>getComputedStyle(el).gap),'16px');checks++;
     if(output)await page.screenshot({path:path.join(output,archived?'campaign-manager-plans-archive.png':'campaign-manager-plans.png'),fullPage:true});
     if(archived){
      const remove=actions.locator('[data-tooltip="Delete"]');assert.equal(await remove.getAttribute('data-admin-unlock-required'),'');checks++;
      await page.evaluate(()=>{window.unlockChecks=0;window.DnrAdminUnlock={request:async()=>{window.unlockChecks++;return false;}};});
      await remove.click();await page.waitForFunction(()=>window.unlockChecks===1);assert.equal(await page.locator('#audience-confirm-title').evaluate(el=>el.closest('dialog').open),false);assert.equal(await page.evaluate(()=>window.plannerSubmissions.length),0);checks+=2;
      await page.evaluate(()=>window.DnrAdminUnlock.request=async()=>true);await remove.click();const confirmation=page.locator('dialog').filter({has:page.locator('#audience-confirm-title')});await confirmation.waitFor({state:'visible'});await confirmation.getByRole('button',{name:'Continue',exact:true}).click();await page.waitForFunction(()=>window.plannerSubmissions.length===1);assert.equal(await page.evaluate(()=>window.plannerSubmissions[0].operation),'delete');checks++;
     }
    }
   }
   role='admin';layout='agenda';archived=true;await open();assert.equal(await page.locator('[data-touchpoint-edit]').count(),0);assert.equal(await page.locator('.campaign-agenda-actions [data-tooltip="Restore"]').count(),6);assert.equal(await page.locator('.campaign-agenda-actions [data-tooltip="Delete"]').count(),6);assert.deepEqual(await page.locator('.campaign-agenda-actions').nth(1).locator('button,a').evaluateAll(items=>items.map(item=>item.dataset.tooltip)),['Restore','Delete','View email']);checks+=4;
   role='editor';archived=false;await open();await page.locator('[data-touchpoint-edit]').first().click();assert.equal(await page.locator('[data-touchpoint-delete]').count(),0);assert.equal(await page.locator('[data-touchpoint-actions] [value=archive]').count(),1);checks+=2;
  }
  assert.deepEqual(errors,[]);await page.close();
 }
 console.log('Campaign Manager cards, actions, empty state and calendar: '+checks+' browser checks passed.');
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
