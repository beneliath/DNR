'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const {expandRowActions} = require('./helpers/responsive-workspace.cjs');
const root = path.resolve(__dirname, '../..');

function fixture(view) {
    if (view === 'segments') {
        return execFileSync('php', ['-r',
            '$definitionArchiveAvailable=true;require "tests/browser/audience-segment-actions-fixture.php";', 'admin'],
        {cwd: root, encoding: 'utf8'});
    }
    return execFileSync('php', ['tests/browser/audience-tag-actions-fixture.php', 'admin', 'active', 'locked', '2', '', '', view],
        {cwd: root, encoding: 'utf8'});
}

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        for (const width of [390, 1440]) {
            for (const [view, trigger, dialogId, field] of [
                ['tags', '.audience-tag-actions [data-tag-edit]', 'tag-editor', 'name'],
                ['groups', '.audience-group-grid [data-group-edit]', 'group-editor', 'name'],
                ['fields', '.audience-field-actions [data-field-edit]', 'field-editor', 'label'],
                ['contact', '[data-open-dialog="edit-details"]', 'edit-details', 'first_name'],
                ['segments', '[data-segment-edit]', 'segment-builder', 'name']
            ]) {
                const page = await browser.newPage({viewport: {width, height: 1000}});
                page.setDefaultTimeout(8000);
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.route('https://audience-reminders.test/**', route => {
                    const url = new URL(route.request().url());
                    if (url.pathname === '/audience.php') return route.fulfill({contentType: 'text/html', body: fixture(view)});
                    if (url.pathname === '/admin_unlock_status.php') return route.fulfill({contentType: 'application/json', body: '{"unlocked":false}'});
                    const file = path.join(root, 'src', url.pathname);
                    return fs.existsSync(file) ? route.fulfill({path: file}) : route.fulfill({status: 404, body: ''});
                });
                await page.goto('https://audience-reminders.test/audience.php?view=' + view);
                await expandRowActions(page.locator('.audience-tag-actions, .audience-field-actions, .audience-segment-actions').first());
                const dialog = page.locator('#' + dialogId);
                const save = dialog.locator('[data-pending-reminder]');
                async function pending(expected) {
                    await page.waitForFunction(({dialogId, expected}) => document.querySelector('#' + dialogId + ' [data-pending-reminder]').classList.contains('form-submit-pending') === expected, {dialogId, expected});
                }
                const triggers = page.locator(trigger);
                const models = view === 'tags' ? Math.min(await triggers.count(), 2) : 1;
                for (let model = 0; model < models; model++) {
                    await expandRowActions(page.locator('.audience-tag-actions').nth(model));
                    await triggers.nth(model).click();
                    await dialog.waitFor({state: 'visible'});
                    await pending(false);
                    const input = dialog.locator('[name="' + field + '"]'), original = await input.inputValue();
                    await input.fill(original + ' edited'); await pending(true);
                    assert.equal(await save.evaluate(button => getComputedStyle(button).outlineWidth), '3px');
                    await input.fill(original); await pending(false);
                    if (view === 'groups') {
                        const handle = await dialog.locator('[data-group-drag]').nth(1).elementHandle();
                        await handle.focus(); await handle.press('ArrowUp'); await pending(true);
                        await handle.press('ArrowDown'); await pending(false);
                    }
                    if (view === 'segments') {
                        const match = dialog.locator('[data-rule-focus="root-match"]');
                        await match.selectOption('any'); await pending(true);
                        await match.selectOption('all'); await pending(false);
                    }
                    await dialog.getByRole('button', {name: 'Cancel', exact: true}).click();
                }
                assert.deepEqual(errors, []);
                await page.close();
            }
        }
        console.log('MessageMonkey reminder checks passed for connection details, reused tag editors, group choices/reordering, custom fields, and segment rules at desktop and phone widths.');
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
