'use strict';
// Optional real-browser regression: DNR_PLAYWRIGHT_MODULE points to an installed
// Playwright package. Run directly with node; no application or database needed.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { webkit, devices } = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const header = execFileSync('php', ['-r',
    'ob_start();require "tests/header_scope_test.php";ob_end_clean();echo $header_markup;'
], { cwd: root, encoding: 'utf8' });

function fixture(account, preview) {
    const banners = (account ? '<section class="account-identity-banner"><span class="account-identity-label"><strong>Shalom in Messiah Ministries</strong><span class="account-primary-badge">Primary Account</span></span><a href="accounts.php">Switch Account</a></section>' : '')
        + (preview ? '<section class="role-preview-banner"><div class="role-preview-banner-copy"><strong>Reviewer Preview</strong><span>Viewing this Account as a reviewer</span></div></section>' : '');
    return '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<link rel="stylesheet" href="assets/css/style.min.css"><link rel="stylesheet" href="assets/css/modern.min.css">'
        + '<link rel="stylesheet" href="assets/css/pages/reimbursements.min.css">'
        + '</head><body' + (preview ? ' class="role-preview-active"' : '') + '>'
        + header.replace('<header class="app-shell-header">', '<header class="app-shell-header">' + banners)
        + '<main class="container reimbursement-page"><h1>Mobile shell regression</h1>'
        + '<div class="reimbursement-progress"><p>Review Request</p></div>'
        + '<form><label>Speaker<select><option>Alexandria Montgomery — alexandria.montgomery@example.invalid</option></select></label></form>'
        + '<div style="height:2200px">Scrollable content</div></main></body></html>';
}

async function frame(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

(async () => {
    const browser = await webkit.launch({ headless: true });
    let checks = 0;
    try {
        for (const account of [false, true]) {
            for (const preview of [false, true]) {
                const context = await browser.newContext({ ...devices['iPhone 13'], deviceScaleFactor: 1 });
                const page = await context.newPage();
                const serve = route => {
                    const pathname = new URL(route.request().url()).pathname;
                    if (!pathname.startsWith('/assets/')) return route.fulfill({ contentType: 'text/html', body: fixture(account, preview) });
                    const file = path.join(root, 'src', pathname);
                    const types = { '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml', '.woff2': 'font/woff2' };
                    return fs.existsSync(file) ? route.fulfill({ body: fs.readFileSync(file), contentType: types[path.extname(file)] || 'application/octet-stream' }) : route.abort();
                };
                await page.route('http://mobile.test/**', serve);
                for (const width of [320, 390, 768, 844]) {
                    await page.setViewportSize({ width, height: width === 844 ? 390 : 844 });
                    await page.goto('http://mobile.test/dashboard.php');
                    await frame(page);
                    const initial = await page.evaluate(() => ({
                        position: getComputedStyle(document.body).position,
                        width: document.scrollingElement.scrollWidth,
                        font: parseFloat(getComputedStyle(document.querySelector('main select')).fontSize)
                    }));
                    assert.notEqual(initial.position, 'fixed', 'mobile must scroll the document');
                    assert.ok(initial.width <= width + 1, 'long native options must stay within the viewport');
                    assert.ok(initial.font >= 16, 'native fields must avoid iOS focus zoom');
                    await page.evaluate(() => window.scrollTo({ top: 500, behavior: 'instant' }));
                    await page.waitForFunction(() => window.scrollY >= 499);
                    const scrolled = await page.evaluate(() => {
                        const button = document.querySelector('[data-nav-toggle]');
                        const rect = button.getBoundingClientRect();
                        const bars = [...document.querySelectorAll('.mobile-app-bar,.account-identity-banner,.role-preview-banner')];
                        return {
                            visible: button.contains(document.elementFromPoint(rect.left + rect.width / 2, rect.top + rect.height / 2)),
                            target: Math.min(rect.width, rect.height),
                            progressTop: document.querySelector('.reimbursement-progress').getBoundingClientRect().top,
                            stackBottom: Math.max(...bars.map(bar => bar.getBoundingClientRect().bottom))
                        };
                    });
                    assert.ok(scrolled.visible, 'menu must remain clickable after scrolling');
                    assert.ok(scrolled.target >= 44, 'menu needs a 44px touch target');
                    assert.ok(scrolled.progressTop >= scrolled.stackBottom, 'sticky progress must clear every banner');
                    await page.locator('[data-nav-toggle]').click();
                    assert.equal(await page.locator('#app-sidebar').getAttribute('aria-modal'), 'true');
                    assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'hidden');
                    await page.locator('[data-nav-close]').click();
                    assert.notEqual(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'hidden');
                    checks++;
                }
                await page.setViewportSize({ width: 932, height: 430 });
                await frame(page);
                assert.equal(await page.locator('.mobile-app-bar').isVisible(), false);
                assert.notEqual(await page.evaluate(() => getComputedStyle(document.body).position), 'fixed', 'wide touch screens must retain native document scrolling');
                assert.equal(await page.locator('.app-brand-logo').isVisible(), true);
                const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 } });
                const desktopPage = await desktop.newPage();
                await desktopPage.route('http://mobile.test/**', serve);
                await desktopPage.goto('http://mobile.test/dashboard.php');
                if (account) assert.equal(await desktopPage.evaluate(() => getComputedStyle(document.body).position), 'fixed');
                await desktop.close();
                await context.close();
            }
        }
        console.log(`Mobile shell browser regression passed (${checks} mobile layouts, wide touch screens, and desktop resizing).`);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
