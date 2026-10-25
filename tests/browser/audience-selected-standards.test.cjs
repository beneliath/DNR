'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const fixtures={
 templates:()=>execFileSync('php',['tests/browser/audience-template-lifecycle-fixture.php','admin','saved','locked'],{cwd:root,encoding:'utf8'}),
 forms:()=>execFileSync('php',['tests/browser/audience-collection-lifecycle-fixture.php','admin','active','','forms'],{cwd:root,encoding:'utf8'}),
 agenda:()=>execFileSync('php',['tests/browser/audience-campaign-lifecycle-fixture.php','admin','active','','campaign-manager','agenda'],{cwd:root,encoding:'utf8'}),
 plans:()=>execFileSync('php',['tests/browser/audience-campaign-lifecycle-fixture.php','admin','active','','campaign-manager','list'],{cwd:root,encoding:'utf8'}),
 campaigns:()=>execFileSync('php',['tests/browser/audience-campaign-lifecycle-fixture.php','admin','active','','campaigns'],{cwd:root,encoding:'utf8'})
};
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true])for(const width of [390,1600]){
 const page=await browser.newPage({viewport:{width,height:1100}});const errors=[];page.on('pageerror',error=>errors.push(error.message));let view='forms';
 await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
 await page.route('http://selected.test/**',route=>{const u=new URL(route.request().url());if(u.pathname.startsWith('/assets/')){const file=path.join(root,'src',u.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});}if(u.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});if(route.request().resourceType()==='image')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg"/>'});return route.fulfill({contentType:'text/html',body:fixtures[view]()});});
 for(view of ['forms','agenda','plans','campaigns','templates']){
  await page.goto('http://selected.test/audience.php?view='+view);await page.waitForFunction(()=>document.documentElement.dataset.mmUiStandards==='1');
  const icons=page.locator('.collection-row-actions .action-icon-button,.campaign-agenda-actions .action-icon-button,.campaign-plan-row-actions .action-icon-button,.campaign-row-actions .action-icon-button,.template-card-actions .action-icon-button');
  assert((await icons.count())>0);
  for(const icon of await icons.all()){const geometry=await icon.evaluate(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return {width:r.width,height:r.height,border:s.borderTopStyle,radius:s.borderRadius};});assert.deepEqual(geometry,{width:44,height:44,border:'solid',radius:'8px'});checks++;}
  const overflow=await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(el=>{const r=el.getBoundingClientRect();return r.width&&r.right>innerWidth+1;}).map(el=>({tag:el.tagName,cls:el.className,width:Math.round(el.getBoundingClientRect().width),right:Math.round(el.getBoundingClientRect().right)})).slice(0,12));assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false,view+' remains within the viewport '+JSON.stringify(overflow));checks++;
  if(view==='forms'||view==='plans'){
   const input=page.locator('input[type=search]').first();assert.equal(await input.evaluate(el=>!!el.closest('.mm-search-field')),true);assert.equal(await page.locator('.mm-search-submit').count(),1);checks+=2;
   await input.fill('Community');await page.locator('.mm-search-submit').click();await page.waitForURL(/(?:q|plan_search)=Community/);checks++;
  }
  if(view==='templates'){
   assert.equal(await page.getByRole('group',{name:'Template type',exact:true}).count(),0);assert.equal(await page.locator('[data-template-type]').count(),0);assert.equal(await page.locator('[data-template-search]').evaluate(el=>!!el.closest('.campaign-search').querySelector('svg')),true);checks+=3;
   await page.locator('[data-template-all-filters]').click();await page.locator('#template-filters-dialog').waitFor({state:'visible'});assert.equal(await page.locator('[data-template-type-filter]').count(),0);await page.locator('[data-template-apply-filters]').click();assert.equal(await page.locator('#template-filters-dialog').isVisible(),false);checks+=2;
  }
  if(view==='campaigns'){
   const group=page.locator('.mm-campaign-filter-group[aria-label=Status]');assert.equal(await group.isVisible(),true);await group.getByRole('button',{name:'Draft',exact:true}).click();await page.waitForURL(/status=draft/);checks+=2;
  }
  if(view==='agenda'){
   const positive=page.locator('[data-touchpoint-status=complete]').first();assert.equal(await positive.getAttribute('data-mm-status-tone'),'positive');const draft=page.locator('[data-touchpoint-status=draft]').first();assert.equal(await draft.getAttribute('data-mm-status-tone'),'neutral');checks+=2;
   await page.locator('[data-touchpoint-edit]').first().click();const dialog=page.locator('#campaign-touchpoint-editor');await dialog.waitFor({state:'visible'});const close=dialog.locator('header [data-close-dialog]');assert.equal(await close.getAttribute('data-tooltip'),null);assert.equal(await close.locator('svg').count(),1);assert.equal((await close.boundingBox()).width,44);assert.equal(await dialog.getByRole('button',{name:'Cancel',exact:true}).isVisible(),true);checks+=4;
   await close.click();assert.equal(await dialog.isVisible(),false);checks++;
  }
 }
 await page.evaluate(()=>{const form=document.createElement('form');form.id='mm-test-filters';form.method='get';form.innerHTML='<label>Status: <select name="state"><option value="">All statuses</option><option value="draft">Draft</option><option value="running">Active</option></select></label>';form.addEventListener('submit',event=>event.preventDefault());document.querySelector('.audience-page-body').append(form);window.mmTestForm=form;});
 await page.locator('#mm-test-filters .mm-filter-group[aria-label=Status]').waitFor();await page.locator('#mm-test-filters .mm-filter-group[aria-label=Status]').getByRole('button',{name:'Active',exact:true}).click();assert.equal(await page.locator('#mm-test-filters .mm-filter-group[aria-label=Status]').getByRole('button',{name:'Active',exact:true}).getAttribute('aria-pressed'),'true');assert.equal(await page.locator('select[name=state]').last().inputValue(),'running');checks+=2;
 assert.deepEqual(errors,[]);await page.close();
}console.log('Selected MessageMonkey standards: '+checks+' icon, search, filter, dialog, status and responsive checks passed.');}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
