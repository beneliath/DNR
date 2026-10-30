'use strict';
// Isolated layout regression: no application data, login, or live email is used.
// DNR_PLAYWRIGHT_MODULE points to an installed Playwright package.
// Export fixtures locally with DNR_EXPORT_SELECT_FIXTURES, then consume them
// with DNR_SELECT_FIXTURES in a PHP-free isolated browser container.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const engines = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const selectedEngines = (process.env.DNR_BROWSER_ENGINES || 'webkit,firefox,chromium').split(',');
const root = path.resolve(__dirname, '../..');
const select = '<select data-control><option value="previous">Previous period</option><option value="none">None</option></select>';
const cases = [
    ['Record form', 'record_workspace', `<form><div class="form-group"><label>Status${select}</label></div></form>`],
    ['Add organization', 'add_organization', `<form><div class="form-group"><label>Type${select}</label></div></form>`],
    ['New engagement', 'index', `<form><div class="form-row"><div class="form-field"><label>Caller${select}</label></div></div></form>`],
    ['Edit engagement', 'edit_engagement', `<form class="edit-engagement-page"><div class="event-group">${select}</div></form>`],
    ['Contact affiliation', 'contact_form', `<form><div class="contact-control-pair">${select}</div></form>`],
    ['Booking filters', 'booking_inquiries', `<form class="inquiry-filter-controls">${select}</form>`],
    ['Map filters', null, `<form><div class="map-filter-field"><label>Status${select}</label></div></form>`],
    ['Mail review', 'mail_review', `<form class="mail-review-route-form">${select}</form>`],
    ['Inbound queue', 'inbound_mail', `<form class="inbound-queue-search">${select}</form>`],
    ['Short links', 'short_links', `<form id="short-link-filters">${select}</form>`],
    ['Reimbursements', 'reimbursements', `<div class="reimbursement-filter-block"><form class="reimbursement-inline-form">${select}</form></div>`],
    ['Role preview', null, `<div class="role-preview-control"><div class="role-preview-fields">${select}</div></div>`],
    ['Search popover', null, `<div class="app-popover"><label class="app-popover-field">Category${select}</label></div>`],
    ['MessageMonkey filters', 'audience', `<form class="audience-filters">${select}</form>`],
    ['MessageMonkey pagination', 'audience', `<div class="audience-pagination"><label>Rows${select}</label></div>`],
    ['MessageMonkey switcher', 'audience', `<div class="audience-switcher">${select}</div>`],
    ['MessageMonkey segments', 'audience', `<div class="segment-condition">${select}</div>`],
    ['Analytics toolbar', 'audience-analytics', `<div class="analytics-reference-filters"><div class="analytics-range-control"><label>Date range</label><button class="analytics-range-trigger">Last 90 days</button></div><form class="analytics-comparison-control"><label>Compare with${select}</label></form><div class="analytics-message-control"><label>Filter by message name</label><button>All messages</button></div></div>`],
    ['Analytics date preset', 'audience-analytics', `<div class="analytics-date-fields"><label>Date range${select}</label></div>`],
    ['Inbox settings', 'audience-inbox', `<form><label>Reply routing${select}</label></form>`],
    ['Automation rules', 'audience-flows', `<div class="flow-rule-group"><legend>${select}</legend></div>`],
    ['AI assistance', 'audience-ai', `<form><label class="ai-field">Operation${select}</label></form>`],
    ['Email designer', 'audience-designer', `<div class="designer-field"><label>Font${select}</label></div>`],
    ['Signup editor', 'audience', `<div class="collection-question-settings"><label>Type${select}</label></div>`],
];

function fixture(index, dark, normalize = true) {
    const [name, page, markup] = cases[index];
    const audience = page?.startsWith('audience');
    const styles = audience ? ['assets/css/pages/audience.min.css'] : ['assets/css/style.min.css', 'assets/css/modern.min.css'];
    if (page && page !== 'audience') styles.push(`assets/css/pages/${page}.min.css`);
    if (name === 'Map filters') styles.push('assets/css/map.min.css');
    let head = execFileSync('php', ['-r', 'require "src/functions.php";renderPageHead("Select regression",["styles"=>json_decode($argv[1],true)]);', JSON.stringify(styles)], {cwd: root, encoding: 'utf8'});
    head = head.replace(/<script\b[^>]*>[\s\S]*?<\/script>/g, '');
    if (!normalize) head = head.replace(/\s*<link[^>]+native-selects\.min\.css[^>]*>/g, '');
    return `<!doctype html><html${dark ? ' class="dark-mode"' : ''}>${head}<body${audience ? ' class="audience-app"' : ''}><main><h1>${name}</h1>${markup}
        <label>Multiple choices<select multiple data-multiple><option>One</option><option>Two</option></select></label>
        <label>List box<select size="3" data-list><option>One</option><option>Two</option></select></label>
        <select class="address-country-native-select" aria-hidden="true" tabindex="-1" data-backing><option>Hidden backing field</option></select>
        </main></body></html>`;
}

function publicForm(language) {
    return execFileSync('php', ['-r', `
        require 'src/functions.php';require 'src/audience_store.php';require 'src/audience_collection_render.php';
        $d=audienceCollectionDefaults('survey');$d['language']=$argv[1];
        $d['questions']=[['id'=>'choice','label'=>'Choose','type'=>'dropdown','required'=>true,'options'=>['One','Two'],'other'=>false]];
        echo audienceCollectionHtml(['kind'=>'survey','document'=>$d],[],[],'');
    `, language], {cwd:root, encoding:'utf8'});
}

(async () => {
    let checks = 0;
    const dimensions = {};
    // Build once; every fixture uses the actual shared page-head asset ordering.
    const {fixtures, baseline, publicForms} = process.env.DNR_SELECT_FIXTURES
        ? JSON.parse(fs.readFileSync(process.env.DNR_SELECT_FIXTURES, 'utf8'))
        : {fixtures: cases.map((_, index) => [fixture(index, false), fixture(index, true)]), baseline: fixture(17, false, false), publicForms: ['en','he'].map(publicForm)};
    if (process.env.DNR_EXPORT_SELECT_FIXTURES) {
        fs.writeFileSync(process.env.DNR_EXPORT_SELECT_FIXTURES, JSON.stringify({fixtures, baseline, publicForms}));
        console.log('Exported current source-rendered dropdown fixtures.');
        return;
    }
    for (const engine of selectedEngines) {
        const browser = await engines[engine].launch({headless: true, timeout: 20000});
        try {
            const page = await Promise.race([browser.newPage(), new Promise((_, reject) => setTimeout(() => reject(new Error(`${engine}: new-page timeout`)), 15000).unref())]);
            page.setDefaultTimeout(5000);
            await page.route('http://selects.test/**', route => {
                if (new URL(route.request().url()).pathname === '/') return route.fulfill({contentType:'text/html', body:'<!doctype html><html><body></body></html>'});
                const file = path.join(root, 'src', new URL(route.request().url()).pathname);
                return fs.existsSync(file) && fs.statSync(file).isFile() ? route.fulfill({body: fs.readFileSync(file), contentType: file.endsWith('.css') ? 'text/css' : 'text/javascript'}) : route.abort();
            });
            await page.goto('http://selects.test/');
            await page.setContent(baseline);
            console.log(`${engine} original Analytics select height: ${await page.locator('[data-control]').evaluate(e => e.getBoundingClientRect().height)}px`);
            for (const width of [390, 1440]) {
                await page.setViewportSize({width, height: 900});
                for (let index = 0; index < cases.length; index++) {
                    for (const dark of [false, true]) {
                        await page.setContent(fixtures[index][Number(dark)]);
                        const measured = await page.locator('[data-control]').evaluate(e => {
                            const s = getComputedStyle(e), r = e.getBoundingClientRect();
                            return {height:r.height, minHeight:parseFloat(s.minHeight), appearance:s.appearance, arrow:s.backgroundImage, padding:parseFloat(s.paddingInlineEnd), font:parseFloat(s.fontSize)};
                        });
                        const label = `${engine}: ${cases[index][0]}, ${width}px, ${dark ? 'dark' : 'light'}`;
                        assert.equal(measured.appearance, 'none', `${label}: use the app field box`);
                        assert.ok(measured.height >= measured.minHeight, `${label}: respect the page's minimum height`);
                        assert.ok(measured.padding >= measured.font * 2 - .1, `${label}: reserve space for the arrow`);
                        assert.ok(measured.arrow.includes(dark ? '%23f3f6fb' : '%23646c70'), `${label}: keep the arrow visible in this theme`);
                        for (const selector of ['[data-multiple]', '[data-list]', '[data-backing]']) {
                            assert.equal(await page.locator(selector).evaluate(e => getComputedStyle(e).backgroundImage), 'none', `${label}: preserve list and hidden picker controls`);
                        }
                        if (index === 17) {
                            const heights = await page.locator('.analytics-reference-filters button, [data-control]').evaluateAll(es => es.map(e => e.getBoundingClientRect().height));
                            assert.deepEqual(heights, [42, 42, 42], `${label}: align Analytics control heights`);
                        }
                        if (width === 1440 && !dark) dimensions[`${engine}:${index}`] = measured.height;
                        checks++;
                    }
                }
            }
            await page.locator('[data-control]').selectOption('none');
            assert.equal(await page.locator('[data-control]').inputValue(), 'none', `${engine}: native selection still works`);
            for (const width of [390,1440]) {
                await page.setViewportSize({width,height:900});
                for (let direction=0;direction<publicForms.length;direction++) {
                    await page.setContent(publicForms[direction]);
                    const control=await page.locator('select').evaluate(e=>({appearance:getComputedStyle(e).appearance,height:e.getBoundingClientRect().height,arrow:getComputedStyle(e).backgroundPositionX}));
                    assert.equal(control.appearance,'none',`${engine}: public forms normalize Safari sizing`);
                    assert.ok(control.height>=50,`${engine}: public dropdowns retain authored padding`);
                    const correctSide = direction ? (parseFloat(control.arrow)>0 || control.arrow.includes('0%') || control.arrow.includes('left')) : (control.arrow.includes('100%') || control.arrow.includes('right'));
                    assert.ok(correctSide,`${engine}: public form arrow follows text direction (${control.arrow})`);
                    checks++;
                }
            }
            console.log(`${engine}: all ${cases.length*4+4} layouts passed`);
        } finally { await browser.close(); }
    }
    for (let i = 0; i < cases.length; i++) {
        for (const engine of selectedEngines.slice(1)) assert.ok(Math.abs(dimensions[`${selectedEngines[0]}:${i}`] - dimensions[`${engine}:${i}`]) <= 2, `${cases[i][0]}: ${selectedEngines[0]} and ${engine} heights agree`);
    }
    console.log(`Native select browser regression passed (${checks} layouts across ${selectedEngines.join('/')}, mobile/desktop, light/dark).`);
})().catch(error => {console.error(error); process.exitCode = 1;});
