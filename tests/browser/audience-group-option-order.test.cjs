'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');const root=path.resolve(__dirname,'../..');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;try{
 for(const width of [390,1600])for(const dark of [false,true]){
  const page=await browser.newPage({viewport:{width,height:1000},hasTouch:true});page.setDefaultTimeout(6000);const errors=[],posts=[];
  page.on('pageerror',error=>errors.push(error.message));await page.addInitScript(d=>localStorage.setItem('theme',d?'dark':'light'),dark);
  await page.route('**/*',route=>{const request=route.request(),u=new URL(request.url());if(u.hostname!=='group-order.test')return route.abort();
   if(u.pathname==='/audience.php'){
    if(request.method()==='POST'){posts.push(Object.fromEntries(new URLSearchParams(request.postData())));return route.fulfill({contentType:'text/html',body:'<!doctype html><p>Group fixture saved</p>'});}
    return route.fulfill({contentType:'text/html',body:execFileSync('php',['tests/browser/audience-tag-actions-fixture.php','admin','active','locked','2','','','groups'],{cwd:root,encoding:'utf8'})});
   }
   if(u.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
   const file=path.join(root,'src',u.pathname);return fs.existsSync(file)&&fs.statSync(file).isFile()?route.fulfill({body:fs.readFileSync(file),contentType:file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':'image/svg+xml'}):route.fulfill({status:404,body:''});
  });
  await page.goto('https://group-order.test/audience.php?view=groups');const edit=page.locator('.audience-group-grid [data-group-edit]').first();
  await edit.evaluate(button=>{const data=JSON.parse(button.dataset.groupEdit);for(let i=1;i<=4;i++)data.options.push({id:100+i,name:'Choice '+(i+2),contact_count:0});button.dataset.groupEdit=JSON.stringify(data);});
  await edit.click();const dialog=page.locator('#group-editor'),rows=dialog.locator('[data-group-option]');
  const order=()=>rows.evaluateAll(nodes=>nodes.map(row=>row.querySelector('input[type=hidden]').value));
  const initial=['30','40','101','102','103','104'];
  assert.equal(await rows.locator('[data-group-drag]').count(),6);assert.equal(await dialog.getByRole('button',{name:/Move group choice (up|down)/}).count(),0);assert.equal(await rows.locator('[data-group-drag] svg').count(),6);checks+=3;
  const second=await rows.nth(1).locator('[data-group-drag]').elementHandle();await second.focus();await page.keyboard.press('ArrowUp');
  assert.deepEqual(await order(),['40','30','101','102','103','104']);assert.equal(await second.evaluate(handle=>handle===document.activeElement),true);assert.match(await dialog.locator('[data-group-order-announcement]').textContent(),/position 1/);checks+=3;
  await page.keyboard.press('ArrowDown');assert.deepEqual(await order(),initial);checks++;
  const mouseDrag=async(from,to)=>{
   await rows.last().scrollIntoViewIfNeeded();const start=await rows.nth(from).locator('[data-group-drag]').boundingBox(),target=await rows.nth(to).boundingBox();
   await page.mouse.move(start.x+start.width/2,start.y+start.height/2);await page.mouse.down();await page.mouse.move(start.x+start.width/2,from<to?target.y+target.height-2:target.y+2,{steps:12});await page.mouse.up();
  };
  await mouseDrag(0,5);assert.deepEqual(await order(),['40','101','102','103','104','30']);checks++;
  if(width===390){
   await rows.last().scrollIntoViewIfNeeded();const start=await rows.last().locator('[data-group-drag]').boundingBox(),target=await rows.first().boundingBox(),cdp=await page.context().newCDPSession(page);
   const x=start.x+start.width/2,y=start.y+start.height/2;
   await cdp.send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x,y,id:1}]});
   for(let i=1;i<=12;i++)await cdp.send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{x,y:y+(target.y+2-y)*i/12,id:1}]});
   await cdp.send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});await cdp.detach();
  }else await mouseDrag(5,0);
  assert.deepEqual(await order(),initial);assert.equal(await dialog.locator('.dragging').count(),0);checks+=2;
  await dialog.getByRole('button',{name:'Cancel',exact:true}).click();await edit.click();assert.deepEqual(await order(),initial);checks++;
  await dialog.getByRole('button',{name:/Add option/}).click();await rows.last().locator('input[type=text]').fill('New choice');await rows.last().locator('[data-group-drag]').focus();await page.keyboard.press('ArrowUp');
  assert.deepEqual(await order(),['30','40','101','102','103','0','104']);checks++;
  assert.equal(await rows.nth(0).getByRole('button',{name:'Remove group choice',exact:true}).getAttribute('data-admin-unlock-required'),'');assert.equal(await rows.nth(5).getByRole('button',{name:'Remove group choice',exact:true}).getAttribute('data-admin-unlock-required'),null);checks+=2;
  assert.equal(await page.evaluate(()=>document.scrollingElement.scrollWidth>innerWidth),false);checks++;
  if(width===1600&&!dark)await dialog.screenshot({path:path.join(root,'output/ui/group-option-drag-handles.png')});
  await dialog.getByRole('button',{name:'Save group',exact:true}).click();await page.getByText('Group fixture saved',{exact:true}).waitFor();
  assert.equal(posts.length,1);assert.equal(posts[0].action,'save_group');assert.equal(posts[0].id,'7');assert.equal(posts[0].version,'1');checks+=4;
  const expected=['30','40','101','102','103','0','104'];for(let i=0;i<expected.length;i++){assert.equal(posts[0][`options[${i}][id]`],expected[i]);checks++;}
  assert.equal(posts[0]['options[5][name]'],'New choice');assert.deepEqual(errors,[]);checks+=2;await page.close();
 }
 console.log(`Group option reordering: ${checks} browser checks passed (mouse, touch, keyboard, IDs, save order and removal guards).`);
}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
