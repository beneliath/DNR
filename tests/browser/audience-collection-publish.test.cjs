'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const document=JSON.parse(execFileSync('php',['-r',"require 'src/audience_store.php';echo json_encode(audienceCollectionDocument(audienceCollectionDefaults('hosted'),'hosted',[],[],[]));"],{cwd:root,encoding:'utf8'}));
const name='Shalom in Messiah newsletter signup';
const render=input=>execFileSync('php',[fixture,'editor','hosted'],{cwd:root,encoding:'utf8',input:JSON.stringify({name,document,...input})});
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true])for(const width of [390,1600]){
    const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(7000);
    let html=render({});const posts=[],errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
    await page.route('**/*',route=>{
        const request=route.request(),url=new URL(request.url());if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(request.method()==='GET')return route.fulfill({contentType:'text/html',body:url.searchParams.get('view')==='forms'?'<h1>Forms list</h1>':html});
            const raw=request.postData(),data=request.headers()['content-type']?.includes('multipart/form-data')?Object.fromEntries([...raw.matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(match=>[match[1],match[2]])):Object.fromEntries(new URLSearchParams(raw));
            if(data.action==='collection_preview'){
                const preview=execFileSync('php',[fixture,'preview','hosted'],{cwd:root,encoding:'utf8',input:JSON.stringify({document:JSON.parse(data.document_json),nonce:data.preview_nonce})});
                return route.fulfill({contentType:'application/json',body:JSON.stringify({html:preview})});
            }
            posts.push(data);return route.fulfill({contentType:'text/html',body:'<h1>Forms list</h1>'});
        }
        const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    const go=()=>page.goto('https://collection.test/audience.php?view=form&id=7');
    const publish=page.locator('[data-open-dialog="collection-publish"]'),dialog=page.locator('#collection-publish');
    await go();assert.equal(await publish.evaluate(button=>getComputedStyle(button).animationName),'brand-save-blink');checks++;
    const back=page.getByRole('link',{name:'Back to Forms',exact:true});assert.equal(await back.getAttribute('href'),'audience.php?view=forms');
    assert(await back.evaluate(button=>button.getBoundingClientRect().right<=document.querySelector('.collection-editor-logo').getBoundingClientRect().left));checks+=2;
    await publish.click();await dialog.waitFor({state:'visible'});
    const layout=await dialog.evaluate(dialog=>{const range=document.createRange();range.selectNodeContents(dialog.querySelector('h2'));const box=dialog.getBoundingClientRect();return {lines:new Set([...range.getClientRects()].map(rect=>rect.top)).size,overflow:dialog.scrollWidth>dialog.clientWidth,left:box.left,right:box.right};});
    assert.equal(layout.overflow,false);assert(layout.left>=0&&layout.right<=width);assert.equal(layout.lines===1,width===1600);checks+=3;
    await dialog.getByRole('button',{name:'Cancel',exact:true}).click();assert.equal(posts.length,0);checks++;
    await page.emulateMedia({reducedMotion:'reduce'});assert.equal(await publish.evaluate(button=>getComputedStyle(button).animationName),'none');assert.equal(await publish.evaluate(button=>getComputedStyle(button).outlineWidth),'3px');checks+=2;
    await page.emulateMedia({reducedMotion:'no-preference'});
    html=render({published_document:document});await go();assert.equal(await page.locator('.collection-publish-pending').count(),0);checks++;
    await page.locator('[data-collection-rail="design"]').click();await page.locator('[data-doc="title"]').fill('Current unsaved title');
    await page.locator('#collection-editor [name="name"]').fill('Current unsaved name');
    assert.equal(await page.locator('.collection-publish-pending').count(),0);assert.equal(await page.locator('.collection-save-unsaved').count(),2);checks+=2;
    await publish.click();await dialog.getByRole('button',{name:'Publish',exact:true}).click();
    await page.getByRole('heading',{name:'Forms list'}).waitFor();
    assert.equal(posts.length,1);assert.equal(posts[0].action,'collection_operation');assert.equal(posts[0].operation,'publish');assert.equal(posts[0].name,'Current unsaved name');assert.equal(JSON.parse(posts[0].document_json).title,'Current unsaved title');checks+=5;
    html=render({document:{...document,intro:'A newer saved draft'},published_document:document});await go();assert.equal(await page.locator('.collection-publish-pending').count(),1);checks++;
    await back.click();await page.getByRole('heading',{name:'Forms list'}).waitFor();assert.equal(posts.length,1);checks++;
    assert.deepEqual(errors,[]);checks++;await page.close();
}
console.log(`Collection publish browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
