'use strict';
// Render the real manual with synthetic session data and no database access.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const html = execFileSync('php', ['tests/browser/audience-manual-fixture.php', 'admin'], {cwd:root, encoding:'utf8', maxBuffer:8*1024*1024});
const settle = page => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));

(async () => {
    let checks = 0;
    for (const engine of ['chromium', 'webkit']) {
        const browser = await engines[engine].launch({headless:true});
        try {
            const page = await browser.newPage({viewport:{width:390,height:844}, isMobile:true, hasTouch:true});
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://manual.test/**', route => {
                const url = new URL(route.request().url());
                if (url.pathname.startsWith('/assets/')) {
                    const file = path.join(root, 'src', url.pathname);
                    return fs.existsSync(file) ? route.fulfill({body:fs.readFileSync(file), contentType:({'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml','.woff2':'font/woff2'})[path.extname(file)] || 'application/octet-stream'}) : route.fulfill({status:404,body:''});
                }
                if (url.pathname === '/admin_unlock_status.php') return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
                return route.fulfill({contentType:'text/html',body:html});
            });
            await page.goto('http://manual.test/audience.php?view=manual');
            await page.waitForLoadState('networkidle');
            for (const dark of [false, true]) {
                await page.evaluate(dark => document.documentElement.classList.toggle('dark-mode', dark), dark);
                for (const width of [320,390,844,932]) {
                    await page.setViewportSize({width,height:width>=844?430:844});
                    await settle(page);
                    const tables = await page.locator('.audience-manual table').evaluateAll(tables => tables.map(table => ({
                        width:table.getBoundingClientRect().width,
                        captionWidth:table.caption.getBoundingClientRect().width,
                        rows:[...table.tBodies[0].rows].map(row => [...row.cells].map(cell => ({width:cell.getBoundingClientRect().width,visible:cell.getClientRects().length>0})))
                    })));
                    for (const table of tables) {
                        assert.ok(table.captionWidth >= table.width-1, `${engine}/${width}: manual caption spans the readable width instead of a vertical strip`);
                        for (const row of table.rows) for (const cell of row) {
                            assert.ok(cell.visible, 'Every reference explanation is visible without opening record details');
                            assert.ok(cell.width >= table.width-36, 'Row headings and descriptions use full-width readable lines');
                        }
                    }
                    assert.equal(await page.locator('.audience-manual .mm-mobile-details-toggle').count(),0,'Reference material has no record-action disclosures');
                    assert.ok(await page.evaluate(() => document.scrollingElement.scrollWidth <= innerWidth+1),'The manual fits the mobile viewport');
                    checks++;
                }
            }
            await page.setViewportSize({width:1440,height:1000});
            await settle(page);
            assert.equal(await page.locator('.audience-manual [data-mm-card-table]').count(),0,'Desktop restores the original tables');
            for (const table of await page.locator('.audience-manual table').all()) {
                const cells = await table.locator('tbody tr').first().locator('th,td').evaluateAll(cells=>cells.map(cell=>{const r=cell.getBoundingClientRect();return {x:r.x,y:r.y};}));
                assert.ok(cells.every(cell=>Math.abs(cell.y-cells[0].y)<1),'Desktop reference cells remain in one row');
                assert.ok(cells[1].x>cells[0].x,'Desktop columns remain side by side');
            }
            assert.deepEqual(errors,[]);
            page.removeAllListeners('pageerror');
        } finally { await browser.close(); }
    }
    console.log(`Mobile manual passed: ${checks} light/dark layouts with readable captions, complete reference text and desktop restoration`);
})().catch(error => {console.error(error.message);process.exitCode=1;});
