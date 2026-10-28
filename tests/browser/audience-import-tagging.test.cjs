'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const width of [1360,390])for(const dark of [false,true]){
  const page=await browser.newPage({viewport:{width,height:1040}}),errors=[];page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(d=>localStorage.setItem('theme',d?'dark':'light'),dark);
  await page.route('https://import.test/**',route=>{const u=new URL(route.request().url());if(u.pathname==='/audience.php')return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/browser/audience-import-tagging-fixture.php',u.searchParams.has('empty')?'empty':'normal'],{cwd:root,encoding:'utf8'})});const file=path.join(root,'src',u.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});});
  await page.goto('https://import.test/audience.php?view=import&step=tag');
  const form=page.locator('form'),search=page.getByRole('searchbox',{name:'Find a tag'}),tagCount=page.locator('[data-import-tag-count]'),groupCount=page.locator('[data-import-group-count]');
  await search.waitFor();assert.equal(await tagCount.textContent(),'4 selected');assert.equal(await groupCount.textContent(),'0 selected');assert.equal(await page.locator('.import-selected-tags button').count(),4);checks+=3;
  assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);checks++;
  if(width===1360&&!dark){fs.mkdirSync(path.join(root,'output/ui'),{recursive:true});await page.screenshot({path:path.join(root,'output/ui/import-tagging-mockup.png'),fullPage:true});}
  await search.fill('donor');assert.equal(await page.locator('[data-import-tag-option]:visible').count(),6);assert.equal(await form.evaluate(n=>new FormData(n).getAll('tag_ids[]').length),4);checks+=2;
  await page.getByRole('button',{name:'Remove news tag',exact:true}).click();assert.equal(await tagCount.textContent(),'3 selected');assert.equal(await form.evaluate(n=>new FormData(n).getAll('tag_ids[]').includes('12')),false);checks+=2;
  await search.fill('does not exist');assert.equal(await page.getByText('No tags match your search.').isVisible(),true);assert.equal(await form.evaluate(n=>new FormData(n).getAll('tag_ids[]').length),3);checks+=2;
  await search.fill('');const sms=page.getByRole('checkbox',{name:'SMS',exact:true});await sms.focus();await page.keyboard.press('Space');assert.equal(await tagCount.textContent(),'4 selected');checks++;
  await page.getByRole('checkbox',{name:'News Alerts',exact:true}).check();assert.equal(await groupCount.textContent(),'1 selected');checks++;
  await page.getByRole('button',{name:'Continue',exact:true}).click();const data=await page.evaluate(()=>window.fixtureSubmittedData);assert.deepEqual(data.filter(([name])=>name==='tag_ids[]').map(([,v])=>v),['8','13','17','18']);assert.deepEqual(data.filter(([name])=>name==='group_ids[]').map(([,v])=>v),['4']);assert.equal(data.some(([name])=>name==='q'),false);checks+=3;
  await page.goto('https://import.test/audience.php?empty=1');assert.equal(await page.getByText('No tags yet. You can map a Tags column or add tags after import.').isVisible(),true);assert.equal(await page.getByText('No groups available. You can add them after import.').isVisible(),true);assert.deepEqual(errors,[]);checks+=3;
  await page.close();
 }
 console.log(`Import tagging: ${checks} browser checks passed (selection, search, hidden selections, chips, groups, keyboard, payload, themes, mobile and empty catalogs).`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
