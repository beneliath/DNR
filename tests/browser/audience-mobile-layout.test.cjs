'use strict';
// Run against a fresh disposable preview seeded by scripts/message-monkey-manual.
// Set DNR_MOBILE_URL, DNR_PLAYWRIGHT_MODULE and PLAYWRIGHT_BROWSERS_PATH.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto');
const playwright=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..'),work=path.resolve(process.env.DNR_MOBILE_WORK||path.join(root,'tmp/mm-mobile'));
const fixtureDir=path.resolve(process.env.DNR_MOBILE_FIXTURE_DIR||path.join(root,'tmp/pdfs/message-monkey-manual'));
const base=process.env.DNR_MOBILE_URL;
if(!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base||''))throw Error('DNR_MOBILE_URL must be a disposable loopback preview.');
const credentials=JSON.parse(fs.readFileSync(path.join(fixtureDir,'credentials.json')));
const fixtures=JSON.parse(fs.readFileSync(path.join(fixtureDir,'fixture.json')));
const cases=require('./audience-mobile-cases.cjs')(fixtures).filter(c=>!process.env.DNR_MOBILE_CASES||new RegExp(process.env.DNR_MOBILE_CASES).test(c.id));
const widths=(process.env.DNR_MOBILE_WIDTHS||'320,390,430,768,844').split(',').map(Number),themes=(process.env.DNR_MOBILE_THEMES||'light').split(',');
const engine=process.env.DNR_MOBILE_ENGINE||'chromium', audit=process.env.DNR_MOBILE_AUDIT==='1';
fs.mkdirSync(work,{recursive:true});
function totp(){const bits=[...credentials.totp_secret.toUpperCase().replace(/=+$/,'')].map(c=>'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5,'0')).join('');const key=Buffer.from(bits.match(/.{8}/g).map(b=>parseInt(b,2)));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const h=crypto.createHmac('sha1',key).update(counter).digest();return ((h.readUInt32BE(h[19]&15)&0x7fffffff)%1000000).toString().padStart(6,'0');}
function measure(){
 const visible=e=>{if(e.closest('.sr-only,.audience-skip,[hidden],[inert]'))return false;for(let p=e;p;p=p.parentElement){if(p.parentElement?.matches('details:not([open])')&&!p.matches('summary'))return false;const css=getComputedStyle(p);if(css.clip!=='auto'||css.clipPath==='inset(50%)')return false;}const r=e.getBoundingClientRect(),s=getComputedStyle(e);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'&&s.opacity!=='0';};
 const scrollParent=e=>{for(let p=e.parentElement;p&&p!==document.body;p=p.parentElement){const s=getComputedStyle(p);const r=p.getBoundingClientRect();if(/auto|scroll/.test(s.overflowX)&&p.scrollWidth>p.clientWidth+2&&r.left>=-2&&r.right<=innerWidth+2)return p;}return null;};
 const label=e=>(e.tagName.toLowerCase()+(e.id?'#'+e.id:'')+(typeof e.className==='string'?'.'+e.className.trim().replace(/\s+/g,'.'):'')).slice(0,180);
 const elements=[...document.querySelectorAll('body *')].filter(visible),offscreen=[],smallFields=[],clipped=[],overlaps=[];
 for(const e of elements){
  const r=e.getBoundingClientRect(),s=getComputedStyle(e);
  if(e.closest('.audience-skip,[hidden],[inert],script,style'))continue;
  if((r.left<-2||r.right>innerWidth+2)&&!scrollParent(e)&&!['HTML','BODY','SVG','PATH'].includes(e.tagName)&&(!e.children.length||e.matches('input,select,textarea,button,h1,h2,h3'))){offscreen.push({element:label(e),text:(e.textContent||e.getAttribute('aria-label')||'').trim().slice(0,80),left:Math.round(r.left),right:Math.round(r.right)});}
  if(e.matches('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),textarea,select')&&parseFloat(s.fontSize)<16)smallFields.push({element:label(e),font:s.fontSize});
  if(e.matches('button,label,h1,h2,h3,p')&&e.scrollWidth>e.clientWidth+3&&s.textOverflow!=='ellipsis'&&!scrollParent(e)&&!e.querySelector('table,iframe,pre')&&s.overflowX==='hidden')clipped.push({element:label(e),text:e.textContent.trim().slice(0,80)});
 }
 for(const parent of elements){
  const s=getComputedStyle(parent);if(!['flex','inline-flex','grid','inline-grid'].includes(s.display))continue;
  const children=[...parent.children].filter(e=>visible(e)&&getComputedStyle(e).position==='static');
  for(let i=0;i<children.length;i++)for(let j=i+1;j<children.length;j++){
   const a=children[i],b=children[j],r=a.getBoundingClientRect(),t=b.getBoundingClientRect();
   if(Math.min(r.right,t.right)-Math.max(r.left,t.left)>3&&Math.min(r.bottom,t.bottom)-Math.max(r.top,t.top)>3)overlaps.push({parent:label(parent),a:label(a),b:label(b)});
  }
 }
 if(innerWidth<=1000)for(const block of document.querySelectorAll('.designer-block.selected')){
  const bar=block.querySelector('.designer-block-hover-tools'),content=block.querySelector('.designer-block-content'),section=block.closest('.designer-row').querySelector('.designer-layout-tools');
  for(const other of [content,section])if(visible(bar)&&visible(other)){
   const a=bar.getBoundingClientRect(),b=other.getBoundingClientRect();
   if(Math.min(a.right,b.right)-Math.max(a.left,b.left)>3&&Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top)>3)overlaps.push({parent:label(block),a:label(bar),b:label(other)});
  }
 }
 const badOverlays=[...document.querySelectorAll('dialog[open],[popover]:popover-open')].filter(visible).filter(e=>{const r=e.getBoundingClientRect();return r.top<-2||r.bottom>innerHeight+2||r.left<-2||r.right>innerWidth+2;}).map(label);
 const smallTargets=innerWidth<=860?[...document.querySelectorAll('.audience-account-tools>button,.audience-account-tools>a,[data-audience-nav-toggle],.inbox-mobile-nav')].filter(visible).filter(e=>{const r=e.getBoundingClientRect();return r.width<44||r.height<44;}).map(label):[];
 return {viewport:innerWidth,document:document.scrollingElement.scrollWidth,offscreen:offscreen.slice(0,35),clipped:clipped.slice(0,20),overlaps:overlaps.slice(0,20),smallFields:smallFields.slice(0,20),badOverlays,smallTargets,dark:document.documentElement.classList.contains('dark-mode'),title:document.title};
}
(async()=>{
 const browser=await playwright[engine].launch({headless:true});const session=path.join(work,'session-'+engine+'.json');
 const context=await browser.newContext({viewport:{width:390,height:844},deviceScaleFactor:1,isMobile:true,hasTouch:true,reducedMotion:'reduce'});
 await context.route('**/*',route=>{const req=route.request(),u=new URL(req.url());if(u.origin!==base&&!['data:','blob:'].includes(u.protocol))return route.abort();if(req.method()==='POST'&&!['login.php','verify_2fa.php','admin_elevation.php'].includes(u.pathname.split('/').pop())){const body=req.postData()||'',action=req.headers()['content-type']?.includes('multipart/form-data')?body.match(/name="action"\r?\n\r?\n([^\r\n]+)/)?.[1]:new URLSearchParams(body).get('action');if(!['design_preview','design_people','collection_preview','inbox_read','inbox_presence','preview_segment','campaign_preview_recipients'].includes(action))return route.abort();}return route.continue();});
 let page=await context.newPage();page.setDefaultTimeout(5000);let errors=[];
 await page.goto(base+'/dashboard.php');await page.waitForLoadState('networkidle');
 if(page.url().includes('login.php')){await page.locator('[name=username]').fill(credentials.username);await page.locator('[name=password]').fill(credentials.password);await page.getByRole('button',{name:'Sign In',exact:true}).click();if(page.url().includes('verify_2fa.php')){await page.locator('[name=authentication_code]').fill(totp());await page.getByRole('button',{name:'Verify',exact:true}).click();}if(/login.php|verify_2fa.php/.test(page.url())){await browser.close();throw Error('Preview login failed; run browser audits sequentially so the one-time code is not reused.');}await page.waitForLoadState('networkidle');await context.storageState({path:session});}
 const results=[];
 try{for(const theme of themes)for(const width of widths){
  await page.setViewportSize({width,height:width===844?390:844});await page.evaluate(t=>localStorage.setItem('theme',t),theme);
  let count=0;
  for(const item of cases){if(item.mobileOnly&&width>860)continue;
   // Keep requests cancelled during the previous document's unload out of this case.
   page.removeAllListeners('pageerror');await page.close();page=await context.newPage();
   await page.setViewportSize({width,height:width===844?390:844});page.setDefaultTimeout(5000);
   errors=[];page.on('pageerror',e=>errors.push(e.message));let data;
   try{
    const response=await page.goto(base+'/'+item.route,{waitUntil:'domcontentloaded'});if(response.status()>=400)throw Error('HTTP '+response.status());await page.evaluate(()=>document.fonts.ready);await page.waitForLoadState('networkidle');
    if(/login.php|verify_2fa.php/.test(page.url()))throw Error('Preview session expired');
    for(const action of item.actions){if(action.op==='bottom'){await page.evaluate(()=>{for(const e of document.querySelectorAll('body *'))if(/auto|scroll/.test(getComputedStyle(e).overflowY)&&e.scrollHeight>e.clientHeight)e.scrollTop=e.scrollHeight;window.scrollTo(0,document.body.scrollHeight);});}else{let locator=page.locator(action.selector);if(action.first)locator=locator.first();if(action.op==='scroll')await locator.scrollIntoViewIfNeeded();else await locator.click();}await page.waitForTimeout(150);}
    data=await page.evaluate(measure);data.errors=errors;if(data.dark!==(theme==='dark'))data.errors.push('Theme did not apply');
    const issues=(width<=860&&data.smallFields.length)||data.document>width+2||data.offscreen.length||data.clipped.length||data.overlaps.length||data.badOverlays.length||data.smallTargets.length||errors.length;
    if(process.env.DNR_MOBILE_SCREENSHOTS==='all'||(issues&&process.env.DNR_MOBILE_SCREENSHOTS!=='none'))await page.screenshot({path:path.join(work,engine+'-'+theme+'-'+width+'-'+item.id+'.png'),animations:'disabled'});
    if(issues)count++;
   }catch(e){data={error:e.message};count++;}
   results.push({id:item.id,engine,theme,width,...data});
  }
  console.log(`${engine} ${theme} ${width}px: ${cases.length} views, ${count} flagged for review`);
  fs.writeFileSync(path.join(work,engine+'-'+theme+'-'+width+'.json'),JSON.stringify(results.filter(r=>r.theme===theme&&r.width===width),null,2));
 }}finally{await browser.close();}
 fs.writeFileSync(path.join(work,'results-'+engine+'.json'),JSON.stringify(results,null,2));
 const failures=results.filter(r=>r.error||(r.width<=860&&r.smallFields?.length)||r.document>r.width+2||r.offscreen?.length||r.clipped?.length||r.overlaps?.length||r.badOverlays?.length||r.smallTargets?.length||r.errors?.length);
 console.log(JSON.stringify({engine,layouts:results.length,flagged:failures.length}));if(failures.length&&!audit)process.exitCode=1;
})().catch(e=>{console.error(e.message);process.exitCode=1;});
