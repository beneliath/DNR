'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const width of [390,1600])for(const dark of [false,true]){
  const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(6000);const errors=[];
  page.on('pageerror',error=>errors.push(error.message));
  await page.addInitScript(d=>localStorage.setItem('theme',d?'dark':'light'),dark);
  await page.route('**/*',route=>{const u=new URL(route.request().url());if(u.hostname!=='counts.test')return route.abort();
   if(u.pathname==='/audience.php')return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/browser/audience-counts-fixture.php',u.search.slice(1)],{cwd:root,encoding:'utf8'})});
   if(u.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
   const file=path.join(root,'src',u.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.png')?'image/png':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  const pill=key=>page.locator(`[data-audience-count="${key}"]`);
  const selected=()=>page.locator('.audience-count-pill.is-selected').evaluateAll(nodes=>nodes.map(n=>n.dataset.audienceCount));
  const animation=key=>pill(key).evaluate(node=>getComputedStyle(node).animationName);
  await page.goto('https://counts.test/audience.php');
  assert.deepEqual(await page.locator('[data-audience-count]').evaluateAll(nodes=>nodes.map(n=>n.dataset.audienceCount)),['total','subscribed','unsubscribed','non-subscribed','pending','cleaned','archived']);checks++;
  assert.deepEqual(await selected(),['total','pending','cleaned']);checks++;
  assert.equal(await pill('subscribed').textContent(),'16 Subscribed');checks++;
  assert.deepEqual(await page.locator('.audience-count-operator').allTextContents(),['=','+','+']);
  assert.deepEqual(await page.locator('.audience-count-equation [data-audience-count]').evaluateAll(nodes=>nodes.map(n=>n.dataset.audienceCount)),['total','subscribed','unsubscribed','non-subscribed']);
  const numbers=await page.locator('.audience-count-equation strong').allTextContents();assert.equal(Number(numbers[0]),numbers.slice(1).reduce((sum,value)=>sum+Number(value),0));checks+=3;
  const aggregate=page.locator('.audience-matching-count');
  assert.equal(await aggregate.evaluate(n=>getComputedStyle(n).animationName),'mm-control-outline-blink');checks++;
  const spacing=await page.locator('[data-audience-count]').evaluateAll(nodes=>nodes.map(n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom};}));
  for(let i=1;i<spacing.length;i++){const a=spacing[i-1],b=spacing[i];assert(b.y>=a.bottom+12||b.x>=a.right+12,'Pill outlines need at least 12px clearance');checks++;}
  for(const [key,count]of [['pending','2'],['unsubscribed','1'],['non-subscribed','2']]){assert.equal(await pill(key).locator('strong').textContent(),count);checks++;}
  assert.equal(await animation('pending'),'mm-control-outline-blink');
  assert.equal(await animation('unsubscribed'),'none');assert.equal(await animation('non-subscribed'),'none');checks+=3;
  await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await animation('pending'),'none');assert.equal(await pill('pending').evaluate(n=>getComputedStyle(n).outlineWidth),'3px');checks+=2;
  assert.equal(await aggregate.evaluate(n=>getComputedStyle(n).animationName),'none');assert.equal(await aggregate.evaluate(n=>getComputedStyle(n).outlineWidth),'3px');checks+=2;
  await page.emulateMedia({reducedMotion:'no-preference'});
  for(const [key,count]of [['unsubscribed',1],['non-subscribed',2],['pending',2]]){
   await pill(key).click();await page.waitForURL(url=>url.searchParams.get('status[0]')===key);
   assert.deepEqual(await selected(),[key]);assert.equal(await pill(key).getAttribute('aria-current'),'page');assert.equal(await animation(key),'mm-control-outline-blink');checks+=3;
   assert.equal(await aggregate.evaluate(n=>getComputedStyle(n).animationName),'none');checks++;
   assert.equal(await page.locator('[data-people-table] tbody .audience-status').count(),count);assert.deepEqual(await page.locator('[data-people-table] tbody .audience-status').allTextContents(),Array(count).fill(key[0].toUpperCase()+key.slice(1)));checks+=2;
  }
  await page.goto('https://counts.test/audience.php?q=person-');assert.deepEqual(await selected(),[]);assert.equal(await animation('pending'),'none');checks+=2;
  await page.goto('https://counts.test/audience.php?status%5B0%5D=subscribed&status%5B1%5D=unsubscribed');assert.equal(await animation('unsubscribed'),'none');checks++;
  await page.goto('https://counts.test/audience.php?archived=1');assert.deepEqual(await selected(),['archived']);assert.equal(await animation('pending'),'none');assert.equal(await animation('unsubscribed'),'none');checks+=3;
  await page.goto('https://counts.test/audience.php');assert.deepEqual(await selected(),['total','pending','cleaned']);assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);assert.deepEqual(errors,[]);checks+=3;
  if(width===1600&&!dark)await page.screenshot({path:path.join(root,'output/ui/audience-count-pills.png')});
  await page.close();
 }
 console.log(`Audience count pills: ${checks} browser checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
