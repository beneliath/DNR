'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixtures = Object.fromEntries(['outcomes','report-outcomes'].map(kind => [kind,
    process.env.DNR_FLOW_OUTCOMES_FIXTURES
        ? fs.readFileSync(path.join(process.env.DNR_FLOW_OUTCOMES_FIXTURES, kind+'.html'), 'utf8')
        : execFileSync('php', ['tests/browser/audience-mobile-flow-fixture.php', kind], {cwd:root, encoding:'utf8'})]));
const labels = ['Started','Finished','Exited early','In progress','Needs attention'];
const keys = ['started','completed','exited','active','failed'];

(async () => {
    const browser = await engines.chromium.launch({timeout:30000});
    try {
        for (const theme of ['light','dark']) {
            const page = await browser.newPage({viewport:{width:1440,height:1000}});
            page.setDefaultTimeout(8000);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(theme => localStorage.setItem('theme',theme), theme);
            await page.route('**/*', route => {
                const url = new URL(route.request().url());
                if (url.hostname !== 'automations.test') return route.abort();
                if (url.pathname === '/audience.php') return route.fulfill({contentType:'text/html',
                    body:fixtures[url.searchParams.get('view') === 'flow-report' ? 'report-outcomes' : 'outcomes']});
                const file = path.join(root, 'src', url.pathname);
                return fs.existsSync(file) ? route.fulfill({path:file}) : route.fulfill({status:404,body:''});
            });
            for (const view of ['automations','flow-report']) {
                await page.goto('http://automations.test/audience.php?view='+view);
                for (const width of [320,390,1024,1440,1920]) {
                    await page.setViewportSize({width,height:1000});
                    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
                    const headings = page.locator(view === 'automations'
                        ? '.flow-overview-row:nth-child(2) .flow-overview-run-summary dt' : '.flow-report-totals h2');
                    assert.deepEqual(await headings.allTextContents(), labels);
                    const geometry = await headings.evaluateAll(elements => elements.map(element => {
                        const range = document.createRange(); range.selectNodeContents(element);
                        const rects = [...range.getClientRects()];
                        const box = element.getBoundingClientRect();
                        return {label:element.textContent,lines:rects.length,
                            fits:rects.every(rect => rect.left >= box.left-1 && rect.right <= box.right+1),
                            right:box.right};
                    }));
                    for (const item of geometry) {
                        assert.equal(item.lines, 1, `${theme}/${view}/${width}: ${item.label} stays on one line`);
                        assert.ok(item.fits && item.right <= width+1, `${theme}/${view}/${width}: ${item.label} fits its column`);
                    }
                    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1),
                        `${theme}/${view}/${width}: page fits viewport`);
                    if (view === 'automations') {
                        const summary = page.locator('.flow-overview-row:nth-child(2) .flow-overview-run-summary');
                        assert.deepEqual((await summary.locator('dd').allTextContents()).map(text=>text.trim()), ['5','5','0','0','0']);
                        for (const [index,key] of keys.entries()) {
                            const link = new URL(await summary.locator('dd a').nth(index).getAttribute('href'), page.url());
                            assert.equal(link.searchParams.get('view'),'flow-report');
                            assert.equal(link.searchParams.get('id'),'2');
                            assert.equal(link.searchParams.get('state'),key === 'started' ? null : key);
                        }
                        const mixed = page.locator('.flow-overview-row:nth-child(3) .flow-overview-run-summary');
                        assert.deepEqual((await mixed.locator('dd').allTextContents()).map(text=>text.trim()), ['20','12','3','4','1']);
                    } else {
                        assert.deepEqual(await page.locator('.flow-run-state').allTextContents(),labels.slice(1));
                        assert.equal(await page.locator('.audience-notice.success').textContent(),'Exit step');
                        assert.equal(await page.locator('.audience-notice.error').textContent(),'Action failed');
                    }
                    if (process.env.DNR_FLOW_OUTCOMES_SCREENSHOTS && theme === 'light' && [390,1440].includes(width)) {
                        await page.locator(view === 'automations' ? '.flow-overview-results' : '.flow-report-totals')
                            .screenshot({path:path.join(process.env.DNR_FLOW_OUTCOMES_SCREENSHOTS,`${view}-${width}.png`)});
                    }
                }
            }
            assert.deepEqual(errors,[]);
            await page.close();
        }
        console.log('Automation outcomes passed: matching counts and report links, finished/error notices, and single-line labels at five widths in both themes.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
