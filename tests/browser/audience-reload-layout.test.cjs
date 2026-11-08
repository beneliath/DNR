'use strict';

// Inspect the real templates while their deferred workspace script is still in flight.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const origin = 'http://reload.test';
const fixture = (name, ...args) => execFileSync('php', ['tests/browser/' + name + '-fixture.php', ...args], {cwd:root, encoding:'utf8'});
const views = {
    campaigns: [fixture('audience-campaign-lifecycle','admin','active'), '.campaign-list-toolbar,.campaign-list-table tbody tr:first-child>td'],
    content: [fixture('audience-tag-actions','admin','active','locked','2','','','content'), '.content-filters,.content-filters button,.content-filters select'],
    brand: [fixture('audience-tag-actions','admin','active','locked','2','','','brand'), '.content-brand-section,.audience-file-drop,.audience-file-drop>svg,.audience-file-drop>strong,.audience-file-drop>span,.audience-file-drop>p,[data-file-browse],.content-brand-radius,.content-brand-apply-row,.audience-color-trigger,.content-brand-logo-stage'],
    writer: [fixture('audience-mobile-workspace','view=assistant&ai_enabled=1&saved_history=1'), '.ai-history-heading,[data-ai-history] li,[data-ai-history-empty]'],
    forms: [fixture('audience-collection-lifecycle','admin','active'), '.collection-filters,.collection-table']
};

async function checkReload(browser, view, html, selector, width = 1600) {
    const page = await browser.newPage({viewport:{width,height:900}});
    await page.addInitScript(() => {
        if (window !== top) return;
        localStorage.setItem('theme','dark');
        localStorage.setItem('moed:audience:sidebar-collapsed','1');
        localStorage.setItem('campaign-columns:template-lifecycle-fixture','["folder"]');
    });
    let release;
    let hold = new Promise(resolve => {release=resolve;});
    await page.route(origin+'/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.startsWith('/assets/')) {
            if (url.pathname.endsWith('/audience.min.js')) await hold;
            const type = {'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml'}[path.extname(url.pathname)];
            return route.fulfill({path:path.join(root,'src',url.pathname), contentType:type || 'application/octet-stream'});
        }
        if (url.pathname === '/admin_unlock_status.php') return route.fulfill({json:{unlocked:false}});
        return route.fulfill({contentType:'text/html', body:html.replace('</body>','<span hidden data-reload-fixture-parsed></span></body>')});
    });
    const geometry = () => page.locator(selector).evaluateAll(nodes => nodes.filter(n => n.getClientRects().length).map(node => {
        const r=node.getBoundingClientRect();
        return [node.tagName,Math.round(r.x),Math.round(r.y),Math.round(r.width),Math.round(r.height)];
    }));
    try {
        await page.goto(origin+'/audience.php?view='+view, {waitUntil:'commit'});
        await page.locator('[data-reload-fixture-parsed]').waitFor({state:'attached'});
        assert.equal(await page.locator('html[data-mm-ui-standards]').count(),0);
        const before = await geometry();
        assert(before.length > 0,view+' renders its controls without waiting for the workspace bundle');
        if (view==='writer') assert.equal(await page.getByRole('button',{name:'Fictional saved request',exact:true}).count(),1);
        if (view==='campaigns') {
            assert.equal(await page.locator('thead [data-campaign-column=folder]').isVisible(),false);
            assert.equal(await page.evaluate(() => document.documentElement.classList.contains('dark-mode')
                && DnrAccountContext.local.getItem('theme') === 'dark'
                && DnrUserTime.timeZone === document.querySelector('meta[name=user-timezone]').content
                && typeof DnrFormDrafts.initialize === 'function' && typeof DnrPageLoading.begin === 'function'),true,
                'The saved theme, account storage, timezone and form helpers are ready before the workspace script');
        }
        release();
        await page.waitForLoadState('load');
        await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
        assert.deepEqual(await geometry(),before,view+' keeps its initial control and row positions');
        if (view==='brand') {
            hold = new Promise(resolve => {release=resolve;});
            await page.reload({waitUntil:'commit'});
            await page.locator('[data-reload-fixture-parsed]').waitFor({state:'attached'});
            const reloaded = await geometry();
            release();
            await page.waitForLoadState('load');
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
            assert.deepEqual(await geometry(),reloaded,'Brand Kit keeps the prompts and surrounding content in place on reload');
        }
        if (view==='content' || view==='campaigns') {
            const group=page.getByRole('group',{name:'Type',exact:true});
            assert.equal(await group.count(),1);
            const value=view==='content'?'image':'plain-text';
            const navigated=page.waitForURL(url=>url.searchParams.get('type')===value);
            await group.locator('[data-value="'+value+'"]').click();
            await navigated;
        }
    } finally {
        release();
        await page.close();
    }
}

(async () => {
    for (const engine of (process.env.DNR_BROWSER_ENGINES || 'chromium').split(',')) {
        const browser = await engines[engine].launch();
        try {
            for (const [view,[html,selector]] of Object.entries(views)) await checkReload(browser,view,html,selector);
            const [html,selector] = views.brand;
            await checkReload(browser,'brand',html,selector,390);
            console.log(engine+': Campaigns, My files, Brand Kit (desktop/mobile), AI history and Forms keep their initial layout across delayed startup.');
        } finally {
            await browser.close();
        }
    }
})().catch(error=>{console.error(error);process.exitCode=1;});
