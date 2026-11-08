'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require(process.env.DNR_PLAYWRIGHT_MODULE || 'playwright');
const root = path.resolve(__dirname, '../..');
const escapeHtml = value => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');

function fixture(post, savedName, filters) {
    const head = execFileSync('php', ['-r', `
        require 'src/functions.php';
        $_POST = json_decode($argv[1], true);
        renderPageHead('Pending changes', ['styles'=>['assets/css/style.min.css','assets/css/modern.min.css']]);
    `, JSON.stringify(post || {})], {cwd: root, encoding: 'utf8'});
    const name = post?.name ?? savedName;
    const priority = post?.priority ?? 'normal';
    const active = post ? post.active === '1' : true;
    const dialogPost = post && JSON.parse(post._form_reminder).key.endsWith(':dialog');
    return `<!doctype html><html>${head}<body class="has-app-shell">
        <aside class="app-sidebar"><form method="get" action="/edit.php" class="shell-search">
            <input name="q" type="search" aria-label="Sidebar search">
            <button class="button-secondary" data-pending-reminder="shell-search" aria-label="Search sidebar">⌕</button>
        </form></aside>
        <main class="container">
            ${post ? '<p class="error" role="alert">The record could not be saved.</p>' : ''}
            <form method="post" action="/edit.php" id="record">
                <input type="hidden" name="csrf_token" value="token"><input type="hidden" name="version" value="1">
                <label>Name<input name="name" required value="${escapeHtml(name)}"></label>
                <label>Priority<select name="priority"><option value="normal"${priority === 'normal' ? ' selected' : ''}>Normal</option><option value="high"${priority === 'high' ? ' selected' : ''}>High</option></select></label>
                <label><input name="active" type="checkbox" value="1"${active ? ' checked' : ''}>Active</label>
                <label>Note<textarea name="note">${escapeHtml(post?.note ?? 'Saved note')}</textarea></label>
                <label>Find a record<input name="lookup" data-pending-ignore></label>
                <label>Attachment<input name="attachment" type="file"></label>
                <fieldset disabled><input name="locked" value="Locked"></fieldset>
                <button type="reset">Reset</button>
            </form>
            <button id="save" form="record" type="submit" name="save_record" value="1" data-pending-reminder="record">Save</button>
            <form method="get" action="/edit.php" id="filters">
                <label>Search<input type="search" name="q" value="${escapeHtml(filters.get('q') || '')}"></label>
                <label>Order<select name="sort"><option value="newest"${filters.get('sort') !== 'oldest' ? ' selected' : ''}>Newest</option><option value="oldest"${filters.get('sort') === 'oldest' ? ' selected' : ''}>Oldest</option></select></label>
                <button id="apply" data-pending-reminder="filters">Search</button>
            </form>
            <dialog id="editor"><form method="post"><input type="hidden" name="id" value="${dialogPost ? escapeHtml(post.id) : '0'}">
                <label>Dialog name<input name="name" value="${dialogPost ? escapeHtml(post.name) : 'Initial'}"></label>
                <button id="dialog-save" data-pending-reminder="dialog">Save changes</button>
                <button type="button" id="cancel-dialog">Cancel</button>
            </form></dialog>
        </main></body></html>`;
}

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 1000}});
        page.setDefaultTimeout(8000);
        const errors = [], posts = [];
        let failSave = true, savedName = 'Original';
        page.on('pageerror', error => errors.push(error.message));
        await page.route('https://reminders.test/**', route => {
            const request = route.request(), url = new URL(request.url());
            if (url.pathname === '/edit.php') {
                if (request.method() === 'POST') {
                    const post = Object.fromEntries(new URLSearchParams(request.postData()));
                    posts.push(post);
                    if (!failSave) {
                        savedName = post.name;
                        return route.fulfill({contentType: 'text/html', body: fixture(null, savedName, url.searchParams)});
                    }
                    return route.fulfill({contentType: 'text/html', body: fixture(post, savedName, url.searchParams)});
                }
                return route.fulfill({contentType: 'text/html', body: fixture(null, savedName, url.searchParams)});
            }
            const file = path.join(root, 'src', url.pathname);
            return fs.existsSync(file) ? route.fulfill({path: file}) : route.fulfill({status: 404, body: ''});
        });
        await page.goto('https://reminders.test/edit.php');
        async function pending(id, expected) {
            await page.waitForFunction(({id, expected}) => document.getElementById(id).classList.contains('form-submit-pending') === expected, {id, expected});
        }
        const name = page.locator('#record [name=name]');
        await pending('save', false);
        await page.locator('#record [name=lookup]').fill('A lookup query');
        await pending('save', false);
        for (const [selector, changed, original] of [
            ['#record [name=name]', 'Changed', 'Original'],
            ['#record [name=note]', 'Edited note', 'Saved note']
        ]) {
            await page.locator(selector).fill(changed); await pending('save', true);
            await page.locator(selector).fill(original); await pending('save', false);
        }
        await page.locator('#record [name=priority]').selectOption('high'); await pending('save', true);
        await page.locator('#record [name=priority]').selectOption('normal'); await pending('save', false);
        await page.locator('#record [name=active]').uncheck(); await pending('save', true);
        await page.getByRole('button', {name: 'Reset', exact: true}).click(); await pending('save', false);
        await page.locator('[name=attachment]').setInputFiles({name: 'note.txt', mimeType: 'text/plain', buffer: Buffer.from('test')});
        await pending('save', true);
        await page.locator('[name=attachment]').setInputFiles([]); await pending('save', false);
        await page.evaluate(() => {
            document.querySelector('[name=csrf_token]').value = 'rotated';
            document.querySelector('[name=version]').value = '2';
            const input = document.createElement('input');
            input.name = 'extra'; input.id = 'extra'; input.value = 'New row';
            document.getElementById('record').append(input);
        });
        await pending('save', true);
        await page.locator('#extra').evaluate(input => input.remove()); await pending('save', false);
        await name.fill('Changed');
        assert.equal(await page.locator('#save').evaluate(button => getComputedStyle(button).animationName), 'form-submit-outline-blink');
        await page.emulateMedia({reducedMotion: 'reduce'});
        assert.equal(await page.locator('#save').evaluate(button => getComputedStyle(button).animationName), 'none');
        assert.equal(await page.locator('#save').evaluate(button => getComputedStyle(button).outlineWidth), '3px');
        await page.emulateMedia({reducedMotion: 'no-preference'});
        await page.locator('#save').click();
        await page.getByText('The record could not be saved.', {exact: true}).waitFor();
        await pending('save', true);
        assert.equal(posts[0].save_record, '1');
        assert.match(JSON.parse(posts[0]._form_reminder).baseline, /^[0-9a-f]{64}$/);
        await name.fill('Original'); await pending('save', false);
        failSave = false;
        await name.fill('Saved edit');
        await page.locator('#save').click();
        await page.waitForFunction(() => document.querySelector('#record [name=name]')?.value === 'Saved edit' && !document.querySelector('main > .error'));
        await pending('save', false);
        await page.locator('#filters [name=q]').fill('community'); await pending('apply', true);
        await page.locator('#filters [name=sort]').selectOption('oldest');
        await page.locator('#filters [name=q]').fill('');
        await pending('apply', true);
        await page.locator('#filters [name=sort]').selectOption('newest'); await pending('apply', false);
        await page.locator('#filters [name=q]').fill('community');
        await page.locator('#apply').click(); await page.waitForURL(/q=community/);
        await pending('apply', false);
        assert.equal(await page.locator('#filters [name=_form_reminder]').count(), 0);
        for (const model of ['First record', 'Second record']) {
            await page.evaluate(model => {
                const dialog = document.getElementById('editor'), form = dialog.querySelector('form');
                form.elements.name.value = model;
                window.DnrFormReminders.reset(form);
                dialog.showModal();
            }, model);
            await pending('dialog-save', false);
            await page.locator('#editor [name=name]').fill('An edit'); await pending('dialog-save', true);
            await page.locator('#editor [name=name]').fill(model); await pending('dialog-save', false);
            await page.evaluate(() => document.getElementById('editor').close());
        }
        failSave = true;
        await page.evaluate(() => {
            const dialog = document.getElementById('editor'), form = dialog.querySelector('form');
            form.elements.id.value = '7'; form.elements.name.value = 'Record seven';
            window.DnrFormReminders.reset(form); dialog.showModal();
        });
        await page.locator('#editor [name=name]').fill('Rejected edit');
        await page.locator('#dialog-save').click();
        await page.getByText('The record could not be saved.', {exact: true}).waitFor();
        await pending('dialog-save', true);
        await page.evaluate(() => {
            const dialog = document.getElementById('editor'), form = dialog.querySelector('form');
            form.elements.id.value = '8'; form.elements.name.value = 'Record eight';
            window.DnrFormReminders.reset(form); dialog.showModal();
        });
        await pending('dialog-save', false);
        await page.evaluate(() => document.getElementById('editor').close());
        const spacing = await page.locator('.app-sidebar').evaluate(sidebar => {
            const button = sidebar.querySelector('button').getBoundingClientRect();
            return {gap: button.left - sidebar.querySelector('input').getBoundingClientRect().right,
                right: sidebar.getBoundingClientRect().right - button.right};
        });
        assert.equal(spacing.gap, 16);
        assert.ok(Math.abs(spacing.gap - spacing.right) <= 1, JSON.stringify(spacing));
        assert.deepEqual(errors, []);
        console.log('Form reminders passed: edits/reverts, selectors, checkboxes, files, added/removed rows, reset, failed and successful saves, named submitters, GET filters, dialog reuse, reduced motion, and sidebar spacing.');
    } finally {
        await browser.close();
    }
})().catch(error => {console.error(error); process.exitCode = 1;});
