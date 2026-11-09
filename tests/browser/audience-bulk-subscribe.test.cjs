'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');

(async () => {
    const browser = await chromium.launch({headless: true});
    let cases = 0;
    try {
        for (const width of [390, 1600]) for (const dark of [false, true]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            const posts = [], errors = [];
            page.setDefaultTimeout(6000);
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(dark => localStorage.setItem('theme', dark ? 'dark' : 'light'), dark);
            await page.route('**/*', route => {
                const request = route.request(), url = new URL(request.url());
                if (url.hostname !== 'subscribe.test') return route.abort();
                if (url.pathname === '/audience.php') {
                    if (request.method() === 'POST') {
                        posts.push(new URLSearchParams(request.postData()));
                        return route.fulfill({contentType: 'text/html', body: '<p>Subscription submitted</p>'});
                    }
                    return route.fulfill({contentType: 'text/html', body: execFileSync('php', ['tests/browser/audience-counts-fixture.php', url.search.slice(1)], {cwd: root, encoding: 'utf8'})});
                }
                if (url.pathname === '/admin_unlock_status.php') return route.fulfill({contentType: 'application/json', body: '{"unlocked":false}'});
                const file = path.join(root, 'src', url.pathname);
                return fs.existsSync(file) && fs.statSync(file).isFile()
                    ? route.fulfill({body: fs.readFileSync(file), contentType: file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'image/svg+xml'})
                    : route.fulfill({status: 404, body: ''});
            });
            const go = () => page.goto('https://subscribe.test/audience.php?fixture_role=admin&status%5B0%5D=pending');
            const dialog = page.getByRole('dialog', {name: 'Update selected connections'});
            const action = dialog.getByLabel('Action', {exact: true});
            const permission = dialog.getByLabel('Permission record', {exact: true});
            const apply = dialog.getByRole('button', {name: 'Apply to selected connections'});
            for (const scope of ['selected', 'all']) {
                await go();
                for (const checkbox of await page.locator('[data-person-select]').all()) await checkbox.check();
                if (scope === 'all') await page.locator('[data-select-matches]').click();
                await page.locator('[data-open-dialog="bulk-people"]').click();
                assert.equal(await permission.isVisible(), false);
                assert.equal(await permission.evaluate(node => node.required), false);
                await action.selectOption('subscribe');
                assert.equal(await permission.isVisible(), true);
                assert.equal(await permission.evaluate(node => node.required), true);
                assert.equal(await permission.getAttribute('maxlength'), '1000');
                assert.equal(await dialog.locator('[data-bulk-tag]').isVisible(), false);
                assert.equal(await dialog.locator('[data-bulk-group]').isVisible(), false);
                await apply.click();
                assert.equal(posts.length, scope === 'selected' ? 0 : 1, 'Empty permission prevents submission');
                const note = 'Signed permission forms on November 9.\nNewsletter signup at the event.';
                await permission.fill(note);
                await action.selectOption('unsubscribe');
                assert.equal(await permission.isVisible(), false);
                assert.equal(await permission.evaluate(node => node.required), false);
                await action.selectOption('subscribe');
                assert.equal(await permission.inputValue(), note);
                const bounds = await dialog.boundingBox();
                assert(bounds.x >= 0 && bounds.x + bounds.width <= width, 'Bulk dialog fits the viewport');
                assert.equal(await page.evaluate(() => document.scrollingElement.scrollWidth > innerWidth), false);
                if (process.env.DNR_REVIEW_OUTPUT && scope === 'selected') {
                    fs.mkdirSync(process.env.DNR_REVIEW_OUTPUT, {recursive: true});
                    await page.screenshot({path: path.join(process.env.DNR_REVIEW_OUTPUT, `bulk-subscribe-${width}${dark ? '-dark' : ''}.png`)});
                }
                await apply.click();
                await page.getByText('Subscription submitted', {exact: true}).waitFor();
                const post = posts.at(-1);
                assert.equal(post.get('action'), 'bulk');
                assert.equal(post.get('operation'), 'subscribe');
                assert.equal(post.get('consent_note'), note.replaceAll('\n', '\r\n'));
                assert.equal(post.get('selection_scope'), scope);
                assert.deepEqual(post.getAll('selected[]'), scope === 'selected' ? ['20', '21'] : []);
                assert.deepEqual(post.getAll('status[0]'), ['pending']);
                assert.equal(post.get('csrf_token'), 'fixture-token');
                cases++;
            }
            await page.goto('https://subscribe.test/audience.php?fixture_role=reviewer');
            assert.equal(await page.locator('[data-open-dialog="bulk-people"]').count(), 0);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log(`Bulk Subscribe browser passed (${cases} selection, theme and viewport cases).`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
