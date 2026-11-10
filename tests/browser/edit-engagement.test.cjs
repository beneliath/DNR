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

async function verifyPresentationDraft(page) {
    await page.goto('https://engagement.test/edit_engagement.php?draft');
    const time = page.locator('#presentation_time_1');
    const duplicate = page.getByRole('button', {name: 'Duplicate Presentation 1', exact: true});
    await time.fill('07:30');
    await duplicate.click();
    await page.locator('#presentation_time_2').fill('08:45');
    await page.locator('[name=presentation_ampm_2][value=AM]').check();
    await page.getByRole('button', {name: 'Save Draft', exact: true}).click();

    await page.reload();
    assert.equal(await time.inputValue(), '06:00');
    await duplicate.click(); // Recreate the unsaved row before restoring its fields.
    await page.getByRole('button', {name: 'Restore Draft', exact: true}).click();
    assert.equal(await time.inputValue(), '07:30');
    assert.equal(await page.locator('#presentation_time_2').inputValue(), '08:45');
    const times = await page.locator('#engagement-edit-form').evaluate(form => {
        const data = new FormData(form);
        return [1, 2].map(id => data.get('presentations[' + id + '][presentation_time]'));
    });
    assert.deepEqual(times, ['07:30 PM', '08:45 AM']);
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

    const badge = page.locator('[data-lifecycle-badge]');
    assert.equal(await badge.evaluate(el => getComputedStyle(el).fontSize), '14px');
    const badgeBox = await badge.boundingBox();
    const stateBox = await page.locator('#lifecycle_status').boundingBox();
    assert.ok(Math.abs((badgeBox.y + badgeBox.height / 2) - (stateBox.y + stateBox.height / 2)) < 1,
        'The lifecycle badge is centered on the dropdown control');
    const searchStatus = page.locator('[data-relationship-search-status]');
    for (const text of ['', '1 matching organization.']) {
        await searchStatus.evaluate((el, value) => { el.textContent = value; }, text);
        const sides = await page.locator('#organization_id, #caller_user_id, #event_type, #event_description')
            .evaluateAll(fields => fields.map(field => field.getBoundingClientRect().right));
        assert.ok(Math.max(...sides) - Math.min(...sides) < 1, 'Event details fields share a right edge');
    }

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
                const asset = path.join(root, 'src', pathname);
                return pathname.startsWith('/assets/') && fs.existsSync(asset) ? route.fulfill({path: asset}) : route.abort();
            });
            await verifyForm(page, width);
            await verifyPresentationDraft(page);
            await page.close();
        }
        console.log('Edit Engagement desktop and mobile browser checks passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
