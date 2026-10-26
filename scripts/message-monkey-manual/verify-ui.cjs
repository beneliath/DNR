/* Verify the guide download, theme layout, and live editor regression in the disposable preview. */
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),work=path.join(root,'tmp/pdfs/message-monkey-manual');
const base=process.env.DNR_MANUAL_BASE_URL||'http://127.0.0.1:18146';
if(!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base))throw Error('Use the disposable loopback preview.');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE});
 const context=await browser.newContext({storageState:path.join(work,'session.json'),viewport:{width:1280,height:1000}});
 await context.route('**/*',route=>{const req=route.request();if(new URL(req.url()).origin!==base||req.method()!=='GET')return route.abort();return route.continue();});
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const results=[];
 for(const theme of ['light','dark'])for(const width of [1280,390]){
  await page.goto(base+'/audience.php?view=manual');await page.evaluate(t=>localStorage.setItem('theme',t),theme);
  await page.setViewportSize({width,height:1000});await page.reload({waitUntil:'networkidle'});
  assert.equal(await page.locator('html').evaluate(e=>e.classList.contains('dark-mode')),theme==='dark');
  const pdf=page.getByRole('link',{name:'View Comprehensive User Guide (PDF, opens in a new tab)',exact:true});
  assert.equal(await pdf.count(),1);assert.equal(await pdf.getAttribute('target'),'_blank');
  const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);assert.equal(overflow,false);
  const dimensions=await page.locator('.manual-pdf-link').evaluate(e=>({height:e.getBoundingClientRect().height,color:getComputedStyle(e.querySelector('a')).color}));
  results.push({theme,width,overflow,link:dimensions});
 }
 await page.setViewportSize({width:1280,height:1000});await page.goto(base+'/audience.php?view=manual');
 const url=await page.locator('.manual-pdf-link a').getAttribute('href');const response=await context.request.get(base+'/'+url);
 assert.equal(response.status(),200);assert.match(response.headers()['content-type'],/application\/pdf/);
 const body=await response.body();assert.ok(body.equals(fs.readFileSync(path.join(root,'output/pdf/message-monkey-comprehensive-user-guide.pdf'))));
 await page.goto(base+'/audience.php?view=designer&kind=campaign&id=1',{waitUntil:'networkidle'});
 assert.ok(await page.locator('.designer-block').count()>0,'Email editor must render native blocks');
 assert.equal(await page.locator('.designer-email-footer').count(),1,'Footer normalization must finish');
 await page.locator('.designer-block').first().click();
 assert.ok(await page.locator('.designer-block.selected').count()>0,'A block can be selected for editing');
 assert.deepEqual(errors,[]);
 fs.writeFileSync(path.join(root,'docs/message-monkey-user-guide/ui-verification.json'),JSON.stringify({checks:results,pdf_download:'200 application/pdf; identical to output',email_editor:'renders blocks and footer; block selection works',browser_errors:errors},null,2)+'\n');
 console.log('PASS: manual light/dark at 1280/390px, PDF download, email editor rendering and block selection.');await browser.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
