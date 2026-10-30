'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/audience-notifications.js'), 'utf8');

function fixture(items = ['inbox-1', 'job-1'], scope = 'account-a-user-a', storage = new Map(), transport = null) {
    const badge = {};
    const trigger = { dataset: { notificationScope: scope, ...(transport?{notificationEndpoint:'audience.php?api=notifications',notificationCsrf:'test-token'}:{}) }, attrs: {}, querySelector: () => badge, setAttribute(k, v) { this.attrs[k] = v; }, focus() { this.focused = true; } };
    const events = {};
    const control = () => ({ events: {}, addEventListener(k, fn) { this.events[k] = fn; }, focus() { this.focused = true; } });
    const rows = items.map(key => { const button = control(); return { dataset: { notificationKey: key }, button, querySelector: () => button }; });
    const empty = {}, actions = {}, list = {}, clearAll = control(), close = control();
    const panel = { open: true, hidePopover() { this.open = false; }, querySelectorAll: () => rows, querySelector: selector => ({ '[data-notification-empty]': empty, '[data-notification-actions]': actions, '[data-notification-list]': list, '[data-notification-clear-all]': clearAll, '[popovertargetaction="hide"]': close }[selector]), addEventListener(k, fn) { events[k] = fn; } };
    const windowEvents = {};
    const localStorage = { getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value) };
    vm.runInNewContext(source, { document: { hidden:false, addEventListener() {}, querySelector: () => trigger, getElementById: () => panel }, window: { setInterval() {}, addEventListener(k, fn) { windowEvents[k] = fn; } }, localStorage, fetch:transport, URLSearchParams, AbortSignal });
    return { badge, trigger, panel, events, windowEvents, storage, rows, empty, actions, list, clearAll, close };
}

test('badge counts uncleared items and opening the bell keeps it visible after reload', () => {
    const f = fixture();
    assert.equal(f.badge.textContent, '2');
    assert.equal(f.badge.hidden, false);
    assert.equal(f.trigger.attrs['aria-label'], 'Notifications, 2 uncleared');
    f.events.toggle?.({ newState: 'open' });
    assert.equal(f.badge.hidden, false);
    assert.equal(fixture(undefined, undefined, f.storage).badge.textContent, '2');
});

test('old seen state does not hide existing notifications', () => {
    const storage = new Map([['moed:audience:notifications:account-a-user-a', '["inbox-1","job-1"]']]);
    assert.equal(fixture(undefined, undefined, storage).badge.textContent, '2');
});

test('new items and job versions restore the badge after older activity is cleared', () => {
    const f = fixture();
    f.clearAll.events.click();
    const updated = fixture(['inbox-1', 'job-1-completed', 'inbox-2'], undefined, f.storage);
    assert.equal(updated.badge.textContent, '2');
});

test('cleared state is isolated between users and accounts', () => {
    const f = fixture();
    f.clearAll.events.click();
    assert.equal(fixture(undefined, 'account-a-user-b', f.storage).badge.textContent, '2');
    assert.equal(fixture(undefined, 'account-b-user-a', f.storage).badge.textContent, '2');
});

test('other tabs synchronize dismissals and an empty list has no badge', () => {
    const first = fixture(), second = fixture(undefined, undefined, first.storage);
    first.clearAll.events.click();
    second.windowEvents.storage({ key: 'moed:audience:notifications:account-a-user-a:cleared' });
    assert.equal(second.badge.hidden, true);
    assert.equal(fixture([]).badge.hidden, true);
});

test('blocked or corrupt browser storage does not break notifications', () => {
    const storage = { get() { throw new Error('Blocked'); }, set() { throw new Error('Blocked'); } };
    const f = fixture(undefined, undefined, storage);
    assert.equal(f.badge.textContent, '2');
    f.clearAll.events.click();
    assert.equal(f.badge.hidden, true);
    assert.equal(fixture(undefined, undefined, new Map([['moed:audience:notifications:account-a-user-a:cleared', 'null']])).badge.textContent, '2');
});

test('clearing one notification hides only that item and keeps it cleared after navigation', () => {
    const f = fixture();
    f.rows[0].button.events.click();
    assert.equal(f.rows[0].hidden, true);
    assert.equal(f.rows[1].hidden, false);
    assert.equal(f.rows[1].button.focused, true);
    assert.equal(f.badge.textContent, '1');
    const next = fixture(undefined, undefined, f.storage);
    assert.equal(next.rows[0].hidden, true);
    assert.equal(next.rows[1].hidden, false);
    assert.equal(fixture(undefined, 'another-user', f.storage).rows[0].hidden, false);
});

test('Clear all closes the popup, persists, and allows new activity to appear', () => {
    const f = fixture();
    f.clearAll.events.click();
    assert.equal(f.empty.hidden, false);
    assert.equal(f.actions.hidden, true);
    assert.equal(f.list.hidden, true);
    assert.equal(f.badge.hidden, true);
    assert.equal(f.panel.open, false);
    assert.equal(f.trigger.focused, true);
    const next = fixture(['inbox-1', 'job-1', 'job-2'], undefined, f.storage);
    assert.deepEqual(next.rows.map(row => row.hidden), [true, true, false]);
    assert.equal(next.badge.textContent, '1');
    assert.equal(next.empty.hidden, true);
});

test('clearing from separate tabs merges dismissals and synchronizes the list', () => {
    const first = fixture(), second = fixture(undefined, undefined, first.storage);
    first.rows[0].button.events.click();
    second.rows[1].button.events.click();
    first.windowEvents.storage({ key: 'moed:audience:notifications:account-a-user-a:cleared' });
    assert.equal(first.empty.hidden, false);
    assert.equal(second.empty.hidden, false);
});

test('clearing the final item works even when browser storage is blocked', () => {
    const f = fixture(['only'], undefined, { get() { throw new Error('Blocked'); }, set() { throw new Error('Blocked'); } });
    f.rows[0].button.events.click();
    assert.equal(f.empty.hidden, false);
    assert.equal(f.badge.hidden, true);
    assert.equal(f.close.focused, true);
});

const settled = () => new Promise(resolve => setImmediate(resolve));
function server(keys = ['inbox-1','job-1']) {
    const cleared = new Set(), requests = [];
    let offline = false;
    return {cleared, requests, setOffline(value) { offline = value; }, async fetch(url, options) {
        requests.push(options.method || 'GET');
        if (offline) throw new Error('Offline');
        if (options.method === 'POST') {
            assert.equal(options.body.get('csrf_token'), 'test-token');
            JSON.parse(options.body.get('keys')).forEach(key => cleared.add(key));
        }
        return {ok:true, async json() { return {cleared:[...cleared],items:keys.map(key => ({key}))}; }};
    }};
}
test('independent browsers merge concurrent dismissals through server state', async () => {
    const backend=server();
    const a=fixture(undefined,undefined,new Map(),backend.fetch), b=fixture(undefined,undefined,new Map(),backend.fetch);
    await settled();
    a.rows[0].button.events.click();b.rows[1].button.events.click();await settled();
    a.windowEvents.focus();b.windowEvents.focus();await settled();
    assert.equal(backend.cleared.size,2);assert.equal(a.badge.hidden,true);assert.equal(b.badge.hidden,true);
});
test('legacy browser dismissals migrate to the server and another browser', async () => {
    const backend=server(),storage=new Map([['moed:audience:notifications:account-a-user-a:cleared','["inbox-1"]']]);
    fixture(undefined,undefined,storage,backend.fetch);await settled();
    const phone=fixture(undefined,undefined,new Map(),backend.fetch);await settled();
    assert(backend.cleared.has('inbox-1'));assert.equal(phone.badge.textContent,'1');assert.equal(phone.rows[0].hidden,true);
});
test('failed writes retain local dismissals and retry when connectivity returns', async () => {
    const backend=server();backend.setOffline(true);
    const f=fixture(undefined,undefined,new Map(),backend.fetch);await settled();
    f.rows[0].button.events.click();await settled();assert.equal(f.badge.textContent,'1');assert.equal(backend.cleared.size,0);
    backend.setOffline(false);f.windowEvents.online();await settled();
    assert(backend.cleared.has('inbox-1'));assert.equal(f.badge.textContent,'1');
});
