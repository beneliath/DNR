'use strict';
// Real editor templates and bundles; synthetic save/preview requests only.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(mode,kind,input)=>execFileSync('php',[fixture,mode,kind],{cwd:root,encoding:'utf8',input:JSON.stringify(input)});
const values=req=>req.headers()['content-type']?.includes('multipart/form-data')
    ?Object.fromEntries([...req.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]))
    :Object.fromEntries(new URLSearchParams(req.postData()));
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const kind of ['hosted','popup','embedded'])for(const width of [390,1600]){
    let document=JSON.parse(execFileSync('php',['-r',`require 'src/audience_store.php';$d=audienceCollectionDefaults('${kind}');$d['fields']=['email','first_name','last_name','custom:4'];echo json_encode($d);`],{cwd:root,encoding:'utf8'}));
    const errors=[],posts=[];const page=await browser.newPage({viewport:{width,height:1000},hasTouch:true});page.setDefaultTimeout(7000);
    await page.addInitScript(()=>localStorage.setItem('theme','dark'));page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/*',route=>{
        const req=route.request(),url=new URL(req.url());if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:php('editor',kind,{document})});
            const data=values(req);
            if(data.action==='collection_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',kind,{document:JSON.parse(data.document_json),nonce:data.preview_nonce})})});
            assert.equal(data.action,'collection_save');posts.push(data);document=JSON.parse(data.document_json);
            return route.fulfill({contentType:'text/html',body:php('editor',kind,{document})});
        }
        const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    await page.goto('https://collection.test/audience.php?view=form&id=7');await page.locator('[data-collection-tab="design"]').click();
    const list=page.locator('[data-collection-fields]'),grip=key=>page.locator(`[data-field="${key}"] [data-field-drag]`);
    const state=async()=>JSON.parse(await page.locator('[data-collection-document]').inputValue());
    const align=()=>list.evaluate(list=>{const form=list.closest('form');form.scrollTop+=list.getBoundingClientRect().top-form.getBoundingClientRect().top-12;});
    assert.equal(await page.locator('[data-field-move]').count(),0);assert.equal(await grip('email').count(),1);
    assert.equal(await list.locator('[data-field]:last-child').evaluate(row=>getComputedStyle(row).borderBottomWidth),'0px');checks+=3;
    assert.equal(await page.locator('.collection-section-divider').count(),3);
    assert.equal(await page.locator('.collection-interests-section').evaluate(section=>getComputedStyle(section).borderTopWidth),'0px');checks+=2;
    await align();
    const move=async(from,to,touch=false,finish=true)=>{
        const start=await grip(from).boundingBox(),end=await page.locator(`[data-field="${to}"]`).boundingBox();
        const x=start.x+start.width/2,y=start.y+start.height/2,target=end.y+end.height-3;
        if(touch){
            const session=await page.context().newCDPSession(page);
            await session.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y}]});
            for(let step=1;step<=8;step++)await session.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:y+(target-y)*step/8}]});
            await session.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await session.detach();
        }else{
            await page.mouse.move(x,y);await page.mouse.down();await page.mouse.move(x,target,{steps:8});
            if(finish)await page.mouse.up();
        }
    };
    await move('email','custom:4',width===390);
    assert.deepEqual((await state()).fields,['first_name','last_name','custom:4','email']);
    assert.match(await page.locator('#collection-field-order-status').textContent(),/Email address moved to position 4/);
    assert.equal(await list.locator('.collection-field-dragging').count(),0);checks+=3;
    await page.locator('[data-collection-undo]').click();assert.deepEqual((await state()).fields,['email','first_name','last_name','custom:4']);
    await page.locator('[data-collection-redo]').click();assert.deepEqual((await state()).fields,['first_name','last_name','custom:4','email']);checks+=2;
    await align();await grip('email').press('ArrowUp');
    assert.deepEqual((await state()).fields,['first_name','last_name','email','custom:4']);
    assert.equal(await grip('email').evaluate(handle=>handle===document.activeElement),true);checks+=2;
    await grip('email').press('Home');assert.deepEqual((await state()).fields,['email','first_name','last_name','custom:4']);checks++;
    // Escape cancels an in-progress drag and does not change the saved document.
    await align();await move('email','last_name',false,false);await page.keyboard.press('Escape');await page.mouse.up();
    assert.deepEqual((await state()).fields,['email','first_name','last_name','custom:4']);
    assert.equal(await list.locator('[data-field]').first().getAttribute('data-field'),'email');checks+=2;
    await align();await grip('email').press('End');
    await Promise.all([page.waitForNavigation(),page.locator('[data-collection-save]').first().click()]);
    assert.deepEqual((await state()).fields,['first_name','last_name','custom:4','email']);assert.deepEqual(document.fields,['first_name','last_name','custom:4','email']);assert.equal(posts.length,1);checks+=3;
    const html=php('public',kind,{document});assert(html.indexOf('name="first_name"')<html.indexOf('name="email"'));checks++;
    assert.equal(await page.evaluate(()=>scrollY),0);assert.deepEqual(errors,[]);checks+=2;
    if(process.env.DNR_REVIEW_OUTPUT&&kind==='embedded'&&width===1600){await page.locator('[data-collection-tab="design"]').click();await align();await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,'form-field-drag-handles.png')});}
    await page.close();
}
console.log(`Collection field order browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
