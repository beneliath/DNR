'use strict';
// Real templates and bundles; all network requests and records are synthetic.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(args=[],input)=>execFileSync('php',[fixture,...args],{cwd:root,encoding:'utf8',input});
const images=JSON.parse(php(['images'])),html=php();
if(process.env.DNR_REVIEW_OUTPUT)fs.mkdirSync(process.env.DNR_REVIEW_OUTPUT,{recursive:true});
const fields=body=>Object.fromEntries([...body.matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]));
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true])for(const width of [390,1600]){
 const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(7000);const errors=[],calls=[];let failSave=true;page.on('pageerror',e=>errors.push(e.message));
 await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
 await page.route('**/*',route=>{
  const req=route.request(),url=new URL(req.url());if(url.origin!=='https://collection.test')return route.abort();
  if(url.pathname==='/audience.php'){
   if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:html});
   const data=req.headers()['content-type']?.includes('multipart/form-data')?fields(req.postData()):Object.fromEntries(new URLSearchParams(req.postData()));calls.push(data);
   if(data.action==='collection_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php(['preview'],JSON.stringify({document:JSON.parse(data.document_json),nonce:data.preview_nonce}))})});
   if(data.action==='collection_library'){
    const all=data.search==='empty'?[]:Array.from({length:data.search?1:28},(_,i)=>({id:i+1,name:i===0?'Content image':`Image ${i+1}`}));const pageNumber=Math.min(Number(data.page),Math.max(1,Math.ceil(all.length/Number(data.per_page))));
    return route.fulfill({contentType:'application/json',body:JSON.stringify({items:all.slice((pageNumber-1)*Number(data.per_page),pageNumber*Number(data.per_page)),total:all.length,page:pageNumber,per_page:Number(data.per_page)})});
   }
   if(data.action==='collection_library_image')return route.fulfill({contentType:'application/json',body:JSON.stringify({image:images.library,alt:'Content image'})});
   if(data.action==='collection_save')return route.fulfill({contentType:'text/html',body:php(['editor'],JSON.stringify({document:JSON.parse(data.document_json),name:data.name,recovered:failSave}))});
   throw new Error('Unexpected write: '+data.action);
  }
  const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
 });
 await page.goto('https://collection.test/audience.php?view=form&id=7');
 const frame=page.frameLocator('[data-collection-preview]');
 const documentState=async()=>JSON.parse(await page.locator('[data-collection-document]').inputValue());
 const saves=page.locator('[data-collection-save]');assert.equal(await saves.count(),2);assert.equal(await page.locator('.collection-save-unsaved').count(),0);checks+=2;
 await page.locator('[data-collection-rail="design"]').click();const heading=page.locator('[data-doc="title"]'),originalHeading=await heading.inputValue();
 await heading.fill('Edited preferences');assert.equal(await page.locator('.collection-save-unsaved').count(),2);assert.equal(await saves.last().evaluate(el=>getComputedStyle(el).animationName),'brand-save-blink');checks+=2;
 await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await saves.last().evaluate(el=>getComputedStyle(el).animationName),'none');checks++;
 await page.emulateMedia({reducedMotion:'no-preference'});await heading.fill(originalHeading);assert.equal(await page.locator('.collection-save-unsaved').count(),0);checks++;
 const formName=page.locator('#collection-editor [name="name"]'),originalName=await formName.inputValue();await formName.fill('Renamed preferences');assert.equal(await page.locator('.collection-save-unsaved').count(),2);await formName.fill(originalName);assert.equal(await page.locator('.collection-save-unsaved').count(),0);checks+=2;
 await page.locator('[data-collection-rail="elements"]').click();

 const dragResize=async(label,delta)=>{
  const handle=frame.getByRole('slider',{name:label,exact:true});await handle.waitFor();await handle.scrollIntoViewIfNeeded();await handle.focus();
  const before=Number(await handle.getAttribute('aria-valuenow')),box=await handle.boundingBox();
  await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await page.mouse.down();await page.mouse.move(box.x+box.width/2+delta,box.y+box.height/2,{steps:6});await page.mouse.up();
  return before;
 };

 await page.locator('[data-collection-insert-logo="logo"]').click();await frame.locator('img.logo').waitFor();assert.equal(await frame.locator('img.logo').getAttribute('src'),images.primary);checks++;
 assert.equal(await page.locator('.collection-save-unsaved').count(),2);await page.locator('[data-collection-undo]').click();await page.waitForFunction(()=>document.querySelectorAll('.collection-save-unsaved').length===0);checks++;
 await page.locator('[data-collection-redo]').click();await page.waitForFunction(()=>document.querySelectorAll('.collection-save-unsaved').length===2);checks++;
 const originalLogoWidth=await dragResize('Resize logo',-31);
 await page.waitForFunction(original=>{const d=JSON.parse(document.querySelector('[data-collection-document]').value);return d.logo_resized&&d.logo_width<original;},originalLogoWidth);checks++;
 assert.equal(await page.locator('[data-doc="logo_width"]').evaluate(el=>el.validity.valid),true);checks++;
 const resizedLogo=await documentState();await page.waitForFunction(w=>{const root=document.querySelector('[data-collection-preview]').contentDocument?.documentElement;return root&&getComputedStyle(root).getPropertyValue('--collection-logo-width').trim()===w+'px';},resizedLogo.logo_width);
 const logoGeometry=await frame.locator('img.logo').evaluate(el=>({w:el.getBoundingClientRect().width,h:el.getBoundingClientRect().height}));assert(Math.abs(logoGeometry.w/logoGeometry.h-260/90)<.02);assert(Math.abs(logoGeometry.w-resizedLogo.logo_width)<1);checks++;
 const savedLogo=php(['public'],JSON.stringify({document:resizedLogo}));assert(savedLogo.includes('--collection-logo-width:'+resizedLogo.logo_width+'px;--collection-logo-height:none'));checks++;
 await page.locator('[data-collection-undo]').click();await page.waitForFunction(()=>!JSON.parse(document.querySelector('[data-collection-document]').value).logo_resized);checks++;
 await page.locator('[data-collection-insert-logo="secondary_logo"]').click();await page.waitForFunction(value=>JSON.parse(document.querySelector('[data-collection-document]').value).logo===value,images.secondary);
 await frame.locator('img.logo').waitFor();await frame.locator('.collection-editable-block').first().click();await frame.getByRole('button',{name:'Remove logo',exact:true}).click();await page.waitForFunction(()=>JSON.parse(document.querySelector('[data-collection-document]').value).logo==='');checks++;
 await page.locator('[data-collection-undo]').click();await page.waitForFunction(value=>JSON.parse(document.querySelector('[data-collection-document]').value).logo===value,images.secondary);checks++;
 await page.locator('[data-collection-rail="design"]').click();await page.locator('[data-doc="logo"]').evaluate(el=>el.closest('details').open=true);
 await page.getByRole('button',{name:'Browse images',exact:true}).last().click();const picker=page.locator('#collection-images');
 await picker.locator('.collection-image-preview img').first().waitFor();assert.equal(await picker.locator('.collection-image-card').count(),25);assert.equal(await picker.locator('.collection-image-preview img').first().evaluate(img=>img.complete&&img.naturalWidth>0),true);checks++;
 if(process.env.DNR_REVIEW_OUTPUT)await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,`content-picker-${width}-${dark?'dark':'light'}.png`)});
 const geometry=await picker.evaluate(el=>({scroll:el.scrollWidth,width:el.clientWidth}));assert(geometry.scroll<=geometry.width+1);checks++;
 await picker.getByRole('button',{name:'Next page',exact:true}).click();await page.waitForFunction(()=>document.querySelectorAll('.collection-image-card').length===3);checks++;
 await picker.locator('[data-collection-image-search]').fill('image');await picker.locator('[data-collection-image-find]').click();await page.waitForFunction(()=>document.querySelectorAll('.collection-image-card').length===1);await picker.getByRole('button',{name:'Content image',exact:true}).click();await page.waitForFunction(value=>JSON.parse(document.querySelector('[data-collection-document]').value).logo===value,images.library);checks++;
 await page.getByRole('button',{name:'× Remove logo',exact:true}).click();await page.waitForFunction(()=>JSON.parse(document.querySelector('[data-collection-document]').value).logo==='');checks++;
 await page.getByRole('button',{name:'Browse images',exact:true}).last().click();await picker.locator('[data-collection-image-search]').fill('empty');await picker.locator('[data-collection-image-find]').click();await picker.getByText('No images found.',{exact:true}).waitFor();assert.equal(await picker.locator('.collection-image-card').count(),0);await picker.getByRole('button',{name:'Close',exact:true}).click();checks++;
 await page.locator('[data-collection-rail="elements"]').click();await page.locator('[data-add-layout="image"]').click();
 const section=page.locator('[data-section-list] .collection-item').first();await section.getByRole('button',{name:'Browse images',exact:true}).click();await picker.locator('[data-collection-image-search]').fill('image');await picker.locator('[data-collection-image-find]').click();await picker.getByRole('button',{name:'Content image',exact:true}).click();await frame.locator('img.section-image').waitFor();
 const originalImageWidth=await dragResize('Resize image section',-35);
 await page.waitForFunction(original=>{const d=JSON.parse(document.querySelector('[data-collection-document]').value);return d.sections[0]?.width<original;},originalImageWidth);checks++;
 assert.equal(await section.getByLabel('Image width (pixels)',{exact:true}).evaluate(el=>el.validity.valid),true);checks++;
 const resizedImage=await documentState();assert(php(['public'],JSON.stringify({document:resizedImage})).includes('class="section-image" width="'+resizedImage.sections[0].width+'"'));checks++;
 await page.waitForFunction(w=>document.querySelector('[data-collection-preview]').contentDocument?.querySelector('img.section-image')?.getAttribute('width')===String(w),resizedImage.sections[0].width);
 const resizedHandle=frame.getByRole('slider',{name:'Resize image section',exact:true});await resizedHandle.press('ArrowLeft');await page.waitForFunction(w=>JSON.parse(document.querySelector('[data-collection-document]').value).sections[0]?.width===w-10,resizedImage.sections[0].width);checks++;
 await page.locator('[data-collection-undo]').click();await page.waitForFunction(w=>JSON.parse(document.querySelector('[data-collection-document]').value).sections[0]?.width===w,resizedImage.sections[0].width);checks++;
 await frame.locator('[data-collection-section]').first().click();await frame.getByRole('button',{name:'Remove image section',exact:true}).click();await page.waitForFunction(()=>JSON.parse(document.querySelector('[data-collection-document]').value).sections.length===0);checks++;

 const document=await page.locator('[data-collection-document]').inputValue();const publicHtml=php(['public'],JSON.stringify({document:JSON.parse(document)}));assert(!publicHtml.includes('collection-block-remove'));assert(!publicHtml.includes('data-collection-section'));checks++;
 await saves.last().click();await page.waitForFunction(()=>JSON.parse(document.getElementById('collection-config').textContent).recoveredDraft===true);assert.equal(await page.locator('.collection-save-unsaved').count(),2);checks++;
 failSave=false;await saves.first().click();await page.waitForFunction(()=>JSON.parse(document.getElementById('collection-config').textContent).recoveredDraft===false);assert.equal(await page.locator('.collection-save-unsaved').count(),0);checks++;
 assert.deepEqual(errors,[]);assert(calls.every(data=>['collection_preview','collection_library','collection_library_image','collection_save'].includes(data.action)));checks++;

 if(process.env.DNR_REVIEW_OUTPUT){fs.mkdirSync(process.env.DNR_REVIEW_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,`preference-editor-${width}-${dark?'dark':'light'}.png`)});}
 await page.close();
}console.log('Collection image/logo browser checks passed: '+checks);}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
