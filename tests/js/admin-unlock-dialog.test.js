'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require.resolve('../../src/assets/js/admin-unlock-dialog.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));

function fixture(request) {
    const node = () => ({ value: '', disabled: false, hidden: true, events: {},
        addEventListener(name, handler) { this.events[name] = handler; },
        focus() { this.focused = true; } });
    const password = node(), code = node(), csrf = node(), submit = node(), cancel = node(), error = node();
    const otherToken = { value: 'old' }, telemetry = { dataset: { csrfToken: 'old' } };
    const form = node();
    const fields = { admin_password: password, admin_code: code, csrf_token: csrf };
    form.querySelector = selector => selector === '[data-unlock-submit]' ? submit : selector === '[data-unlock-cancel]' ? cancel
        : fields[selector.match(/name="([^"]+)"/)[1]];
    const dialog = node();
    dialog.querySelector = selector => selector === 'form' ? form : error;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    const events = [], requests = [], navigations = [];
    const window = { location: { assign: url => navigations.push(url) } };
    vm.runInNewContext(source, {
        window,
        document: { getElementById: () => dialog, addEventListener() {},
            querySelectorAll: selector => selector.startsWith('input') ? [csrf, otherToken] : [telemetry],
            dispatchEvent: event => events.push(event) },
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        FormData: class extends Map { constructor() { super(Object.entries(fields).map(([name, field]) => [name, field.value])); } },
        fetch: async (url, options) => {
            requests.push({ url, options });
            return request(url, options);
        }
    });
    return { request: window.DnrAdminUnlock.request, dialog, form, password, code, csrf, submit, cancel, error,
        otherToken, telemetry, events, requests, navigations,
        authenticate: () => form.events.submit({ preventDefault() {} }),
        escape: () => dialog.events.cancel({ preventDefault() {} }) };
}
const response = (unlocked, token = 'fresh', error = '') => ({ ok: !error, status: error ? 422 : 200,
    json: async () => ({ unlocked, csrf_token: token, expires_at: unlocked ? 1300 : null, server_now: 1000, error }) });

test('cancel and Escape leave the activity untouched, clear credentials, and never submit', async () => {
    for (const cancelWith of ['cancel', 'escape']) {
        const page = fixture(async () => response(false));
        const pending = page.request();
        assert.equal(page.request(), pending, 'overlapping requests share the dialog');
        await settle();
        page.password.value = 'private password'; page.code.value = 'recovery-code';
        if (cancelWith === 'cancel') page.cancel.events.click(); else page.escape();
        assert.equal(await pending, false);
        assert.equal(page.password.value, ''); assert.equal(page.code.value, '');
        assert.equal(page.dialog.open, false);
        assert.equal(page.requests.length, 1);
        assert.deepEqual(page.navigations, []);
    }
});

test('successful unlock refreshes every pending token, resolves the action, and does not navigate', async () => {
    const page = fixture(async (url, options) => response(options.method === 'POST', options.method === 'POST' ? 'rotated' : 'initial'));
    const pending = page.request(); await settle();
    page.password.value = 'password'; page.code.value = '123456';
    await page.authenticate();
    assert.equal(await pending, true);
    assert.equal(page.requests[1].options.body.get('admin_password'), 'password');
    assert.equal(page.requests[1].options.body.get('csrf_token'), 'initial');
    assert.equal(page.otherToken.value, 'rotated');
    assert.equal(page.telemetry.dataset.csrfToken, 'rotated');
    assert.equal(page.events.at(-1).detail.unlocked, true);
    assert.equal(page.password.value, ''); assert.equal(page.code.value, '');
    assert.equal(page.dialog.open, false); assert.deepEqual(page.navigations, []);
});

test('failed credentials stay in the dialog and a second attempt can succeed', async () => {
    let attempts = 0;
    const page = fixture(async (url, options) => options.method === 'POST'
        ? (++attempts === 1 ? response(false, 'retry-token', 'Credentials were not accepted.') : response(true)) : response(false));
    const pending = page.request(); await settle();
    await page.authenticate();
    assert.equal(page.dialog.open, true); assert.equal(page.error.hidden, false);
    assert.equal(page.password.value, ''); assert.equal(page.code.value, '');
    assert.equal(page.csrf.value, 'retry-token');
    await page.authenticate(); assert.equal(await pending, true);
});

test('a failed status check never approves an action or posts credentials', async () => {
    const page = fixture(async () => { throw new Error('Offline'); });
    const pending = page.request(); await settle();
    assert.equal(page.error.hidden, false); assert.equal(page.submit.disabled, true);
    await page.authenticate(); assert.equal(page.requests.length, 1);
    page.escape(); assert.equal(await pending, false);
});

test('an already unlocked session continues without another authentication post', async () => {
    const page = fixture(async () => response(true));
    assert.equal(await page.request(), true);
    assert.equal(page.requests.length, 1); assert.equal(page.dialog.open, false);
});
