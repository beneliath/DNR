'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixture = (editing, version = 'version-1') => execFileSync('php',
    [path.join(__dirname, 'inquiry-draft-fixture.php'), editing ? 'edit' : 'add', version], {encoding: 'utf8'});
const html = {add: fixture(false), edit: fixture(true), stale: fixture(true, 'version-2')};

(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [390, 1440]) {
            for (const dark of [false, true]) {
                const page = await browser.newPage({viewport: {width, height: 950}});
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('https://inquiry-drafts.test/**', route => {
                    const url = new URL(route.request().url());
                    if (url.pathname.endsWith('inquiry_relationship_search.php')) {
                        const id = url.searchParams.get('selected_id');
                        return route.fulfill({json: {results: [], has_more: false,
                            selected: id ? {id, label: 'Organization ' + id} : null}});
                    }
                    if (url.pathname.endsWith('add_organization.php')) return route.fulfill({body: 'Related record fixture'});
                    if (url.pathname.endsWith('inquiry.php')) return route.fulfill({contentType: 'text/html',
                        body: url.searchParams.has('stale') ? html.stale : html[url.pathname.includes('edit_') ? 'edit' : 'add']});
                    const asset = path.join(root, 'src', url.pathname);
                    return url.pathname.startsWith('/assets/') && fs.existsSync(asset) ? route.fulfill({path: asset}) : route.abort();
                });
                const gold = async locator => {
                    const style = await locator.evaluate(el => ({width: getComputedStyle(el).outlineWidth, color: getComputedStyle(el).outlineColor}));
                    assert.equal(style.width, '2px');
                    assert.equal(style.color, dark ? 'rgb(245, 189, 104)' : 'rgb(154, 91, 5)');
                };
                for (const editing of [false, true]) {
                    const url = 'https://inquiry-drafts.test/' + (editing ? 'edit_inquiry.php?id=7' : 'add_inquiry.php');
                    await page.goto(url);
                    await page.evaluate(dark => document.documentElement.classList.toggle('dark-mode', dark), dark);
                    const panel = page.locator('.form-draft-panel');
                    assert.equal(await panel.evaluate(el => el.tagName), 'DETAILS');
                    assert.equal(await panel.getAttribute('open'), null);
                    assert.equal(await page.getByRole('button', {name: 'Save Draft', exact: true}).isVisible(), false);
                    await page.locator('#inquiry-title').fill('Draft inquiry');
                    await page.locator('#inquiry-summary').fill('Saved draft summary');
                    await panel.locator('summary').click();
                    await page.getByRole('button', {name: 'Save Draft', exact: true}).click();
                    await page.goto(url + (editing ? '&stale=1' : ''));
                    await page.evaluate(dark => document.documentElement.classList.toggle('dark-mode', dark), dark);
                    assert.equal(await panel.getAttribute('open'), null);
                    assert.equal(await page.locator('#inquiry-title').inputValue(), 'Current inquiry');
                    await panel.locator('summary').click();
                    if (editing) {
                        await gold(page.locator('[data-field="Saved Draft"]').first());
                        await page.getByRole('button', {name: 'Restore Inquiry Title', exact: true}).click();
                        assert.equal(await page.locator('#inquiry-summary').inputValue(), 'Current summary');
                    } else {
                        await page.getByRole('button', {name: 'Restore Draft', exact: true}).click();
                        await page.waitForFunction(() => document.querySelector('.form-draft-panel [role=status]').textContent.startsWith('Draft restored.'));
                        await gold(page.locator('#inquiry-summary'));
                    }
                    await gold(page.locator('#inquiry-title'));
                    assert.equal(await page.locator('#inquiry-organization').getAttribute('data-draft-restored'), null);
                    await page.locator('#inquiry-title').fill('Further edit');
                    assert.equal(await page.locator('#inquiry-title').getAttribute('data-draft-restored'), null);
                    await page.getByRole('button', {name: 'Discard Draft', exact: true}).click();
                }
                await page.goto('https://inquiry-drafts.test/add_inquiry.php');
                await page.locator('#inquiry-title').fill('Returned inquiry draft');
                await page.locator('#inquiry-organization').selectOption('2');
                await page.locator('[data-inquiry-create=organization]').click();
                await page.goto('https://inquiry-drafts.test/add_inquiry.php?created_organization_id=3');
                await page.evaluate(dark => document.documentElement.classList.toggle('dark-mode', dark), dark);
                await page.waitForFunction(() => document.querySelector('#inquiry-title').dataset.draftRestored !== undefined);
                assert.equal(await page.locator('#inquiry-title').inputValue(), 'Returned inquiry draft');
                await gold(page.locator('#inquiry-title'));
                assert.equal(await page.locator('#inquiry-organization').inputValue(), '3');
                assert.equal(await page.locator('#inquiry-organization').getAttribute('data-draft-restored'), null,
                    'The newly created organization is not labeled as restored draft data');
                assert.deepEqual(errors, []);
                await page.close();
            }
        }
        console.log('Inquiry draft recovery passed on desktop/mobile in light/dark themes.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
