'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const src = path.resolve(__dirname, '../../src');
const origin = 'https://directory-drafts.test/';

(async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route(origin + '**', route => {
            const url = new URL(route.request().url());
            const name = url.pathname.slice(1);
            if (name.startsWith('assets/')) return route.fulfill({path: path.join(src, name)});
            if (name === 'inquiry_relationship_search.php') {
                const id = Number(url.searchParams.get('selected_id') || url.searchParams.get('q'));
                const selection = id ? {id, contact_first_name: 'Draft', contact_last_name: 'Contact ' + id,
                    organization_name: 'Organization ' + id} : null;
                return route.fulfill({json: {results: selection ? [selection] : [], selected: selection, has_more: false}});
            }
            if (name === 'contact_photo.php') return route.fulfill({status: 404});
            const html = execFileSync('php', [path.join(__dirname, 'directory-draft-fixture.php'), name,
                url.searchParams.get('version') || '1', url.searchParams.get('id') || '0'], {encoding: 'utf8'});
            return route.fulfill({contentType: 'text/html', body: html});
        });

        const panel = page.locator('[data-form-draft-panel]');
        const named = name => page.locator(`[name="${name}"]`);
        const action = name => page.getByRole('button', {name, exact: true});
        const waitForStatus = prefix => panel.locator('[role=status]').filter({hasText: prefix}).waitFor({state: 'attached'});
        const save = async () => {
            await panel.locator('summary').click();
            await action('Save Draft').click();
            await waitForStatus('Draft saved in this browser');
        };
        const restore = async () => {
            await page.reload();
            await panel.locator('summary').click();
            await action('Restore Draft').click();
            await waitForStatus('Draft restored.');
        };
        const chooseRelated = async (input, select, id) => {
            await input.fill(String(id));
            await select.selectOption(String(id));
        };

        await page.goto(origin + 'add_organization.php');
        await named('organization_name').fill('Draft organization');
        const existing = page.locator('[data-existing-organization-contact-rows]');
        await action('Add Another Existing Contact').click();
        await chooseRelated(existing.locator('[data-relationship-search-input]').last(), named('existing_contacts[1][contact_id]'), 72);
        await named('existing_contacts[1][role_title]').fill('Board member');
        await action('Add Another Contact').click();
        await action('Add Another Contact').click();
        await page.locator('[data-remove-contact]').first().click();
        await named('contacts[2][first_name]').fill('New contact draft');
        const phonePicker = page.locator('[data-phone-country-picker]').first();
        await phonePicker.locator('[data-phone-country-toggle]').click();
        await phonePicker.locator('[data-country-code="+44"]').first().click();
        await save();
        await restore();
        assert.equal(await named('organization_name').inputValue(), 'Draft organization');
        assert.equal(await named('existing_contacts[1][contact_id]').inputValue(), '72');
        assert.equal(await named('existing_contacts[1][role_title]').inputValue(), 'Board member');
        assert.equal(await named('contacts[1][first_name]').count(), 0);
        assert.equal(await named('contacts[2][first_name]').inputValue(), 'New contact draft');
        assert.equal(await phonePicker.locator('[data-phone-country-dial-code]').textContent(), '+44');

        await page.goto(origin + 'edit_organization.php?id=7');
        await named('new_chron_entry').fill('Unsaved Chron entry');
        await waitForStatus('Draft saved in this browser');
        await restore();
        assert.equal(await named('new_chron_entry').inputValue(), 'Unsaved Chron entry');
        await page.goto(origin + 'edit_organization.php?id=7&version=2');
        await waitForStatus('The record changed');
        await panel.locator('summary').click();
        assert.equal(await action('Restore Draft').isDisabled(), true);
        await action('Restore New Chron Log Entry').click();
        assert.equal(await named('new_chron_entry').inputValue(), 'Unsaved Chron entry');
        assert.equal(await named('organization_version').inputValue(), '2');
        await page.goto(origin + 'edit_organization.php?id=9');
        await waitForStatus('No saved draft');

        for (const route of ['add_contact.php', 'edit_contact.php?id=7']) {
            await page.goto(origin + route);
            await named('contact_first_name').fill('Draft contact');
            const affiliations = page.locator('[data-affiliation-rows]');
            await action('Add Another Organization').click();
            await chooseRelated(affiliations.locator('[data-relationship-search-input]').last(), named('additional_organizations[1][organization_id]'), 92);
            await named('additional_organizations[1][role_title]').fill('Volunteer');
            await save();
            await restore();
            assert.equal(await named('contact_first_name').inputValue(), 'Draft contact');
            assert.equal(await named('additional_organizations[1][organization_id]').inputValue(), '92');
            assert.equal(await named('additional_organizations[1][role_title]').inputValue(), 'Volunteer');
        }

        await page.goto(origin + 'edit_speaker.php');
        await named('name').fill('Draft speaker');
        await action('Add Custom Link').click();
        await named('custom_links[1][label]').fill('New link');
        await named('custom_links[1][url]').fill('https://example.test/new');
        await save();
        await restore();
        assert.equal(await named('name').inputValue(), 'Draft speaker');
        assert.equal(await named('custom_links[1][url]').inputValue(), 'https://example.test/new');

        await page.goto(origin + 'edit_speaker.php?id=7');
        await action('Remove First link').click();
        await waitForStatus('Draft saved in this browser');
        await restore();
        assert.equal(await page.locator('[data-custom-link-row]').count(), 1);
        assert.equal(await named('custom_links[1][key]').inputValue(), 'kept-key');
        assert.equal(await named('custom_links[1][label]').inputValue(), 'Second link');
        await action('Discard Draft').click();
        await page.reload();
        await waitForStatus('No saved draft');
        assert.deepEqual(errors, []);
        console.log('Directory drafts restored contact rows, affiliations, Chron text, phone codes and speaker links.');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
