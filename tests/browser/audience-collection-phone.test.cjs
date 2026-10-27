'use strict';
// Real MOED phone component in the preview and public renderer; no database or mail.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),fixture='tests/browser/audience-collection-images-fixture.php';
const php=(mode,kind,input)=>execFileSync('php',[fixture,mode,kind],{cwd:root,encoding:'utf8',input:JSON.stringify(input)});
const defaults=kind=>JSON.parse(execFileSync('php',['-r',`require 'src/audience_store.php';echo json_encode(audienceCollectionDefaults('${kind}'));`],{cwd:root,encoding:'utf8'}));
const requestValues=req=>Object.fromEntries(new URLSearchParams(req.postData()));
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{
for(const kind of ['hosted','popup','landing','embedded','preferences'])for(const width of [390,1600]){
    const document=defaults(kind);document.fields=['email','custom:4'];document.required=['email','custom:4'];document.help_text={'custom:4':'We may call you.'};
    const editor=php('editor',kind,{document}),publicHtml=php('public',kind,{document});
    const nonce=publicHtml.match(/<style nonce="([^"]+)"/)[1];
    const page=await browser.newPage({viewport:{width,height:1000}});page.setDefaultTimeout(7000);
    const errors=[];let submitted=null;page.on('pageerror',e=>errors.push(e.message));
    page.on('console',message=>{if(message.type()==='error'&&/Content Security Policy|violates/.test(message.text()))errors.push(message.text());});
    await page.route('**/*',route=>{
        const req=route.request(),url=new URL(req.url());if(url.origin!=='https://collection.test')return route.abort();
        if(url.pathname==='/audience.php'){
            if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:editor});
            const data=Object.fromEntries([...req.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]]));
            assert.equal(data.action,'collection_preview');
            return route.fulfill({contentType:'application/json',body:JSON.stringify({html:php('preview',kind,{document:JSON.parse(data.document_json),nonce:data.preview_nonce})})});
        }
        if(url.pathname==='/form'){
            if(req.method()==='POST'){submitted=requestValues(req);return route.fulfill({contentType:'text/html',body:'<p>Submission captured</p>'});}
            return route.fulfill({contentType:'text/html',body:publicHtml,headers:{'Content-Security-Policy':`default-src 'none'; script-src 'nonce-${nonce}'; style-src 'nonce-${nonce}'; font-src data:; img-src 'self' data: https:; form-action 'self'; base-uri 'none'`}});
        }
        const file=path.join(root,'src',url.pathname);
        return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    const exercise=async surface=>{
        const input=surface.locator('[data-phone-number]'),picker=surface.locator('[data-phone-country-picker]');
        await input.waitFor();await input.fill('3125550199');await input.blur();
        assert.equal(await input.inputValue(),'(312) 555-0199');assert.equal(await input.evaluate(el=>el.required),true);checks+=2;
        const help=await input.getAttribute('aria-describedby');assert.equal(await surface.locator(`#${help}`).textContent(),'We may call you.');checks++;
        await picker.locator('[data-phone-country-toggle]').click();
        const option=picker.locator('[data-country-code="+44"]');await option.scrollIntoViewIfNeeded();await option.click();
        assert.equal(await picker.locator('[data-phone-country-code]').inputValue(),'+44');
        assert.equal(await picker.locator('[data-phone-country-dial-code]').textContent(),'+44');checks+=2;
        await input.fill('123');await input.blur();assert.equal(await input.evaluate(el=>el.validity.customError),true);checks++;
        await input.fill('020 7946 0958');await input.blur();assert.equal(await input.evaluate(el=>el.checkValidity()),true);checks++;
        const geometry=await input.evaluate(el=>{const group=el.closest('[data-phone-input-group]'),rect=group.getBoundingClientRect(),input=el.getBoundingClientRect(),button=group.querySelector('button').getBoundingClientRect();return {fits:input.right<=rect.right+1,separate:button.right<=input.left,overflow:document.documentElement.scrollWidth>innerWidth+1};});
        assert.equal(geometry.fits,true);assert.equal(geometry.separate,true);assert.equal(geometry.overflow,false);checks+=3;
    };
    await page.goto('https://collection.test/audience.php?view=form&id=7');
    if(width===390)await page.locator('[data-collection-device="mobile"]').click();
    await exercise(page.frameLocator('[data-collection-preview]'));
    // A fresh preview document gets the same shared behavior after edits.
    if(width===1600){await page.locator('[data-collection-rail="design"]').click();await page.locator('[data-doc="intro"]').fill('Updated introduction');await page.waitForFunction(()=>document.querySelector('[data-collection-preview]').contentDocument?.querySelector('.collection-intro')?.textContent==='Updated introduction');await exercise(page.frameLocator('[data-collection-preview]'));}
    await page.goto('https://collection.test/form');await exercise(page);
    await page.locator('input[name="email"]').fill('phone-fixture@example.test');
    if(await page.locator('[name="permission"]').count())await page.locator('[name="permission"]').check();
    if(process.env.DNR_REVIEW_OUTPUT&&kind==='popup'&&width===1600)await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,'message-monkey-phone-tool.png')});
    await page.locator('form > button').click();await page.getByText('Submission captured').waitFor();
    assert.equal(submitted['custom[4][country_code]'],'+44');assert.equal(submitted['custom[4][number]'],'020 7946 0958');assert.deepEqual(errors,[]);checks+=3;
    await page.close();
}
for(const option of ['disable_javascript','remove_css']){
    const document=defaults('embedded');document.fields=['email','custom:4'];document.embedded[option]=true;
    const html=php('public','embedded',{document}),page=await browser.newPage({javaScriptEnabled:false});let submitted;
    await page.route('**/*',route=>{
        const req=route.request();if(req.method()==='POST'){submitted=requestValues(req);return route.fulfill({contentType:'text/html',body:'<p>Native submission captured</p>'});}
        return route.fulfill({contentType:'text/html',body:html});
    });
    await page.goto('https://collection.test/form');await page.locator('select[data-phone-country-code]').selectOption('+44');
    await page.locator('[data-phone-number]').fill('020 7946 0958');await page.locator('[name=email]').fill('phone-fixture@example.test');await page.locator('[name=permission]').check();
    await page.locator('form > button').click();await page.getByText('Native submission captured').waitFor();
    assert.equal(submitted['custom[4][country_code]'],'+44');assert.equal(submitted['custom[4][number]'],'020 7946 0958');checks+=2;await page.close();
}
console.log(`Collection phone browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
