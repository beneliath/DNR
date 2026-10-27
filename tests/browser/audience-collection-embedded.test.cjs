'use strict';
// The real shared editor, validator, public renderer and bundles; synthetic records only.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(mode,input)=>execFileSync('php',[fixture,mode,'embedded'],{cwd:root,encoding:'utf8',input:JSON.stringify(input)});
const initial=JSON.parse(execFileSync('php',['-r',"require 'src/audience_store.php';$d=audienceCollectionDefaults('embedded');$d['width']=0;$d['fields']=['custom:1','email','first_name','custom:5'];$d['required']=['email','custom:5'];$d['labels']['custom:1']='Your name';$d['help_text']['custom:1']='Saved help';$d['tag_ids']=[7];$d['origins']=['https://website.example'];$d['embedded']=['show_title'=>false,'remove_css'=>true,'disable_javascript'=>true,'show_format'=>true,'show_archive'=>true,'badge'=>true,'badge_style'=>'dark'];echo json_encode($d);"],{cwd:root,encoding:'utf8'}));
const values=request=>request.headers()['content-type']?.includes('multipart/form-data')
    ?Object.fromEntries([...request.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(match=>[match[1],match[2]]))
    :Object.fromEntries(new URLSearchParams(request.postData()));
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true])for(const width of [390,1600]){
    let tags=[{id:7,name:'Existing tag'}],document=structuredClone(initial),published=structuredClone(initial),state='published',writes=[];
    const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(7000);
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
    await page.route('**/*',route=>{
        const request=route.request(),url=new URL(request.url());
        if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(request.method()==='GET')return route.fulfill({contentType:'text/html',body:php('editor',{document,published_document:published,tags,state})});
            const data=values(request);
            if(data.action==='collection_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',{document:JSON.parse(data.document_json),nonce:data.preview_nonce,tags})})});
            writes.push(data);
            if(data.action==='collection_new_tag'){
                tags.push({id:9,name:data.tag_name});
                return route.fulfill({contentType:'application/json',body:JSON.stringify(tags.at(-1))});
            }
            if(data.action==='collection_save'||data.operation==='publish'){
                document=JSON.parse(data.document_json);
                if(data.operation==='publish'){published=structuredClone(document);state='published';}
                return route.fulfill({contentType:'text/html',body:php('editor',{document,published_document:published,tags,state})});
            }
            throw new Error('Unexpected write: '+data.action);
        }
        const file=path.join(root,'src',url.pathname);
        return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    await page.goto('https://collection.test/audience.php?view=form&id=7');
    const model=async()=>JSON.parse(await page.locator('[data-collection-document]').inputValue());
    const tab=key=>page.locator(`[data-collection-rail="${key}"]`).click();
    const frame=page.frameLocator('[data-collection-preview]');
    await frame.locator('.collection-embedded').waitFor();
    assert.equal(await page.locator('.collection-builder').count(),1);
    assert.equal(await page.locator('[data-collection-undo],[data-collection-redo],[data-collection-device]').count(),4);
    assert.equal(await page.locator('footer,.embedded-workspace').count(),0);checks+=3;
    assert.equal(await page.locator('[data-field="custom:1"],[data-field="custom:2"],[data-field="custom:3"],[data-add-field="custom:1"],[data-add-field="custom:2"],[data-add-field="custom:3"]').count(),0);
    assert.equal(await page.locator('[data-doc-label],[data-doc-help],.collection-field-details').count(),0);checks+=2;
    let current=await model();
    for(const key of ['fields','required','origins','embedded','tag_ids'])assert.deepEqual(current[key],initial[key]);
    assert.equal(current.width,0);assert.equal(current.labels['custom:1'],'Your name');assert.equal(current.help_text['custom:1'],'Saved help');checks+=8;
    await tab('setup');
    const setup=page.locator('[data-collection-panel="setup"]');
    assert.equal(await setup.locator('[data-embed-option]').count(),7);
    assert.equal(await setup.locator('[data-doc="width"]').inputValue(),'');
    assert.equal(await setup.locator('[data-doc-lines="origins"]').inputValue(),'https://website.example');checks+=3;
    await tab('publish');
    const code=page.locator('[data-collection-embed-code]'),copy=page.locator('[data-copy-collection-embed]');
    assert.match(await code.inputValue(),/^<iframe/);assert.equal(await copy.isDisabled(),false);checks+=2;
    await tab('setup');await page.locator('[data-embed-option="disable_javascript"]').uncheck();
    await page.locator('[data-collection-undo]').click();
    assert.equal(await page.locator('[data-embed-option="disable_javascript"]').isChecked(),true);assert.equal(await page.locator('[data-doc="width"]').inputValue(),'');checks+=2;
    await page.locator('[data-collection-redo]').click();
    assert.equal(await page.locator('[data-embed-option="disable_javascript"]').isChecked(),false);checks++;
    await page.locator('[data-embed-option="remove_css"]').uncheck();
    await page.locator('[data-embed-option="show_title"]').check();
    await page.locator('[data-embed-option="badge_style"]').selectOption('light');
    await page.locator('[data-doc="width"]').fill('480');
    await tab('publish');assert.match(await code.inputValue(),/^<script/);assert.equal(await copy.isDisabled(),true);checks+=2;
    await tab('design');
    const row=page.locator('[data-field="first_name"]');
    await row.locator('[data-field-drag]').press('ArrowDown');
    assert.deepEqual((await model()).fields,['custom:1','email','custom:5','first_name']);
    assert.equal((await model()).help_text['custom:1'],'Saved help');checks+=2;
    await tab('setup');await page.locator('[data-collection-tag-name]').fill('New signup tag');await page.locator('[data-collection-tag-create]').click();
    await page.locator('[data-collection-tag-status]').filter({hasText:'Tag added.'}).waitFor();
    assert.deepEqual((await model()).tag_ids,[7,9]);checks++;
    await page.locator('[data-collection-save]').first().click();
    await page.waitForFunction(()=>!document.querySelector('.collection-save-unsaved'));
    await tab('publish');assert.equal(await copy.isDisabled(),true);checks++;
    await page.locator('[data-open-dialog="collection-publish"]').click();
    await page.locator('#collection-publish').getByRole('button',{name:'Publish',exact:true}).click();
    await page.waitForFunction(()=>!document.querySelector('.collection-publish-pending'));
    await tab('publish');assert.equal(await copy.isDisabled(),false);assert.match(await code.inputValue(),/^<script/);checks+=2;
    current=await model();assert.equal(current.width,480);assert.equal(current.embedded.badge_style,'light');assert.equal(current.labels['custom:1'],'Your name');assert.equal(current.help_text['custom:1'],'Saved help');assert.deepEqual(current.tag_ids,[7,9]);checks+=5;
    const publicHtml=php('public',{document:current,tags});
    assert.match(publicHtml,/Saved help/);assert.match(publicHtml,/collection-referral-light/);assert.match(publicHtml,/--collection-width:480px/);assert.match(publicHtml,/name="email_format"/);checks+=4;
    await tab('setup');await page.locator('[data-doc="width"]').fill('');
    await page.locator('[data-collection-undo]').click();assert.equal(await page.locator('[data-doc="width"]').inputValue(),'480');
    await page.locator('[data-collection-redo]').click();assert.equal(await page.locator('[data-doc="width"]').inputValue(),'');assert.equal((await model()).width,0);checks+=3;
    // Editor layout has fixed chrome and independently scrolling panes on desktop and mobile.
    const layout=await page.evaluate(()=>({overflow:document.documentElement.scrollHeight>innerHeight+1,railOverflow:document.querySelector('.collection-rail').scrollHeight>document.querySelector('.collection-rail').clientHeight+1}));
    assert.equal(layout.overflow,false);assert.equal(layout.railOverflow,false);checks+=2;
    assert.equal(writes.filter(data=>data.action==='collection_save').length,1);assert.equal(writes.filter(data=>data.operation==='publish').length,1);assert.deepEqual(errors,[]);checks+=3;
    if(process.env.DNR_REVIEW_OUTPUT&&width===1600){await tab('setup');await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,`embedded-shared-${dark?'dark':'light'}.png`)});}
    await page.close();
}
// Reviewers can inspect the shared editor without writing or installing unpublished drafts.
const readonly=await browser.newPage();await readonly.route('**/*',route=>{
    const url=new URL(route.request().url());if(url.pathname==='/audience.php'){
        if(route.request().method()==='POST')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',{document:initial,nonce:values(route.request()).preview_nonce})})});
        return route.fulfill({contentType:'text/html',body:php('editor',{document:initial,role:'reviewer',state:'published',published_document:initial})});
    }
    const file=path.join(root,'src',url.pathname);return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':'text/javascript'}):route.abort();
});
await readonly.goto('https://collection.test/audience.php?view=form&id=7');await readonly.locator('[data-collection-rail="setup"]').click();
assert.equal(await readonly.locator('[data-embed-option="show_title"]').isDisabled(),true);assert.equal(await readonly.locator('[data-collection-save]').count(),0);checks+=2;await readonly.close();
console.log(`Embedded shared editor browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
