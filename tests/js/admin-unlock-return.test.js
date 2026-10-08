'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('./helpers/account-context.js');
const source = fs.readFileSync(require.resolve('../../src/assets/js/footer.js'), 'utf8');

function page(storage = new Map(), { url = 'https://app.example/reimbursement_setup.php?return=dashboard.php#settings', user = '17', version = '1', baseline = 'Saved Name', accountBanner = false } = {}) {
    const events = {}, windowEvents = {}, notifications = [];
    function field(name, type, value, extra = {}) {
        return Object.defineProperties({ name, type, value, checked: false,
            hasAttribute: () => false, dispatchEvent(event) { notifications.push([name, event.type]); } }, Object.getOwnPropertyDescriptors(extra));
    }
    const fields = [field('organization_name', 'text', baseline), field('selected_ids[]', 'checkbox', '12'),
        field('selected_ids[]', 'checkbox', '34'), field('choices[name]', 'select-one', 'target'),
        field('recipients[]', 'select-multiple', 'one', { options: [{ value: 'one', selected: true }, { value: 'two', selected: false }],
            get selectedOptions() { return this.options.filter(option => option.selected); } }),
        field('csrf_token', 'hidden', 'fresh-csrf'), field('version', 'hidden', version), field('new_password', 'password', ''),
        field('attachment', 'file', ''), field('prune_confirmation', 'text', ''), field('api_secret', 'text', '')];
    const form = { elements: fields, getAttribute: name => ({ id: 'settings', method: 'post', action: 'reimbursement_setup.php' })[name] || null };
    const nav = { href: 'https://app.example/admin_elevation.php?return=reimbursement_setup.php',
        addEventListener(type, fn) { this[type] = fn; } };
    const location = new URL(url);
    let scroll;
    const context = {
        document: {
            body: { classList: { contains: name => accountBanner && name === 'account-context-active' },
                scrollLeft: 0, scrollTop: 735, scrollTo(x, y) { scroll = [x, y]; } },
            activeElement: { id: 'save-settings' },
            getElementById: id => id === 'app-sidebar' ? { dataset: { navPreferenceUser: user } } : null,
            querySelectorAll: selector => selector === 'main form' ? [form] : selector === '[data-admin-unlock-link]' ? [nav] : [],
            addEventListener(type, fn) { (events[type] ||= []).push(fn); }
        },
        window: { location, scrollX: 0, scrollY: 430,
            sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
            addEventListener(type, fn) { windowEvents[type] = fn; }, scrollTo(x, y) { scroll = [x, y]; } },
        URL, Event
    };
    vm.runInNewContext(source, context);
    return { fields, nav, storage, notifications, context, windowEvents, scroll: () => scroll,
        capture() { (events['admin-unlock-redirect'] || []).forEach(fn => fn()); } };
}

function edit(view) {
    view.fields[0].value = 'Unsaved Name';
    view.fields[1].checked = true;
    view.fields[3].value = 'source';
    view.fields[4].options[1].selected = true;
    view.fields[5].value = 'old-csrf';
    view.fields[7].value = 'private-password';
    view.fields[8].value = 'C:\\fakepath\\receipt.pdf';
    view.fields[9].value = 'PRUNE';
    view.fields[10].value = 'private-secret';
}

test('unlock round trips restore unsaved fields, merge choices, multiple selects and external bulk selections once', () => {
    const view = page();
    edit(view);
    view.nav.click();
    const saved = [...view.storage.values()][0];
    assert.match(saved, /Unsaved Name/);
    for (const sensitive of ['old-csrf', 'private-password', 'receipt.pdf', 'PRUNE', 'private-secret']) assert.equal(saved.includes(sensitive), false);
    const returned = page(view.storage);
    assert.equal(returned.fields[0].value, 'Unsaved Name');
    assert.equal(returned.fields[1].checked, true);
    assert.equal(returned.fields[2].checked, false);
    assert.equal(returned.fields[3].value, 'source');
    assert.equal(returned.fields[4].options[1].selected, true);
    assert.equal(returned.fields[5].value, 'fresh-csrf');
    assert.equal(returned.fields[7].value, '');
    assert.equal(returned.notifications.some(([name, type]) => name === 'selected_ids[]' && type === 'change'), true);
    returned.windowEvents.pageshow();
    assert.deepEqual(returned.scroll(), [0, 430]);
    assert.equal(view.storage.size, 0);
    assert.equal(page(view.storage).fields[0].value, 'Saved Name');
});

test('unlock restores the content scroll position beneath a fixed account banner', () => {
    const view = page(new Map(), { accountBanner: true });
    edit(view);
    view.capture();
    const returned = page(view.storage, { accountBanner: true });
    returned.windowEvents.pageshow();
    assert.equal(returned.fields[0].value, 'Unsaved Name');
    assert.deepEqual(returned.scroll(), [0, 735]);
});

test('snapshots are isolated by tab, account and exact query, and consumed by cancel as well as success', () => {
    const view = page(); edit(view); view.capture();
    assert.equal(page().fields[0].value, 'Saved Name', 'another tab has separate storage');
    assert.equal(page(view.storage, { user: '18' }).fields[0].value, 'Saved Name');
    assert.equal(page(view.storage, { url: 'https://app.example/reimbursement_setup.php?return=users.php' }).fields[0].value, 'Saved Name');
    assert.equal(page(view.storage, { url: 'https://app.example/admin_elevation.php?return=reimbursement_setup.php' }).fields[0].value, 'Saved Name');
    assert.equal(view.storage.size, 1);
    assert.equal(page(view.storage, { url: 'https://app.example/reimbursement_setup.php?return=dashboard.php' }).fields[0].value, 'Unsaved Name');
    assert.equal(view.storage.size, 0);
});

test('expired drafts and changed record versions or server values never overwrite current data', () => {
    for (const change of ['expired', 'version', 'baseline']) {
        const view = page(); edit(view); view.capture();
        if (change === 'expired') {
            const [key, text] = [...view.storage.entries()][0];
            view.storage.set(key, JSON.stringify({ ...JSON.parse(text), expires: Date.now() - 1 }));
        }
        const returned = page(view.storage, change === 'version' ? { version: '2' } : change === 'baseline' ? { baseline: 'New Server Name' } : {});
        assert.equal(returned.fields[0].value, change === 'baseline' ? 'New Server Name' : 'Saved Name');
        assert.equal(view.storage.size, 0);
    }
});

test('blocked session storage still permits routing to unlock', () => {
    const view = page();
    view.context.window.sessionStorage.setItem = () => { throw new Error('Storage unavailable'); };
    assert.doesNotThrow(() => view.nav.click());
    assert.equal(new URL(view.nav.href).searchParams.get('return'), 'reimbursement_setup.php?return=dashboard.php#settings');
});
