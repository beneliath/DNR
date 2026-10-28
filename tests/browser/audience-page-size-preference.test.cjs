'use strict';
// Use real HTTP cookies and the production pagination helper with fictional data.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),os=require('node:os'),net=require('node:net'),crypto=require('node:crypto'),{spawn}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
(async()=>{
 const temp=fs.mkdtempSync(path.join(os.tmpdir(),'dnr-page-size-'));const router=path.join(temp,'router.php');
 fs.writeFileSync(router,`<?php $p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if($p==='/audience.php'){require '${root}/tests/browser/audience-counts-fixture.php';return true;}if($p==='/admin_unlock_status.php'){header('Content-Type: application/json');echo '{"unlocked":false}';return true;}return false;`);
 const port=await new Promise(resolve=>{const server=net.createServer();server.listen(0,'127.0.0.1',()=>{const p=server.address().port;server.close(()=>resolve(p));});});
 const origin=`http://127.0.0.1:${port}`,server=spawn('php',['-S',`127.0.0.1:${port}`,'-t',path.join(root,'src'),router],{cwd:root,env:{...process.env,DNR_REQUIRE_HTTPS:'0',DNR_ACCOUNTS_ENABLED:'1',DNR_ACCOUNT_MODE:'primary',DNR_ACCOUNT_KEY:'shalom-in-messiah',DNR_PUBLIC_BASE_URL:`http://127.0.0.1:${port}/a/shalom-in-messiah`},stdio:'ignore'});let browser,checks=0;
 try{
  for(let attempt=0;attempt<30;attempt++){try{const response=await fetch(origin+'/audience.php');if(response.ok)break;}catch{}if(attempt===29)throw Error('Fixture server did not start');await new Promise(resolve=>setTimeout(resolve,100));}
  browser=await chromium.launch({headless:true});const page=await browser.newPage({viewport:{width:1600,height:1000}});page.setDefaultTimeout(7000);const errors=[];page.on('pageerror',e=>errors.push(e.message));await page.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());
  const size=page.locator('[data-mm-page-size]'),navigate=async url=>{await page.goto(origin+url);assert.equal(await size.inputValue(),'50');checks++;};
  const cookieName='dnr_rows_per_page_mm_'+crypto.createHash('sha256').update('shalom-in-messiah:11:contacts::per_page').digest('hex').slice(0,24);
  await page.context().addCookies([{name:cookieName,value:'25',domain:'127.0.0.1',path:'/a/shalom-in-messiah/',httpOnly:true}]);
  await page.goto(origin+'/audience.php');assert.equal(await size.inputValue(),'25');checks++;
  await Promise.all([page.waitForNavigation(),size.selectOption('50')]);assert.equal(await size.inputValue(),'50');checks++;
  const cookies=await page.context().cookies();assert(cookies.some(c=>c.name.startsWith('dnr_rows_per_page_mm_')&&c.value==='50'&&c.httpOnly&&c.path==='/'));checks++;
  assert.equal(cookies.some(c=>c.name===cookieName&&c.path==='/a/shalom-in-messiah/'),false);checks++;
  for(const key of ['subscribed','unsubscribed','non-subscribed','pending','cleaned','archived','total']){await Promise.all([page.waitForNavigation(),page.locator(`[data-audience-count="${key}"]`).click()]);assert.equal(await size.inputValue(),'50');checks++;}
  await navigate('/audience.php?per_page=25&archived=0');await navigate('/audience.php?per_page=10&status%5B0%5D=unsubscribed');await navigate('/audience.php');
  await page.locator('[popovertarget="filter-status"]').click();await page.locator('#filter-status [value="pending"]').check();await page.waitForFunction(()=>document.querySelector('[data-audience-results]').getAttribute('aria-busy')!=='true');assert.equal(await size.inputValue(),'50');assert.equal(await page.locator('.audience-filter-popovers [name="per_page"]').inputValue(),'50');checks+=2;
  await page.keyboard.press('Escape');await page.locator('#contact-search').fill('person-');await page.waitForFunction(()=>new URL(location.href).searchParams.get('q')==='person-');assert.equal(await size.inputValue(),'50');checks++;
  await page.reload();assert.equal(await size.inputValue(),'50');checks++;
  await Promise.all([page.waitForNavigation(),size.selectOption('100')]);assert.equal(await size.inputValue(),'100');await page.goto(origin+'/audience.php?per_page=25&archived=1');assert.equal(await size.inputValue(),'100');checks+=2;
  assert.deepEqual(errors,[]);checks++;await page.close();console.log(`Connections page-size preference: ${checks} browser checks passed.`);
 }finally{await browser?.close();server.kill();fs.rmSync(temp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
