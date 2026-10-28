'use strict';
// Only against the disposable manual/mobile preview. Two independent logins.
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const base=process.env.DNR_MOBILE_URL;
if(!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base||''))throw Error('Use the disposable loopback preview.');
const dir=path.resolve(process.env.DNR_MOBILE_FIXTURE_DIR||'tmp/pdfs/message-monkey-manual'),work=path.resolve(process.env.DNR_MOBILE_WORK||'tmp/mm-mobile-audit');
const credentials=JSON.parse(fs.readFileSync(path.join(dir,'credentials.json')));
function totp(){const bits=[...credentials.totp_secret.toUpperCase().replace(/=+$/,'')].map(c=>'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(c).toString(2).padStart(5,'0')).join('');const key=Buffer.from(bits.match(/.{8}/g).map(b=>parseInt(b,2))),counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const h=crypto.createHmac('sha1',key).update(counter).digest();return ((h.readUInt32BE(h[19]&15)&0x7fffffff)%1000000).toString().padStart(6,'0');}
(async()=>{const browser=await chromium.launch();try{
 const desktop=await browser.newContext({viewport:{width:1440,height:1000}});
 const phone=await browser.newContext({viewport:{width:390,height:844},hasTouch:true,isMobile:true});
 const a=await desktop.newPage(),b=await phone.newPage();
 const login=async page=>{await page.goto(base+'/login.php');await page.locator('[name=username]').fill(credentials.username);await page.locator('[name=password]').fill(credentials.password);await page.getByRole('button',{name:'Sign In',exact:true}).click();
 if(page.url().includes('verify_2fa.php')){await page.locator('[name=authentication_code]').fill(totp());await page.getByRole('button',{name:'Verify',exact:true}).click();}
 assert(!/login.php|verify_2fa.php/.test(page.url()),'Independent login succeeded');};
 await login(a);
 // A second login needs the next one-time-code window.
 await new Promise(resolve=>setTimeout(resolve,31000-Date.now()%30000));
 await login(b);
 await a.goto(base+'/audience.php?view=home');await b.goto(base+'/audience.php?view=home');
 await Promise.all([a.waitForLoadState('networkidle'),b.waitForLoadState('networkidle')]);
 const api=(page,method='GET',form)=>page.evaluate(async({method,form})=>{const r=await fetch('audience.php?api=notifications',{method,...(form?{body:new URLSearchParams(form)}:{})});return {status:r.status,url:r.url,contentType:r.headers.get('content-type'),data:r.headers.get('content-type')?.includes('application/json')?await r.json():null};},{method,form});
 const firstResponse=await api(a);if(!firstResponse.data)console.log({page:a.url(),title:await a.title(),response:firstResponse});const state=firstResponse.data;assert(state,'Authenticated notification API returns JSON');assert(state.items.length>=2,'Seed at least two notifications.');
 const keys=state.items.map(x=>x.key);const token=await a.locator('[data-notification-csrf]').getAttribute('data-notification-csrf');
 const bad=await api(a,'POST',{keys:JSON.stringify([keys[0]]),csrf_token:'invalid'});assert.equal(bad.status,400);
 const wrong=await api(a,'POST',{keys:'["invalid"]',csrf_token:token});assert.equal(wrong.status,400);
 const count=p=>p.locator('[data-notification-count]').textContent();
 const waitCount=(p,n)=>p.waitForFunction(n=>document.querySelector('[data-notification-count]').textContent===String(n),n);
 const refresh=p=>p.evaluate(()=>window.dispatchEvent(new Event('focus')));
 await a.locator('.audience-notification-trigger').click();
 const persisted=a.waitForResponse(r=>r.url().includes('api=notifications')&&r.request().method()==='POST'&&r.status()===200);
 await a.locator('[data-notification-key]:not([hidden]) [data-notification-dismiss]').first().click();await persisted;
 await refresh(b);await waitCount(b,keys.length-1);assert.equal(await count(a),await count(b));
 // Import an existing browser-only dismissal on a new page load.
 await a.evaluate(key=>{const scope=document.querySelector('[data-notification-scope]').dataset.notificationScope;const name=`moed:audience:notifications:${scope}:cleared`;const values=JSON.parse(localStorage.getItem(name)||'[]');values.push(key);localStorage.setItem(name,JSON.stringify(values));},keys[1]);
 await a.reload();await a.waitForLoadState('networkidle');await refresh(b);await waitCount(b,keys.length-2);
 // Server truth survives loss of local storage and a page navigation.
 await b.evaluate(()=>localStorage.clear());await b.reload();await b.waitForLoadState('networkidle');await waitCount(b,keys.length-2);
 const after=(await api(b)).data;assert(after.cleared.includes(keys[0])&&after.cleared.includes(keys[1]));
 assert.equal((await api(a,'PUT')).status,405);
 await desktop.storageState({path:path.join(work,'session-chromium.json')});
 console.log('Notification sync: separate desktop/phone logins, legacy import, persisted reload, CSRF, input validation and method checks passed.');
}finally{await browser.close();}})().catch(e=>{console.error(e);process.exitCode=1;});
