'use strict';
// DNR_PLAYWRIGHT_MODULE may point to a locally installed Playwright package.
// Run directly with node; the actual workspace is rendered without a database.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixtures = Object.fromEntries(['editor', 'reviewer'].map(role => [role,
    execFileSync('php', ['tests/browser/audience-navigation-fixture.php', role], {cwd: root, encoding: 'utf8'})
]));
const toggle = '[data-audience-nav-toggle]';
const close = '[data-audience-nav-close]';
const sidebar = '#audience-sidebar';
const backdrop = '[data-audience-nav-backdrop]';

async function frame(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

(async () => {
    let layouts = 0;
    for (const engine of (process.env.DNR_BROWSER_ENGINES || 'webkit,chromium').split(',')) {
        const browser = await engines[engine].launch({headless: true});
        try {
            const page = await browser.newPage({reducedMotion: 'reduce'});
            page.setDefaultTimeout(5000);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://navigation.test/**', route => {
                const url = new URL(route.request().url());
                if (!url.pathname.startsWith('/assets/')) {
                    const role = url.searchParams.get('role') || 'editor';
                    return route.fulfill({contentType: 'text/html', body: fixtures[role]});
                }
                const file = path.join(root, 'src', url.pathname);
                const type = {'.css':'text/css', '.js':'text/javascript', '.svg':'image/svg+xml'}[path.extname(file)];
                return fs.existsSync(file) ? route.fulfill({body: fs.readFileSync(file), contentType: type || 'application/octet-stream'}) : route.abort();
            });
            for (const width of [320, 390, 680, 768, 844, 860]) {
                await page.setViewportSize({width, height: width === 844 ? 390 : 844});
                await page.goto('http://navigation.test/audience.php');
                await frame(page);
                assert.equal(await page.locator(sidebar).isVisible(), false, 'mobile navigation starts closed');
                assert.equal(await page.locator(toggle).getAttribute('aria-expanded'), 'false');
                assert.equal(await page.locator(sidebar).evaluate(node => node.inert), true);
                assert.ok(await page.evaluate(() => document.scrollingElement.scrollWidth <= innerWidth + 1), `${width}px shell fits the viewport`);
                const target = await page.locator(toggle).boundingBox();
                assert.ok(Math.min(target.width, target.height) >= 44, 'hamburger has a 44px touch target');
                assert.equal(await page.locator('main').evaluate(node => node.getBoundingClientRect().left), 0, 'content uses the full width');
                await page.evaluate(() => window.scrollTo({top: 500, behavior: 'instant'}));
                await page.waitForFunction(() => window.scrollY >= 499);
                await page.locator(toggle).click();
                assert.equal(await page.locator(sidebar).getAttribute('aria-modal'), 'true');
                assert.equal(await page.locator(close).evaluate(node => node === document.activeElement), true);
                assert.equal(await page.locator('main').evaluate(node => node.inert), true);
                assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'hidden');
                for (const label of ['Home', 'Campaigns', 'Automations', 'Forms', 'Audience', 'Content', 'Analytics']) {
                    assert.equal(await page.locator(`${sidebar} .audience-nav-item[aria-label="${label}"]`).isVisible(), true, `${label} is available`);
                }
                assert.equal(await page.locator(`${sidebar} .audience-create`).isVisible(), true);
                assert.equal(await page.locator(`${sidebar} .audience-subnav a`).first().isVisible(), true);
                const last = page.locator(`${sidebar} .audience-return`);
                await last.focus();
                await page.keyboard.press('Tab');
                assert.equal(await page.locator(close).evaluate(node => node === document.activeElement), true, 'Tab wraps to the first drawer control');
                await page.keyboard.press('Shift+Tab');
                assert.equal(await last.evaluate(node => node === document.activeElement), true, 'Shift+Tab wraps to the last drawer control');
                await page.keyboard.press('Escape');
                assert.equal(await page.locator(sidebar).isVisible(), false);
                assert.equal(await page.locator(toggle).evaluate(node => node === document.activeElement), true, 'closing restores hamburger focus');
                assert.equal(await page.locator('main').evaluate(node => node.inert), false);
                assert.notEqual(await page.evaluate(() => getComputedStyle(document.documentElement).overflowY), 'hidden');
                await page.locator(toggle).click();
                await page.locator(backdrop).click({position: {x: width - 5, y: 10}});
                assert.equal(await page.locator(toggle).getAttribute('aria-expanded'), 'false');
                await page.locator(toggle).click();
                await page.locator(close).click();
                assert.equal(await page.locator(toggle).getAttribute('aria-expanded'), 'false');
                await page.locator(toggle).click();
                await page.locator(`${sidebar} .audience-nav-item[aria-label="Home"]`).click();
                await page.waitForURL('**/audience.php?view=home');
                assert.equal(await page.locator(toggle).getAttribute('aria-expanded'), 'false');
                layouts++;
            }
            await page.setViewportSize({width: 1200, height: 900});
            await frame(page);
            assert.equal(await page.locator(toggle).isVisible(), false);
            assert.equal(await page.locator(sidebar).isVisible(), true);
            await page.locator('[data-sidebar-toggle]').click();
            const collapsedWidth = await page.locator(sidebar).evaluate(node => node.getBoundingClientRect().width);
            assert.ok(collapsedWidth < 100, 'desktop collapse leaves the icon rail');
            await page.setViewportSize({width: 390, height: 844});
            await page.locator(toggle).click();
            assert.ok(await page.locator(sidebar).evaluate(node => node.getBoundingClientRect().width) > 200, 'desktop collapse preference does not shrink the mobile drawer');
            assert.equal(await page.locator(`${sidebar} .audience-nav-item[aria-label="Audience"] span`).last().isVisible(), true);
            await page.setViewportSize({width: 1200, height: 900});
            await frame(page);
            assert.equal(await page.locator(sidebar).evaluate(node => node.inert), false);
            assert.equal(await page.locator('main').evaluate(node => node.inert), false);
            assert.equal(await page.locator(sidebar).getAttribute('aria-modal'), null);
            assert.equal(await page.locator(sidebar).evaluate(node => node.getBoundingClientRect().width), collapsedWidth, 'desktop collapse preference survives resizing');
            await page.setViewportSize({width: 390, height: 844});
            await page.goto('http://navigation.test/audience.php?role=reviewer');
            await page.locator(toggle).click();
            assert.equal(await page.locator(`${sidebar} .audience-create`).count(), 0, 'reviewers retain permission-based navigation');
            assert.deepEqual(errors, [], `${engine} has no browser errors`);
        } finally {
            await browser.close();
        }
    }
    console.log(`MessageMonkey navigation passed (${layouts} mobile layouts, keyboard dismissal, and desktop resizing).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
