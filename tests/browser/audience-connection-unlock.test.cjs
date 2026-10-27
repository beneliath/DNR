'use strict';
// Real profile template and scripts, synthetic sessions and intercepted writes.
const assert = require('node:assert/strict');
const fs = require('node:fs'), path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixture = role => execFileSync('php', ['tests/browser/audience-tag-actions-fixture.php', role, 'active', 'locked', '2', '', '', 'contact'], {cwd: root, encoding: 'utf8'});
(async () => {
    const browser = await chromium.launch({headless: true}); let checks = 0;
    try {
        for (const dark of [false, true]) for (const width of [390, 1600]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            page.setDefaultTimeout(7000);
            const posts = [], errors = []; let unlocked = false;
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(dark => localStorage.setItem('theme', dark ? 'dark' : 'light'), dark);
            await page.route('**/*', route => {
                const request = route.request(), url = new URL(request.url());
                if (url.origin !== 'https://connection.test') return route.abort();
                if (['/admin_elevation.php', '/admin_unlock_status.php'].includes(url.pathname)) {
                    if (request.method() === 'POST') unlocked = true;
                    return route.fulfill({contentType: 'application/json', body: JSON.stringify({unlocked, csrf_token: unlocked ? 'rotated-token' : 'fixture-token', server_now: Date.now()/1000, expires_at: unlocked ? Date.now()/1000 + 300 : null})});
                }
                if (url.pathname === '/audience.php') {
                    if (request.method() === 'POST') {
                        posts.push(Object.fromEntries(new URLSearchParams(request.postData())));
                        return route.fulfill({contentType: 'text/html', body: '<p>Deletion submitted</p>'});
                    }
                    return route.fulfill({contentType: 'text/html', body: fixture(url.searchParams.get('role') || 'admin')});
                }
                const file = path.join(root, 'src', url.pathname);
                return fs.existsSync(file) && fs.statSync(file).isFile()
                    ? route.fulfill({body: fs.readFileSync(file), contentType: file.endsWith('.js') ? 'text/javascript' : file.endsWith('.css') ? 'text/css' : 'image/svg+xml'})
                    : route.fulfill({status: 404, body: ''});
            });
            const go = () => page.goto('https://connection.test/audience.php?view=contact&id=7');
            const open = async () => { await page.locator('.audience-menu summary').click(); await page.locator('[data-person-operation="delete"]').click(); };
            const unlock = page.locator('#admin-unlock-dialog'), confirm = page.locator('#person-action');
            const enter = async () => {
                await unlock.waitFor({state: 'visible'});
                await page.waitForFunction(() => { const button = document.querySelector('#admin-unlock-dialog [data-unlock-submit]'); return button && !button.disabled && !button.hasAttribute('aria-busy'); });
                await unlock.locator('[name=admin_password]').fill('fixture-password');
                await unlock.locator('[name=admin_code]').fill('123456');
                await unlock.locator('[data-unlock-submit]').click();
            };
            await go(); await open(); await unlock.waitFor({state: 'visible'});
            assert.equal(await confirm.isVisible(), false); await unlock.locator('[data-unlock-cancel]').click();
            assert.equal(posts.length, 0); checks += 2;
            await open(); await enter(); await confirm.waitFor({state: 'visible'});
            assert.equal(posts.length, 0); await confirm.locator('#person-delete-word').fill('DELETE');
            // Simulate expiry while the destructive confirmation is still open.
            unlocked = false; await confirm.locator('[data-person-action-submit]').click();
            await unlock.waitFor({state: 'visible'}); await unlock.locator('[data-unlock-cancel]').click();
            assert.equal(posts.length, 0); checks += 2;
            await confirm.locator('[data-person-action-submit]').click(); await enter();
            await page.getByText('Deletion submitted', {exact: true}).waitFor();
            assert.equal(posts.length, 1); assert.equal(posts[0].action, 'person_action');
            assert.equal(posts[0].operation, 'delete'); assert.equal(posts[0].confirm_text, 'DELETE');
            assert.equal(posts[0].csrf_token, 'rotated-token'); checks += 5;
            for (const role of ['editor', 'reviewer']) {
                await page.goto('https://connection.test/audience.php?view=contact&id=7&role=' + role);
                assert.equal(await page.locator('[data-person-operation="delete"]').count(), 0); checks++;
            }
            assert.deepEqual(errors, []); checks++;
            await page.close();
        }
        console.log(`Connection deletion browser: ${checks} checks passed.`);
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
