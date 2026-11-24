'use strict';

const assert = require('node:assert/strict');
const {execFileSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const {test} = require('node:test');
const {chromium} = require('playwright');

test('qualified browser workflows', {timeout: 120_000}, async t => {
    const base = new URL(process.env.DNR_TEST_BASE_URL);
    const project = process.env.DNR_INTEGRATION_PROJECT;
    if (base.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(base.hostname)
        || !/^dnr-test-[a-f0-9]{12}$/.test(project)) {
        throw new Error('Browser workflows require the disposable loopback integration environment.');
    }
    const suffix = randomBytes(6).toString('hex');
    const fixture = (script, action) => execFileSync('docker', [
        'exec', '-u', 'www-data', '-e', 'DNR_INTEGRATION_TEST=1', '-e', 'DNR_INTEGRATION_TARGET=disposable',
        '-e', 'DNR_TEST_SOURCE_DIR=/var/www/html', project + '-web-1',
        'php', '/opt/dnr/tests/' + script, action, suffix,
    ], {encoding: 'utf8', timeout: 20_000});
    fixture('http_fixture.php', 'setup');
    const data = JSON.parse(fixture('browser/release-workflows-fixture.php', 'seed'));
    const browser = await chromium.launch();
    try {
        const login = async role => {
            const context = await browser.newContext({baseURL: base.href});
            const page = await context.newPage();
            page.setDefaultTimeout(15_000);
            await page.goto('login.php');
            await page.locator('#username').fill('http-' + role + '-' + suffix);
            await page.locator('#password').fill('HttpTestPassword!123');
            await Promise.all([
                page.waitForURL('**/verify_2fa.php'),
                page.getByRole('button', {name: 'Sign In', exact: true}).click({noWaitAfter: true}),
            ]);
            await page.locator('#authentication_code').fill('ABCD-EFGH-JKLM');
            await page.getByRole('button', {name: 'Verify', exact: true}).click();
            await page.waitForURL('**/dashboard.php');
            return page;
        };

        const reviewer = await login('reviewer');
        assert.equal((await reviewer.goto('add_inquiry.php')).status(), 403);

        const editor = await login('editor');
        await editor.goto('add_inquiry.php');
        const title = 'Browser Engagement ' + suffix;
        await editor.locator('#inquiry-title').fill(title);
        await editor.locator('#inquiry-organization').selectOption(String(data.organization));
        await editor.locator('#inquiry-summary').fill('Initial booking request.');
        await editor.locator('#preferred-start-display').fill('07/17/2098');
        await editor.locator('#preferred-end-display').fill('07/18/2098');
        await editor.getByRole('button', {name: 'Create Inquiry', exact: true}).click();
        await editor.waitForURL('**/view_inquiry.php?id=*');
        const inquiryUrl = editor.url();
        await editor.getByRole('link', {name: 'Convert to Engagement'}).click();
        await editor.getByRole('button', {name: /^Book Engagement/}).click();
        await editor.getByRole('dialog').getByRole('button', {name: /^Book Engagement/}).click();
        await editor.waitForURL('**/view_engagement.php?id=*');
        const engagementUrl = new URL(editor.url());
        await editor.reload();
        await editor.getByRole('heading', {name: title, exact: true}).waitFor();
        await editor.goto(inquiryUrl);
        assert.equal(new URL(await editor.getByRole('link', {name: 'Open Engagement'}).getAttribute('href'), base).href, engagementUrl.href);

        t.diagnostic('Inquiry created, converted, and linked back to the booked source.');
        const editUrl = 'edit_engagement.php' + engagementUrl.search;
        await reviewer.goto(editUrl);
        assert.notEqual(new URL(reviewer.url()).pathname, '/edit_engagement.php');
        await editor.goto(editUrl);
        const updatedTitle = title + ' Restored';
        await editor.locator('#event_title').fill(updatedTitle);
        await editor.locator('.form-draft-panel > summary').click();
        await editor.getByRole('button', {name: 'Save Draft', exact: true}).click();
        await editor.reload();
        assert.equal(await editor.locator('#event_title').inputValue(), title);
        await editor.locator('.form-draft-panel > summary').click();
        await editor.getByRole('button', {name: 'Restore Draft', exact: true}).click();
        await editor.waitForFunction(() => document.querySelector('.form-draft-panel [role=status]').textContent.startsWith('Draft restored'));
        assert.equal(await editor.locator('#event_title').inputValue(), updatedTitle);
        await editor.getByRole('button', {name: 'Save Changes', exact: true}).click();
        await editor.waitForURL('**/engagements.php');
        await editor.goto(engagementUrl.href);
        await editor.getByRole('heading', {name: updatedTitle, exact: true}).waitFor();

        t.diagnostic('Engagement draft restored, saved, and reloaded as an editor.');
        const sendUrl = 'audience.php?view=campaign-send&id=' + data.campaign;
        await reviewer.goto(sendUrl);
        assert.equal(await reviewer.locator('[data-campaign-dispatch-submit]:enabled').count(), 0);
        await editor.goto(sendUrl);
        const reviewError = editor.locator('.campaign-send-review [role=alert]');
        if (await reviewError.count()) throw new Error(await reviewError.innerText());
        await editor.getByRole('link', {name: 'Preview Your Recipients'}).click();
        await editor.locator('#campaign-send-recipients').getByText(data.recipient, {exact: true}).waitFor();
        await editor.getByRole('button', {name: 'Close Recipient Preview'}).click();
        await editor.getByRole('button', {name: 'Send Now', exact: true}).click();
        await editor.locator('#campaign-send-confirm').getByRole('button', {name: 'Send Now', exact: true}).click();
        await editor.waitForURL('**/audience.php?view=campaign-delivery&id=' + data.campaign);
        await editor.reload();
        await editor.getByRole('heading', {name: 'Your Email Is on Its Way', exact: true}).waitFor();
        fixture('browser/release-workflows-fixture.php', 'dispatch');
        await editor.reload();
        await editor.getByRole('heading', {name: 'Email Processing Complete', exact: true}).waitFor();
        t.diagnostic('Campaign reviewed, queued, and completed by the simulated provider.');
    } finally {
        await browser.close();
    }
});
