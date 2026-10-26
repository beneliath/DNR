'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const browsers=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const engine=process.env.DNR_PLAYWRIGHT_BROWSER||'chromium';
const root=path.resolve(__dirname,'../..'),fixture=path.join(root,'tests/browser/audience-collection-images-fixture.php');
const php=(mode,input)=>execFileSync('php',[fixture,mode,'survey'],{cwd:root,encoding:'utf8',input});
const fields=body=>Object.fromEntries([...body.matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]));
(async()=>{const browser=await browsers[engine].launch({headless:true});let checks=0;
try{for(const role of ['admin','reviewer'])for(const width of [390,1600]){
    const html=php(role),page=await browser.newPage({viewport:{width,height:1000}}),errors=[],calls=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('https://survey.test/**',route=>{
        const req=route.request(),url=new URL(req.url());
        if(url.pathname==='/audience.php'){
            if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:html});
            const data=fields(req.postData());calls.push(data.action);
            assert.equal(data.action,'collection_preview','Device controls must not save or publish.');
            assert.equal(data.csrf_token,'fixture-token');assert.equal(data.id,'7');
            return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',JSON.stringify({document:JSON.parse(data.document_json),nonce:data.preview_nonce}))})});
        }
        if(url.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
        const file=path.join(root,'src',url.pathname);
        return route.fulfill({status:fs.existsSync(file)?200:404,body:fs.existsSync(file)?fs.readFileSync(file):'',contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'});
    });
    await page.goto('https://survey.test/audience.php?view=form&id=7');
    const form=page.locator('#collection-editor'),panel=page.locator('.collection-preview'),frame=page.locator('[data-collection-preview]');
    const mobile=page.getByRole('button',{name:'Mobile',exact:true}),desktop=page.getByRole('button',{name:'Desktop',exact:true});
    await page.waitForFunction(()=>Boolean(document.querySelector('[data-collection-document]').value));
    assert(await panel.isHidden());assert(await form.isVisible());checks+=2;
    if(role==='admin')await page.getByLabel('What would you like to ask?',{exact:true}).fill('Updated question before preview');
    const before=await page.locator('[data-collection-document]').inputValue();
    await mobile.click();assert(await panel.isVisible());assert(await form.isHidden());assert.equal(await mobile.getAttribute('aria-pressed'),'true');assert.equal(await desktop.getAttribute('aria-pressed'),'false');checks+=4;
    await frame.waitFor({state:'visible'});
    await page.waitForFunction(()=>document.querySelector('[data-collection-preview]').contentDocument?.querySelector('form'));
    const mobileWidth=await frame.evaluate(el=>el.getBoundingClientRect().width);assert(mobileWidth>200&&mobileWidth<=390);checks++;
    if(role==='admin')await page.frameLocator('[data-collection-preview]').getByText('Updated question before preview',{exact:false}).waitFor();
    const back=page.getByRole('button',{name:'Back to editing',exact:true});assert(await back.isVisible());checks++;
    await desktop.click();assert(await panel.isVisible());assert(await form.isHidden());assert.equal(await desktop.getAttribute('aria-pressed'),'true');assert.equal(await mobile.getAttribute('aria-pressed'),'false');checks+=4;
    const desktopWidth=await frame.evaluate(el=>el.getBoundingClientRect().width);assert(desktopWidth>=mobileWidth);if(width===1600)assert(desktopWidth>600);checks++;
    await back.click();assert(await form.isVisible());assert(await panel.isHidden());assert.equal(await page.locator('[data-collection-document]').inputValue(),before);checks+=3;
    await desktop.click();assert(await panel.isVisible());assert(await form.isHidden());await page.getByRole('button',{name:'Back to editing',exact:true}).click();assert(await form.isVisible());assert(await panel.isHidden());checks+=4;
    if(role==='admin'){
        assert.equal(await page.getByLabel('What would you like to ask?',{exact:true}).inputValue(),'Updated question before preview');
        assert.equal(await page.locator('.collection-save-unsaved').count(),2);checks+=2;
        await page.getByRole('button',{name:'Preview',exact:true}).click();assert(await panel.isVisible());await page.getByRole('button',{name:'Back to editing',exact:true}).click();assert(await form.isVisible());checks+=2;
        await page.locator('[data-collection-tab="design"]').click();assert(await panel.isVisible());
        await mobile.click();assert(await form.isVisible());assert(await panel.isVisible());checks+=3;
        await desktop.click();assert(await form.isVisible());checks++;
    }else{assert.equal(await page.locator('[data-collection-save]').count(),0);checks++;}
    assert(calls.length>0&&calls.every(action=>action==='collection_preview'));assert.deepEqual(errors,[]);checks+=2;
    await page.close();
}console.log(`Survey device preview (${engine}): ${checks} checks passed.`);}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
