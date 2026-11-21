'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixture = selected => execFileSync('php', [path.join(__dirname, 'new-engagement-location-fixture.php'), selected], {encoding: 'utf8'});
const fields = ['event_address_line_1', 'event_address_line_2', 'event_city', 'event_state', 'event_zipcode', 'event_country'];
const addresses = {
    1: ['123 Main Street', 'Suite 2', 'Chicago', 'IL', '60601', 'US'],
    2: ['456 King Street', '', 'Toronto', 'ON', 'M5V 2T6', 'CA'],
    3: ['78 High Street', '', 'London', 'Greater London', 'SW1A 1AA', 'GB'],
    4: null,
    6: ['9 Search Lane', '', 'Boston', 'MA', '02108', 'US'],
};
const addressPayload = id => ({address: addresses[id] && Object.fromEntries(fields.map((name, index) => [name, addresses[id][index]]))});
const values = page => page.locator('#new-engagement-form').evaluate((form, names) => {
    const data = new FormData(form);
    return names.map(name => data.get(name));
}, fields);

(async () => {
    const browser = await chromium.launch({channel: process.env.DNR_BROWSER_CHANNEL});
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({viewport: {width, height: 1000}});
            page.setDefaultTimeout(5000);
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            let pendingAddress;
            let holdAddress = false;
            let failAddress = false;
            let addressRequests = 0;
            await page.route('https://engagement.test/**', async route => {
                const url = new URL(route.request().url());
                if (url.pathname === '/index.php') {
                    return route.fulfill({contentType: 'text/html', body: fixture(url.searchParams.get('created_organization_id') || '')});
                }
                if (url.pathname === '/organization_address.php') {
                    addressRequests += 1;
                    if (holdAddress) { pendingAddress = route; return; }
                    if (failAddress) return route.fulfill({status: 503, json: {error: 'Unavailable'}});
                    return route.fulfill({json: addressPayload(url.searchParams.get('organization_id'))});
                }
                if (url.pathname === '/inquiry_relationship_search.php') {
                    return route.fulfill({json: {results: [{id: 6, organization_name: 'Search Result Organization'}], has_more: false}});
                }
                const asset = path.join(root, 'src', url.pathname);
                return url.pathname.startsWith('/assets/') && fs.existsSync(asset) ? route.fulfill({path: asset}) : route.abort();
            });

            await page.goto('https://engagement.test/index.php');
            const button = page.getByRole('button', {name: 'Use Organization Address', exact: true});
            const organization = page.locator('#organization_id');
            const status = page.locator('#organization-address-status');
            const confirmation = page.getByRole('dialog', {name: 'Replace Event Location?', exact: true});
            const startCopy = async (confirm = true) => {
                await button.click();
                assert.equal(await confirmation.isVisible(), confirm);
                if (confirm) await confirmation.getByRole('button', {name: 'Replace Event Location', exact: true}).click();
            };
            const copy = async (confirm = true) => {
                await startCopy(confirm);
                await page.waitForFunction(() => document.getElementById('organization-address-status').textContent.startsWith('Organization address copied.'));
            };
            assert.equal(await button.isVisible(), false);
            await organization.selectOption('1');
            assert.equal(await button.isVisible(), true);
            assert.equal((await values(page))[0], '', 'Selecting an organization requires an explicit copy action');
            await copy(false);
            assert.deepEqual(await values(page), addresses[1]);
            assert.equal(await page.locator('.address-region-label').textContent(), 'Illinois');
            await page.locator('#event_address_line_1').fill('Custom venue');
            const customLocation = await values(page);
            await button.click();
            assert.equal(await confirmation.isVisible(), true);
            assert.equal(await confirmation.getByRole('button', {name: 'Cancel', exact: true}).evaluate(element => element === document.activeElement), true);
            await confirmation.getByRole('button', {name: 'Cancel', exact: true}).click();
            assert.deepEqual(await values(page), customLocation, 'Cancel preserves unsaved event location edits');
            assert.equal(addressRequests, 1, 'Cancel does not request an address');
            await button.click();
            await page.keyboard.press('Escape');
            await confirmation.waitFor({state: 'hidden'});
            assert.deepEqual(await values(page), customLocation, 'Escape dismisses confirmation without replacing the location');
            assert.equal(addressRequests, 1);
            await organization.selectOption('2');
            assert.equal((await values(page))[0], 'Custom venue', 'Changing organizations preserves the event location');
            await copy();
            assert.deepEqual(await values(page), addresses[2], 'Canada switches the region control and clears absent address line 2');
            assert.equal(await page.locator('.address-region-label').textContent(), 'Ontario');
            assert.equal(await page.locator('[data-address-country-label]').textContent(), 'Canada');
            await organization.selectOption('3');
            await copy();
            assert.deepEqual(await values(page), addresses[3], 'Free-text regions are copied into the enabled state field');
            await organization.selectOption('4');
            await startCopy();
            await page.waitForFunction(() => document.getElementById('organization-address-status').textContent.includes('no saved physical address'));
            assert.deepEqual(await values(page), addresses[3], 'An empty organization address preserves the existing location');

            await organization.selectOption('1');
            failAddress = true;
            await startCopy();
            await page.waitForFunction(() => document.getElementById('organization-address-status').textContent.includes('could not be loaded'));
            assert.equal(await button.isEnabled(), true);
            assert.deepEqual(await values(page), addresses[3]);
            failAddress = false;
            await copy();
            assert.deepEqual(await values(page), addresses[1]);

            holdAddress = true;
            const loading = page.waitForRequest('**/organization_address.php?organization_id=1');
            await startCopy();
            await loading;
            await page.locator('#event_city').fill('User edit while loading');
            await pendingAddress.fulfill({json: addressPayload(1)});
            await page.waitForFunction(() => document.getElementById('organization-address-status').textContent.includes('changed while'));
            assert.equal((await values(page))[2], 'User edit while loading');

            const staleLoading = page.waitForRequest('**/organization_address.php?organization_id=1');
            await startCopy();
            await staleLoading;
            const staleRequest = pendingAddress;
            await organization.selectOption('2');
            await organization.selectOption('1');
            await staleRequest.fulfill({json: addressPayload(1)});
            assert.equal(await status.textContent(), '');
            assert.equal((await values(page))[2], 'User edit while loading', 'An obsolete request cannot overwrite the location after an organization round trip');
            holdAddress = false;

            await page.locator('[data-relationship-search-input]').fill('Search Result');
            await page.waitForFunction(() => document.querySelector('#organization_id option[value="6"]'));
            await organization.selectOption('6');
            await copy();
            assert.deepEqual(await values(page), addresses[6], 'Organizations found through search support the same copy action');
            await page.getByRole('button', {name: 'Save Draft', exact: true}).click();
            await page.reload();
            // Restore the searchable organization option before recovering its saved selection.
            await page.locator('[data-relationship-search-input]').fill('Search Result');
            await page.waitForFunction(() => document.querySelector('#organization_id option[value="6"]'));
            await page.getByRole('button', {name: 'Restore Draft', exact: true}).click();
            assert.deepEqual(await values(page), addresses[6], 'Copied location fields survive draft recovery');

            await page.goto('https://engagement.test/index.php?created_organization_id=2');
            assert.equal(await button.isVisible(), true, 'An organization preselected after creation exposes the action');
            await copy(false);
            assert.deepEqual(await values(page), addresses[2]);
            const bounds = await page.locator('.engagement-location-section').boundingBox();
            const action = await button.boundingBox();
            assert.ok(action.x >= bounds.x && action.x + action.width <= bounds.x + bounds.width, 'The copy action fits the location pane');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('New Engagement address copy passed desktop and mobile browser checks.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
