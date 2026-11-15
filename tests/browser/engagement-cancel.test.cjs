'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const html = execFileSync('php', [path.join(__dirname, 'engagement-cancel-fixture.php')], {encoding: 'utf8'});

(async () => {
    const browser = await chromium.launch({headless: true, executablePath: process.env.DNR_CHROMIUM_EXECUTABLE_PATH});
    try {
        const page = await browser.newPage();
        const errors = [], posts = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('https://engagement.test/**', route => {
            const request = route.request(), url = new URL(request.url());
            if (request.method() === 'POST') {
                posts.push(new URLSearchParams(request.postData()));
                return route.fulfill({contentType: 'text/html', body: 'Submitted fixture'});
            }
            if (url.pathname === '/engagements.php') return route.fulfill({contentType: 'text/html', body: html});
            const file = path.join(root, 'src', url.pathname);
            return fs.existsSync(file) ? route.fulfill({path: file}) : route.fulfill({status: 404, body: ''});
        });
        for (const width of [1280, 390]) {
            await page.setViewportSize({width, height: 844});
            await page.goto('https://engagement.test/engagements.php');
            for (const dark of [false, true]) {
                await page.evaluate(value => document.documentElement.classList.toggle('dark-mode', value), dark);
                const rows = await page.locator('.action-buttons').evaluateAll(elements => elements.map(row =>
                    Array.from(row.querySelectorAll('.action-icon-button')).map(button => {
                        const box = button.getBoundingClientRect();
                        return {action: button.querySelector('svg').dataset.actionIcon, x: box.x, right: box.right, y: box.y};
                    })));
                for (const row of rows) {
                    for (let i = 1; i < row.length; i++) assert.ok(row[i].x >= row[i - 1].right, 'Action hit areas never overlap');
                    assert.ok(row.every(item => item.y === row[0].y), 'Actions stay on one row');
                }
                for (const action of ['view', 'edit', 'archive', 'delete']) {
                    assert.ok(rows.every(row => row.find(item => item.action === action).x === rows[0].find(item => item.action === action).x), 'Missing cancellation leaves the other columns aligned');
                }
                const bolt = page.locator('[data-action-icon=cancel]').first();
                assert.equal(await bolt.locator('path').count(), 1, 'Cancellation uses one lightning bolt');
                assert.equal(await bolt.evaluate(icon => getComputedStyle(icon).fill), 'none', 'Lightning bolt is outlined');
                assert.equal(await bolt.evaluate(icon => getComputedStyle(icon).strokeWidth), '1.8px');
            }
            const opener = page.locator('[data-cancel-engagement="11"]');
            const dialog = page.locator('#engagement-cancel-dialog');
            await opener.click();
            await dialog.waitFor({state: 'visible'});
            assert.equal(await page.locator('#engagement-cancel-name').textContent(), 'Example engagement 11');
            assert.ok(await dialog.evaluate(element => element.getBoundingClientRect().width <= innerWidth), 'Dialog fits the viewport');
            const phrase = page.locator('#engagement-cancel-confirmation');
            const confirm = page.locator('[data-confirm-engagement-cancel]');
            for (const value of ['cancel', 'CANCEL ', '']) {
                await phrase.fill(value);
                assert.equal(await confirm.isDisabled(), true, 'Only exact CANCEL enables submission');
            }
            await phrase.fill('CANCEL');
            await confirm.click();
            assert.equal(posts.length, 0, 'An empty required reason prevents submission');
            await page.locator('#engagement-cancel-reason').fill('Fixture cancellation reason.');
            await page.getByRole('button', {name: 'Keep Engagement', exact: true}).click();
            await dialog.waitFor({state: 'hidden'});
            assert.equal(await opener.evaluate(button => document.activeElement === button), true, 'Dismissal restores focus');
            await opener.click();
            assert.equal(await phrase.inputValue(), '', 'Reopening requires fresh confirmation');
            assert.equal(await page.locator('#engagement-cancel-reason').inputValue(), '');
            await page.keyboard.press('Escape');
            await dialog.waitFor({state: 'hidden'});
            assert.equal(posts.length, 0, 'Dismissal never submits cancellation');
        }
        await page.setViewportSize({width: 1000, height: 600});
        await page.locator('.engagement-table').screenshot({path: '/tmp/dnr-engagement-cancel-actions.png'});
        await page.locator('[data-cancel-engagement="13"]').click();
        await page.locator('#engagement-cancel-reason').fill('Host requested cancellation.');
        await page.locator('#engagement-cancel-confirmation').fill('CANCEL');
        await page.locator('#engagement-cancel-dialog').screenshot({path: '/tmp/dnr-engagement-cancel-dialog.png'});
        await page.locator('[data-confirm-engagement-cancel]').click();
        await page.waitForURL(url => url.pathname === '/engagements.php' && posts.length === 1);
        assert.equal(posts[0].get('engagement_id'), '13');
        assert.equal(posts[0].get('expected_version'), '2026-11-15 13:00:00.123456');
        assert.equal(posts[0].get('cancel_confirmation'), 'CANCEL');
        assert.equal(posts[0].get('cancellation_reason'), 'Host requested cancellation.');
        assert.deepEqual(errors, []);
        console.log('Engagement cancellation browser checks passed: aligned actions, single outlined bolt, light/dark/mobile layouts, exact confirmation, required reason, dismissal and correct submitted engagement.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
