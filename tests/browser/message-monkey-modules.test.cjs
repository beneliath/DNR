'use strict';

// Exercise feature boundaries through the production bundle and real templates.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        for (const kind of ['tag', 'segment']) {
            const page = await browser.newPage({viewport: {width: 1600, height: 1000}});
            page.setDefaultTimeout(5000);
            const errors = [], previews = [];
            page.on('pageerror', error => errors.push(error.message));
            const markup = execFileSync('php', [`tests/browser/audience-${kind}-actions-fixture.php`, 'admin'], {cwd: root, encoding: 'utf8'});
            await page.addInitScript(() => {
                window.submissions = [];
                document.addEventListener('submit', event => {
                    if (event.defaultPrevented || event.target.method === 'dialog') return;
                    event.preventDefault();
                    window.submissions.push(Object.fromEntries(new FormData(event.target)));
                });
            });
            await page.route('http://modules.test/**', route => {
                const request = route.request(), url = new URL(request.url());
                if (url.pathname.startsWith('/assets/')) {
                    const file = path.join(root, 'src', url.pathname);
                    return route.fulfill({body: fs.readFileSync(file), contentType:
                        {'.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2'}[path.extname(file)] || 'application/octet-stream'});
                }
                if (url.pathname === '/admin_unlock_status.php') return route.fulfill({contentType: 'application/json', body: '{"unlocked":false}'});
                if (request.method() === 'POST') {
                    previews.push(request.postData());
                    return route.fulfill({contentType: 'application/json', body: JSON.stringify({count: 1, people: [{id: 17, email: 'fixture@example.test'}]})});
                }
                return route.fulfill({contentType: 'text/html', body: markup});
            });
            await page.goto(`http://modules.test/audience.php?view=${kind}s`);
            await page.evaluate(() => {
                window.unlockAllowed = false;
                window.unlockCalls = 0;
                window.DnrAdminUnlock = {request: async () => {
                    window.unlockCalls++;
                    if (window.unlockAllowed) {
                        document.querySelectorAll('[name=csrf_token]').forEach(node => { node.value = 'rotated-token'; });
                        document.dispatchEvent(new CustomEvent('admin-unlock-changed', {detail: {csrf_token: 'rotated-token'}}));
                    }
                    return window.unlockAllowed;
                }};
            });

            const renameSelector = kind === 'tag' ? '[data-tag-edit]' : '[data-segment-rename]';
            const renameDialog = page.locator(kind === 'tag' ? '#tag-editor' : '#rename-segment');
            await page.locator(`.audience-${kind}-actions ${renameSelector}`).first().click();
            await renameDialog.waitFor({state: 'visible'});
            assert.equal(await renameDialog.locator('[name=id]').inputValue(), '7');
            assert.equal(await renameDialog.locator('[name=version]').inputValue(), '3');
            assert.equal(await renameDialog.locator('[name=name]').inputValue(), 'Newsletter subscribers');
            await renameDialog.getByRole('button', {name: 'Cancel', exact: true}).click();

            if (kind === 'segment') {
                const builder = page.locator('#segment-builder');
                await page.locator('.audience-segment-actions [data-segment-edit]').first().click();
                await builder.waitFor({state: 'visible'});
                await builder.locator('[data-segment-preview]').click();
                await builder.getByRole('heading', {name: '1 matching connections'}).waitFor();
                assert.match(await builder.getByRole('link', {name: 'fixture@example.test'}).getAttribute('href'), /view=contact&id=17/);
                assert.equal(previews.length, 1);
                assert.match(previews[0], /preview_segment/);
                assert.match(previews[0], /email_status/);
                await builder.getByRole('button', {name: 'Cancel', exact: true}).click();
                await page.locator('.audience-segment-actions [data-segment-copy]').first().click();
                await builder.waitFor({state: 'visible'});
                assert.equal(await builder.locator('[name=id]').inputValue(), '0');
                assert.equal(await builder.locator('[name=name]').inputValue(), 'Newsletter subscribers (copy)');
                await builder.getByRole('button', {name: 'Cancel', exact: true}).click();
            }

            // Selection belongs to connections; permission/confirmation belongs to dialogs.
            await page.locator('[data-definition-item]').first().check();
            const selection = page.locator('#definition-selection');
            assert.equal(await selection.locator('[data-definition-count]').textContent(), '1 Selected');
            const confirm = page.getByRole('dialog', {name: 'Confirm action', exact: true});
            await selection.locator('button[type=submit]').click();
            await page.waitForFunction(() => window.unlockCalls === 1);
            assert.equal(await confirm.isVisible(), false);
            assert.equal(await page.evaluate(() => window.submissions.length), 0);
            await page.evaluate(() => { window.unlockAllowed = true; });
            await selection.locator('button[type=submit]').click();
            await confirm.waitFor({state: 'visible'});
            await confirm.getByRole('button', {name: 'Cancel', exact: true}).click();
            assert.equal(await page.evaluate(() => window.submissions.length), 0);
            await selection.locator('button[type=submit]').click();
            await confirm.waitFor({state: 'visible'});
            await confirm.getByRole('button', {name: 'Continue', exact: true}).click();
            await page.waitForFunction(() => window.submissions.length === 1);
            const submission = await page.evaluate(() => window.submissions[0]);
            assert.equal(submission.action, 'delete_selected_definitions');
            assert.equal(submission.kind, `${kind}s`);
            assert.equal(submission['selected_ids[]'], '7');
            assert.equal(submission['versions[7]'], '3');
            assert.equal(submission.csrf_token, 'rotated-token');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('MessageMonkey module boundaries passed: rename, segment edit/copy/preview, selection, unlock, cancellation, confirmation and CSRF rotation.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
