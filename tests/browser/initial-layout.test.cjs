'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const delayed = new Set(['task-form.min.js', 'workflow-ui.min.js', 'record-workspace.min.js', 'inquiry-workflow.min.js', 'page-actions.min.js', 'form-reminders.min.js']);
const fixtures = [
    ['task', '.form-section, #task-selected-record, .engagement-page-actions'],
    ['task-linked', '.form-section, #task-selected-record, .engagement-page-actions'],
    ['task-duplicate', '.form-section, #task-selected-record, .engagement-page-actions'],
    ...['tasks', 'contacts', 'organizations', 'speakers', 'engagements', 'email_templates', 'reimbursement_cost_centers', 'audit_log'].map(page => ['list:' + page, '.list-search-form, .list-search-form button, table, tbody tr, tbody td, #after-content']),
    ['affiliations', '.contact-affiliation-row, .contact-affiliation-actions, [data-add-affiliation], #after-content'],
    ['inquiry', '.form-draft-panel, .form-section, .engagement-page-actions'],
    ...['draft', 'draft-chron'].flatMap(name => ['none', 'saved', 'stale', 'cleared'].map(state => [name, '.form-draft-panel, #after-content', state]))
].filter(([name]) => !process.env.DNR_LAYOUT_CASE || name === process.env.DNR_LAYOUT_CASE);

(async () => {
    const browser = await chromium.launch({headless: true});
    let checks = 0;
    try {
        for (const width of [390, 1440]) {
            for (const [name, selector, draftState = 'none'] of fixtures) {
                const html = execFileSync('php', ['tests/browser/initial-layout-fixture.php', name, draftState], {cwd: root, encoding: 'utf8'});
                const page = await browser.newPage({viewport: {width, height: 1000}});
                page.setDefaultTimeout(8000);
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                await page.addInitScript(({state, name}) => {
                    localStorage.setItem('theme', 'dark');
                    if (state === 'none') return;
                    localStorage.setItem('dnr.form-draft.layout-fixture.layout', JSON.stringify({
                        fields: [{name: 'name', type: 'text', value: 'A saved name'}],
                        version: state === 'stale' ? 'older-version' : (name === 'draft-chron'
                            ? '[["engagement_version","1"],["chron_entry_versions[8]","2"],["chron_entry_versions[9]","3"],["record",""]]'
                            : '[["version","1"],["record",""]]'),
                        at: Date.now(), expires: Date.now() + 60000
                    }));
                }, {state: draftState, name});
                let release, hold;
                await page.route('https://initial-layout.test/**', async route => {
                    const url = new URL(route.request().url());
                    if (url.pathname.startsWith('/assets/')) {
                        if (delayed.has(path.basename(url.pathname))) await hold;
                        return route.fulfill({path: path.join(root, 'src', url.pathname)});
                    }
                    return route.fulfill({contentType: 'text/html', body: html});
                });
                const geometry = () => page.locator(selector).evaluateAll(nodes => nodes.filter(node => node.getClientRects().length).map(node => {
                    const rect = node.getBoundingClientRect();
                    return [node.tagName, Math.round(rect.x), Math.round(rect.y), Math.round(rect.width), Math.round(rect.height)];
                }));
                try {
                    const url = 'https://initial-layout.test/' + (name.startsWith('list:') ? name.slice(5) : name) + '.php';
                    for (const reload of [false, true]) {
                        hold = new Promise(resolve => { release = resolve; });
                        if (reload) await page.reload({waitUntil: 'commit'});
                        else await page.goto(url, {waitUntil: 'commit'});
                        await page.locator('[data-initial-layout-parsed]').waitFor({state: 'attached'});
                        await page.evaluate(() => new Promise(requestAnimationFrame));
                        const initial = await geometry();
                        const fields = name === 'inquiry' ? await page.locator('.inquiry-form [id]').evaluateAll(nodes => Object.fromEntries(nodes.map(node => {
                            const rect = node.getBoundingClientRect();
                            return [node.id, [Math.round(rect.y), Math.round(rect.height)]];
                        }))) : null;
                        assert.ok(initial.length > 0, name + ' has visible content while deferred scripts load');
                        release();
                        await page.waitForLoadState('load');
                        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                        if (fields && JSON.stringify(await geometry()) !== JSON.stringify(initial)) {
                            const changed = await page.locator('.inquiry-form [id]').evaluateAll((nodes, fields) => nodes.map(node => {
                                const rect = node.getBoundingClientRect(), next = [Math.round(rect.y), Math.round(rect.height)];
                                return JSON.stringify(next) === JSON.stringify(fields[node.id]) ? null : [node.id, fields[node.id], next];
                            }).filter(Boolean), fields);
                            console.error('Changed inquiry controls', changed);
                        }
                        assert.deepEqual(await geometry(), initial, name + ' / ' + draftState + ' / ' + width + ' keeps its first rendered positions');
                        if (name.startsWith('draft')) {
                            const status = await page.locator('.form-draft-panel [role=status]').textContent();
                            assert.match(status, draftState === 'saved' ? /^Draft available/ : draftState === 'stale' ? /^The record changed/ : /^No saved draft/);
                        }
                        checks++;
                    }
                    if (name.startsWith('draft') && draftState === 'saved') {
                        await page.getByRole('button', {name: 'Restore Draft', exact: true}).click();
                        assert.equal(await page.locator('[name="name"]').inputValue(), 'A saved name');
                        assert.match(await page.locator('.form-draft-panel [role=status]').textContent(), /^Draft restored\./);
                    }
                    assert.deepEqual(errors, []);
                } finally {
                    release();
                    await page.close();
                }
            }
        }
        console.log('Initial layout passed for ' + checks + ' desktop/phone cases with deferred scripts held during the first paint.');
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error.stack || error); process.exitCode = 1;});
