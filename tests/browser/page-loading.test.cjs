'use strict';
const assert = require('node:assert/strict');
const path = require('node:path');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const assets = path.resolve(__dirname, '../../src/assets');
const origin = 'http://loading.test';
const indicator = '.page-loading-indicator';

function documentFor(url) {
    return `<!doctype html><html><head><meta charset="utf-8">
        <script async src="/assets/js/page-loading.min.js"></script><link rel="stylesheet" href="/assets/css/page-loading.min.css">
        <script defer src="/assets/js/button-feedback.min.js"></script><script defer src="/assets/js/audience-analytics.min.js"></script>
        </head><body>
        <a href="/slow">Next page</a><a href="#details">Details</a><a href="/export?download=csv" download>Export</a>
        <div id="details"></div><form method="post" action="/slow"><button>Save form</button></form>
        <button id="analytics-message-trigger">All messages</button>
        <form data-analytics-messages><input name="messages_mode" value="all" type="hidden"><input data-analytics-message-search>
            <input type="checkbox" data-analytics-all checked><div class="analytics-message-options"><label>
            <input type="checkbox" name="messages[]" value="1" checked>Message</label></div>
            <span data-analytics-filter-status></span></form><div data-analytics-results="email">Report</div>
        ${url.pathname === '/incoming' ? '<img src="/slow-image" alt="">' : ''}</body></html>`;
}

async function exercise(browser, fallback) {
    const page = await browser.newPage({reducedMotion:fallback?'reduce':'no-preference'});
    page.setDefaultTimeout(6000);
    const errors = [], shown = [];
    let nextIndicator;
    await page.exposeFunction('recordLoadingIndicator', event => {
        shown.push(event);
        nextIndicator?.(event);
    });
    page.on('pageerror', e => errors.push(e.message));
    await page.addInitScript(fallback => {
        if (fallback) Object.defineProperty(window, 'navigation', {value:undefined, configurable:true});
        window.spinnerTimes = [];
        new MutationObserver(changes => {
            for (const change of changes) for (const node of change.addedNodes) {
                if (node.classList?.contains('page-loading-indicator')) {
                    window.spinnerTimes.push(performance.now());
                    window.recordLoadingIndicator({role:node.getAttribute('role'), motion:getComputedStyle(node,'::before').animationName});
                }
            }
        }).observe(document, {subtree:true, childList:true});
    }, fallback);
    let release;
    const hold = () => new Promise(resolve => { release = resolve; });
    let failReport = false;
    await page.route(origin+'/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.startsWith('/assets/')) return route.fulfill({path:path.join(assets, url.pathname.slice(8))});
        if (url.pathname === '/slow-image') {
            await hold();
            return route.fulfill({contentType:'image/svg+xml', body:'<svg xmlns="http://www.w3.org/2000/svg"/>'});
        }
        if (url.pathname === '/slow' || route.request().resourceType() === 'fetch') await hold();
        if (route.request().resourceType() === 'fetch' && failReport) return route.fulfill({status:500, body:''});
        if (url.pathname === '/export') return route.fulfill({headers:{'Content-Disposition':'attachment; filename=report.csv'}, body:'count\n1'});
        return route.fulfill({contentType:'text/html', body:documentFor(url)});
    });
    const visible = page.locator(indicator);
    try {
        await page.goto(origin+'/fast');
        assert.deepEqual(await page.evaluate(() => window.spinnerTimes), []);
        for (const target of ['a[href="/slow"]','form[action="/slow"] button']) {
            const before = shown.length, started = Date.now();
            const appeared = new Promise((resolve,reject) => {
                const timeout = setTimeout(() => reject(new Error('Outgoing page did not show the spinner')),5000);
                nextIndicator = event => { clearTimeout(timeout); resolve(event); };
            });
            await page.evaluate(selector => document.querySelector(selector).click(), target);
            await new Promise(resolve => setTimeout(resolve,250));
            assert.equal(shown.length, before);
            const event = await appeared;
            assert.ok(Date.now()-started >= 995);
            assert.deepEqual(event,{role:'status',motion:fallback?'none':'page-loading-spin'});
            nextIndicator = null;
            release();
            await page.waitForURL(origin+'/slow');
            await page.waitForLoadState('load');
            assert.equal(await visible.count(), 0);
            await page.goto(origin+'/fast');
        }

        await page.goto(origin+'/incoming', {waitUntil:'domcontentloaded'});
        await visible.waitFor();
        assert.ok((await page.evaluate(() => window.spinnerTimes))[0] >= 995);
        release();
        await page.waitForLoadState('load');
        assert.equal(await visible.count(), 0);

        await page.goto(origin+'/fast');
        await page.evaluate(() => {
            if (window.navigation) window.navigation.addEventListener('navigate', e => e.preventDefault(), {once:true});
            else window.addEventListener('click', e => e.preventDefault(), {once:true});
            document.querySelector('a[href="/slow"]').click();
        });
        await page.locator('a[href="#details"]').click();
        await Promise.all([page.waitForEvent('download'), page.getByText('Export', {exact:true}).click()]);
        await page.waitForTimeout(1100);
        assert.deepEqual(await page.evaluate(() => window.spinnerTimes), []);

        for (const fail of [false,true]) {
            failReport = fail;
            await page.locator('[name="messages[]"]').click();
            await visible.waitFor();
            release();
            await visible.waitFor({state:'detached'});
            assert.match(await page.locator('[data-analytics-filter-status]').textContent(), fail ? /Unable to update/ : /Report updated/);
        }
        // Cancel a pending operation, then complete two overlapping slow operations independently.
        await page.evaluate(() => {
            window.DnrPageLoading.begin()();
            window.finishFirst = window.DnrPageLoading.begin();
            window.finishSecond = window.DnrPageLoading.begin();
        });
        await visible.waitFor();
        await page.evaluate(() => window.finishFirst());
        assert.equal(await visible.isVisible(), true);
        await page.evaluate(() => window.finishSecond());
        assert.equal(await visible.count(), 0);
        assert.deepEqual(errors, []);
    } finally {
        release?.();
        await page.close();
    }
}

(async () => {
    for (const engine of (process.env.DNR_BROWSER_ENGINES || 'chromium').split(',')) {
        const browser = await engines[engine].launch({timeout:30000});
        try {
            await exercise(browser, false);
            await exercise(browser, true);
            console.log(engine+': slow navigations, incoming loads, report updates, downloads and overlapping operations passed.');
        } finally { await browser.close(); }
    }
})().catch(e => { console.error(e); process.exitCode=1; });
