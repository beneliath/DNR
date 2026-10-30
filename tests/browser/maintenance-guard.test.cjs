'use strict';

// Started by deployment_guard_integration_test.py against its real HTTP stack.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium, webkit} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const directory = process.env.DNR_GUARD_FIXTURE, origin = process.env.DNR_GUARD_ORIGIN;
if (!directory || !origin) throw new Error('Requires the isolated guard fixture');

(async () => {
    const browsers = [], pages = [];
    try {
        for (const [engine, account, viewport] of [
            [chromium, 'primary-account', {width: 1440, height: 1000}],
            [webkit, 'member-account', {width: 390, height: 844}],
        ]) {
            const browser = await engine.launch({headless: true});
            browsers.push(browser);
            const page = await browser.newPage({viewport});
            const errors = [], documents = [];
            page.on('pageerror', error => errors.push(error.message));
            page.on('response', response => {
                if (response.request().isNavigationRequest()) documents.push(response.status());
            });
            await page.addInitScript(() => {
                const schedule = window.setInterval.bind(window);
                window.setInterval = (callback, delay) => schedule(callback, delay === 30000 ? 300 : delay);
            });
            const target = origin + '/a/' + account + '/audience_collect_public.php?key=synthetic#form';
            assert.equal((await page.goto(target)).status(), 503);
            await page.locator('.timer-expired').waitFor();
            assert.equal(await page.locator('.timer-expired').evaluate(node => getComputedStyle(node).color), 'rgb(255, 103, 103)');
            pages.push({page, errors, documents, target});
        }
        fs.writeFileSync(path.join(directory, 'browser-ready'), 'ready');
        const deadline = Date.now() + 60000;
        while (!fs.existsSync(path.join(directory, 'ingress-removed'))) {
            if (Date.now() > deadline) throw new Error('Fixture did not remove ingress');
            await new Promise(resolve => setTimeout(resolve, 100));
        }
        for (const {page} of pages) {
            assert.equal((await page.reload()).status(), 503);
            await page.locator('.timer-expired').waitFor();
        }
        fs.writeFileSync(path.join(directory, 'browser-refreshed'), 'ready');
        await Promise.all(pages.map(async ({page, errors, documents, target}) => {
            await page.getByRole('heading', {name: 'Requested content'}).waitFor({timeout: 90000});
            assert.equal(page.url(), target.split('#')[0]);
            assert.deepEqual(documents, [503, 503, 200]);
            assert.deepEqual(errors, []);
        }));
        console.log('PASS: Chromium and iPhone WebKit refreshed to maintenance with ingress absent, then automatically recovered to the original content after expiry.');
    } finally {
        await Promise.all(browsers.map(browser => browser.close()));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
