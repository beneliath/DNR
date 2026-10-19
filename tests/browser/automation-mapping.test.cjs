'use strict';
// Uses actual PHP-rendered templates with synthetic data and no application server.
const assert=require('node:assert/strict'), fs=require('node:fs'), path=require('node:path');
const {execFileSync}=require('node:child_process');
const engines=require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root=path.resolve(__dirname,'../..'), kinds=['catalog','split','cleanup','followup','series','transactional','builder'];
const fixtures=process.env.DNR_AUTOMATION_FIXTURES?JSON.parse(fs.readFileSync(process.env.DNR_AUTOMATION_FIXTURES,'utf8')):Object.fromEntries(kinds.map(kind=>[kind,[false,true].map(dark=>execFileSync('php',['tests/browser/automation-mapping-fixture.php',kind,dark?'dark':'light'],{cwd:root,encoding:'utf8'}))]));
if(process.env.DNR_EXPORT_AUTOMATION_FIXTURES){fs.writeFileSync(process.env.DNR_EXPORT_AUTOMATION_FIXTURES,JSON.stringify(fixtures));console.log('Exported current source-rendered automation fixtures.');process.exit(0);}
(async()=>{
    let layouts=0;
    for(const engine of (process.env.DNR_BROWSER_ENGINES || 'webkit,chromium,firefox').split(',')){
        const browser=await engines[engine].launch({headless:true});
        try{
            const page=await browser.newPage();page.setDefaultTimeout(5000);const errors=[];page.on('pageerror',error=>errors.push(error.message));
            await page.route('http://automations.test/**',route=>{
                const pathname=new URL(route.request().url()).pathname;
                if(!pathname.startsWith('/assets/'))return route.fulfill({contentType:'text/html',body:'<!doctype html><body></body>'});
                const file=path.join(root,'src',pathname);
                return fs.existsSync(file)?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.abort();
            });
            for(const width of [390,1440])for(const dark of [false,true])for(const kind of kinds){
                await page.setViewportSize({width,height:1000});await page.goto('http://automations.test/?view=flow-templates'+(kind==='catalog'?'':'&preview='+kind));await page.setContent(fixtures[kind][Number(dark)]);
                const label=`${engine} ${kind} ${width}px ${dark?'dark':'light'}`;
                await page.waitForFunction(()=>document.querySelector('.flow-preview-map-scroll') || document.querySelector('.transactional-totals') || document.querySelector('.flow-map .flow-node') || document.querySelector('[data-flow-template-count]')?.textContent==='17 flow templates');
                assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),label+': no page overflow');
                if(kind==='catalog'){
                    const selectHeights=await page.locator('.flow-catalog-controls select').evaluateAll(es=>es.map(e=>e.getBoundingClientRect().height));assert.ok(selectHeights.every(h=>h>=34),label+': controls retain their box size');
                    await page.getByRole('combobox',{name:'Topics:',exact:true}).selectOption('Special dates');
                    assert.equal(await page.locator('[data-flow-template-count]').textContent(),'2 flow templates',label+': unique result count');
                    assert.equal(await page.locator('[data-flow-template-section]:visible').count(),1,label+': hide empty sections');
                    await page.locator('[data-flow-template-clear]:visible').click();
                    await page.getByRole('combobox',{name:'Apps & Integrations:',exact:true}).selectOption('forms');
                    assert.equal(await page.locator('[data-flow-template-count]').textContent(),'5 flow templates',label+': native integration filter');
                    await page.locator('[data-flow-template-clear]:visible').click();
                    await page.getByRole('combobox',{name:'Channels:',exact:true}).selectOption('data');assert.equal(await page.locator('[data-flow-template-count]').textContent(),'3 flow templates',label+': data-only channel');
                    await page.locator('[data-flow-template-clear]:visible').click();
                    await page.getByRole('searchbox',{name:'Search templates'}).fill('MOED forms');assert.equal(await page.locator('[data-flow-template-count]').textContent(),'5 flow templates',label+': search by visible integration name');
                    await page.locator('[data-flow-template-clear]:visible').click();
                    await page.getByRole('searchbox',{name:'Search templates'}).fill('If/Else');assert.equal(await page.locator('[data-flow-template-count]').textContent(),'1 flow template',label+': search');
                    assert.match(await page.getByRole('link',{name:'Guide connections with If/Else conditions'}).getAttribute('href'),/q=If/);assert.match(page.url(),/q=If/);
                    await page.getByRole('searchbox',{name:'Search templates'}).fill('No matching template 999');assert.equal(await page.locator('[data-flow-template-empty]').isVisible(),true,label+': empty state');
                }else if(kind==='builder'){
                    assert.equal(await page.locator('.flow-node.kind-trigger').count(),2,label+': builder preserves separate starting points');
                    assert.equal(await page.locator('.flow-node>.flow-symbol').count(),3,label+': builder shares native step artwork');
                    assert.equal(await page.locator('[data-flow-add="split"]').count(),0,label+': testing is excluded');
                    await page.locator('.flow-node.kind-trigger').first().click();
                    assert.equal(await page.getByRole('combobox',{name:'New email status',exact:true}).count(),2,label+': both status triggers can be reviewed');
                }else if(kind!=='transactional'){
                    const graph=page.locator('.flow-preview-graph');assert.equal(await graph.count(),1,label+': real vector graph');
                    assert.ok(await graph.locator('.flow-preview-connector').count()>0,label+': connected steps');
                    if(kind==='cleanup')assert.equal(await page.locator('.flow-preview-graph-node.kind-trigger').count(),2,label+': both cleanup triggers');
                    if(kind==='split')assert.deepEqual(await page.locator('.flow-preview-edge-label').allTextContents(),['Yes','No'],label+': real branch labels');
                    if(kind==='followup')assert.deepEqual(await page.locator('.flow-preview-edge-label').allTextContents(),['Trigger matched','Timed out'],label+': click or timeout');
                    await page.getByRole('button',{name:'Zoom out',exact:true}).click();assert.equal(await page.locator('[data-flow-preview-zoom]').textContent(),'90%',label+': zoom');
                    await page.getByRole('button',{name:'Reset view',exact:true}).click();assert.equal(await page.locator('[data-flow-preview-zoom]').textContent(),'100%',label+': reset');
                    const colors=await graph.locator('rect').evaluateAll(es=>es.map(e=>getComputedStyle(e).fill));assert.ok(colors.every(c=>dark?c!=='rgb(255, 255, 255)':c==='rgb(255, 255, 255)'),label+': node theme');
                }else{
                    assert.equal(await page.getByRole('list',{name:'Transactional email lifecycle'}).count(),1,label+': lifecycle graphic');assert.equal(await page.getByText('Acceptance uncertain',{exact:true}).count(),3,label+': honest status labels');
                    assert.equal(await page.locator('form[method="post"]').count(),0,label+': no write controls');
                }
                layouts++;
            }
            assert.deepEqual(errors,[],engine+': no browser exceptions');console.log(`${engine}: ${kinds.length*4} automation layouts and functional interactions passed`);
        }finally{await browser.close();}
    }
    console.log(`Automation mapping browser qualification passed: ${layouts} layouts.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
