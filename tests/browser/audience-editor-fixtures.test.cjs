'use strict';
// Run the standalone editor regressions through real browser engines, with all
// resource requests served from this checkout and external requests refused.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixtures = ['richtext', 'colors', 'designer-drop', 'image-upload'];
(async () => {
    for (const engine of (process.env.DNR_BROWSER_ENGINES || 'chromium,webkit').split(',')) {
        const browser = await engines[engine].launch({headless: true});
        try {
            for (const fixture of fixtures) {
                const page = await browser.newPage({viewport: {width: 1280, height: 1000}});
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('**/*', route => {
                    const url = new URL(route.request().url());
                    // WebKit routes locally generated object URLs; these contain
                    // uploaded pixels, not files to resolve against the checkout.
                    if (url.protocol === 'blob:') return route.continue();
                    const file = path.resolve(root, '.' + url.pathname);
                    if (url.origin !== 'http://editor.test' || !file.startsWith(root + path.sep) || !fs.existsSync(file)) return route.abort();
                    const contentType = {'.html':'text/html', '.js':'text/javascript', '.mjs':'text/javascript', '.css':'text/css', '.svg':'image/svg+xml'}[path.extname(file)] || 'application/octet-stream';
                    return route.fulfill({contentType, body: fs.readFileSync(file)});
                });
                await page.goto('http://editor.test/tests/browser/audience-' + fixture + '.html');
                await page.locator('#run').click();
                await page.waitForFunction(() => /^(PASS|FAIL)/.test(document.querySelector('#result').textContent), null, {timeout: 90000});
                const result = await page.locator('#result').textContent();
                assert.match(result, /^PASS:/, engine + ' ' + fixture + ': ' + result);
                assert.deepEqual(errors, [], engine + ' ' + fixture + ' browser errors');
                console.log(engine + ' ' + fixture + ': ' + result);
                await page.close();
            }
        } finally {
            await browser.close();
        }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
