'use strict';
// Exercise the actual canvas without reading or changing account data.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const fixtures=Object.fromEntries(['builder','builder-save'].map(kind=>[kind,execFileSync('php',['tests/browser/automation-mapping-fixture.php',kind],{cwd:root,encoding:'utf8'})]));
const frame=page=>page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
(async()=>{
    const browser=await chromium.launch({headless:true});
    try{
        for(const editable of [false,true])for(const dark of [false,true]){
            const page=await browser.newPage({viewport:{width:2200,height:1200}}),errors=[],writes=[];
            page.setDefaultTimeout(5000);
            page.on('pageerror',error=>errors.push(error.message));
            await page.addInitScript(dark=>localStorage.setItem('theme',dark?'dark':'light'),dark);
            await page.route('http://canvas.test/**',route=>{
                const request=route.request(),pathname=new URL(request.url()).pathname;
                if(request.method()==='POST'){writes.push(request.postData());return route.abort();}
                if(!pathname.startsWith('/assets/'))return route.fulfill({contentType:'text/html',body:fixtures[editable?'builder-save':'builder']});
                const file=path.join(root,'src',pathname);
                return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.abort();
            });
            await page.goto('http://canvas.test/');await page.waitForSelector('.flow-node');await frame(page);
            const canvas=page.locator('.flow-map-scroll'),map=page.locator('.flow-map'),node=page.locator('.flow-node').first();
            const viewport=await canvas.boundingBox(),initial=await node.boundingBox();
            assert.ok((await map.boundingBox()).width<viewport.width,'The flow fits horizontally before panning');
            assert.ok((await map.boundingBox()).height<viewport.height,'The flow fits vertically before panning');
            const origin={x:viewport.x+viewport.width-80,y:viewport.y+viewport.height-120};
            await page.mouse.move(origin.x,origin.y);await page.mouse.down();
            assert.equal(await canvas.evaluate(element=>getComputedStyle(element).cursor),'grabbing');
            await page.mouse.move(origin.x+50,origin.y+70,{steps:5});
            const moved=await node.boundingBox();
            assert.ok(Math.abs(moved.x-initial.x-50)<2,'Drag moves a fitting flow horizontally');
            assert.ok(Math.abs(moved.y-initial.y-70)<2,'Drag moves a fitting flow vertically');
            // Pointer capture finishes a drag even when released outside the canvas.
            await page.mouse.move(viewport.x+10,viewport.y-20,{steps:3});await page.mouse.up();
            assert.equal(await canvas.evaluate(element=>element.classList.contains('is-panning')),false);
            const released=await canvas.evaluate(element=>[element.scrollLeft,element.scrollTop]);
            await page.mouse.move(origin.x,origin.y);await frame(page);
            assert.deepEqual(await canvas.evaluate(element=>[element.scrollLeft,element.scrollTop]),released,'Moving after release does not pan');
            await page.getByRole('button',{name:'Reset view',exact:true}).click();await frame(page);
            const reset=await node.boundingBox();assert.ok(Math.abs(reset.x-initial.x)<2&&Math.abs(reset.y-initial.y)<2,'Reset restores the centered view');
            await page.mouse.move(origin.x,origin.y);await page.mouse.down();await page.mouse.move(origin.x-70,origin.y-60,{steps:4});await page.mouse.up();
            const reverse=await node.boundingBox();assert.ok(Math.abs(reverse.x-initial.x+70)<2&&Math.abs(reverse.y-initial.y+60)<2,'Panning works in the opposite direction');
            await page.getByRole('button',{name:'Reset view',exact:true}).click();await frame(page);
            await node.click();assert.equal(await page.getByRole('heading',{name:'Starting point',exact:true}).count(),1,'Node clicks still open the inspector');
            if(editable){
                await page.getByRole('button',{name:'Add a step after trigger',exact:true}).last().click();
                assert.equal(await page.locator('[data-flow-add="email"]').evaluate(element=>element===document.activeElement),true,'Add-step controls still focus the palette');
            }
            await page.getByRole('button',{name:'Zoom out',exact:true}).click();assert.equal(await page.locator('[data-flow-zoom-label]').textContent(),'90%');
            await page.getByRole('button',{name:'Reset view',exact:true}).click();
            await canvas.focus();const top=await canvas.evaluate(element=>element.scrollTop);await page.keyboard.press('ArrowDown');
            await page.waitForFunction(top=>document.querySelector('.flow-map-scroll').scrollTop>top,top);
            await page.setViewportSize({width:1024,height:900});await frame(page);
            assert.ok(await page.evaluate(()=>document.scrollingElement.scrollWidth<=innerWidth+1),'The padded canvas stays inside its viewport after resizing');
            assert.deepEqual(writes,[],'Panning, selecting, zooming, and resetting never save the flow');
            assert.deepEqual(errors,[]);await page.close();
        }
        console.log('Canvas pan passed in editable/read-only and light/dark modes: fitting flows, both axes/directions, outside release, reset, node/add-step clicks, zoom, keyboard scroll, resizing, and no writes');
    }finally{await browser.close();}
})().catch(error=>{console.error(error);process.exitCode=1;});
