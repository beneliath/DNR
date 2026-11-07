'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync('php', [path.join(__dirname, 'edit-engagement-fixture.php'), ...args], {encoding: 'utf8'});
const html = fixture();

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
                if (pathname === '/edit_engagement.php') return route.fulfill({contentType: 'text/html', body: html});
                const asset = path.join(root, 'src', pathname);
                return pathname.startsWith('/assets/') && fs.existsSync(asset) ? route.fulfill({path: asset}) : route.abort();
            });
            await verifyForm(page, width);
            await page.close();
        }
        console.log('Edit Engagement desktop and mobile browser checks passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
