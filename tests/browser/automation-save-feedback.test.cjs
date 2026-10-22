'use strict';
// Exercise the real editor and PHP-rendered markup without writing account data.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const fixture=execFileSync('php',['tests/browser/automation-mapping-fixture.php','builder-save'],{cwd:root,encoding:'utf8'});
(async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        for(const dark of [false,true]){
            const page=await browser.newPage({viewport:{width:1440,height:1000}}),requests=[],errors=[];
            page.on('pageerror',error=>errors.push(error.message));
            await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
            await page.route('http://automations.test/**',route=>{
                if(route.request().method()==='POST')return new Promise(resolve=>requests.push({route,resolve,input:Object.fromEntries(new URLSearchParams(route.request().postData()))}));
                const pathname=new URL(route.request().url()).pathname;
                if(!pathname.startsWith('/assets/'))return route.fulfill({contentType:'text/html',body:fixture.replace('<html>','<html'+(dark?' class="dark-mode"':'')+'>')});
                const file=path.join(root,'src',pathname);
                return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.abort();
            });
            const waitForCount=async count=>{
                const deadline=Date.now()+5000;
                while(requests.length<count&&Date.now()<deadline)await page.waitForTimeout(20);
                assert.equal(requests.length,count);
            };
            const respond=async(index,error)=>{
                const request=requests[index];
                await request.route.fulfill({status:error?422:200,contentType:'application/json',body:JSON.stringify(error?{error}:{id:1,version:Number(request.input.version)+1,document:JSON.parse(request.input.document),errors:[]})});
                request.resolve();
            };
            const notice=async text=>{
                await page.waitForFunction(text=>{const node=document.querySelector('[data-flow-save-feedback]');return node&&!node.hidden&&node.textContent===text;},text);
                const message=page.locator('[data-flow-save-feedback]');assert.equal(await message.isVisible(),true);
                const box=await message.boundingBox();assert.ok(box.y>=0&&box.y+box.height<=1000,'Save confirmation is visible within the viewport');
            };
            await page.goto('http://automations.test/?view=flow&id=1');
            await page.waitForSelector('.flow-map .flow-node');
            assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('dark-mode')),dark);
            const save=page.locator('[data-flow-save]');
            await save.click();await notice('All changes are already saved');
            assert.equal(requests.length,0,'A clean draft confirms its saved state without a redundant write');

            await page.getByRole('button',{name:'Settings',exact:true}).click();
            const name=page.locator('[data-flow-panel-body="settings"]').getByLabel('Flow name',{exact:true});
            await name.fill('Updated cleanup');await save.click();await waitForCount(1);
            assert.equal(await save.isDisabled(),true);assert.equal(await save.getAttribute('aria-busy'),'true');
            await notice('Saving draft');
            await name.fill('Latest cleanup');await respond(0);await waitForCount(2);
            assert.equal(requests[1].input.name,'Latest cleanup');assert.equal(requests[1].input.version,'2');
            await respond(1);await notice('Draft saved');
            assert.equal(await save.textContent(),'✓ Saved');assert.equal(await save.isEnabled(),true);

            await name.fill('Autosave draft');await waitForCount(3);await save.click();
            await respond(2,'The draft changed on the server. Reload before saving.');
            await notice('Draft was not saved. The draft changed on the server. Reload before saving.');
            assert.equal(await page.locator('[data-flow-save-status]').textContent(),'Not saved');
            await page.waitForTimeout(100);
            assert.equal(requests.length,3,'Waiting on a failed autosave must not retry silently or announce success');
            assert.ok(await page.evaluate(()=>Object.values(localStorage).some(value=>value.includes('Autosave draft'))),'Failed draft stays recoverable');
            await save.click();await waitForCount(4);assert.equal(requests[3].input.version,'3');
            await respond(3);await notice('Draft saved');

            await page.keyboard.press('Control+s');await notice('All changes are already saved');
            assert.equal(requests.length,4,'Keyboard Save shares the clean-draft confirmation');
            assert.deepEqual(errors,[]);
            if(process.env.DNR_FLOW_SAVE_SCREENSHOT){
                const output=path.resolve(process.env.DNR_FLOW_SAVE_SCREENSHOT);fs.mkdirSync(path.dirname(output),{recursive:true});
                await page.screenshot({path:output.replace(/\.png$/,dark?'-dark.png':'.png')});
            }
            await page.close();
        }
        console.log('Automation Save feedback passed in light and dark themes: clean draft, progress, edits during saving, failed autosave, retry, and keyboard Save.');
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
