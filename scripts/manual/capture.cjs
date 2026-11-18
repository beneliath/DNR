/* Capture guide figures as native 435 DPI PNGs from a disposable preview.
 * Preserve editorial titles and instructions from screenshots.json. Business-changing
 * POSTs are blocked; only authentication and selection-review requests are allowed.
 * Captured page HTML, sessions and credentials remain in the ignored work directory.
 */
const fs=require('node:fs');
const path=require('node:path');
const cp=require('node:child_process');
const crypto=require('node:crypto');
const {crc32}=require('node:zlib');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const root=path.resolve(__dirname,'../..');
const folder=process.env.DNR_MANUAL_WORK_DIR || path.join(root,'tmp/pdfs/manual-capture');
const output=process.env.DNR_MANUAL_OUTPUT_DIR || path.join(root,'docs/user-manual');
const base=process.env.DNR_MANUAL_BASE_URL || 'http://127.0.0.1:9087';
if(!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base))throw Error('Use a loopback disposable preview.');
fs.mkdirSync(folder,{recursive:true});
fs.mkdirSync(path.join(output,'screenshots'),{recursive:true});
const session=process.env.DNR_MANUAL_SESSION || path.join(root,'tmp/pdfs/manual-session.json');
const f=JSON.parse(fs.readFileSync(process.env.DNR_MANUAL_FIXTURE_JSON || path.join(root,'tmp/pdfs/fixture.json'))),x=JSON.parse(fs.readFileSync(process.env.DNR_MANUAL_EXTRA_JSON || path.join(root,'tmp/pdfs/extra.json')));
const credentials=JSON.parse(fs.readFileSync(process.env.DNR_MANUAL_CREDENTIALS || path.join(folder,'credentials.json')));
const shots=JSON.parse(fs.readFileSync(path.join(root,'docs/user-manual/screenshots.json')));
const only=process.env.DNR_MANUAL_ONLY?.split(',');
const desktopWidth=Number(process.env.DNR_MANUAL_VIEWPORT_WIDTH || 1280);
if(!Number.isInteger(desktopWidth)||desktopWidth<1280||desktopWidth>2560)throw Error('Desktop capture width must be between 1280 and 2560 CSS pixels.');
const captureDpi=435;
const deviceScaleFactor=captureDpi/96;
const capturedAt=new Intl.DateTimeFormat('en-CA',{timeZone:'America/Chicago',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
fs.mkdirSync(path.join(folder,'captures'),{recursive:true});
function captureMetadata(file, png, details) {
 const density=Buffer.alloc(21);
 density.writeUInt32BE(9,0);
 density.write('pHYs',4,'ascii');
 density.writeUInt32BE(Math.round(captureDpi/0.0254),8);
 density.writeUInt32BE(Math.round(captureDpi/0.0254),12);
 density[16]=1;
 density.writeUInt32BE(crc32(density.subarray(4,17)),17);
 // Add physical density without decoding or recompressing any screenshot pixels.
 const chunks=[png.subarray(0,33),density];
 for(let offset=33;offset<png.length;){
  const end=offset+12+png.readUInt32BE(offset);
  if(png.toString('ascii',offset+4,offset+8)!=='pHYs')chunks.push(png.subarray(offset,end));
  offset=end;
 }
 const tagged=Buffer.concat(chunks);
 fs.writeFileSync(file,tagged);
 return {...details,dpi:captureDpi,native_pixels:[png.readUInt32BE(16),png.readUInt32BE(20)],
  sha256:crypto.createHash('sha256').update(tagged).digest('hex')};
}
function code(){let bits='';for(const ch of credentials.totp_secret.toUpperCase().replace(/=+$/,''))bits+='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(ch).toString(2).padStart(5,'0');const key=Buffer.from(bits.match(/.{8}/g).map(b=>parseInt(b,2)));const counter=Buffer.alloc(8);counter.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const h=crypto.createHmac('sha1',key).update(counter).digest();return ((h.readUInt32BE(h[19]&15)&0x7fffffff)%1000000).toString().padStart(6,'0');}
function route(s){
 let u=new URL(s.route,base+'/');const file=u.pathname.slice(1);
 const ids={'view_inquiry.php':f.inquiry,'edit_inquiry.php':f.inquiry,'convert_inquiry.php':f.inquiry,'compose_inquiry_email.php':f.inquiry,'view_engagement.php':f.event,'edit_engagement.php':f.event,'close_engagement.php':f.event,'view_organization.php':f.org,'edit_organization.php':f.org,'view_contact.php':f.contact,'edit_contact.php':f.contact,'view_speaker.php':f.speaker,'edit_speaker.php':f.speaker,'edit_task.php':f.task,'inbound_mail.php':f.mail,'map_pin.php':f.event,'edit_email_template.php':x.template,'edit_user.php':x.target_user,'view_standard_task.php':x.standard,'reimbursement_request.php':x.request,'reimbursement_submit.php':x.request,'reimbursement_expense.php':(x.expenses || [])[2]};
 if(ids[file])u.searchParams.set('id',ids[file]);
 if(['final-report','financial-history','financial-correction'].includes(s.id))u.searchParams.set('id',x.past_event);
 if(s.id==='inquiry-archive')u.searchParams.set('id',x.archived_inquiry);
 if(s.id==='reimbursement-delivery')u.searchParams.set('id',x.submitted);
 if(s.id.startsWith('reimbursement-photo-')&&x.photo_request)u.searchParams.set('id',x.photo_request);
 if(s.id==='reimbursement-detail')u.searchParams.set('id',(x.expenses || [])[1]);
 if(file==='short_links.php'){u.search='';u.searchParams.set(s.id==='link-controls'?'id':'presentation_id',s.id==='link-controls'?x.link:x.presentation);}
 if(file==='reset_presentation_stats.php'){u.searchParams.set('presentation_id',x.presentation);if(s.id==='presentation-link-reset')u.searchParams.set('link_id',x.link);}
 if(file==='restore_presentations.php')u.searchParams.set('engagement_id',f.event);
 if(file==='add_task.php')u.searchParams.set('duplicate_from',f.task);
 if(file==='map.php'&&s.id==='map'){u.search='?date_from=2026-11-10&date_to=2026-11-14';}
 if(s.id==='calendar-agenda')u.search='?day=2026-10-13';
 if(s.id==='record-merge')u.search='?kind=organization&source='+x.duplicate_org+'&target='+f.org;
 if(s.id==='task-archived')u.search='?view=archived&scope=mine';
 if(s.id==='task-confirmation')u.search='?view=completed&scope=mine';
 if(s.id==='bulk-speaker-review')return base+'/speakers.php';
 if(s.id.startsWith('bulk-user'))return base+'/users.php';
 if(s.id==='task-bulk-preview')return base+'/tasks.php?owner=me';
 return u.href;
}
const targets={
 'inquiry-action':'#inquiry-next-action','engagement-form':'.engagement-details-section','presentation-form':'.presentation-fields','presentation-files':'.presentation-assets','presentation-file-pending':'.presentation-assets','presentation-management':'.presentation-management-actions','lifecycle':'[data-engagement-lifecycle]',
 'statistics':'.short-link-report','email-template-fields':'.email-template-field-help','speaker-links':'#speaker-custom-links-heading','standard-form':'.form-section:has(#standard-task-priority)','email-message':'#body','subscription':'#calendar-subscriptions-title','retention':'#audit-retention-title','link-controls':'[name=target_url]','closeout-amounts':'.closeout-form','recovery-email':'#recovery-email-heading',
 'digest-preferences':'#notification-preferences-heading','reimbursement-profile':'.profile-reviewer-email','reimbursement-receipts':'[data-receipt-drop]','reimbursement-selection':'.reimbursement-selection-summary','reimbursement-confirm':'.reimbursement-confirm','dashboard-timing':'.dashboard-my-work'};
(async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.CHROMIUM_EXECUTABLE?{executablePath:process.env.CHROMIUM_EXECUTABLE}:{})});
 const context=await browser.newContext({...(fs.existsSync(session)?{storageState:session}:{}),viewport:{width:desktopWidth,height:1000},deviceScaleFactor,reducedMotion:'reduce'});
 await context.route('**/*',route=>{const request=route.request();if(request.method()!=='POST')return route.continue();const file=new URL(request.url()).pathname.split('/').pop();const action=new URLSearchParams(request.postData()||'').get('action');if(['login.php','verify_2fa.php','admin_elevation.php','admin_lock.php'].includes(file)||(['bulk_delete.php','task_bulk.php'].includes(file)&&action==='review'))return route.continue();return route.abort('blockedbyclient');});
 await context.addInitScript(()=>localStorage.setItem('theme','light'));
 const regularPage=await context.newPage();let page=regularPage;page.setDefaultTimeout(30000);let elevatedAt=0,usedCounter=Math.floor(Date.now()/30000);
 await page.goto(base+'/dashboard.php',{waitUntil:'domcontentloaded',timeout:30000});if(page.url().includes('login.php')){await page.locator('[name=username]').fill(credentials.username);await page.locator('[name=password]').fill(credentials.password);await page.getByRole('button',{name:'Sign In',exact:true}).click();if(page.url().includes('verify_2fa.php')){await page.locator('[name=authentication_code]').fill(code());await page.getByRole('button',{name:'Verify',exact:true}).click();}}
 await page.locator('#app-sidebar').waitFor({state:'attached',timeout:10000});
 await context.storageState({path:session});
 async function unlock(){if(Date.now()-elevatedAt<210000)return;if(Math.floor(Date.now()/30000)<=usedCounter)await page.waitForTimeout(30500-Date.now()%30000);await page.goto(base+'/admin_elevation.php');if(!page.url().includes('admin_elevation.php')){elevatedAt=Date.now();return;}await page.locator('#admin_password').fill(credentials.password);await page.locator('#admin_code').fill(code());await page.getByRole('button',{name:'Unlock Sensitive Actions',exact:true}).click();await page.waitForURL(url=>!url.pathname.includes('admin_elevation.php'),{timeout:7000});elevatedAt=Date.now();usedCounter=Math.floor(Date.now()/30000);await context.storageState({path:session});}
 const result=[];let releaseUpload=null;
 for(const s of shots){if(only&&!only.includes(s.id))continue;
  try{
   page=regularPage;if(s.anonymous){const anonymous=await browser.newContext({viewport:{width:1280,height:1000},deviceScaleFactor,reducedMotion:'reduce'});page=await anonymous.newPage();page.setDefaultTimeout(30000);}
   if(s.id==='reimbursement-report-receipt'){
    const response=await context.request.get(base+'/download_reimbursement.php?id='+x.request+'&format=pdf');if(!response.ok())throw Error('Sample report download failed: '+response.status()+' '+(await response.text()).slice(0,300));fs.writeFileSync(path.join(folder,'sample-report.pdf'),await response.body());
    const prefix=path.join(output,'screenshots',s.id);
    cp.execFileSync('pdftoppm',['-f','3','-l','3','-r',String(captureDpi),'-png','-singlefile',path.join(folder,'sample-report.pdf'),prefix]);
    const file=prefix+'.png';
    const capture=captureMetadata(file,fs.readFileSync(file),{render_dpi:captureDpi,source:'actual generated fictional report, receipt page 3'});
    result.push({id:s.id,route:'download_reimbursement.php?id='+x.request+'&format=pdf',file:'screenshots/'+s.id+'.png',captured_at:capturedAt,capture});console.log('Captured '+s.id);continue;
   }
   const mobile=s.id==='mobile'||s.id==='calendar-agenda';
   const width=process.env.DNR_MANUAL_VIEWPORT_WIDTH?desktopWidth:s.capture?.viewport?.width??desktopWidth;
   await page.setViewportSize({width:mobile?390:width,height:mobile?844:1000});
   if(s.elevated||['bulk-speaker-review','bulk-user-review','bulk-user-confirmation','delete-user','presentation-delete-confirmation','network-reset','record-merge','reset-statistics','presentation-link-reset'].includes(s.id))await unlock();
   const targetUrl=route(s);const response=await page.goto(targetUrl,{waitUntil:'domcontentloaded'});if(response&&response.status()!==200)throw Error('HTTP '+response?.status());
   if(page.url().includes('login.php')&&!s.anonymous)throw Error('Preview login expired');
   if(s.tab&&await page.getByRole('tab',{name:new RegExp('^'+s.tab+'(?:\\s|$)')}).count())await page.getByRole('tab',{name:new RegExp('^'+s.tab+'(?:\\s|$)')}).click();
   if(['financial-history','final-report','financial-correction'].includes(s.id)&&await page.getByRole('tab',{name:'Financials',exact:true}).count())await page.getByRole('tab',{name:'Financials',exact:true}).click();
   if(['presentation-ppt-qr','presentation-exports'].includes(s.id))await page.getByRole('tab',{name:/^Presentations(?:\s|$)/}).click();
   if(s.id==='elevation'){await page.goto(base+'/users.php');if(await page.locator('[data-admin-lock-button]:visible').count()){await page.locator('[data-admin-lock-button]:visible').click();await page.locator('[data-admin-lock-button]').waitFor({state:'hidden'});elevatedAt=0;}await page.locator('a[data-admin-unlock-link]:visible').first().click();await page.locator('#admin-unlock-dialog[open]').waitFor({state:'visible'});}
   if(s.id==='dark')await page.locator('[data-theme-toggle]').last().click();
   if(s.id==='mobile')await page.locator('[data-nav-toggle]').click();
   if(s.id==='engagement-cancel'){await page.locator('[data-cancel-engagement="'+f.event+'"]').click();await page.locator('#engagement-cancel-reason').fill('The host has canceled this fictional workshop. Coordinate a replacement date.');}
   if(s.id==='presentation-optional')await page.locator('#presentation_not_required').check();
   if(s.id==='presentation-duplicate')await page.locator('[data-duplicate-presentation]').first().click();
   if(s.id==='presentation-upload-progress'){
    let chunks=0;const hold=new Promise(resolve=>{releaseUpload=resolve;});
    await page.route('**/presentation_chunk.php',async request=>{const body=request.request().postData()||'';if(/name="action"\r?\n\r?\nchunk/.test(body)&&++chunks>1)await hold;await request.continue().catch(()=>{});});
    await page.locator('[data-ppt-slidedeck-pane] input[type=file]').first().setInputFiles(process.env.DNR_MANUAL_LARGE_SLIDEDECK_FILE);
    await page.getByRole('button',{name:'Save Changes',exact:true}).click();
    await page.waitForFunction(()=>{const bar=document.querySelector('.presentation-upload-progress progress');return bar&&bar.value>0&&bar.value<100;});
   }
   if(s.id==='roles')await page.locator('.role-preview-disclosure summary, .role-preview-disclosure > summary').first().click();
   if(s.id==='manual-search')await page.locator('[data-manual-search]').fill('financial closeout');
   if(s.id==='email-template-fields')await page.getByText(/Personalize with Event Fields/i,{exact:true}).click();
   if(s.id==='standard-form'){await page.locator('#standard-task-title').fill('Review presentation resources');await page.locator('#standard-task-details').fill('Check the uploaded speaker notes and audience resource links.');await page.locator('#standard-task-priority').selectOption('high');await page.locator('#standard-task-offset').fill('-3');await page.locator('#generate-existing-engagements').check();}
   if(s.id==='email'){if(await page.locator('[data-select-all-recipients]').count())await page.locator('[data-select-all-recipients]').click();}
   if(s.id==='chron')await page.getByText('Add Chron Log Entry',{exact:true}).first().click();
   if(s.id==='reimbursement-selection'){
    await page.locator('[data-select-all-available]').first().click();
    await page.waitForFunction(()=>Array.from(document.querySelectorAll('[data-selection-count]')).some(node=>/^[1-9]\d* expenses? selected/i.test(node.textContent.trim())));
   }
   if(s.id==='presentation-file-pending'){await page.locator('[data-ppt-slidedeck-pane] input[type=file]').first().setInputFiles(process.env.DNR_MANUAL_SLIDEDECK_FILE || path.join(root,'tests/fixtures/powerpoint/basic_test_ppt_file.ppt'));}
   if(['presentation-management','presentation-delete-confirmation'].includes(s.id)){
    if(s.id==='presentation-delete-confirmation')await page.locator('button[form^="delete-presentation-"]').first().click();
   }
   if(['qr-selection','presentation-exports'].includes(s.id)){
    await page.getByText('Export Presentation',{exact:true}).first().click();
    if(s.id==='qr-selection'){await page.getByRole('link',{name:'View QR Codes PDF',exact:true}).first().click();}
   }
   if(s.id==='presentation-ppt-qr'){await page.getByText('Files and QR Codes',{exact:true}).first().click();}if(s.id==='presentation-ppt-qr')await page.getByRole('button',{name:'Copy PPT Slidedeck QR Code',exact:true}).evaluate(e=>e.closest('.presentation-view-qr-grid').scrollIntoView({block:'start'}));
   // The completed-task figure is read-only; prepare a completed fictional task beforehand.
   if(s.id==='network-reset')await page.getByRole('button',{name:'Reset Statistics',exact:true}).click();
   if(s.id==='delete-user'){await page.locator('.user-details').filter({hasText:'jamie.manual'}).getByRole('button',{name:'Delete User',exact:true}).click();}
   if(s.id==='bulk-speaker-review'){await page.locator('input[type=checkbox][name="selected_ids[]"]').last().check();await page.locator('[data-bulk-submit]').click();}
   if(s.id.startsWith('bulk-user')){await page.locator('input[name="selected_ids[]"]').first().check();await page.locator('[data-bulk-submit]').click();if(s.id==='bulk-user-confirmation')await page.locator('#bulk-delete-confirmation').fill('DELETE USERS');void 0;}
   if(s.id==='financial-history')await page.getByText('Financial Revision History',{exact:true}).click();
   if(s.id==='map-pending-filters'){await page.locator('#map-date-from-display').fill('2026-11-01');await page.locator('#map-date-from-display').press('Tab');}
   if(s.id==='task-bulk-preview'){await page.locator('[data-task-selection]').first().check();await page.getByRole('button',{name:'Preview Updates',exact:true}).click();}
   if(s.id==='duplicate-record-warning'){await page.locator('[name=organization_name]').fill('Cedar Grove Community - Manual Example');await page.locator('[name=organization_name]').press('Tab');await page.locator('[data-duplicate-warning]').waitFor({state:'visible'});}
   if(s.id==='browser-draft-recovery'){await page.locator('#subject').fill('Community Learning Weekend - planning draft');await page.locator('#body').fill('Hello Jordan,\n\nPlease confirm the arrival details for our upcoming workshop.\n\nFictional documentation draft.');await page.waitForTimeout(800);await page.goto(base+'/dashboard.php',{waitUntil:'domcontentloaded',timeout:30000});await page.goto(targetUrl);}
   const body=await page.locator('body').innerText();if(/Fatal error:|unexpected error|Warning:|selection has expired/.test(body))throw Error('Unexpected application output');
   if(s.id==='reimbursement-photo-reduced'&&!/\b[1-9]\d* of \d+ photos reduced\b/i.test(body))throw Error('The fictional receipt photos were not reduced.');
   await page.waitForLoadState('networkidle',{timeout:7000}).catch(()=>{});
   await page.evaluate(async()=>{await document.fonts.ready;await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
   if(['map','map-pin','map-pending-filters'].includes(s.id))await page.waitForTimeout(1800);
   let selector=targets[s.id]||s.focus;
   if(selector&&await page.locator(selector).count())await page.locator(selector).first().evaluate(e=>{e.scrollIntoView({block:'start'});window.scrollBy(0,-20);});
   if(s.id==='presentation-management')await page.locator(selector).first().evaluate(e=>e.scrollIntoView({block:'center'}));
   if(['bulk-speaker-review','bulk-user-review','bulk-user-confirmation'].includes(s.id))await page.evaluate(()=>window.scrollTo(0,0));
   if(s.id==='reimbursement-confirm')await page.locator('.reimbursement-confirm').evaluate(e=>window.scrollTo(0,window.scrollY+e.getBoundingClientRect().top-630));
   if(s.id.startsWith('reimbursement-photo-'))await page.locator('#receipt-photo-heading').evaluate(heading=>{for(let element=heading;element;element=element.parentElement)element.scrollTop=0;window.scrollTo(0,0);});
   if(s.id==='reimbursement-delivery')await page.getByRole('heading',{name:'Email Delivery',exact:true}).evaluate(e=>window.scrollTo(0,window.scrollY+e.getBoundingClientRect().top-80));
   await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
   const dialog=page.locator('dialog[open]');let clip;
   if(await dialog.count()){
    const box=await dialog.last().boundingBox();clip={x:Math.floor(Math.max(0,box.x-18)),y:Math.floor(Math.max(0,box.y-18)),width:Math.ceil(Math.min(page.viewportSize().width-box.x+18,box.width+36)),height:Math.ceil(Math.min(1000-box.y+18,box.height+36))};
   }else if(s.shell||['manual','roles','inbox','login','forgot-password'].includes(s.id)){
    const v=page.viewportSize();clip={x:0,y:0,width:v.width,height:v.height};
   }else{
    const v=page.viewportSize();clip={x:270,y:0,width:v.width-270,height:s.id==='engagement-form'?v.height:Math.min(850,v.height)};
   }
   const footer=page.locator('.app-footer');const fb=await footer.count()?await footer.boundingBox():null;
   if(fb&&fb.y>clip.y+160&&fb.y<clip.y+clip.height)clip.height=Math.floor(fb.y-clip.y-8);
   if(['bulk-speaker-review','bulk-user-review','bulk-user-confirmation'].includes(s.id)){const box=await page.locator('main').boundingBox();clip={x:Math.max(0,Math.floor(box.x-8)),y:0,width:Math.min(page.viewportSize().width-Math.max(0,Math.floor(box.x-8)),Math.ceil(box.width+16)),height:Math.min(850,Math.ceil(box.y+box.height))};}
   if(['engagement-list','organizations','contacts','speakers','tasks','task-confirmation','task-archived'].includes(s.id)){
    const rows=await page.locator('main table tbody tr').evaluateAll(elements=>elements.map(e=>e.getBoundingClientRect().bottom));
    const last=rows.filter(bottom=>bottom>clip.y&&bottom<=clip.y+clip.height).pop();
    if(last&&rows.some(bottom=>bottom>clip.y+clip.height))clip.height=Math.floor(last-clip.y);
   }
   const file=path.join(output,'screenshots',s.id+'.png');
   const png=await page.screenshot({type:'png',scale:'device',clip,animations:'disabled'});
   const capture=captureMetadata(file,png,{device_scale_factor:deviceScaleFactor,viewport:page.viewportSize(),clip,source:'native browser PNG; no resizing or sharpening'});
   if(releaseUpload){releaseUpload();releaseUpload=null;}
   fs.writeFileSync(path.join(folder,'captures',s.id+'.txt'),body);fs.writeFileSync(path.join(folder,'captures',s.id+'.html'),await page.content());
   const location=new URL(page.url());location.searchParams.delete('selection');
   result.push({id:s.id,route:location.pathname.slice(1)+location.search,file:'screenshots/'+s.id+'.png',captured_at:capturedAt,capture});
   console.log('Captured '+s.id);
  }catch(error){fs.writeFileSync(path.join(folder,'captures',s.id+'-failed.txt'),await page.locator('body').innerText().catch(()=>''));fs.writeFileSync(path.join(folder,'captures',s.id+'-failed.html'),await page.content().catch(()=>''));console.error('FAILED '+s.id+': '+error.message);result.push({id:s.id,error:error.message});}
 }
 const previous=fs.existsSync(path.join(folder,'capture-results.json'))?JSON.parse(fs.readFileSync(path.join(folder,'capture-results.json'))):[];
 fs.writeFileSync(path.join(folder,'capture-results.json'),JSON.stringify([...result,...previous.filter(s=>!result.some(r=>r.id===s.id))],null,2)+'\n');
 const priorShots=fs.existsSync(path.join(output,'screenshots.json'))?JSON.parse(fs.readFileSync(path.join(output,'screenshots.json'))):[];
 const updated=shots.map(shot=>{const capture=result.find(item=>item.id===shot.id);if(!capture)return priorShots.find(previous=>previous.id===shot.id)||shot;if(capture.error)return {...shot,error:capture.error};const refreshed={...shot,...capture,framing:'Native high-resolution capture from the actual application in an isolated fictional-data preview.'};for(const key of ['error','crop','omitted_regions','footer_removed'])delete refreshed[key];return refreshed;});
 fs.writeFileSync(path.join(output,'screenshots.json'),JSON.stringify(updated,null,2)+'\n');
 await context.storageState({path:session});await browser.close();if(result.some(s=>s.error))process.exitCode=1;
})();
