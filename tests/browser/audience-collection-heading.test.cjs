'use strict';
// The shared editor, validator and renderer with synthetic save/publish responses.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(mode,kind,input)=>execFileSync('php',[fixture,mode,kind],{cwd:root,encoding:'utf8',input:JSON.stringify(input)});
const values=req=>req.headers()['content-type']?.includes('multipart/form-data')
    ?Object.fromEntries([...req.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]))
    :Object.fromEntries(new URLSearchParams(req.postData()));
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const kind of ['hosted','embedded','popup','landing','survey','preferences']){
    let document=JSON.parse(execFileSync('php',['-r',`require 'src/audience_store.php';$d=audienceCollectionDefaults('${kind}');$d['title']='Original heading';$d['origins']=['https://website.example'];echo json_encode($d);`],{cwd:root,encoding:'utf8'}));
    let state='draft',published=null;const name='Form name stays separate',posts=[],errors=[];
    const page=await browser.newPage({viewport:{width:1600,height:1000}});page.setDefaultTimeout(7000);page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',route=>{
        const req=route.request(),url=new URL(req.url());if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:php('editor',kind,{document,name,state,published_document:published})});
            const data=values(req);
            if(data.action==='collection_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',kind,{document:JSON.parse(data.document_json),nonce:data.preview_nonce})})});
            posts.push(data);document=JSON.parse(data.document_json);
            if(data.operation==='publish'){published=structuredClone(document);state='published';}
            return route.fulfill({contentType:'text/html',body:php('editor',kind,{document,name,state,published_document:published})});
        }
        const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    await page.goto('https://collection.test/audience.php?view=form&id=7');
    const frame=page.frameLocator('[data-collection-preview]'),heading=page.locator('[data-doc="title"]');
    await frame.locator('h1').filter({hasText:'Original heading'}).waitFor({state:'attached'});
    await page.locator('[data-collection-tab="design"]').click();assert.equal(await heading.evaluate(el=>el.required),false);checks++;
    await heading.fill('');
    const waitBlank=()=>page.waitForFunction(()=>document.querySelector('[data-collection-preview]').contentDocument?.querySelector('main')&&!document.querySelector('[data-collection-preview]').contentDocument.querySelector('h1'));
    await waitBlank();assert.equal(await heading.evaluate(el=>el.checkValidity()),true);assert.equal(await page.locator('[data-collection-preview-status]').textContent(),'');checks+=2;
    await page.locator('[data-collection-undo]').click();await frame.getByRole('heading',{name:'Original heading',exact:true}).waitFor();
    await page.locator('[data-collection-redo]').click();await waitBlank();assert.equal(await heading.inputValue(),'');checks++;
    await Promise.all([page.waitForNavigation(),page.locator('[data-collection-save]').first().click()]);
    await waitBlank();assert.equal(posts.at(-1).action,'collection_save');assert.equal(document.title,'');assert.equal(await heading.inputValue(),'');assert.equal(posts.at(-1).name,name);checks+=4;
    await page.locator('[data-collection-tab="publish"]').click();await page.locator('[data-open-dialog="collection-publish"]').click();
    await Promise.all([page.waitForNavigation(),page.locator('#collection-publish').getByRole('button',{name:'Publish',exact:true}).click()]);
    await waitBlank();assert.equal(posts.at(-1).operation,'publish');assert.equal(published.title,'');assert.equal(await heading.inputValue(),'');checks+=3;
    const publicHtml=php('public',kind,{document:published});assert.equal(publicHtml.includes('<h1>'),false);assert.equal(publicHtml.includes(name),false);assert.deepEqual(errors,[]);checks+=3;
    await page.close();
}
console.log(`Optional collection heading browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
