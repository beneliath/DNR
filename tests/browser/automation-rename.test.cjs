'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const {expandRowActions} = require('./helpers/responsive-workspace.cjs');

const root = path.resolve(__dirname, '../..');
const html = process.env.DNR_FLOW_RENAME_FIXTURE
    ? fs.readFileSync(process.env.DNR_FLOW_RENAME_FIXTURE, 'utf8')
    : execFileSync('php', ['tests/browser/audience-mobile-flow-fixture.php'], {cwd:root, encoding:'utf8'});
const list = 'http://automations.test/audience.php?view=automations&tab=flows&sort=updated';

(async () => {
    for (const engine of (process.env.DNR_BROWSER_ENGINES || 'chromium').split(',')) {
        const browser = await engines[engine].launch({timeout:30000});
        try {
            for (const width of [390, 1440]) {
                const page = await browser.newPage({viewport:{width, height:900}});
                page.setDefaultTimeout(8000);
                const errors = [], posts = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('http://automations.test/**', route => {
                    const request = route.request(), url = new URL(request.url());
                    if (request.method() === 'POST') {
                        posts.push({url:request.url(), values:Object.fromEntries(new URLSearchParams(request.postData()))});
                        return route.fulfill({contentType:'text/html', body:'Saved'});
                    }
                    if (url.pathname === '/audience.php') return route.fulfill({contentType:'text/html', body:html});
                    const file = path.join(root, 'src', url.pathname);
                    if (!fs.existsSync(file)) return route.fulfill({status:404, body:''});
                    return route.fulfill({path:file});
                });
                await page.goto(list);
                const rows = page.locator('.flow-overview-row');
                const dialog = page.getByRole('dialog', {name:'Rename automation'});
                const name = dialog.getByLabel('Flow Name');
                for (const row of await rows.all()) {
                    await expandRowActions(row.locator('.flow-overview-row-actions'));
                    const original = await row.locator('h2').innerText();
                    await row.getByRole('button', {name:'Rename '+original, exact:true}).click();
                    assert.equal(await name.inputValue(), original);
                    assert.ok(await name.evaluate(el => document.activeElement === el));
                    await name.fill('Unsubmitted name');
                    await dialog.getByRole('button', {name:'Cancel'}).click();
                    assert.equal(await row.locator('h2').innerText(), original);
                }
                assert.deepEqual(posts, []);
                await rows.nth(1).getByRole('button', {name:'Rename Community follow-up'}).click();
                assert.equal(await name.inputValue(), 'Community follow-up');
                await name.fill('Updated outreach');
                await dialog.getByRole('button', {name:'Save name'}).click();
                await page.getByText('Saved', {exact:true}).waitFor();
                assert.deepEqual(posts, [{url:list, values:{
                    csrf_token:'fixture-token', action:'flow_operation', operation:'rename',
                    id:'2', version:'1', name:'Updated outreach',
                }}]);
                assert.deepEqual(errors, []);
                await page.close();
            }
            console.log(engine+': desktop and mobile automation rename checks passed.');
        } finally {
            await browser.close();
        }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
