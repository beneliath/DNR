const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');

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
    if (url.pathname === '/audience.min.js') return route.fulfill({contentType:'text/javascript',body:fs.readFileSync(path.join(__dirname,'../../src/assets/js/audience.min.js'))});
    if (request.method() === 'POST') {
     requests.push({url:url.pathname+url.search, body:request.postData()});
     if (url.pathname+url.search !== endpoint) return route.fulfill({status:404,body:'Wrong preview endpoint'});
     return route.fulfill({contentType:'application/json',body:JSON.stringify({count:4})});
    }
    return route.fulfill({contentType:'text/html',body:`<!doctype html><form method="post" ${explicitAction?'action="'+endpoint+'"':''}>
     <input type="hidden" name="action" value="campaign_section"><input type="hidden" name="include[]" value="segment:1">
     <button type="button" data-campaign-recipient-preview>Preview recipients</button><p role="status" data-campaign-recipient-count></p></form>
     <script id="audience-config" type="application/json">{"url":"audience.php"}</script><script src="/audience.min.js"></script>`});
   });
   await page.goto('http://preview.test/audience.php?view=campaign&id=3');
   assert.equal(await page.evaluate(() => document.querySelector('form').action instanceof HTMLInputElement),true);
   await page.getByRole('button',{name:'Preview recipients'}).click();
   await page.getByRole('status').getByText('4 eligible email subscribers').waitFor();
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
