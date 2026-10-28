'use strict';
// Shared consent control in real editor/public templates; requests stay synthetic.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(mode,kind,input)=>execFileSync('php',[fixture,mode,kind],{cwd:root,encoding:'utf8',input:JSON.stringify(input)});
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const kind of ['hosted','embedded','popup'])for(const width of [390,1600]){
    const document=JSON.parse(execFileSync('php',['-r',`require 'src/audience_store.php';$d=audienceCollectionDefaults('${kind}');$d['fields']=['email'];echo json_encode($d);`],{cwd:root,encoding:'utf8'}));
    const editor=php('editor',kind,{document}),html=php('public',kind,{document}),nonce=html.match(/<style nonce="([^"]+)"/)[1];
    const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(7000);const errors=[];let posts=0;
    page.on('pageerror',error=>errors.push(error.message));page.on('console',message=>{if(message.type()==='error'&&/Content Security Policy|violates/.test(message.text()))errors.push(message.text());});
    await page.route('**/*',route=>{
        const request=route.request(),url=new URL(request.url());if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(request.method()==='GET')return route.fulfill({contentType:'text/html',body:editor});
            const data=Object.fromEntries([...request.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]));
            assert.equal(data.action,'collection_preview');return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',kind,{document:JSON.parse(data.document_json),nonce:data.preview_nonce})})});
        }
        if(url.pathname==='/form'){
            if(request.method()==='POST'){posts++;return route.fulfill({status:400,body:'Unexpected submission'});}
            return route.fulfill({contentType:'text/html',body:html,headers:{'Content-Security-Policy':`default-src 'none'; script-src 'nonce-${nonce}'; style-src 'nonce-${nonce}'; font-src data:; img-src 'self' data: https:; form-action 'self'; base-uri 'none'`}});
        }
        const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    const exercise=async(surface,preview)=>{
        const email=surface.locator('input[name=email]'),consent=surface.locator('input[name=permission]');
        await email.waitFor();await email.fill('focus-fixture@example.test');await email.focus();await page.keyboard.press('Tab');
        assert.equal(await consent.evaluate(input=>input===document.activeElement),true);
        assert.equal(await consent.evaluate(input=>getComputedStyle(input).outlineWidth),'3px');checks+=2;
        await consent.click();assert.equal(await consent.isChecked(),true);assert.equal(await consent.evaluate(input=>getComputedStyle(input).outlineStyle),'none');checks+=2;
        await consent.click();assert.equal(await consent.isChecked(),false);checks++;
        if(preview)await consent.evaluate(input=>input.reportValidity());else await surface.locator('form > button').click();
        assert.equal(await consent.evaluate(input=>input===document.activeElement),true);
        assert.equal(await consent.evaluate(input=>getComputedStyle(input).outlineStyle),'none');
        assert.equal(await consent.evaluate(input=>input.validity.valueMissing),true);checks+=3;
        await email.focus();await page.keyboard.press('Tab');
        assert.equal(await consent.evaluate(input=>getComputedStyle(input).outlineWidth),'3px');checks++;
    };
    await page.goto('https://collection.test/audience.php?view=form&id=7');
    if(width===390)await page.locator('[data-collection-device="mobile"]').click();
    await exercise(page.frameLocator('[data-collection-preview]'),true);
    await page.goto('https://collection.test/form');await exercise(page,false);
    assert.equal(posts,0);assert.deepEqual(errors,[]);checks+=2;await page.close();
}
console.log(`Collection consent focus browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
