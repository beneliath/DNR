'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const origin = 'http://home-paint.test';
const fixture = mode => execFileSync('php', ['tests/browser/audience-home-fixture.php', mode], {cwd:root, encoding:'utf8'});

async function inspectHome(browser, mode, width, theme, javaScriptEnabled = true) {
    const page = await browser.newPage({viewport:{width,height:900},javaScriptEnabled});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.addInitScript(theme => {
        localStorage.setItem('theme', theme);
        localStorage.setItem('moed:audience:sidebar-collapsed', '1');
    }, theme);
    const html = fixture(mode);
    const bundle = mode === 'legacy' ? 'audience.min.js' : 'audience-home.min.js';
    let release;
    const delayed = new Promise(resolve => {release=resolve;});
    await page.route(origin+'/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.startsWith('/assets/')) {
            if (url.pathname.endsWith('/'+bundle)) await delayed;
            const file = path.resolve(root, 'src', '.'+url.pathname);
            assert(file.startsWith(path.join(root, 'src/assets')+path.sep));
            if (!fs.existsSync(file)) return route.fulfill({status:404,body:''});
            const contentType = {'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml'}[path.extname(file)];
            return route.fulfill({path:file,contentType:contentType||'application/octet-stream'});
        }
        if (url.searchParams.get('api') === 'workspace_search') return route.fulfill({json:{groups:[]}});
        if (url.pathname === '/admin_unlock_status.php') return route.fulfill({json:{unlocked:false}});
        if (url.pathname === '/audience.php') return route.fulfill({contentType:'text/html',body:html});
        return route.fulfill({status:204,body:''});
    });
    const geometry = () => page.locator('.audience-topbar,.audience-page-heading,.analytics-metrics,.analytics-audience')
        .evaluateAll(nodes => nodes.map(node => {
            const r = node.getBoundingClientRect();
            return [Math.round(r.x),Math.round(r.y),Math.round(r.width),Math.round(r.height)];
        }));
    try {
        await page.goto(origin+'/audience.php?view=home', {waitUntil:'commit'});
        await page.locator('#audience-config').waitFor({state:'attached'});
        if (!javaScriptEnabled) {
            release();
            await page.waitForLoadState('load');
            assert.equal(await page.locator('#home-email-explanation').isVisible(),true,'Metric explanations remain available without JavaScript');
            assert.equal(await page.locator('[data-home-explanation-toggle]').first().isVisible(),false);
            return {mode,width,javaScriptEnabled};
        }
        // The browser must have the CSS before comparing the initial layout.
        await page.waitForFunction(() => [...document.querySelectorAll('link[rel=stylesheet]')].every(link => link.sheet));
        const before = await geometry();
        if (mode !== 'legacy') {
            // A held deferred script must not prevent the first contentful paint.
            if (browser.browserType().name() === 'chromium') {
                await page.waitForFunction(() => performance.getEntriesByName('first-contentful-paint').length > 0);
            }
            assert.equal(await page.locator('html[data-mm-ui-standards]').count(), 0, 'Content paints before the Home bundle executes');
            assert.equal(await page.locator('#snapshot-heading').innerText(), 'Email Snapshot');
            assert.equal(await page.locator('html').evaluate(node => node.classList.contains('dark-mode')), theme === 'dark');
            assert.equal(await page.locator('body').evaluate(node => node.classList.contains('audience-sidebar-collapsed')), width > 860);
        }
        // Simulate a slow cold download for a reproducible before/after comparison.
        await new Promise(resolve => setTimeout(resolve, 1000));
        release();
        await page.waitForLoadState('load');
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        const timings = await page.evaluate(bundle => ({
            fcp: performance.getEntriesByName('first-contentful-paint')[0]?.startTime,
            bundleEnd: performance.getEntriesByType('resource').find(entry => new URL(entry.name).pathname.endsWith('/'+bundle))?.responseEnd,
        }), bundle);
        if (mode !== 'legacy') {
            assert.deepEqual(await geometry(), before, 'Home metrics keep their initial layout after enhancement');
            if (timings.fcp !== undefined) assert(timings.fcp < timings.bundleEnd, 'Home paints before its controls finish downloading');
            if (width < 860) {
                const explanation = page.locator('[aria-controls="home-email-explanation"]');
                await explanation.click();
                assert.equal(await page.locator('#home-email-explanation').isVisible(),true);
                await explanation.click();
                assert.equal(await page.locator('#home-email-explanation').isVisible(),false);
            }
            await page.locator('[data-theme-toggle]').click();
            assert.equal(await page.locator('html').evaluate(node => node.classList.contains('dark-mode')),theme !== 'dark');
            await page.locator('[data-theme-toggle]').click();
            await page.locator('.audience-notification-trigger').click();
            assert.equal(await page.locator('#audience-notifications').isVisible(),true);
            await page.getByRole('button',{name:'Close Notifications',exact:true}).click();
            await page.getByRole('button',{name:'Search MessageMonkey',exact:true}).click();
            assert.equal(await page.getByRole('dialog',{name:'Search MessageMonkey',exact:true}).isVisible(),true);
            await page.getByRole('button',{name:'Close Search',exact:true}).click();
            assert.equal(await page.getByRole('dialog',{name:'Search MessageMonkey',exact:true}).isVisible(),false);
            await page.locator('#analytics-range-trigger').click();
            assert.equal(await page.locator('#analytics-range').isVisible(),true);
            await page.getByRole('button',{name:'Cancel',exact:true}).click();
            if (width < 860) {
                await page.getByRole('button',{name:'Open MessageMonkey Navigation',exact:true}).click();
                assert.equal(await page.locator('[data-audience-nav-toggle]').getAttribute('aria-expanded'),'true');
                await page.getByRole('button',{name:'Close Navigation',exact:true}).click();
            } else {
                await page.getByRole('button',{name:'Expand Navigation',exact:true}).click();
                assert.equal(await page.locator('body.audience-sidebar-collapsed').count(),0);
            }
            await page.getByRole('button',{name:'Unlock Administrator Actions',exact:true}).click();
            assert.equal(await page.locator('#admin-unlock-dialog').isVisible(),true);
        } else if (timings.fcp !== undefined) {
            assert(timings.fcp >= timings.bundleEnd, 'The original bundle blocked the first paint');
        }
        assert.deepEqual(errors, []);
        return {mode,width,theme,...timings};
    } finally { release(); await page.close(); }
}

(async () => {
    for (const name of (process.env.DNR_BROWSER_ENGINES || 'chromium').split(',')) {
        const browser = await engines[name].launch();
        try {
            const evidence = [await inspectHome(browser,'legacy',1440,'light')];
            for (const [width,theme] of [[1440,'light'],[1440,'dark'],[390,'light'],[390,'dark']]) {
                evidence.push(await inspectHome(browser,'home',width,theme));
            }
            evidence.push(await inspectHome(browser,'home',390,'light',false));
            console.log(name+': Home paint and controls passed '+JSON.stringify(evidence));
        } finally { await browser.close(); }
    }
})().catch(error => {console.error(error);process.exitCode=1;});
