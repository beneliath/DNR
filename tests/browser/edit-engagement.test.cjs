'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync('php', [path.join(__dirname, 'edit-engagement-fixture.php'), ...args], {encoding: 'utf8'});
const html = fixture();
const draftHtml = fixture('draft');
const newDraftHtml = execFileSync('php', [path.join(__dirname, 'new-engagement-draft-fixture.php')], {encoding: 'utf8'});

async function waitForDependency(promise) {
    let timeout;
    try {
        return await Promise.race([promise, new Promise((_, reject) => {
            timeout = setTimeout(() => reject(new Error('Draft dependency request did not start')), 10000);
        })]);
    } finally { clearTimeout(timeout); }
}

async function verifyPresentationDraft(page) {
    await page.goto('https://engagement.test/edit_engagement.php?draft');
    const time = page.locator('#presentation_time_1');
    const duplicate = page.getByRole('button', {name: 'Duplicate Presentation 1', exact: true});
    await time.fill('07:30');
    await duplicate.click();
    await page.locator('#presentation_time_2').fill('08:45');
    await page.locator('[name=presentation_ampm_2][value=AM]').check();
    await page.locator('.form-draft-panel > summary').click();
    await page.getByRole('button', {name: 'Save Draft', exact: true}).click();

    await page.reload();
    assert.equal(await time.inputValue(), '06:00');
    await duplicate.click(); // Recreate the unsaved row before restoring its fields.
    await page.locator('.form-draft-panel > summary').click();
    await page.getByRole('button', {name: 'Restore Draft', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('.form-draft-panel [role=status]').textContent.startsWith('Draft restored'));
    assert.equal(await time.inputValue(), '07:30');
    assert.equal(await page.locator('#presentation_time_2').inputValue(), '08:45');
    await page.waitForFunction(() => getComputedStyle(document.getElementById('presentation_time_1')).outlineWidth === '2px');
    const restoredStyle = await time.evaluate(el => ({outline: getComputedStyle(el).outlineColor, width: getComputedStyle(el).outlineWidth}));
    assert.ok(['rgb(154, 91, 5)', 'rgb(245, 189, 104)'].includes(restoredStyle.outline), 'Restored changes use the gold highlight in either theme: ' + JSON.stringify(restoredStyle));
    assert.equal(restoredStyle.width, '2px');
    assert.equal(await page.getByRole('button', {name: 'Restore Draft', exact: true}).evaluate(el => getComputedStyle(el).outlineColor), restoredStyle.outline);
    assert.equal(await page.locator('#event_title').getAttribute('data-draft-restored'), null, 'Unchanged values are not highlighted');
    const times = await page.locator('#engagement-edit-form').evaluate(form => {
        const data = new FormData(form);
        return [1, 2].map(id => data.get('presentations[' + id + '][presentation_time]'));
    });
    assert.deepEqual(times, ['07:30 PM', '08:45 AM']);
}

async function verifyOrganizationAddress(page) {
    const fields = ['event_address_line_1', 'event_address_line_2', 'event_city', 'event_state', 'event_zipcode', 'event_country'];
    const addresses = {
        1: ['123 Main Street', 'Suite 2', 'Chicago', 'IL', '60601', 'US'],
        2: ['456 King Street', '', 'Toronto', 'ON', 'M5V 2T6', 'CA'],
    };
    await page.route('**/organization_address.php?*', route => {
        const id = new URL(route.request().url()).searchParams.get('organization_id');
        return route.fulfill({json: {address: Object.fromEntries(fields.map((field, index) => [field, addresses[id][index]]))}});
    });
    await page.goto('https://engagement.test/edit_engagement.php?draft');
    const values = () => page.locator('#engagement-edit-form').evaluate((form, names) => {
        const data = new FormData(form);
        return names.map(name => data.get(name));
    }, fields);
    const button = page.getByRole('button', {name: 'Use Organization Address', exact: true});
    const confirmation = page.getByRole('dialog', {name: 'Replace Event Location?', exact: true});
    const save = page.getByRole('button', {name: 'Save Changes', exact: true});
    const copy = async () => {
        await button.click();
        assert.equal(await confirmation.isVisible(), true);
        await confirmation.getByRole('button', {name: 'Replace Event Location', exact: true}).click();
        await page.waitForFunction(() => document.getElementById('organization-address-status').textContent.startsWith('Organization address copied.'));
    };
    assert.equal(await button.isVisible(), true, 'The saved organization exposes its address action on load');
    assert.deepEqual(await values(), ['', '', 'Washington', 'DC', '', 'US'], 'The saved event location stays intact until the user requests a copy');
    assert.equal(await save.getAttribute('data-unsaved-changes'), null);
    await button.click();
    assert.equal(await confirmation.isVisible(), true, 'Replacing an existing saved location requires confirmation');
    await confirmation.getByRole('button', {name: 'Cancel', exact: true}).click();
    assert.deepEqual(await values(), ['', '', 'Washington', 'DC', '', 'US'], 'Cancel preserves the saved event location');
    assert.equal(await save.getAttribute('data-unsaved-changes'), null, 'Cancel leaves the edit form clean');
    await copy();
    assert.deepEqual(await values(), addresses[1]);
    assert.equal(await page.locator('.address-region-label').textContent(), 'Illinois');
    await page.waitForFunction(() => document.querySelector('[name=save_engagement]').hasAttribute('data-unsaved-changes'));
    await page.locator('#event_city').fill('Custom event city');
    await page.locator('#organization_id').selectOption('2');
    assert.equal((await values())[2], 'Custom event city', 'Selecting another organization preserves manual location edits');
    await copy();
    assert.deepEqual(await values(), addresses[2], 'Copying replaces all six saved fields, including an empty second address line');
    assert.equal(await page.locator('.address-region-label').textContent(), 'Ontario');
    assert.equal(await page.locator('[data-address-country-label]').textContent(), 'Canada');
    const pane = await page.locator('.engagement-location-section').boundingBox();
    const action = await button.boundingBox();
    assert.ok(action.x >= pane.x && action.x + action.width <= pane.x + pane.width, 'The address action fits the edit pane');
    await page.locator('.form-draft-panel > summary').click();
    await page.getByRole('button', {name: 'Save Draft', exact: true}).click();
    await page.reload();
    await page.locator('.form-draft-panel > summary').click();
    await page.getByRole('button', {name: 'Restore Draft', exact: true}).click();
    await page.waitForFunction(() => document.querySelector('.form-draft-panel [role=status]').textContent.startsWith('Draft restored'));
    assert.deepEqual(await values(), addresses[2], 'Copied event locations survive edit draft recovery');
    assert.equal(await page.locator('.address-region-trigger').getAttribute('data-draft-restored'), '', 'Restored regions highlight their visible picker');
    assert.equal(await page.locator('[data-address-country-toggle]').getAttribute('data-draft-restored'), '', 'Restored countries highlight their visible picker');
}

async function verifyDelayedDraftDependencies(page, url) {
    const contacts = {contacts: [{id: 202, name: 'Draft Contact'}], roles: {primary_host: 'Primary Host', billing: 'Billing'}};
    const replacements = {engagements: [{id: 99, label: 'Replacement Event', lifecycle: 'Scheduled'}]};
    let delay = false, contactStarted, replacementStarted;
    const contactRequest = new Promise(resolve => { contactStarted = resolve; });
    const replacementRequest = new Promise(resolve => { replacementStarted = resolve; });
    await page.route('**/organization_contacts.php?*', route => delay ? contactStarted(route) : route.fulfill({json: contacts}));
    await page.route('**/engagement_reschedule_options.php?*', route => delay ? replacementStarted(route) : route.fulfill({json: replacements}));
    await page.goto(url);
    await page.locator('#lifecycle_status').selectOption('postponed');
    await page.locator('#organization_id').selectOption('2');
    const role = page.locator('input[name="engagement_contacts[202][]"][value="primary_host"]');
    await role.check();
    await page.locator('#rescheduled_to_engagement_id option[value="99"]').waitFor({state: 'attached'});
    await page.locator('#rescheduled_to_engagement_id').selectOption('99');
    await page.locator('.form-draft-panel > summary').click();
    await page.getByRole('button', {name: 'Save Draft', exact: true}).click();
    await page.reload();
    delay = true;
    await page.locator('.form-draft-panel > summary').click();
    const restore = page.getByRole('button', {name: 'Restore Draft', exact: true});
    await restore.click();
    const [contactRoute, replacementRoute] = await waitForDependency(Promise.all([contactRequest, replacementRequest]));
    assert.equal(await restore.isDisabled(), true);
    assert.equal(await page.locator('.form-draft-panel [role=status]').textContent(), 'Restoring draft…');
    assert.equal(await page.locator('.form-draft-recovery').isVisible(), false, 'Pending fields are not reported as missing');
    await contactRoute.fulfill({json: contacts});
    await role.waitFor();
    assert.equal(await restore.isDisabled(), true, 'Restoration also waits for replacement-event options');
    await replacementRoute.fulfill({json: replacements});
    await page.waitForFunction(() => document.querySelector('.form-draft-panel [role=status]').textContent.startsWith('Draft restored.'));
    assert.equal(await role.isChecked(), true);
    assert.equal(await role.getAttribute('data-draft-restored'), '');
    assert.equal(await page.locator('input[name="engagement_contacts[202][]"][value="billing"]').getAttribute('data-draft-restored'), null);
    assert.equal(await page.locator('#rescheduled_to_engagement_id').inputValue(), '99');
    assert.equal(await page.locator('.form-draft-recovery').isVisible(), false);
    delay = false;
    await page.locator('#organization_id').evaluate(select => {
        select.value = '';
        select.dispatchEvent(new Event('change', {bubbles: true}));
    });
    assert.equal(await page.locator('#rescheduled_to_engagement_id option[value="99"]').count(), 0);
    await page.locator('#organization_id').selectOption('2');
    await page.locator('#rescheduled_to_engagement_id option[value="99"]').waitFor({state: 'attached'});
    await page.reload();
    // Search-result lists may not contain the saved organization until the current lookup finishes.
    await page.locator('#organization_id option[value="2"]').evaluate(option => option.remove());
    let searchStarted;
    const searchRequest = new Promise(resolve => { searchStarted = resolve; });
    await page.route('**/inquiry_relationship_search.php?*', route => searchStarted(route));
    await page.locator('[data-relationship-search-input]').first().fill('Draft organization');
    await page.locator('.form-draft-panel > summary').click();
    await restore.click();
    const searchRoute = await waitForDependency(searchRequest);
    assert.equal(await restore.isDisabled(), true, 'A pending organization search participates in draft readiness');
    await searchRoute.fulfill({json: {results: [{id: 2, organization_name: 'Draft organization'}], has_more: false}});
    await page.waitForFunction(() => document.querySelector('.form-draft-panel [role=status]').textContent.startsWith('Draft restored.'));
    assert.equal(await page.locator('#organization_id').inputValue(), '2');
    assert.equal(await role.isChecked(), true);
}

async function verifyForm(page, width) {
    await page.setViewportSize({width, height: 800});
    await page.goto('https://engagement.test/edit_engagement.php');
    const save = page.getByRole('button', {name: 'Save Changes', exact: true});
    const expectDirty = changed => page.waitForFunction(expected =>
        document.querySelector('[name=save_engagement]').hasAttribute('data-unsaved-changes') === expected, changed);
    const cards = page.locator('#presentation-1 .presentation-notes-card');
    const pdf = cards.first().locator('input[type=file]');
    const notice = cards.first().locator('[data-file-drop] [data-file-save-notice]');

    assert.equal(await page.locator('[data-lifecycle-badge]').count(), 0);
    assert.equal(await page.locator('.form-draft-panel').getAttribute('open'), null);
    assert.equal(await page.getByRole('button', {name: 'Save Draft', exact: true}).isVisible(), false);
    const searchStatus = page.locator('[data-relationship-search-status]');
    for (const text of ['', '1 matching organization.']) {
        await searchStatus.evaluate((el, value) => { el.textContent = value; }, text);
        const sides = await page.locator('#organization_id, #event_title, #event_description')
            .evaluateAll(fields => fields.map(field => field.getBoundingClientRect().right));
        assert.ok(Math.max(...sides) - Math.min(...sides) < 1, 'Event details fields share a right edge');
    }
    assert.equal(await page.locator('.required-fields-note').textContent(), '*Required Field');
    assert.equal(await page.locator('[data-sticky-toolbar] .engagement-schedule-row').count(), 1);
    assert.equal(await page.locator('.engagement-schedule-row label').first().textContent(), '*Start');
    assert.equal(await page.locator('#event_start_date').inputValue(), '2026-11-18');
    assert.equal(await page.locator('#event_end_date').inputValue(), '2026-11-19');
    assert.equal(await page.locator('#event_type').inputValue(), 'study or teaching');
    assert.equal(await page.locator('label[for="event_title"]').textContent(), '*Event Title');
    assert.equal(await page.locator('#engagement-contact-selector #caller_user_id').inputValue(), '7');
    assert.equal(await page.locator('.address-region-label').textContent(), 'District of Columbia');
    const addressRows = await page.locator('.engagement-location-section input[id], .address-region-trigger, .address-country-trigger')
        .evaluateAll(fields => fields.filter(field => !field.hidden).map(field => field.getBoundingClientRect().top));
    if (width > 1000) {
        assert.equal(new Set(addressRows).size, 3, 'Location has three paired rows');
    } else {
        assert.equal(new Set(addressRows).size, 6, 'Location stacks in a narrow pane');
    }
    const chronPane = await page.locator('#chron-log').boundingBox();
    const formPane = await page.locator('.engagement-primary-row').boundingBox();
    assert.ok(Math.abs(chronPane.width - formPane.width) < 1 && Math.abs(chronPane.x - formPane.x) < 1,
        'Chron Log spans the full edit form content width');
    const locationPane = await page.locator('.engagement-location-section').boundingBox();
    const presentationPane = await page.locator('#presentations-container').boundingBox();
    const chronGap = chronPane.y - presentationPane.y - presentationPane.height;
    const presentationGap = presentationPane.y - locationPane.y - locationPane.height;
    assert.ok(Math.abs(chronGap - presentationGap) < 1, 'Chron Log uses the same gap as the other panes');
    await page.locator('#new-chron-entry').scrollIntoViewIfNeeded();
    await page.waitForFunction(() => document.querySelector('[data-sticky-toolbar]').classList.contains('is-stuck'));
    assert.ok(await page.locator('[data-sticky-toolbar]').evaluate(node =>
        getComputedStyle(node).position === 'sticky' && node.getBoundingClientRect().top >= 0
            && node.getBoundingClientRect().bottom <= innerHeight),
        'The lifecycle, schedule, Cancel, and Save Changes remain visible while editing Chron entries');
    assert.equal(await page.locator('[data-sticky-toolbar] [name="save_engagement"]').count(), 1);
    assert.equal(await page.locator('.app-footer').count(), 0, 'Edit Engagement omits the site footer');
    assert.equal(await page.locator('#delete-confirmation').count(), 1, 'Record confirmation dialogs remain available');
    assert.equal(await save.evaluate(button => button.form.id), 'engagement-edit-form');
    assert.equal(await page.locator('[name="chron_entries[8]"]').inputValue(), 'Saved Chron entry');
    assert.equal(await page.locator('.chron-entry-card + .chron-entry-card').evaluate(card =>
        getComputedStyle(card, '::before').content), 'none', 'Saved Chron entries have no divider');
    assert.equal(await page.locator('#presentation-1 .presentation-entry-banner').evaluate(banner =>
        getComputedStyle(banner).borderBottomWidth), '0px', 'The presentation banner has no divider');
    assert.equal(await page.locator('#presentation-1 .presentation-assets').evaluate(assets =>
        getComputedStyle(assets).borderTopWidth), '0px', 'Presentation files have no divider');

    assert.equal(await save.evaluate(el => getComputedStyle(el).outlineStyle), 'none');
    await pdf.setInputFiles({name: 'speaker-notes.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-')});
    await expectDirty(true);
    assert.equal(await notice.textContent(), 'Select Save Changes to upload this file.');
    const colors = [];
    for (const dark of [false, true]) {
        await page.evaluate(enabled => document.documentElement.classList.toggle('dark-mode', enabled), dark);
        await page.waitForFunction(() => {
            const el = document.querySelector('[data-file-save-notice]:not([hidden])');
            const drop = el.parentElement.querySelector('[data-file-drop-button]');
            return getComputedStyle(el).backgroundColor === getComputedStyle(drop).backgroundColor;
        });
        colors.push(await notice.evaluate(el => {
            const style = getComputedStyle(el);
            const drop = el.parentElement.querySelector('[data-file-drop-button]');
            const card = el.closest('.presentation-notes-card');
            return [style.backgroundColor, getComputedStyle(drop).backgroundColor,
                style.borderLeftColor, getComputedStyle(card).borderTopColor];
        }));
    }
    for (const [noticeBg, dropBg, accent, cardBorder] of colors) {
        assert.equal(noticeBg, dropBg);
        assert.equal(accent, cardBorder);
    }
    assert.notEqual(colors[0][0], colors[1][0]);
    assert.equal(await cards.first().getByText('speaker-notes.pdf', {exact: true}).count(), 1);
    const [pdfCard, pptCard] = await Promise.all((await cards.all()).map(card => card.boundingBox()));
    const pane = await page.locator('#presentation-1 .presentation-upload-cards').boundingBox();
    assert.equal(pdfCard.width, pptCard.width);
    assert.equal(pdfCard.x, pane.x);
    assert.equal(pptCard.x + pptCard.width, pane.x + pane.width);
    assert.equal(pdfCard.y === pptCard.y, width > 800);
    for (const card of await cards.all()) {
        const box = await card.boundingBox();
        const drop = await card.locator('[data-file-drop-button]').boundingBox();
        assert.equal(drop.y - box.y, 16);
        assert.equal(box.x + box.width - drop.x - drop.width, 16);
    }
    const outline = await save.evaluate(el => {
        const ring = getComputedStyle(el, '::after');
        return [ring.animationName, ring.outlineColor, getComputedStyle(el).backgroundColor];
    });
    assert.equal(outline[0], 'engagement-save-outline');
    assert.equal(outline[1], outline[2]);
    await page.emulateMedia({reducedMotion: 'reduce'});
    assert.equal(await save.evaluate(el => getComputedStyle(el, '::after').animationName), 'none');
    await pdf.setInputFiles([]);
    await expectDirty(false);
    assert.equal(await notice.isVisible(), false);
    const chron = page.locator('[name=new_chron_entry]');
    await chron.fill('An external form field');
    await expectDirty(true);
    await chron.fill('');
    await expectDirty(false);
    await page.getByRole('button', {name: 'Add Presentation'}).click();
    await expectDirty(true);
    const newDeck = page.locator('#presentation-2 [data-ppt-slidedeck-pane]');
    await newDeck.locator('input[type=file]').setInputFiles({
        name: 'slides.pptx', mimeType: 'application/octet-stream', buffer: Buffer.from('slides'),
    });
    assert.equal(await newDeck.locator('[data-file-drop] [data-file-save-notice]').textContent(), 'Select Save Changes to upload this file.');
    assert.equal(await newDeck.getByText('slides.pptx', {exact: true}).count(), 1);
    await page.locator('[data-remove-presentation="2"]').click();
    await expectDirty(false);

    const source = page.locator('#presentation-1');
    const duplicate = source.getByRole('button', {name: 'Duplicate Presentation 1', exact: true});
    const bannerLayout = await duplicate.evaluate(button => {
        const banner = button.closest('.presentation-entry-banner');
        const strip = banner.getBoundingClientRect();
        const control = button.getBoundingClientRect();
        const heading = banner.querySelector('h3').getBoundingClientRect();
        const style = getComputedStyle(banner);
        return {background: style.backgroundColor, rightGap: strip.right - control.right,
            padding: parseFloat(style.paddingRight), inside: control.top >= strip.top && control.bottom <= strip.bottom,
            sameRow: heading.right <= control.left && heading.bottom >= control.top && heading.top <= control.bottom};
    });
    assert.notEqual(bannerLayout.background, 'rgba(0, 0, 0, 0)');
    assert.ok(bannerLayout.inside && bannerLayout.sameRow && Math.abs(bannerLayout.rightGap - bannerLayout.padding) < 1,
        'Duplicate is on the right inside the colored banner at this viewport');
    await pdf.setInputFiles({name: 'shared-notes.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-')});
    await duplicate.click();
    const copy = page.locator('#presentation-3');
    assert.equal(await copy.locator('[name$="[topic_title]"]').inputValue(), 'Opening session');
    assert.equal(await copy.locator('[name$="[presentation_time]"]').inputValue(), '06:00 PM');
    assert.equal(await copy.locator('input[name$="[id]"]').count(), 0);
    assert.equal(await copy.locator('input[type=file]').first().evaluate(el => el.files[0].name), 'shared-notes.pdf');
    await copy.locator('[name$="[topic_title]"]').fill('Afternoon session');
    await copy.getByRole('button', {name: 'Duplicate Presentation 2'}).click();
    assert.equal(await page.locator('#presentation_topic_4').inputValue(), 'Afternoon session');
    assert.equal(await page.locator('#presentation_topic_1').inputValue(), 'Opening session');
    const uploads = await page.locator('#engagement-edit-form').evaluate(form => {
        form.dispatchEvent(new Event('submit', {cancelable: true}));
        const data = new FormData(form);
        return [data.get('presentations[1][speaker_notes]').name, data.has('presentations[3][speaker_notes]'),
            data.get('presentations[3][upload_from][speaker_notes]'), data.get('presentations[4][upload_from][speaker_notes]')];
    });
    assert.deepEqual(uploads, ['shared-notes.pdf', false, '1', '1']);
    await page.locator('[data-remove-presentation="4"]').click();
    await page.locator('[data-remove-presentation="3"]').click();
    await pdf.setInputFiles([]);
    await expectDirty(false);

    const time = page.locator('#presentation_time_1');
    await time.click();
    await page.keyboard.press('Tab');
    const period = page.locator('input[name=presentation_ampm_1]:focus');
    assert.equal(await period.evaluate(el => getComputedStyle(el).outlineStyle), 'solid');
    await period.click();
    assert.equal(await period.evaluate(el => getComputedStyle(el).outlineStyle), 'none');

    const scrollPosition = value => {
        const scroller = getComputedStyle(document.body).position === 'fixed' ? document.body : document.scrollingElement;
        if (value !== null) scroller.scrollTo({top: value, behavior: 'instant'});
        return scroller.scrollTop;
    };
    const top = await page.evaluate(scrollPosition, 320);
    assert.equal(top, 320);
    await page.reload();
    await page.waitForFunction(() => !document.documentElement.hasAttribute('data-engagement-restoring'));
    assert.equal(await page.evaluate(scrollPosition, null), top);

    const baseline = await page.locator('[name=edit_baseline]').inputValue();
    assert.match(baseline, /^[a-f0-9]{64}$/);
    await page.route('**/edit_engagement.php?retry', route => route.fulfill({contentType: 'text/html', body: fixture(baseline)}));
    await page.goto('https://engagement.test/edit_engagement.php?retry');
    await expectDirty(true);
    await page.locator('#presentation_topic_1').fill('Opening session');
    await expectDirty(false);
}

(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage();
            await page.route('https://engagement.test/**', route => {
                const pathname = new URL(route.request().url()).pathname;
                if (pathname === '/edit_engagement.php') return route.fulfill({contentType: 'text/html',
                    body: new URL(route.request().url()).search === '?draft' ? draftHtml : html});
                if (pathname === '/index.php') return route.fulfill({contentType: 'text/html', body: newDraftHtml});
                const asset = path.join(root, 'src', pathname);
                return pathname.startsWith('/assets/') && fs.existsSync(asset) ? route.fulfill({path: asset}) : route.abort();
            });
            await verifyForm(page, width);
            await verifyPresentationDraft(page);
            await verifyOrganizationAddress(page);
            await verifyDelayedDraftDependencies(page, 'https://engagement.test/edit_engagement.php?draft');
            await verifyDelayedDraftDependencies(page, 'https://engagement.test/index.php');
            await page.close();
        }
        console.log('Edit Engagement desktop and mobile browser checks passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
