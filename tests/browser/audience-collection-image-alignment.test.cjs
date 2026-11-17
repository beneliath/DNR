'use strict';
// Real editor/preview/public templates with synthetic save responses; no database writes.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(mode,input)=>execFileSync('php',[fixture,mode,'landing'],{cwd:root,encoding:'utf8',input:JSON.stringify(input)});
const images=JSON.parse(php('images'));
const defaults=JSON.parse(execFileSync('php',['-r',"require 'src/audience_store.php';echo json_encode(audienceCollectionDefaults('landing'));"],{cwd:root,encoding:'utf8'}));
const fields=req=>req.headers()['content-type']?.includes('multipart/form-data')
    ?Object.fromEntries([...req.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]))
    :Object.fromEntries(new URLSearchParams(req.postData()));

(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true]){
    let saved={...structuredClone(defaults),sections:[{type:'image',url:images.library,text:'Example image',width:187}]};
    const page=await browser.newPage({viewport:{width:1600,height:1000}});page.setDefaultTimeout(7000);
    const errors=[],writes=[];page.on('pageerror',error=>errors.push(error.message));
    await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
    await page.route('**/*',route=>{
        const req=route.request(),url=new URL(req.url());if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:php('editor',{document:saved})});
            const data=fields(req);
            if(data.action==='collection_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',{document:JSON.parse(data.document_json),nonce:data.preview_nonce})})});
            assert.equal(data.action,'collection_save');writes.push(data);saved=JSON.parse(data.document_json);
            return route.fulfill({contentType:'text/html',body:php('editor',{document:saved,name:data.name})});
        }
        const file=path.join(root,'src',url.pathname);
        return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    await page.goto('https://collection.test/audience.php?view=form&id=7');
    const frame=page.frameLocator('[data-collection-preview]'),image=frame.locator('img.section-image');
    const alignment=page.getByRole('combobox',{name:'Image Alignment',exact:true});
    const state=async()=>JSON.parse(await page.locator('[data-collection-document]').inputValue());
    const waitPreview=(align,width)=>page.waitForFunction(({align,width})=>{
        const img=document.querySelector('[data-collection-preview]').contentDocument?.querySelector('img.section-image');
        return img?.complete&&img.naturalWidth>0&&img.parentElement.dataset.imageAlign===align&&img.parentElement.querySelector('.collection-image-resize')&&(!width||img.getAttribute('width')===String(width));
    },{align,width});
    const checkPosition=async(locator,align)=>{
        const bounds=await locator.evaluate(img=>{
            const main=img.closest('main'),style=getComputedStyle(main),box=main.getBoundingClientRect(),rect=img.getBoundingClientRect();
            return {left:rect.left-box.left-parseFloat(style.borderLeftWidth)-parseFloat(style.paddingLeft),right:box.right-parseFloat(style.borderRightWidth)-parseFloat(style.paddingRight)-rect.right};
        });
        assert(bounds.left>=-1&&bounds.right>=-1,'Image stays inside the form');
        assert(Math.abs(align==='center'?bounds.left-bounds.right:bounds[align])<1,`${align} alignment: ${JSON.stringify(bounds)}`);checks+=2;
    };
    await page.locator('[data-collection-rail="design"]').click();await page.locator('[data-section-list]').evaluate(el=>el.closest('details').open=true);
    await waitPreview('left',187);assert.equal(await alignment.inputValue(),'left');assert.equal(await page.locator('.collection-save-unsaved').count(),0);checks+=2;
    const publicPage=await browser.newPage();await publicPage.route('**/*',route=>route.abort());
    for(const align of ['left','center','right']){
        await alignment.selectOption(align);await waitPreview(align,187);
        assert.equal((await state()).sections[0].align,align);checks++;
        for(const [device,width]of[['desktop',1600],['mobile',390]]){
            await page.locator(`[data-collection-device="${device}"]`).click();await checkPosition(image,align);
            await publicPage.setViewportSize({width,height:1000});
            await publicPage.setContent(php('public',{document:await state()}));
            await publicPage.locator('img.section-image').evaluate(img=>img.decode());await checkPosition(publicPage.locator('img.section-image'),align);
            await publicPage.locator('html').evaluate(el=>el.dir='rtl');await checkPosition(publicPage.locator('img.section-image'),align);
            // Large fixed widths must remain responsive without changing alignment.
            const oversized=await state();oversized.sections[0].width=1200;
            await publicPage.setContent(php('public',{document:oversized}));
            await publicPage.locator('img.section-image').evaluate(img=>img.decode());await checkPosition(publicPage.locator('img.section-image'),align);
        }
        await page.locator('[data-collection-device="desktop"]').click();
        if(align!=='left'){
            const handle=frame.getByRole('slider',{name:'Resize image section',exact:true});await handle.scrollIntoViewIfNeeded();
            const box=await handle.boundingBox(),x=box.x+box.width/2,y=box.y+box.height/2;
            await page.mouse.move(x,y);await page.mouse.down();await page.mouse.move(x+(align==='right'?20:-10),y,{steps:6});await page.mouse.up();
            await waitPreview(align,167);assert.equal((await state()).sections[0].width,167);await checkPosition(image,align);checks++;
            await handle.press('ArrowRight');await waitPreview(align,177);await checkPosition(image,align);
            await page.getByLabel('Image Width (Pixels)',{exact:true}).fill('187');await waitPreview(align,187);
        }
    }
    await publicPage.close();
    await alignment.selectOption('center');await waitPreview('center');
    await page.locator('[data-collection-undo]').click();await waitPreview('right');assert.equal(await alignment.inputValue(),'right');checks++;
    await page.locator('[data-collection-redo]').click();await waitPreview('center');assert.equal(await alignment.inputValue(),'center');checks++;
    await Promise.all([page.waitForNavigation(),page.locator('[data-collection-save]').first().click()]);
    await page.goto('https://collection.test/audience.php?view=form&id=7');await waitPreview('center',187);await checkPosition(image,'center');
    await page.locator('[data-collection-rail="design"]').click();await page.locator('[data-section-list]').evaluate(el=>el.closest('details').open=true);
    assert.equal(await alignment.inputValue(),'center');assert.equal(saved.sections[0].align,'center');assert.equal(writes.length,1);assert.equal(await page.locator('.collection-save-unsaved').count(),0);checks+=4;
    if(process.env.DNR_REVIEW_OUTPUT){
        fs.mkdirSync(process.env.DNR_REVIEW_OUTPUT,{recursive:true});
        await page.locator('[data-collection-rail="design"]').click();await alignment.evaluate(el=>el.closest('details').open=true);await alignment.scrollIntoViewIfNeeded();
        await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,`image-alignment-${dark?'dark':'light'}.png`)});
    }
    assert.deepEqual(errors,[]);checks++;await page.close();
}
console.log(`Collection image alignment browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
