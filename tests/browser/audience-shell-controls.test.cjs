'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const fixture=execFileSync('php',['tests/browser/audience-campaign-lifecycle-fixture.php','admin','active','','home'],{cwd:root,encoding:'utf8'});
const snapshot=page=>page.evaluate(()=>{
 const selectors=['.audience-topbar','.audience-brand','[data-audience-logo]','[data-audience-logo]>svg','[data-audience-nav-toggle]','.audience-global-search','.audience-account-tools','.audience-admin-unlock-trigger','.audience-theme-trigger','.audience-notification-trigger','.audience-account'];
 return Object.fromEntries(selectors.map(selector=>{const el=document.querySelector(selector),r=el.getBoundingClientRect(),s=getComputedStyle(el);return [selector,{rect:[r.x,r.y,r.width,r.height].map(n=>Math.round(n*100)/100),display:s.display,border:s.border,background:s.backgroundColor,fill:s.fill,stroke:s.stroke}];}));
});
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
 try{for(const dark of [false,true])for(const width of [390,1600]){
  const page=await browser.newPage({viewport:{width,height:1100}});let baseline=false;const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
  await page.route('http://shell.test/**',route=>{
   const u=new URL(route.request().url());if(u.pathname.startsWith('/assets/')){const file=path.join(root,'src',u.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});}
   if(u.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
   if(route.request().resourceType()==='image')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg"/>'});
   const body=baseline?fixture.replace('<html>','<html data-mm-ui-standards="baseline">').replace(/<link\b[^>]*href="[^"]*audience-ui-standards[^>]*>/g,''):fixture;
   return route.fulfill({contentType:'text/html',body});
  });
  baseline=true;await page.goto('http://shell.test/audience.php?view=home');await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));const original=await snapshot(page);
  baseline=false;await page.reload();await page.waitForFunction(()=>document.documentElement.dataset.mmUiStandards==='1');await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
  assert.deepEqual(await snapshot(page),original,'Shared content standards must preserve every shell control and logo');checks++;
  assert.equal(await page.locator('[data-audience-nav-toggle]').isVisible(),width<1001,'Hamburger appears only in the mobile layout');checks++;
  assert.equal(await page.locator('.audience-topbar .action-icon-button,[data-audience-logo].action-icon-button').count(),0,'Shell controls never become content action buttons');checks++;
  if(width>=1001){const header=await page.locator('.audience-topbar').boundingBox(),tools=await page.locator('.audience-account-tools').boundingBox();assert(tools.y>=header.y&&tools.y+tools.height<=header.y+header.height,'Account controls remain inside the upper toolbar');checks++;}
  assert.deepEqual(errors,[]);await page.close();
 }console.log('Upper toolbar restoration: '+checks+' desktop/mobile, light/dark and original-style checks passed.');}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
