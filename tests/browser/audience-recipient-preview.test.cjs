const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixture = execFileSync('php', ['tests/browser/audience-campaign-checklist-fixture.php'], {cwd:root, encoding:'utf8'});

// Native form named-property clobbering cannot be reproduced by a plain object mock.
(async () => {
 const browser = await chromium.launch({headless:true});
 try {
  for (const explicitAction of [false,true]) {
   const page = await browser.newPage();
   const errors = [], requests = [];
   page.on('pageerror', error => errors.push(error.message));
   const endpoint = explicitAction ? '/audience.php?view=campaign&id=4' : '/audience.php?view=campaign&id=3';
   await page.route('http://preview.test/**', async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.pathname.startsWith('/assets/')) return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(path.join(root,'src',url.pathname))});
    if (request.method() === 'POST') {
     requests.push({url:url.pathname+url.search, body:request.postData()});
     if (url.pathname+url.search !== endpoint) return route.fulfill({status:404,body:'Wrong preview endpoint'});
     return route.fulfill({contentType:'application/json',body:JSON.stringify({count:4,people:[{id:1,first_name:'Fixture',last_name:'<recipient>',email:'fixture@example.test'}]})});
    }
    const html = explicitAction ? fixture.replace('<form method="post" class="campaign-section-form">', '<form method="post" action="'+endpoint+'" class="campaign-section-form">') : fixture;
    return route.fulfill({contentType:'text/html',body:html});
   });
   await page.goto('http://preview.test/audience.php?view=campaign&id=3');
   assert.equal(await page.evaluate(() => document.querySelector('form').action instanceof HTMLInputElement),true);
   await page.locator('.campaign-checklist-item > summary').first().click();
   await page.getByRole('button',{name:'Preview recipients'}).click();
   const dialog = page.getByRole('dialog',{name:'Preview recipients'});
   await dialog.getByRole('status').getByText('4 eligible email subscribers').waitFor();
   assert.equal(await dialog.getByRole('cell',{name:'fixture@example.test'}).isVisible(),true);
   assert.equal(await dialog.getByRole('cell',{name:'Fixture <recipient>'}).isVisible(),true);
   assert.equal(await dialog.locator('recipient').count(),0);
   assert.equal(await dialog.getByText('Showing the first 1 recipients, sorted by email address.').isVisible(),true);
   assert.equal(requests.length,1);
   assert.equal(requests[0].url,endpoint);
   assert.match(requests[0].body,/campaign_preview/);
   assert.match(requests[0].body,/segment:1/);
   assert.equal(await page.getByRole('button',{name:'Preview recipients'}).isEnabled(),true);
   assert.deepEqual(errors,[]);
   await page.close();
  }
  console.log('Recipient preview preserves current and explicit campaign endpoints with a hidden action field.');
 } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
