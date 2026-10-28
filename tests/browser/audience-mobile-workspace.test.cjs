'use strict';
// Real PHP views and browser events, synthetic records, no database or email.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const work = path.join(root, 'tmp/mm-mobile-workspace');
fs.mkdirSync(work, {recursive:true});
const fixture = (name, ...args) => execFileSync('php', [`tests/browser/${name}-fixture.php`, ...args], {cwd:root, encoding:'utf8', maxBuffer:8*1024*1024});
const views = {
    contacts: fixture('audience-mobile-workspace'),
    templates: fixture('audience-template-lifecycle', 'admin', 'saved', 'locked', 'bulk'),
    archive: fixture('audience-template-lifecycle', 'admin', 'archived', 'locked'),
    campaigns: fixture('audience-campaign-lifecycle', 'admin', 'active'),
    forms: fixture('audience-collection-lifecycle', 'admin', 'active'),
    home: fixture('audience-campaign-lifecycle', 'admin', 'active', '', 'home'),
    designer: fixture('audience-designer-order'),
    formEditor: fixture('audience-collection-images', 'admin', 'hosted'),
    surveyEditor: fixture('audience-collection-images', 'admin', 'survey'),
    inbox: fixture('audience-mobile-inbox'),
    conversation: fixture('audience-mobile-inbox', 'thread'),
    automations: fixture('audience-mobile-flow'),
    flowEditor: fixture('audience-mobile-flow', 'editor'),
    calendar: fixture('audience-campaign-lifecycle', 'admin', 'active', '', 'campaign-manager', 'calendar'),
    writingAi: fixture('audience-mobile-workspace', 'view=assistant'),
    analyticsAi: fixture('audience-mobile-workspace', 'view=assistant&mode=analytics'),
    reviewer: fixture('audience-template-lifecycle', 'reviewer', 'saved'),
};
const settle = async page => {
    // Finish status/preview requests before navigating again. WebKit otherwise
    // reports cancelled requests from the previous document as access errors.
    await page.waitForLoadState('networkidle');
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
};
const desktopGeometry = page => page.locator('.audience-topbar,.audience-page-heading,.audience-page-body,.template-gallery,.campaign-list-table,[data-people-table]').evaluateAll(nodes => nodes.map(node => {
    const r=node.getBoundingClientRect();return [node.className,...[r.x,r.y,r.width,r.height].map(value=>Math.round(value*100)/100)];
}));
// Resizing can change a few antialiased border pixels. Compare decoded pixels
// with a 0.01% allowance, plus the exact desktop geometry and cleanup checks.
const visualDifference = (page, a, b) => page.evaluate(async ({a,b}) => {
    const decode = async data => {
        const image=new Image();image.src='data:image/png;base64,'+data;await image.decode();
        const canvas=document.createElement('canvas');canvas.width=image.width;canvas.height=image.height;
        const ctx=canvas.getContext('2d');ctx.drawImage(image,0,0);return ctx.getImageData(0,0,image.width,image.height).data;
    };
    const left=await decode(a),right=await decode(b);let changed=0;
    for(let i=0;i<left.length;i+=4)if(left[i]!==right[i]||left[i+1]!==right[i+1]||left[i+2]!==right[i+2])changed++;
    return changed/(left.length/4);
},{a:a.toString('base64'),b:b.toString('base64')});

(async () => {
    let layouts = 0;
    for (const engine of (process.env.DNR_BROWSER_ENGINES || 'chromium,webkit').split(',')) {
        const browser = await engines[engine].launch({headless:true});
        try {
            let context=await browser.newContext({viewport:{width:390,height:844},reducedMotion:'reduce',isMobile:true,hasTouch:true});
            let page = await context.newPage(), activeTheme='light';
            page.setDefaultTimeout(5000);
            let current = 'templates', baseline = false;
            const errors = [];
            const onError = error => errors.push(`${current}: ${error.message}`);
            page.on('pageerror', onError);
            const serve = route => {
                const u = new URL(route.request().url());
                if (u.pathname.startsWith('/assets/')) {
                    const relative = 'src'+u.pathname, file = path.join(root,relative);
                    if (baseline && u.pathname.endsWith('audience-mobile.min.css')) return route.fulfill({contentType:'text/css',body:'.mm-mobile-only{display:none!important}.mm-mobile-menu:not([open]){display:none}'});
                    const body = baseline && u.pathname.endsWith('/audience.min.js') ? execFileSync('git',['show','HEAD:'+relative],{cwd:root}) : fs.existsSync(file) ? fs.readFileSync(file) : null;
                    return body ? route.fulfill({body, contentType:({'.css':'text/css','.js':'text/javascript','.mjs':'text/javascript','.svg':'image/svg+xml','.woff2':'font/woff2'})[path.extname(file)] || 'application/octet-stream'}) : route.fulfill({status:404,body:''});
                }
                if(u.pathname==='/admin_unlock_status.php')return route.fulfill({contentType:'application/json',body:'{"unlocked":false}'});
                if(u.searchParams.has('inbox_api'))return route.fulfill({contentType:'application/json',body:'{"items":[],"settings":{}}'});
                if(route.request().method()==='POST')return route.fulfill({contentType:'application/json',body:'{"ok":true,"html":"<p>Preview</p>"}'});
                return route.fulfill({contentType:'text/html',body:views[current]});
            };
            await page.route('http://mobile-workspace.test/**', serve);
            const visit=async url=>{
                const viewport=page.viewportSize();
                // A fresh document excludes WebKit's unload-time status requests
                // from the next page's errors without filtering application errors.
                page.removeAllListeners('pageerror');await page.close();
                page=await context.newPage();await page.setViewportSize(viewport);page.setDefaultTimeout(5000);
                await page.addInitScript(theme=>{try{localStorage.setItem('theme',theme);}catch{}},activeTheme);
                await page.route('http://mobile-workspace.test/**',serve);page.on('pageerror',onError);
                await page.goto(url);
            };
            for (const theme of ['light','dark']) {
                activeTheme=theme;
                for (const width of (process.env.DNR_MOBILE_WIDTHS || '320,390,768,844,932').split(',').map(Number)) {
                    await page.setViewportSize({width,height:width>=844?390:844});
                    for (current of Object.keys(views)) {
                        await visit('http://mobile-workspace.test/audience.php?view='+current);
                        await settle(page);
                        const geometry = await page.evaluate(() => ({width:document.scrollingElement.scrollWidth,viewport:innerWidth}));
                        if(geometry.width>width+1)await page.screenshot({path:path.join(work,`${engine}-${theme}-${width}-${current}-overflow.png`)});
                        assert.ok(geometry.width<=width+1, `${engine}/${theme}/${width}/${current} fits viewport: ${JSON.stringify(geometry)}`);
                        assert.equal(await page.locator('.mm-mobile-dock').isVisible(), true);
                        const targets = await page.locator('.mm-mobile-dock>a,.mm-mobile-dock>button').evaluateAll(nodes => nodes.map(node => {const r=node.getBoundingClientRect();return Math.min(r.width,r.height);}));
                        assert.ok(targets.every(size=>size>=44),'navigation touch targets are at least 44px');
                        if(width===390 && ['templates','contacts','campaigns','home','inbox','conversation','automations','calendar'].includes(current))await page.screenshot({path:path.join(work,`${engine}-${theme}-${current}.png`)});
                        layouts++;
                    }
                }
            }
            await page.setViewportSize({width:390,height:844});
            current='contacts'; await visit('http://mobile-workspace.test/audience.php'); await settle(page);
            const row=page.locator('[data-mm-card-row]').first();
            assert.equal(await row.locator('[data-mm-cell=detail]').first().isVisible(),false);
            await row.locator('.mm-mobile-details-toggle').tap();
            assert.equal(await row.locator('[data-mm-cell=detail]').first().isVisible(),true,'Details exposes original record fields');
            await page.locator('.mm-mobile-select-page').click();
            assert.equal(await page.locator('[data-person-select]').first().isChecked(),true,'mobile select page uses existing bulk selection');
            await page.locator('.mm-mobile-select-page').click();
            assert.equal(await page.locator('[data-person-select]').first().isChecked(),false);
            await page.locator('.mm-mobile-filter-toggle').click();
            assert.equal(await page.locator('[popovertarget=filter-status]').isVisible(),true);
            await page.locator('[popovertarget=filter-status]').click();
            assert.equal(await page.locator('#filter-status').isVisible(),true);
            await page.keyboard.press('Escape');

            await page.locator('[data-mm-menu-open]').tap();
            assert.equal(await page.locator('#mm-mobile-menu').isVisible(),true);
            assert.equal(await page.getByRole('link',{name:'Email templates',exact:true}).last().isVisible(),true);
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('[data-mm-menu-open]').evaluate(node=>node===document.activeElement),true,'menu dismissal restores focus');

            current='templates'; await visit('http://mobile-workspace.test/audience.php'); await settle(page);
            const group=page.locator('.template-card-actions').first();
            await group.locator('.mm-mobile-action-toggle').tap();
            const icons=group.locator('.action-icon-button');
            for(const icon of await icons.all())assert.equal(await icon.isVisible(),true,'expanded actions remain available');
            const rename=group.locator('[data-tooltip=Rename]');
            await rename.click();
            assert.equal(await page.locator('#template-operation').isVisible(),true,'original rename handler still opens its dialog');
            await page.keyboard.press('Escape');
            await page.locator('.mm-mobile-preview-button').first().tap();
            assert.equal(await page.locator('.mm-mobile-email-preview').isVisible(),true,'full email preview is available without editing');
            await page.getByRole('button',{name:'Close',exact:true}).click();

            current='designer'; await visit('http://mobile-workspace.test/audience.php'); await settle(page);
            assert.equal(await page.locator('.designer-inspector').isVisible(),false);
            await page.locator('.designer-block').first().click();
            assert.equal(await page.locator('.designer-inspector').isVisible(),true,'selecting content opens its editor');
            await page.getByRole('button',{name:'Email',exact:true}).click();
            assert.equal(await page.locator('.designer-stage').isVisible(),true);

            current='formEditor';await visit('http://mobile-workspace.test/audience.php');await settle(page);
            await page.getByRole('button',{name:'Preview',exact:true}).click();
            assert.equal(await page.locator('.collection-preview').isVisible(),true);
            assert.equal(await page.locator('.collection-controls').isVisible(),false);
            await page.getByRole('button',{name:'Editing tools',exact:true}).click();
            assert.equal(await page.locator('.collection-controls').isVisible(),true);

            current='flowEditor';await visit('http://mobile-workspace.test/audience.php');await settle(page);
            assert.equal(await page.locator('.flow-map-stage').isVisible(),true);
            await page.locator('.flow-node').first().click();
            assert.equal(await page.locator('.flow-inspector').isVisible(),true);

            current='inbox';await visit('http://mobile-workspace.test/audience.php');await settle(page);
            assert.equal(await page.locator('.inbox-list').isVisible(),true);
            const conversationUrl=await page.locator('.inbox-thread-item').first().getAttribute('href');
            current='conversation';await visit(new URL(conversationUrl,'http://mobile-workspace.test/').href);await settle(page);
            assert.equal(await page.locator('.inbox-list').isVisible(),false,'reading a conversation uses the screen');
            assert.equal(await page.locator('.inbox-return').isVisible(),true,'conversation has an obvious way back');

            current='reviewer';await visit('http://mobile-workspace.test/audience.php');await settle(page);
            await page.locator('[data-mm-menu-open]').click();
            assert.equal(await page.locator('#mm-mobile-menu .mm-mobile-create').count(),0,'reviewers cannot create campaigns');
            assert.equal(await page.locator('#mm-mobile-menu a[href*="view=import"]').count(),0,'reviewers have no import control');
            await page.keyboard.press('Escape');

            // Mobile changes must clean up on resize. Compare actual desktop pixels
            // against the previous bundle and without the new mobile stylesheet.
            page.removeAllListeners('pageerror');await context.close();
            context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});page=await context.newPage();
            page.setDefaultTimeout(5000);page.on('pageerror',onError);
            await page.route('http://mobile-workspace.test/**',serve);
            for(current of ['templates','campaigns','contacts','home']){
                await page.setViewportSize({width:390,height:844});
                await visit('http://mobile-workspace.test/audience.php');await settle(page);
                await page.setViewportSize({width:1440,height:1000});await settle(page);
                assert.equal(await page.locator('.mm-mobile-dock').isVisible(),false);
                assert.equal(await page.locator('[data-mm-card-table],[data-mm-actions],[data-mm-filters]').count(),0,'mobile enhancements clean up at desktop size');
                const geometry=await desktopGeometry(page);
                const actual=await page.screenshot({animations:'disabled'});
                baseline=true;await visit('http://mobile-workspace.test/audience.php');await settle(page);
                const original=await page.screenshot({animations:'disabled'});baseline=false;
                assert.deepEqual(await desktopGeometry(page),geometry,`${current} desktop geometry matches the existing UI`);
                if(!actual.equals(original)){fs.writeFileSync(path.join(work,`${engine}-desktop-${current}.png`),actual);fs.writeFileSync(path.join(work,`${engine}-baseline-${current}.png`),original);}
                assert.ok(await visualDifference(page,actual,original)<=0.0001,`${current} desktop appearance matches the existing UI`);
            }
            console.log(`${engine}: mobile interactions and desktop comparisons completed`);
            assert.equal(errors.length,0,`${engine} browser errors: ${JSON.stringify([...new Set(errors)])}`);
        } finally { await browser.close(); }
    }
    console.log(`MessageMonkey mobile workspace passed: ${layouts} layouts, actions, filters, selection, navigation, editing, permissions and desktop visual comparisons`);
})().catch(error=>{console.error(error);process.exitCode=1;});
