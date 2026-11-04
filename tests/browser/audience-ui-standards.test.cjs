'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),{execFileSync}=require('node:child_process');
const {chromium}=require(process.env.DNR_PLAYWRIGHT_MODULE||'playwright');
const root=path.resolve(__dirname,'../..');
function nav(file,match,archived,occurrence=0){
 const source=fs.readFileSync(path.join(root,'src/templates/audience',file),'utf8');
 const snippets=[...source.matchAll(/<nav\b[\s\S]*?<\/nav>/g)].map(m=>m[0]).filter(s=>s.includes(match));
 assert(snippets[occurrence],file+' contains its actual navigation component');
 const prelude=`<?php $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$url=static fn($p=[])=>'audience.php?'.http_build_query($p);$campaignUrl=$listUrl=$managerUrl=$url;$campaignFilters=['trash'=>'${archived?'1':'0'}'];$trash=$planArchived=$touchpointArchived=$templateArchived=$classic=${archived?'true':'false'};$listQuery=[];$templates=[1];$archivedTemplates=[1,2];$tab='${archived?'archived':'saved'}';?>`;
 return execFileSync('php',{input:prelude+snippets[occurrence],encoding:'utf8'});
}
const styles=['audience','audience-flows','audience-inbox','audience-designer','audience-campaign-list','audience-ui-standards','audience-mobile'];
function html(body,dark){return '<!doctype html><html'+(dark?' class="dark-mode"':'')+'><head>'+styles.map(name=>`<link rel="stylesheet" href="/assets/css/pages/${name}.min.css">`).join('')+'</head><body class="audience-app" data-mm-workspace="workspace">'+body+'</body></html>';}
const profile=execFileSync('php',['tests/browser/audience-tag-actions-fixture.php','admin','active','locked','2','','','contact'],{cwd:root,encoding:'utf8'});
const profileControls=['<dialog id="person-action"','<dialog id="admin-unlock-dialog"','<details class="audience-menu primary"'].map(start=>{
 const tag=start.startsWith('<dialog')?'dialog':'details';
 const from=profile.indexOf(start), to=profile.indexOf('</'+tag+'>',from);
 assert(from>=0&&to>from,'Profile fixture renders '+start);
 return profile.slice(from,to+tag.length+3);
}).join('');
(async()=>{const browser=await chromium.launch({headless:true});let checks=0;
try{for(const dark of [false,true]){
 const page=await browser.newPage({viewport:{width:1500,height:1000}});let markup='';
 await page.route('http://standards.test/**',route=>{const u=new URL(route.request().url());if(u.pathname.startsWith('/assets/')){const file=path.join(root,'src',u.pathname);return route.fulfill({body:fs.readFileSync(file),contentType:'text/css'});}return route.fulfill({contentType:'text/html',body:html(markup,dark)});});
 for(const archived of [false,true])for(const [file,match,occurrence]of[
  ['campaign-list-toolbar.php','campaign-archive-tabs',0],['flow-overview.php','flow-archive-tabs',0],['inbox.php','inbox-template-tabs',0],['collection.php','aria-label="Form views"',0],['campaign-manager.php','campaign-plan-lifecycle-tabs',0],['campaign-manager.php','campaign-plan-lifecycle-tabs',1]
 ]){
  markup=nav(file,match,archived,occurrence);await page.goto('http://standards.test/');
  const current=page.locator('nav [aria-current=page]');assert.equal(await current.count(),1,file);
  assert((await current.textContent()).trim().startsWith(archived?'Archive':'Active'));
  const style=await current.evaluate(el=>{const s=getComputedStyle(el);return {background:s.backgroundColor,weight:Number(s.fontWeight),border:s.borderBottomStyle,width:parseFloat(s.borderBottomWidth)};});
  assert.equal(style.background,'rgba(0, 0, 0, 0)',file);assert(style.weight>=600,file);assert.equal(style.border,'solid');assert(style.width>=2,file);checks+=6;
 }
 for(const classic of [false,true]){
  markup=nav('flow-overview.php','flow-overview-tabs',classic);await page.goto('http://standards.test/');
  const selected=page.locator('[aria-current=page]');assert.equal(await selected.evaluate(el=>getComputedStyle(el).backgroundColor),'rgba(0, 0, 0, 0)');assert(Number(await selected.evaluate(el=>getComputedStyle(el).fontWeight))>=600);checks+=2;
 }
 const groups=['flow-overview-row-actions','flow-report-actions','collection-row-actions','template-card-actions','audience-tag-actions','audience-segment-actions','group-toolbar','audience-actions audience-field-actions','inbox-label-actions'];
 markup=groups.map(name=>`<div class="${name}"><a class="action-icon-button">A</a><a class="action-icon-button">B</a></div>`).join('');await page.goto('http://standards.test/');
 for(const name of groups){assert.equal(await page.locator('.'+name.split(' ').at(-1)).evaluate(el=>getComputedStyle(el).columnGap),'16px',name);checks++;}
 const php=`<?php require '${root}/src/functions.php';require '${root}/src/audience_store.php';require_once '${root}/src/audience_flow_presentation.php';$templates=audienceFlowTemplates();foreach($templates as$key=>$template)if(str_contains($template['name'],'Archive unsubscribed')){audienceFlowTemplateCard($key,$template,static fn($p)=>'audience.php',true);break;}`;
 markup=execFileSync('php',{input:php,encoding:'utf8'});assert(markup.includes('Recommended'));await page.goto('http://standards.test/');
 await page.locator('.flow-template-card').hover();await page.waitForTimeout(180);assert.equal(await page.locator('.flow-card-badge').evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(255, 255, 255)');checks++;
 markup=profileControls;
 for(const width of [1440,320]){
  await page.setViewportSize({width,height:900});
  await page.goto('http://standards.test/');
  const dialog=page.locator('#person-action');
  await dialog.evaluate(el=>el.showModal());
  const confirm=dialog.locator('[data-person-action-submit]'),cancel=dialog.getByRole('button',{name:'Cancel'});
  const menuItem=page.locator('.audience-menu>div :is(a,button):not(:disabled)').first();
  const font=locator=>locator.evaluate(el=>getComputedStyle(el).fontSize);
  const titleSize=await font(dialog.locator('h2'));
  const controlSize=await font(confirm);
  assert.equal(await font(cancel),controlSize);
  assert.equal(controlSize,width===320?'16px':'14.8px');
  const left=await confirm.boundingBox(),right=await cancel.boundingBox();
  assert.equal(right.x-left.x-left.width,width===320?8:16);
  const hoverColor=dark?'rgb(19, 78, 74)':'rgb(204, 251, 241)';
  const background=locator=>locator.evaluate(el=>getComputedStyle(el).backgroundColor);
  await cancel.hover();
  assert.equal(await background(cancel),hoverColor);
  await page.mouse.move(0,0);
  await confirm.focus();
  await page.keyboard.press('Tab');
  assert(await cancel.evaluate(el=>el.matches(':focus-visible')));
  assert.equal(await background(cancel),hoverColor);
  await dialog.evaluate(el=>el.close());
  const unlock=page.locator('#admin-unlock-dialog');
  await unlock.evaluate(el=>el.showModal());
  assert.equal(await font(unlock.locator('h2')),titleSize);
  await unlock.evaluate(el=>el.close());
  await page.locator('.audience-menu>summary').click();
  assert.equal(await font(menuItem),controlSize);
  await menuItem.hover();
  assert.equal(await background(menuItem),hoverColor);
  checks+=9;
 }
 await page.close();
}console.log('Shared MessageMonkey typography, spacing, tabs and hover/focus states: '+checks+' checks passed.');}finally{await browser.close();}})().catch(error=>{console.error(error);process.exitCode=1;});
