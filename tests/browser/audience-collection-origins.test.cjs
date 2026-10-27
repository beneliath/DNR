'use strict';
// Real editor templates and bundles; synthetic publish responses, no database or mail.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(mode,kind,input)=>execFileSync('php',[fixture,mode,kind],{cwd:root,encoding:'utf8',input:JSON.stringify(input)});
const defaults=kind=>JSON.parse(execFileSync('php',['-r',`require 'src/audience_store.php';echo json_encode(audienceCollectionDefaults('${kind}'));`],{cwd:root,encoding:'utf8'}));
const values=req=>req.headers()['content-type']?.includes('multipart/form-data')
    ?Object.fromEntries([...req.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]))
    :Object.fromEntries(new URLSearchParams(req.postData()));
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const kind of ['popup','embedded'])for(const dark of [false,true])for(const width of [390,1600]){
    const document=defaults(kind),posts=[],errors=[];let html=php('editor',kind,{document});
    const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(7000);
    page.on('pageerror',error=>errors.push(error.message));await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
    await page.route('**/*',route=>{
        const req=route.request(),url=new URL(req.url());if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:html});
            const data=values(req);
            if(data.action==='collection_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',kind,{document:JSON.parse(data.document_json),nonce:data.preview_nonce})})});
            posts.push(data);const saved=JSON.parse(data.document_json);
            html=php('editor',kind,{document:saved,name:data.name,state:'published',published_document:saved});
            return route.fulfill({contentType:'text/html',body:html});
        }
        const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    const go=()=>page.goto('https://collection.test/audience.php?view=form&id=7');
    await go();await page.locator('[data-collection-rail="publish"]').click();
    if(kind==='popup'){
        await page.getByRole('heading',{name:'Install your popup on your website',exact:true}).waitFor();
        assert.equal(await page.getByText('Your hosted page',{exact:true}).count(),0);
        assert.equal(await page.locator('#collection-public-url').count(),0);
        assert.match(await page.locator('[data-collection-panel="publish"]').textContent(),/display rules in Setup/);checks+=3;
    }
    assert.match(await page.locator('[data-collection-embed-code]').inputValue(),/^<script/);
    assert.equal(await page.locator('[data-copy-collection-embed]').isDisabled(),true);checks+=2;
    await page.locator('[data-collection-rail="design"]').click();await page.locator('[data-doc="title"]').fill('Keep my unsaved heading');
    await page.locator('[data-collection-focus-preview]').click();
    const top=await page.locator('.collection-editor-top').evaluate(el=>el.getBoundingClientRect().top);
    const publish=page.locator('[data-open-dialog="collection-publish"]'),dialog=page.locator('#collection-publish');
    await publish.click();await dialog.getByRole('button',{name:'Publish',exact:true}).click();
    const input=page.locator('#collection-website-origins'),error=page.locator('#collection-origins-error');
    await error.waitFor();assert.equal(await dialog.isVisible(),false);assert.equal(await input.isVisible(),true);
    assert.equal(await page.locator('[data-collection-tab="setup"]').getAttribute('aria-pressed'),'true');
    assert.equal(await input.getAttribute('aria-invalid'),'true');
    assert.equal(await input.evaluate(el=>el===document.activeElement),true);
    assert.equal(await input.evaluate(el=>getComputedStyle(el).outlineWidth),'2px');
    assert.equal(posts.length,0);assert.equal(JSON.parse(await page.locator('[data-collection-document]').inputValue()).title,'Keep my unsaved heading');checks+=8;
    const geometry=await input.evaluate(el=>{const field=el.getBoundingClientRect(),pane=el.closest('form').getBoundingClientRect();return {visible:field.top>=pane.top&&field.bottom<=pane.bottom,scroll:scrollY,bodyOverflow:document.documentElement.scrollHeight>innerHeight+1};});
    assert.equal(geometry.visible,true);assert.equal(geometry.scroll,0);assert.equal(geometry.bodyOverflow,false);
    assert.equal(await page.locator('.collection-editor-top').evaluate(el=>el.getBoundingClientRect().top),top);checks+=4;
    await input.fill('https://website.example');assert.equal(await error.isHidden(),true);assert.equal(await input.getAttribute('aria-invalid'),null);checks+=2;
    await publish.click();await dialog.getByRole('button',{name:'Publish',exact:true}).click();
    await page.waitForFunction(()=>!document.querySelector('.collection-publish-pending'));
    assert.equal(posts.length,1);assert.equal(posts[0].operation,'publish');
    const submitted=JSON.parse(posts[0].document_json);assert.deepEqual(submitted.origins,['https://website.example']);assert.equal(submitted.title,'Keep my unsaved heading');checks+=4;
    await page.locator('[data-collection-rail="publish"]').click();assert.equal(await page.locator('[data-copy-collection-embed]').isDisabled(),false);checks++;
    // Server-rejected publication carries a field identifier and recovers the entered document.
    document.title='Recovered unsaved heading';
    html=php('editor',kind,{document,recovered:true,editorError:{field:'origins',message:'Add the HTTPS website origin under Setup → Website origins before publishing this form.'}});
    await go();await error.waitFor();assert.equal(await input.evaluate(el=>el===document.activeElement),true);
    assert.equal(await input.getAttribute('aria-invalid'),'true');assert.equal(await page.locator('[data-collection-tab="setup"]').getAttribute('aria-pressed'),'true');
    assert.equal(JSON.parse(await page.locator('[data-collection-document]').inputValue()).title,'Recovered unsaved heading');checks+=4;
    // Origins stay optional when saving a draft, including after a publication error.
    await page.locator('[data-collection-save]').first().click();await page.waitForFunction(()=>!document.querySelector('.collection-save-unsaved'));
    assert.equal(posts.at(-1).action,'collection_save');assert.deepEqual(JSON.parse(posts.at(-1).document_json).origins,[]);checks+=2;
    assert.deepEqual(errors,[]);checks++;await page.close();
}
console.log(`Collection origins browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
