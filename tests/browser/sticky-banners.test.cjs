'use strict';

const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const test = require('node:test');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');

const root = path.resolve(__dirname, '../..');
const toolbars = {
    index: '[data-sticky-toolbar]',
    edit_engagement: '[data-sticky-toolbar]',
    reimbursements: '.reimbursement-progress',
    contacts: '[data-bulk-delete]',
    organizations: '[data-bulk-delete]',
};
const fixedBars = '.mobile-app-bar, .deployment-notice-banner, .account-identity-banner, .role-preview-banner, .admin-unlock-banner';

async function assertPinned(page, selector) {
    const geometry = await page.evaluate(({selector, fixedBars}) => {
        const toolbar = document.querySelector(selector);
        const rect = toolbar.getBoundingClientRect();
        const bars = [...document.querySelectorAll(fixedBars)].filter(bar => bar.getClientRects().length);
        const bottom = Math.max(0, ...bars.map(bar => bar.getBoundingClientRect().bottom));
        return {
            top: rect.top,
            expected: bottom + 12,
            visible: toolbar.contains(document.elementFromPoint(rect.left + rect.width / 2, rect.top + 5)),
        };
    }, {selector, fixedBars});
    assert.ok(Math.abs(geometry.top - geometry.expected) < 2, JSON.stringify(geometry));
    assert.equal(geometry.visible, true, 'the top of the toolbar is reachable');
}

test('page toolbars stick below the visible shell banners', async t => {
    const browser = await chromium.launch();
    try {
        for (const [name, selector] of Object.entries(toolbars)) {
            for (const [width, mode] of [[1440, 'plain'], [1440, 'account'], [390, 'preview']]) {
                await t.test(`${name} / ${width} / ${mode}`, async () => {
                    const page = await browser.newPage({viewport: {width, height: 900}});
                    try {
                        const html = execFileSync('php', ['tests/browser/sticky-banners-fixture.php', name, mode], {cwd: root, encoding: 'utf8'});
                        let phase = null;
                        await page.route('https://sticky-banners.test/**', route => {
                            const {pathname} = new URL(route.request().url());
                            if (pathname === '/deployment_status.php') {
                                const now = Date.now() / 1000;
                                return route.fulfill({json: {
                                    server_now: now,
                                    notice: phase && {phase, not_before: now + 60, expires_at: now + 600},
                                }});
                            }
                            if (pathname.startsWith('/assets/')) return route.fulfill({path: path.join(root, 'src', pathname)});
                            return route.fulfill({contentType: 'text/html', body: html});
                        });
                        await page.goto(`https://sticky-banners.test/${name}.php`);
                        await page.evaluate(() => {
                            const scroller = getComputedStyle(document.body).position === 'fixed'
                                ? document.body : document.scrollingElement;
                            scroller.scrollTo({top: 500, behavior: 'instant'});
                        });
                        await assertPinned(page, selector);

                        const notice = page.locator('[data-deployment-notice]');
                        phase = 'preparing';
                        await page.evaluate(() => window.dispatchEvent(new Event('online')));
                        await notice.waitFor({state: 'visible'});
                        await assertPinned(page, selector);

                        if (mode === 'plain') {
                            const originalHeight = (await notice.boundingBox()).height;
                            await notice.evaluate((banner, height) => { banner.style.minHeight = `${height + 32}px`; }, originalHeight);
                            await page.waitForFunction(() => getComputedStyle(document.body).paddingTop === getComputedStyle(document.querySelector('[data-deployment-notice]')).height);
                            await assertPinned(page, selector);

                            await page.locator('.admin-unlock-banner').evaluate(banner => {
                                banner.hidden = false;
                                document.body.classList.add('admin-unlock-active');
                            });
                            await assertPinned(page, selector);
                        }

                        phase = null;
                        await page.evaluate(() => window.dispatchEvent(new Event('online')));
                        await notice.waitFor({state: 'hidden'});
                        await assertPinned(page, selector);
                    } finally {
                        await page.close();
                    }
                });
            }
        }
    } finally {
        await browser.close();
    }
});
