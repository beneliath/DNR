'use strict';
// Real assistant templates/bundle with synthetic responses; no AI calls or database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const markup = execFileSync('php', ['tests/browser/audience-mobile-workspace-fixture.php', 'view=assistant&ai_enabled=1'], {cwd: root, encoding: 'utf8'});
const analyticsMarkup = execFileSync('php', ['tests/browser/audience-mobile-workspace-fixture.php', 'view=assistant&mode=analytics&ai_enabled=1'], {cwd: root, encoding: 'utf8'});
const checks = {facts: [{label: 'Word count', value: 12, note: 'Counted from the supplied text'}], findings: [], note: 'Text checks only'};
const reply = {purpose: 'audience-ai', mode: 'content', reason: '', headline: 'A community update', text: 'Thank you for being part of our community.',
    subjects: ['Community news', 'An update for you', 'Staying connected'], recommendations: ['Review the facts'], checks};
const savedRequestId = 'saved-invitation';
const savedReply = {...reply, headline: 'An invitation', text: 'Please join our next community gathering.',
    checks: {...checks, facts: [{label: 'Word count', value: 7, note: 'Counted from the supplied text'}]}};
const visibleInPane = (page, selector, paneSelector = '.writing-ai-content') => page.waitForFunction(({selector, paneSelector}) => {
    const element = document.querySelector(selector), pane = document.querySelector(paneSelector);
    if (!element || !pane) return false;
    const r = element.getBoundingClientRect(), p = pane.getBoundingClientRect();
    return r.height > 0 && r.top >= Math.max(0, p.top) - 2 && r.bottom <= Math.min(innerHeight, p.bottom) + 2;
}, {selector, paneSelector}, {timeout: 5000});

(async () => {
    for (const name of ['chromium', 'webkit']) {
        const browser = await engines[name].launch({headless: true});
        try {
            for (const viewport of [{width: 1600, height: 900}, {width: 390, height: 844}]) {
                let page = await browser.newPage({viewport});
                let completed = false, submitted = false, requestId, analytics = false;
                const errors = [];
                page.on('pageerror', error => errors.push(error.message));
                const handleRoute = route => {
                    const url = new URL(route.request().url());
                    if (url.pathname.endsWith('admin_unlock_status.php')) return route.fulfill({contentType: 'application/json', body: JSON.stringify({unlocked: false, server_now: 0, expires_at: 0})});
                    if (url.pathname.startsWith('/assets/')) {
                        const file = path.join(root, 'src', url.pathname);
                        return route.fulfill({body: fs.readFileSync(file), contentType: {'.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2'}[path.extname(file)] || 'application/octet-stream'});
                    }
                    if (url.pathname.endsWith('audience_ai_api.php')) {
                        let data;
                        if (url.searchParams.get('action') === 'history') {
                            const items = completed || analytics ? [{client_request_id: requestId, question: 'A saved request', manual_topic: `audience-ai:${analytics ? 'analytics' : 'content'}`, created_at: '2026-10-29 21:49:00', outcome: 'complete'}] : [];
                            if (completed && !analytics) items.push({client_request_id: savedRequestId, question: 'Write a warm invitation', manual_topic: 'audience-ai:content', created_at: '2026-10-29 21:48:00', outcome: 'complete'});
                            data = {items, can_remove_history: true};
                        } else if (route.request().method() === 'POST') {
                            const body = route.request().postDataJSON();
                            if (body.action === 'checks') data = checks;
                            else { submitted = true; requestId = body.request_id; data = {state: 'pending'}; }
                        } else data = completed ? {state: 'complete', response: url.searchParams.get('request_id') === savedRequestId ? savedReply : reply} : {state: 'pending', stage: 'generating'};
                        return route.fulfill({contentType: 'application/json', body: JSON.stringify(data)});
                    }
                    return route.fulfill({contentType: 'text/html', body: analytics ? analyticsMarkup : markup});
                };
                await page.route('https://ai.test/**', handleRoute);
                await page.goto('https://ai.test/audience.php?view=assistant');
                await page.evaluate(() => document.documentElement.classList.add('dark-mode'));
                await page.locator('[data-ai-checks]').click();
                await visibleInPane(page, '[data-ai-check-result] h3');
                assert.match(await page.locator('[data-ai-check-result]').textContent(), /Word count/);
                await page.locator('[name=prompt]').fill('Help me share a community update');
                await page.locator('[data-ai-generate]').click();
                await visibleInPane(page, '[data-ai-thinking]');
                assert.equal(await page.locator('.ai-thinking-dots i').count(), 3);
                assert.equal(await page.locator('.ai-thinking-dots i').first().evaluate(el => getComputedStyle(el).animationName), 'ai-thinking-bounce');
                assert.ok(await page.locator('[data-ai-generate]').isDisabled());
                completed = true;
                await visibleInPane(page, '[data-ai-result] h3');
                assert.ok(submitted);
                assert.equal(await page.locator('[data-ai-text]').inputValue(), reply.text);
                assert.ok(await page.locator('[data-ai-thinking]').isHidden());
                assert.ok(await page.locator('[data-ai-generate]').isHidden());
                assert.ok(await page.locator('[data-ai-checks]').isHidden());
                for (const [question, expected] of [['A saved request', reply], ['Write a warm invitation', savedReply], ['A saved request', reply]]) {
                    await page.locator('.writing-ai-content').evaluate(el => { el.scrollTop = 0; });
                    if (await page.locator('[data-ai-history-panel]').isHidden()) {
                        await page.locator(viewport.width < 700 ? '.mm-mobile-history-toggle' : '[data-ai-history-toggle]').click();
                    }
                    await page.locator('[data-ai-history]').getByRole('button', {name: question, exact: true}).click();
                    await visibleInPane(page, '[data-ai-check-result] h3');
                    assert.equal(await page.locator('[data-ai-check-result] dd').textContent(), String(expected.checks.facts[0].value));
                    assert.equal(await page.locator('[data-ai-text]').inputValue(), expected.text);
                    assert.ok(await page.locator('[data-ai-generate]').isHidden());
                }
                await page.locator('[data-ai-new]').click();
                assert.ok(await page.locator('[data-ai-generate]').isVisible());
                await page.locator('[data-ai-history-reset]').click();
                await page.locator('[data-ai-history-confirm-submit]').click();
                await page.waitForFunction(() => document.querySelector('[data-ai-status]').textContent.startsWith('Request list reset.'));
                const statusGap = await page.evaluate(() => {
                    const status = document.querySelector('[data-ai-status]'), text = document.createRange();
                    text.selectNodeContents(status);
                    return text.getBoundingClientRect().top - document.querySelector('.ai-actions').getBoundingClientRect().bottom;
                });
                assert.ok(statusGap >= 24, `Reset message should be separated from buttons; got ${statusGap}px`);
                assert.equal(await page.locator('.analytics-ai-footer').count(), 0);
                assert.equal(await page.locator('[data-ai-status]').evaluate(el => getComputedStyle(el).paddingTop), '24px');

                await page.close();
                analytics = true; completed = false;
                page = await browser.newPage({viewport});
                page.on('pageerror', error => errors.push(error.message));
                await page.route('https://ai.test/**', handleRoute);
                await page.goto('https://ai.test/audience.php?view=assistant&mode=analytics', {waitUntil: 'networkidle'});
                assert.equal(await page.locator('.analytics-ai-compose-area').evaluate(el => getComputedStyle(el).marginBottom), '50px');
                await page.locator('[name=prompt]').fill('How can I improve my email performance? '.repeat(24));
                await page.locator('[data-ai-generate]').click();
                await visibleInPane(page, '[data-ai-thinking]', '.analytics-ai-conversation');
                assert.equal(await page.locator('.ai-thinking-dots i').first().evaluate(el => getComputedStyle(el).animationName), 'ai-thinking-bounce');
                await page.locator('[data-ai-stop]').click();
                assert.ok(await page.locator('[data-ai-thinking]').isHidden());
                if (await page.locator('[data-ai-history-panel]').isHidden()) {
                    await page.locator(viewport.width < 700 ? '.mm-mobile-history-toggle' : '[data-ai-history-toggle]').click();
                }
                await page.locator('[data-ai-history-reset]').click();
                await page.locator('[data-ai-history-confirm-submit]').click();
                await page.locator('[data-ai-welcome]').waitFor({state: 'visible'});
                assert.ok(await page.locator('[data-ai-chat-transcript]').isHidden());
                assert.equal(await page.locator('[data-ai-history] li').count(), 0);
                assert.equal(await page.locator('[name=prompt]').inputValue(), '');
                assert.equal(await page.locator('.analytics-ai-footer').count(), 0);
                assert.deepEqual(errors, []);
                await page.close();
                console.log(`${name} ${viewport.width}px: history selections reveal the corresponding content checks; writing results and Analytics working dots visible; Reset list opens a fresh chat`);
            }
        } finally { await browser.close(); }
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
