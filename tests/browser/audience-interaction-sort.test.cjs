'use strict';
// Real report markup and browser controls, synthetic records, no database or map service.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixture = execFileSync('php', {cwd:root, encoding:'utf8', input:`<?php
require 'src/functions.php';
require 'src/audience_store.php';
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$interactionMapProvider=null;
$interactionLocations=['observations'=>1012,'unmapped'=>0,'pins'=>[
 ['label'=>'Switzerland','observations'=>1000,'types'=>['Signups'=>1000]],
 ['label'=>'Canada','observations'=>10,'types'=>['Signups'=>10]],
 ['label'=>'Belgium','observations'=>2,'types'=>['Signups'=>2]]
]];
echo '<!doctype html><html lang="en">';
renderPageHead('Interaction sorting fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-analytics.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-analytics.min.js']]);
echo '<body class="audience-app" data-mm-workspace="workspace"><main class="audience-content"><div class="analytics-workspace analytics-dashboard">';
include 'src/templates/audience/analytics-interaction-locations.php';
echo '</div></main></body></html>';
`});
const rows = page => page.locator('#interaction-location-table tbody tr[data-interaction-location]:not([hidden])')
    .evaluateAll(nodes => nodes.map(row => row.dataset.interactionLocation));

(async () => {
    const browser = await chromium.launch({headless:true, timeout:15000});
    let layouts = 0;
    try {
        for (const dark of [false,true]) for (const width of [390,1280]) {
            const page = await browser.newPage({viewport:{width,height:900},reducedMotion:'reduce'});
            page.setDefaultTimeout(5000);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.addInitScript(dark => localStorage.setItem('theme', dark ? 'dark' : 'light'), dark);
            await page.route('http://interaction-sort.test/**', route => {
                const url = new URL(route.request().url());
                if (!url.pathname.startsWith('/assets/')) return route.fulfill({contentType:'text/html',body:fixture});
                const file = path.join(root,'src',url.pathname);
                const type = {'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml'}[path.extname(file)];
                return fs.existsSync(file) ? route.fulfill({body:fs.readFileSync(file),contentType:type || 'application/octet-stream'}) : route.fulfill({status:404,body:''});
            });
            await page.goto('http://interaction-sort.test/audience.php?view=analytics&section=locations');
            const location = page.locator('[data-interaction-sort="location"]');
            const interactions = page.locator('[data-interaction-sort="interactions"]');
            const styles = await location.evaluate(button => {
                const style = getComputedStyle(button);
                const link = document.createElement('a');
                link.textContent = 'Audience sort reference';
                button.parentElement.append(link);
                const expectedColor = getComputedStyle(link).color;
                link.remove();
                return {color:style.color, expectedColor, weight:style.fontWeight, border:style.borderWidth};
            });
            assert.equal(styles.color,styles.expectedColor,'Sort labels use the Audience page link color');
            assert.equal(styles.weight,'400','Sort labels use normal font weight');
            assert.equal(styles.border,'0px','Sort labels have no button border');
            const choose = async key => {
                if (width > 860) return page.locator(`[data-interaction-sort="${key}"]`).click();
                const sort = page.locator('.mm-mobile-list-tools select');
                await sort.waitFor();
                await sort.selectOption(key === 'location' ? '0' : '1');
            };
            assert.deepEqual(await rows(page),['Switzerland','Canada','Belgium'],'Highest interaction count is the initial order');
            await choose('location');
            assert.deepEqual(await rows(page),['Belgium','Canada','Switzerland'],'Location sorts alphabetically');
            assert.equal(await location.locator('..').getAttribute('aria-sort'),'ascending');
            assert.equal(await location.locator('[data-sort-arrow]').textContent(),'↑');
            assert.equal(await interactions.locator('..').getAttribute('aria-sort'),'none');
            assert.equal(await interactions.locator('[data-sort-arrow]').textContent(),'');
            if (width > 860) {
                await location.focus();
                await page.keyboard.press('Enter');
            } else await choose('location');
            assert.deepEqual(await rows(page),['Switzerland','Canada','Belgium'],'Location toggles to reverse alphabetical order');
            assert.equal(await location.locator('[data-sort-arrow]').textContent(),'↓');
            await choose('interactions');
            assert.deepEqual(await rows(page),['Belgium','Canada','Switzerland'],'Counts sort numerically including formatted thousands');
            assert.equal(await interactions.locator('..').getAttribute('aria-sort'),'ascending');
            await choose('interactions');
            assert.deepEqual(await rows(page),['Switzerland','Canada','Belgium'],'Counts toggle to highest first');
            assert.equal(await interactions.locator('[data-sort-arrow]').textContent(),'↓');
            // The map updates these values at the target before the event bubbles.
            await page.evaluate(() => {
                const table = document.getElementById('interaction-location-table');
                for (const row of table.querySelectorAll('tr[data-interaction-location]')) {
                    const count = {Belgium:3,Canada:20,Switzerland:3}[row.dataset.interactionLocation];
                    const cell = row.querySelector('[data-interaction-observations]');
                    cell.dataset.interactionObservations = String(count);
                    cell.textContent = String(count);
                }
                const filter = document.getElementById('interaction-map-type');
                filter.dispatchEvent(new Event('change',{bubbles:true}));
            });
            assert.deepEqual(await rows(page),['Canada','Belgium','Switzerland'],'A type change reapplies the selected numeric order with alphabetical ties');
            assert.equal(await interactions.locator('..').getAttribute('aria-sort'),'descending');
            assert.equal(await page.locator('#interaction-location-table-empty').evaluate(node => node === node.parentElement.lastElementChild),true,'Empty state remains after the data rows');
            assert.deepEqual(errors,[],'No browser errors');
            await page.close();
            layouts++;
        }
        console.log(`Interaction location sorting passed (${layouts} light/dark desktop/mobile layouts, keyboard, numeric order, arrows and filtered counts).`);
    } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exitCode=1;});
