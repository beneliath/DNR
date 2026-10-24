'use strict';
// Render the real review template and CSS; all records and requests are synthetic.
const assert=require('node:assert/strict'), fs=require('node:fs'), path=require('node:path');
const {execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const html=execFileSync('php',['tests/browser/audience-campaign-review-fixture.php'],{cwd:root,encoding:'utf8'});
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{
 const page=await browser.newPage();
 await page.route('**/*',route=>{
  const url=new URL(route.request().url());
  if(url.origin!=='https://review.test')return route.abort();
  if(url.pathname==='/audience.php')return route.fulfill({contentType:'text/html',body:html});
  const file=path.join(root,'src',url.pathname);
  return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
 });
 for(const width of [390,768,1518,1920]){
  await page.setViewportSize({width,height:1000});await page.goto('https://review.test/audience.php');
  const metrics=await page.evaluate(()=>{
   const layout=document.querySelector('.campaign-review-layout'),pre=layout.querySelector('pre'),aside=layout.querySelector('aside');
   // pre-wrap deliberately hangs trailing spaces. Measure visible glyphs instead
   // of including those spaces in a whole-node Range rectangle.
   const text=document.createRange(),node=pre.firstChild;let textRight=0;
   for(let i=0;i<node.length;i++)if(!/\s/.test(node.data[i])){
    text.setStart(node,i);text.setEnd(node,i+1);
    textRight=Math.max(textRight,text.getBoundingClientRect().right);
   }
   return {pageWidth:document.documentElement.scrollWidth,viewport:innerWidth,preWidth:pre.clientWidth,preScroll:pre.scrollWidth,
    preRight:pre.getBoundingClientRect().right,textRight,
    asideLeft:aside.getBoundingClientRect().left,asideTop:aside.getBoundingClientRect().top,preBottom:pre.getBoundingClientRect().bottom,text:pre.textContent};
  });
  assert(metrics.preScroll<=metrics.preWidth+1,`Plain text remains inside its pane at ${width}px: ${metrics.preScroll}px content / ${metrics.preWidth}px pane`);checks++;
  assert(metrics.pageWidth<=metrics.viewport+1,`Review does not force horizontal page scrolling at ${width}px`);checks++;
  assert(metrics.textRight<=metrics.preRight+1,`Rendered text never extends beneath comments at ${width}px: text right ${metrics.textRight}, pane right ${metrics.preRight}`);checks++;
  assert(metrics.text.includes('Hi Doug,\n\n')&&metrics.text.includes('<script>text only</script>'),'Line breaks and escaped literal text are preserved');checks++;
  if(width<=700)assert(metrics.asideTop>=metrics.preBottom,'Mobile comments follow the preview');
  else assert(metrics.asideLeft>=metrics.preRight,'Desktop comments remain beside the preview');checks++;
  if(process.env.DNR_REVIEW_OUTPUT&&[390,1518].includes(width)){fs.mkdirSync(process.env.DNR_REVIEW_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,width===390?'campaign-review-wrapped-mobile.png':'campaign-review-wrapped.png'),fullPage:true});}
 }
 console.log('Campaign review browser checks passed: '+checks+' (long text/URLs, preserved whitespace, escaped text, desktop/mobile comments).');
}finally{await browser.close();}})().catch(error=>{console.error(error.message);process.exitCode=1;});
