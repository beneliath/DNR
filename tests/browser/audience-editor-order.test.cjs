'use strict';
// Actual editor templates/bundles, with synthetic preview/save requests only.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
const php=(fixture,args=[],input)=>execFileSync('php',[`tests/browser/${fixture}.php`,...args],{cwd:root,encoding:'utf8',maxBuffer:8*1024*1024,input:input&&JSON.stringify(input)});
const defaults=kind=>JSON.parse(execFileSync('php',['-r',`require 'src/audience_store.php';echo json_encode(audienceCollectionDefaults('${kind}'));`],{cwd:root,encoding:'utf8'}));
function asset(route,url){const file=path.join(root,'src',url.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});}
async function pointerMove(page,handle,target,{touch=false,cancel=false}={}){
 await handle.scrollIntoViewIfNeeded();const a=await handle.boundingBox(),b=await target.boundingBox(),x=a.x+a.width/2,y=a.y+a.height/2,end=b.y+b.height-2;
 if(touch){const cdp=await page.context().newCDPSession(page);await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y}]});for(let n=1;n<=8;n++)await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:y+(end-y)*n/8}]});await cdp.send('Input.dispatchTouchEvent',{type:cancel?'touchCancel':'touchEnd',touchPoints:[]});await cdp.detach();}
 else {await page.mouse.move(x,y);await page.mouse.down();await page.mouse.move(x,end,{steps:8});if(cancel)await page.keyboard.press('Escape');await page.mouse.up();}
}
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const [kind,key,tab]of [['hosted','sections','design'],['survey','questions','questions'],['popup','quiz','setup']]){
  let document=defaults(kind);document[key]=['Alpha','Bravo','Charlie'].map((text,i)=>key==='questions'?{id:'q'+i,type:'text',label:text,required:false}:key==='sections'?{type:'text',text}:{label:text,outcome:text+' outcome'});
  const errors=[],page=await browser.newPage({viewport:{width:1600,height:1200},hasTouch:true});page.setDefaultTimeout(6000);page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',route=>{const req=route.request(),url=new URL(req.url());if(url.hostname!=='order.test')return route.abort();
   if(url.pathname==='/audience.php'){
    if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:php('audience-collection-images-fixture',['editor',kind],{document})});
    const data=req.headers()['content-type']?.includes('multipart/form-data')?Object.fromEntries([...req.postData().matchAll(/name="([^"]+)"\r\n\r\n([\s\S]*?)\r\n--/g)].map(m=>[m[1],m[2]])):Object.fromEntries(new URLSearchParams(req.postData()));
    if(data.action==='collection_preview')return route.fulfill({contentType:'application/json',body:JSON.stringify({html:'<!doctype html><p>Preview</p>'})});
    // Saves use multipart FormData.
    assert.equal(data.action,'collection_save');document=JSON.parse(data.document_json);
    return route.fulfill({contentType:'text/html',body:php('audience-collection-images-fixture',['editor',kind],{document})});
   }return asset(route,url);
  });
  await page.goto('https://order.test/audience.php?view=form&id=7');await page.locator(`[data-collection-tab="${tab}"]`).click();
  const list=page.locator(`[data-${key==='questions'?'question':key==='sections'?'section':'quiz'}-list]`);
  await list.locator('xpath=ancestor::details[1]').evaluateAll(nodes=>nodes.forEach(n=>n.open=true));
  const state=async()=>JSON.parse(await page.locator('[data-collection-document]').inputValue())[key];
  const labels=async()=>(await state()).map(v=>v.label||v.text);
  const grip=index=>list.locator('.collection-list-head .mm-reorder-handle').nth(index);
  assert.equal(await grip(0).count(),1);assert.equal(await list.getByRole('button',{name:/^[↑↓]$/}).count(),0);checks+=2;
  await grip(0).press('End');assert.deepEqual(await labels(),['Bravo','Charlie','Alpha']);assert.equal(await grip(2).evaluate(n=>n===document.activeElement),true);checks+=2;
  await page.locator('[data-collection-undo]').click();assert.deepEqual(await labels(),['Alpha','Bravo','Charlie']);checks++;
  // Collapse the cards' controls so all three destinations fit inside the scrolling panel.
  await list.evaluate(n=>n.querySelectorAll('.collection-item').forEach(card=>[...card.children].filter(child=>!child.classList.contains('collection-list-head')).forEach(child=>child.style.display='none')));
  await pointerMove(page,grip(0),list.locator('.collection-item').nth(2),{touch:key==='quiz',cancel:true});assert.deepEqual(await labels(),['Alpha','Bravo','Charlie']);checks++;
  await pointerMove(page,grip(0),list.locator('.collection-item').nth(2),{touch:key==='quiz'});assert.deepEqual(await labels(),['Bravo','Charlie','Alpha']);checks++;
  await page.locator('[data-collection-undo]').click();assert.deepEqual(await labels(),['Alpha','Bravo','Charlie']);await page.locator('[data-collection-redo]').click();assert.deepEqual(await labels(),['Bravo','Charlie','Alpha']);checks+=2;
  if(key==='questions'){assert.deepEqual((await state()).map(v=>v.id),['q1','q2','q0']);checks++;}
  await Promise.all([page.waitForNavigation(),page.locator('[data-collection-save]').first().click()]);assert.deepEqual(document[key].map(v=>v.label||v.text),['Bravo','Charlie','Alpha']);assert.deepEqual(errors,[]);checks+=2;await page.close();
 }
 const page=await browser.newPage({viewport:{width:1600,height:1100},hasTouch:true});page.setDefaultTimeout(6000);const errors=[],saves=[];page.on('pageerror',e=>errors.push(e.message));
 await page.route('**/*',route=>{const req=route.request(),url=new URL(req.url());if(url.hostname!=='order.test')return route.abort();if(url.pathname==='/audience.php'){
  if(req.method()==='GET')return route.fulfill({contentType:'text/html',body:php('audience-designer-order-fixture')});
  const data=Object.fromEntries(new URLSearchParams(req.postData()));assert.equal(data.action,'design_save');saves.push(JSON.parse(data.design));return route.fulfill({contentType:'application/json',body:JSON.stringify({version:saves.length+1})});
 }if(url.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});return asset(route,url);});
 await page.goto('https://order.test/audience.php?view=designer&kind=template&id=7');
 const rows=page.locator('#email-canvas>.designer-row'),order=()=>rows.evaluateAll(nodes=>nodes.map(n=>n.dataset.sectionId));
 const blockOrder=()=>page.locator('#email-canvas .designer-block').evaluateAll(nodes=>nodes.map(n=>n.dataset.blockId));
 assert.deepEqual(await order(),['section-0','section-1','section-2']);checks++;
 await page.getByText('More blocks and tools',{exact:true}).click();await page.getByRole('button',{name:'Manage email sections',exact:true}).click();const sections=page.locator('.designer-section-list');
 await sections.locator('.mm-reorder-handle').first().press('End');assert.deepEqual(await order(),['section-1','section-2','section-0']);checks++;
 await page.locator('#design-undo').evaluate(n=>n.click());assert.deepEqual(await order(),['section-0','section-1','section-2']);checks++;
 await pointerMove(page,sections.locator('.mm-reorder-handle').first(),sections.locator('.designer-section-row').last(),{touch:true});assert.deepEqual(await order(),['section-1','section-2','section-0']);checks++;
 await page.locator('#design-undo').evaluate(n=>n.click());assert.deepEqual(await order(),['section-0','section-1','section-2']);checks++;
 await rows.first().hover();await rows.first().locator('.designer-layout-tools .mm-reorder-handle').press('ArrowDown');assert.deepEqual(await order(),['section-1','section-0','section-2']);checks++;
 await page.locator('#design-undo').evaluate(n=>n.click());
 const initial=await blockOrder(),block=page.locator('[data-block-id="block-0-0"]');await block.hover();await block.locator('.designer-block-move').press('ArrowDown');assert.deepEqual((await blockOrder()).slice(0,2),['block-0-1','block-0-0']);checks++;
 await page.locator('#design-undo').evaluate(n=>n.click());assert.deepEqual(await blockOrder(),initial);checks++;
 await block.click();const inspector=page.locator('.designer-block-actions .mm-reorder-handle');assert.equal(await inspector.count(),1);assert.equal(await page.getByRole('button',{name:/^Move (up|down)$/}).count(),0);checks+=2;
 await inspector.press('ArrowDown');assert.deepEqual((await blockOrder()).slice(0,2),['block-0-1','block-0-0']);checks++;
 await page.locator('#design-undo').evaluate(n=>n.click());await block.hover();
 // Move a block into another section using the existing insertion-gap engine.
 const handle=block.locator('.designer-block-move'),a=await handle.boundingBox(),destination=page.locator('[data-block-id="block-1-0"]');await destination.scrollIntoViewIfNeeded();await block.hover();const b=await destination.boundingBox();
 await page.mouse.move(a.x+a.width/2,a.y+a.height/2);await page.mouse.down();await page.mouse.move(b.x+b.width/2,b.y+b.height-3);await page.mouse.up();
 assert.deepEqual(await blockOrder(),['block-0-1','block-1-0','block-0-0','block-1-1','block-2-0','block-2-1']);checks++;
 await page.locator('#design-undo').evaluate(n=>n.click());assert.deepEqual(await blockOrder(),initial);await page.locator('#design-redo').evaluate(n=>n.click());checks++;
 await page.locator('#design-save-only').evaluate(n=>n.click());await page.waitForFunction(()=>document.querySelector('#design-status').textContent==='Saved');
 assert.deepEqual(saves.at(-1).rows[1].columns[0].map(v=>v.id),['block-1-0','block-0-0','block-1-1']);assert.deepEqual(errors,[]);checks+=2;
 await page.close();console.log(`Editor drag ordering: ${checks} browser checks passed.`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
