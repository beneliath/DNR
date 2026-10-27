'use strict';
// Real profile styles/scripts; synthetic records and intercepted profile saves.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const escape=value=>String(value).replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const html=(saved,recovered)=>`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="user-timezone" content="${escape(saved.timezone||'UTC')}"><link rel="stylesheet" href="/assets/css/style.min.css"><link rel="stylesheet" href="/assets/css/modern.min.css"><link rel="stylesheet" href="/assets/css/pages/profile.min.css"><script src="/assets/js/user-time.min.js"></script><script defer src="/assets/js/profile.min.js"></script></head><body class="profile-page"><main class="container profile-container"><h1>My Profile</h1><form class="profile-form" action="profile.php" method="post" enctype="multipart/form-data" data-profile-unsaved="${recovered?'1':'0'}"><input type="hidden" name="csrf_token" value="fixture"><section class="profile-card"><h2>Profile Picture</h2><label>Choose a New Picture<input type="file" name="profile_picture" data-profile-picture-input></label><label><input type="checkbox" name="remove_profile_picture">Remove Current Picture</label></section><section class="profile-card"><h2>Personal Details</h2><label>First Name<input name="first_name" value="${escape(saved.name)}" required></label><label>Display Timezone<select name="display_timezone">${['','UTC','America/Chicago','Asia/Kolkata'].map(zone=>`<option value="${zone}"${zone===saved.timezone?' selected':''}>${zone||'Not selected — UTC'}</option>`).join('')}</select></label></section><section class="profile-card"><h2>Notifications</h2><label><input type="checkbox" name="task_digest_enabled" data-task-digest-enabled>Daily Work Digest</label><p data-task-digest-paused>Digest paused</p><div data-task-digest-schedule><input type="time" name="task_digest_time" value="08:00"><fieldset>${[1,2,4,8,16,32,64].map(value=>`<label><input type="checkbox" name="task_digest_days[]" value="${value}"${value<32?' checked':''}>Day ${value}</label>`).join('')}</fieldset><button type="button" data-task-digest-days="96">Weekends</button><button type="button" data-task-digest-days="31">Weekdays</button></div></section><div class="profile-actions"><button type="submit" class="save-button" data-profile-save aria-describedby="profile-unsaved-status">Save Changes</button><span class="sr-only" id="profile-unsaved-status" data-profile-unsaved-status aria-live="polite"></span></div></form></main></body></html>`;
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true])for(const width of [390,1600]){
    const context=await browser.newContext({viewport:{width,height:1000},timezoneId:'Asia/Tokyo'}),page=await context.newPage();
    page.setDefaultTimeout(7000);let saved={name:'Original',timezone:''};const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.addInitScript(dark=>document.addEventListener('DOMContentLoaded',()=>document.documentElement.classList.toggle('dark-mode',dark)),dark);
    await page.route('**/*',route=>{
        const request=route.request(),url=new URL(request.url());if(url.origin!=='https://profile.test')return route.abort();
        if(url.pathname==='/profile.php'){
            if(request.method()==='POST'){
                const values=Object.fromEntries([...request.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(match=>[match[1],match[2]]));
                saved={name:values.first_name,timezone:values.display_timezone};
            }
            return route.fulfill({contentType:'text/html',body:html(saved,url.searchParams.has('recovered'))});
        }
        const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
    });
    await page.goto('https://profile.test/profile.php');
    const save=page.locator('[data-profile-save]'),name=page.locator('[name=first_name]'),zone=page.locator('[name=display_timezone]');
    const dirty=async expected=>{assert.equal(await save.evaluate(button=>button.classList.contains('profile-save-unsaved')),expected);checks++;};
    await dirty(false);assert.match(await page.evaluate(()=>DnrUserTime.format('2026-10-26 12:00:00')),/12:00 PM UTC$/);checks++;
    await name.fill('Changed');await dirty(true);assert.equal(await save.evaluate(button=>getComputedStyle(button).animationName),'profile-save-blink');checks++;
    await name.fill('Original');await dirty(false);
    await zone.selectOption('America/Chicago');await dirty(true);await zone.selectOption('');await dirty(false);
    await page.locator('[data-task-digest-enabled]').check();await page.getByRole('button',{name:'Weekends',exact:true}).click();await dirty(true);
    await page.getByRole('button',{name:'Weekdays',exact:true}).click();await page.locator('[data-task-digest-enabled]').uncheck();await dirty(false);
    const file=page.locator('[name=profile_picture]');await file.setInputFiles({name:'fixture.png',mimeType:'image/png',buffer:Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=','base64')});await dirty(true);await file.setInputFiles([]);await dirty(false);
    await page.locator('[name=remove_profile_picture]').check();await dirty(true);await page.locator('[name=remove_profile_picture]').uncheck();await dirty(false);
    await zone.selectOption('America/Chicago');await page.emulateMedia({reducedMotion:'reduce'});
    assert.equal(await save.evaluate(button=>getComputedStyle(button).animationName),'none');assert.equal(await save.evaluate(button=>getComputedStyle(button).outlineWidth),'3px');checks+=2;
    await save.click();await page.waitForLoadState('load');await dirty(false);
    assert.match(await page.evaluate(()=>DnrUserTime.format('2026-10-26 12:00:00')),/7:00 AM CDT$/);checks++;
    await page.goto('https://profile.test/profile.php?recovered=1');await dirty(true);
    if(process.env.DNR_REVIEW_OUTPUT){fs.mkdirSync(process.env.DNR_REVIEW_OUTPUT,{recursive:true});await page.screenshot({path:path.join(process.env.DNR_REVIEW_OUTPUT,`profile-unsaved-${width}-${dark?'dark':'light'}.png`)});}
    assert.deepEqual(errors,[]);checks++;await context.close();
}
console.log(`User profile browser: ${checks} checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
