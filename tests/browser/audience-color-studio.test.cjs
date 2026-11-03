'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const html=execFileSync('php',['tests/browser/audience-tag-actions-fixture.php','admin','active','locked','2','','','brand'],{cwd:root,encoding:'utf8'});
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.DNR_BROWSER_EXECUTABLE?{executablePath:process.env.DNR_BROWSER_EXECUTABLE}:{})});
 try{
  for(const width of [390,1280])for(const dark of [false,true]){
   const page=await browser.newPage({viewport:{width,height:1000}}),errors=[],posts=[];
   page.on('pageerror',error=>errors.push(error.message));
   await page.addInitScript(dark=>{localStorage.setItem('theme',dark?'dark':'light');window.EyeDropper=undefined;},dark);
   await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!=='https://color-studio.test')return route.abort();
    if(url.pathname==='/audience.php'){if(request.method()==='POST')posts.push(request.postData());return route.fulfill({contentType:'text/html',body:html});}
    const file=path.join(root,'src',url.pathname);
    return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:{'.css':'text/css','.js':'text/javascript','.mjs':'text/javascript','.svg':'image/svg+xml'}[path.extname(file)]||'application/octet-stream'}):route.fulfill({status:404,body:''});
   });
   await page.goto('https://color-studio.test/audience.php?view=brand');
   const field=page.locator('[data-brand-form] [name=link]'),original=await field.inputValue();
   await field.locator('..').locator('button').click();
   const dialog=page.locator('.audience-color-studio[open]');await dialog.waitFor();
   assert(await dialog.locator('.color-studio-brand').count()>0,'Saved Brand Kit colors are selectable');
   const geometry=await dialog.evaluate(node=>{
    const box=n=>{const r=n.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,center:r.y+r.height/2};};
    const hex=node.querySelector('.color-studio-hex'),chip=node.querySelector('.color-studio-hex-row .color-studio-chip');
    return {hex:box(hex),chip:box(chip),cancel:box(node.querySelector('.color-studio-footer>div>button:not(.primary)')),apply:box(node.querySelector('.color-studio-apply')),chipImage:getComputedStyle(chip).backgroundImage,width:node.clientWidth,scroll:node.scrollWidth};
   });
   assert(Math.abs(geometry.hex.center-geometry.chip.center)<1,'Hex and color swatch are vertically aligned');
   assert(geometry.hex.width<=126,'Hex field is compact');assert.equal(geometry.chipImage,'none','Opaque chip displays its selected color');
   assert(Math.abs(geometry.cancel.center-geometry.apply.center)<1,'Cancel and Apply are vertically aligned');
   assert.equal(geometry.cancel.height,geometry.apply.height,'Cancel and Apply have equal heights');
   assert(geometry.scroll<=geometry.width+1,'Studio fits the viewport');
   const apply=dialog.locator('.color-studio-apply');await dialog.locator('.color-studio-hex').fill('#123456');
   assert.equal(await apply.evaluate(n=>getComputedStyle(n).animationName),'color-studio-apply-blink');
   assert.equal(await field.inputValue(),original,'Previewing does not save a color');
   await page.emulateMedia({reducedMotion:'reduce'});
   assert.equal(await apply.evaluate(n=>getComputedStyle(n).animationName),'none');
   assert.notEqual(await apply.evaluate(n=>getComputedStyle(n).outlineColor),'rgba(0, 0, 0, 0)');
   if(process.env.DNR_COLOR_STUDIO_OUTPUT){fs.mkdirSync(process.env.DNR_COLOR_STUDIO_OUTPUT,{recursive:true});await dialog.screenshot({path:path.join(process.env.DNR_COLOR_STUDIO_OUTPUT,`color-studio-${dark?'dark':'light'}-${width}.png`)});}
   await dialog.getByRole('button',{name:'Cancel',exact:true}).click();
   assert.equal(await field.inputValue(),original);assert.deepEqual(posts,[]);assert.deepEqual(errors,[]);await page.close();
  }
  const design={schema:1,styles:{font:'Tahoma'},rows:[{columns:[[{type:'text',text:'Newsletter formatting preview.'}]]}]};
  const editorHtml=execFileSync('php',['tests/browser/audience-image-editing-fixture.php','editor'],{cwd:root,input:JSON.stringify(design),encoding:'utf8'});
  for(const width of [390,1500]){
   const page=await browser.newPage({viewport:{width,height:1100}}),errors=[];page.on('pageerror',error=>errors.push(error.message));
   await page.route('**/*',route=>{
    const request=route.request(),url=new URL(request.url());if(url.origin!=='https://color-studio.test')return route.abort();
    if(url.pathname==='/audience.php')return route.fulfill({contentType:request.method()==='POST'?'application/json':'text/html',body:request.method()==='POST'?'{}':editorHtml});
    const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:{'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml'}[path.extname(file)]||'application/octet-stream'}):route.fulfill({status:404,body:''});
   });
   await page.goto('https://color-studio.test/audience.php?view=designer');
   const {openDesignerTools}=require('./helpers/responsive-workspace.cjs');
   await page.locator('#email-canvas .designer-block').first().dispatchEvent('click');await openDesignerTools(page);
   const rich=page.locator('.designer-rich-text');await rich.waitFor({state:'visible'});
   const geometry=await page.locator('.designer-rich-toolbar').evaluate(node=>{
    const rect=label=>{const r=node.querySelector(`[aria-label="${label}"]`).getBoundingClientRect();return {x:r.x,y:r.y,height:r.height};};const clear=node.querySelector('[aria-label="Clear styles"]');
    return {bold:rect('Bold selection'),strike:rect('Strikethrough'),undo:rect('Undo text change'),redo:rect('Redo text change'),clearFits:clear.scrollWidth<=clear.clientWidth+1,clearLabel:clear.textContent,clearIcon:!!clear.querySelector('svg'),clearTooltip:clear.getAttribute('title')||clear.getAttribute('data-audience-tooltip'),genericButtons:node.querySelectorAll('.action-icon-button').length};
   });
   console.log('Designer toolbar',width,JSON.stringify(geometry));assert.equal(await page.locator('.designer-panel>.app-footer').count(),0,'Sidebar leaves space for the text editor instead of app footer artwork');
   assert(geometry.clearFits,'Clear fits in the real designer');assert.equal(geometry.clearLabel,'Clear');assert(geometry.clearIcon);assert.equal(geometry.clearTooltip,'Clear styles');assert.equal(geometry.genericButtons,0,'Shared action normalization preserves compact editor controls');assert(Math.abs(geometry.undo.y-geometry.redo.y)<1,'Real designer keeps Undo and Redo together');
   if(width>580){assert.equal(geometry.bold.height,32);assert(Math.abs(geometry.bold.y-geometry.strike.y)<4,'Smaller icons keep Strikethrough on the first row in the real designer');}
   const closeGeometry=await page.locator('.designer-close-block').evaluate(button=>{const rect=button.getBoundingClientRect(),parent=button.parentElement,parentRect=parent.getBoundingClientRect(),style=getComputedStyle(parent),left=parseFloat(style.paddingLeft),right=parseFloat(style.paddingRight);return {x:rect.x,left:parentRect.x+left,width:rect.width,available:parentRect.width-left-right};});
   assert(Math.abs(closeGeometry.x-closeGeometry.left)<1,'Save & close is left aligned');if(width>580)assert(Math.abs(closeGeometry.width-closeGeometry.available*.25)<1,'Save & close uses one-quarter of the available width');
   if(process.env.DNR_COLOR_STUDIO_OUTPUT)await page.locator('.designer-rich-toolbar').screenshot({path:path.join(process.env.DNR_COLOR_STUDIO_OUTPUT,`designer-toolbar-${width}.png`)});
   const family=await rich.evaluate(node=>{const range=document.createRange();range.selectNodeContents(node);const selection=getSelection();selection.removeAllRanges();selection.addRange(range);document.dispatchEvent(new Event('selectionchange'));return getComputedStyle(node).fontFamily;});
   await page.getByRole('button',{name:'Selected text color',exact:true}).click();const picker=page.locator('.audience-color-studio[open]');
   assert.equal(await picker.locator('.color-studio-preview-text').evaluate(node=>getComputedStyle(node).fontFamily),family,'Real designer preview uses the selected font');
   assert.equal(await picker.locator('.color-studio-brand').count(),5,'Real designer exposes the shared account palette');
   await picker.getByRole('button',{name:'Cancel',exact:true}).click();assert.deepEqual(errors,[]);await page.close();
  }
  console.log('Color studio passed full-app desktop/mobile and light/dark checks: aligned swatch/hex, compact field, equal footer buttons, staged Apply blink, reduced motion, and no writes. Real designer toolbar, Strikethrough, font preview, and Brand Kit palette also passed.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
