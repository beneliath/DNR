'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/ai-coach-requests.js'), 'utf8');

function fixture(unlocked = true) {
    const node = () => ({
        events: {}, classList: { add() {} },
        addEventListener(type, fn) { this.events[type] = fn; },
        setAttribute() {}, after() {}, focus() { this.focused = true; },
        replaceChildren(...children) { this.children = children; },
        showModal() { this.open = true; },
        close() { this.open = false; this.events.close(); },
    });
    const button = node(), form = node(), csrf = { value: 'old' };
    button.form = form;
    // HTML forms expose named controls as properties, including "action".
    form.action = button;
    form.getAttribute = name => name === 'action' ? 'ai_coach_requests.php' : null;
    form.closest = () => null;
    form.querySelector = () => csrf;
    const confirmation = node();
    let cancel;
    confirmation.querySelector = selector => selector === 'a'
        ? { replaceWith(value) { cancel = value; } } : { value: 'snapshot-token' };
    const section = { querySelector: () => confirmation, childNodes: [confirmation] };
    const created = [], requests = [], redirects = [];
    vm.runInNewContext(source, {
        HTMLDialogElement: function () {}, URL, FormData: class extends Map { constructor() { super(); } },
        document: {
            querySelector: () => button, body: { appendChild() {} },
            createElement() { const element = node(); created.push(element); return element; },
        },
        window: { location: {
            href: 'http://localhost/ai_coach_requests.php?per_page=50',
            pathname: '/ai_coach_requests.php', search: '?per_page=50',
            assign(url) { redirects.push(url); },
        } },
        DOMParser: class { parseFromString() { return { querySelector: () => section }; } },
        fetch: async (url, options) => {
            requests.push({ url, options });
            if (url === 'admin_unlock_status.php') return {
                ok: true, json: async () => ({ unlocked, csrf_token: 'fresh' }),
            };
            assert.equal(url, 'ai_coach_requests.php');
            return { ok: true, text: async () => '<section>confirmation</section>' };
        },
    });
    return { button, csrf, created, requests, redirects,
        submit: () => form.events.submit({ preventDefault() {} }), cancel: () => cancel.events.click() };
}

test('clear popup posts to the form URL despite its named action button, and cancel restores focus', async () => {
    const view = fixture();
    await view.submit();
    assert.equal(view.requests.length, 2);
    assert.equal(view.requests[1].options.method, 'POST');
    assert.equal(view.requests[1].options.body.get('action'), 'prepare_clear');
    assert.equal(view.csrf.value, 'fresh');
    assert.equal(view.created[0].open, true);
    assert.equal(view.created[1].hidden, true);
    assert.equal(view.created[2].focused, true);
    view.cancel();
    assert.equal(view.created[0].open, false);
    assert.equal(view.button.focused, true);
    assert.equal(view.requests.length, 2);
});

test('locked administrators must unlock before a confirmation can be requested', async () => {
    const view = fixture(false);
    await view.submit();
    assert.equal(view.requests.length, 1);
    assert.match(view.redirects[0], /admin_elevation\.php\?return=ai_coach_requests\.php%3Fper_page%3D50$/);
    assert.equal(view.created[0].open, undefined);
});
