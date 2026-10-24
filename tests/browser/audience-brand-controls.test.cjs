'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');const root=path.resolve(__dirname,'../..');
const fixture=(view,role='admin')=>view==='brand'?execFileSync('php',['tests/browser/audience-tag-actions-fixture.php',role,'active','locked','2','','',view],{cwd:root,encoding:'utf8'}):execFileSync('php',['tests/browser/audience-collection-lifecycle-fixture.php',role,'active','locked',view],{cwd:root,encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true])for(const width of [390,1600]){
 const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(6000);const errors=[],posts=[];page.on('pageerror',e=>errors.push(e.message));await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
 await page.route('**/*',route=>{const request=route.request(),url=new URL(request.url());if(url.origin!=='https://brand.test')return route.abort();
  if(url.pathname==='/audience.php'){if(request.method()==='POST')posts.push(Object.fromEntries(new URLSearchParams(request.postData())));return route.fulfill({contentType:'text/html',body:fixture(url.searchParams.get('view')||'brand',url.searchParams.get('role')||'admin')});}
  const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
 });
 for(const view of ['surveys','preferences','landing-pages','form-settings']){
  await page.goto('https://brand.test/audience.php?view='+view);await page.mouse.move(0,0);const create=page.locator('.audience-actions a.primary').first();
  const button=await create.count()?create:page.locator('a.audience-button.primary').first();
  assert.deepEqual(await button.evaluate(el=>({bg:getComputedStyle(el).backgroundColor,color:getComputedStyle(el).color})),{bg:'rgb(0, 124, 137)',color:'rgb(255, 255, 255)'});
  await button.hover();assert.deepEqual(await button.evaluate(el=>({bg:getComputedStyle(el).backgroundColor,color:getComputedStyle(el).color})),{bg:'rgb(0, 105, 115)',color:'rgb(255, 255, 255)'});checks+=2;
 }
 await page.goto('https://brand.test/audience.php?view=brand');
 const form=page.locator('[data-brand-form]'),save=page.locator('[data-brand-save]');assert.equal(await save.count(),2);assert.equal(await page.locator('.brand-save-unsaved').count(),0);checks+=2;
 await page.waitForFunction(()=>Array.from(document.querySelectorAll('[data-content-preview]')).every(image=>image.complete&&image.style.width.endsWith('px')));
 const geometry=await page.locator('[data-content-preview]').evaluateAll(images=>images.map(image=>({stage:image.parentElement.getBoundingClientRect().height,scaledWidth:parseFloat(image.style.width),scaledHeight:parseFloat(image.style.height),source:image.src,value:image.closest('form').elements[image.dataset.contentPreview].value})));
 assert(geometry.every(item=>Math.abs(item.stage-112.5)<.1&&item.source===item.value));assert(Math.abs(geometry[0].scaledWidth/240*200-225)<1);assert(Math.abs(geometry[1].scaledHeight/160*130-112.5)<1);checks+=3;
 const name=form.locator('[name=organization_name]'),original=await name.inputValue();await name.fill('Changed organization');await page.waitForFunction(()=>document.querySelectorAll('.brand-save-unsaved').length===2);assert.equal(await save.first().evaluate(el=>getComputedStyle(el).animationName),'brand-save-blink');checks++;
 await name.fill(original);assert.equal(await page.locator('.brand-save-unsaved').count(),0);checks++;
 await form.locator('[data-content-clear="logo"]').click();assert.equal(await page.locator('.brand-save-unsaved').count(),2);assert.equal(await form.locator('[data-content-preview="logo"]').evaluate(el=>el.parentElement.hidden),true);checks++;
 await page.reload();await name.fill('Saved organization');await save.first().click();await page.waitForFunction(()=>document.querySelectorAll('.brand-save-unsaved').length===0);assert.equal(posts.at(-1).action,'brand_save');assert.equal(posts.at(-1).organization_name,'Saved organization');checks+=2;
 await page.emulateMedia({reducedMotion:'reduce'});await name.fill('Reduced motion change');assert.equal(await save.first().evaluate(el=>getComputedStyle(el).animationName),'none');checks++;
 if(width===1600){
  for(const name of ['audience-flows','audience-analytics','audience-inbox','audience-designer','audience-campaign-list'])await page.addStyleTag({url:`https://brand.test/assets/css/pages/${name}.min.css`});
  await page.evaluate(()=>{const matrix=document.createElement('div');matrix.id='primary-button-matrix';matrix.innerHTML=[
   '<button class="primary">Create standard</button>',
   '<a class="button primary" href="#">Create link</a>',
   '<a class="audience-button primary" href="#">Create gallery</a>',
   '<div class="flow-catalog-hero"><a class="audience-button primary" href="#">Create flow</a></div>',
   '<div class="flow-task-body"><a class="audience-button primary" href="#">Create task</a></div>',
   '<div class="analytics-home-heading"><a class="audience-button primary" href="#">Create dashboard</a></div>',
   '<button class="primary campaign-list-create">Create campaign</button>',
   '<div class="inbox-main"><button class="primary">Create inbox</button></div>',
   '<div class="designer-color-dialog"><footer><button class="primary">Create designer</button></footer></div>'
  ].join('');document.querySelector('.audience-page-body').append(matrix);});
  for(const button of await page.locator('#primary-button-matrix .primary').all()){
   await page.mouse.move(0,0);assert.deepEqual(await button.evaluate(el=>({bg:getComputedStyle(el).backgroundColor,color:getComputedStyle(el).color})),{bg:'rgb(0, 124, 137)',color:'rgb(255, 255, 255)'});
   await button.hover();assert.deepEqual(await button.evaluate(el=>({bg:getComputedStyle(el).backgroundColor,color:getComputedStyle(el).color})),{bg:'rgb(0, 105, 115)',color:'rgb(255, 255, 255)'},await button.textContent());checks+=2;
  }
 }
 await page.goto('https://brand.test/audience.php?view=brand&role=editor');assert.equal(await save.first().isDisabled(),true);assert.equal(await save.last().isDisabled(),true);checks++;
 assert.deepEqual(errors,[]);await page.close();
}console.log('Brand Kit previews, save indicators and primary colors: '+checks+' browser checks passed.');}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
