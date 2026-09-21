'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/footer.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));

function fixture(kind, request = async () => ({ ok: true, json: async () => ({ unlocked: false }) })) {
    const documentEvents = {};
    const nodes = {};
    function node(id = '') {
        return { id, dataset: {}, attrs: {}, events: {}, textContent: '', value: '', open: false, shown: 0,
            addEventListener(name, fn) { (this.events[name] ||= []).push(fn); },
            fire(name, event = {}) { return Promise.all((this.events[name] || []).map(fn => fn(event))); },
            setAttribute(name, value) { this.attrs[name] = value; },
            getAttribute(name) { return this.attrs[name] ?? null; },
            removeAttribute(name) { delete this.attrs[name]; },
            showModal() { this.open = true; this.shown++; },
            close() { this.open = false; this.fire('close'); }, focus() {}, select() {},
            matches(selector) {
                return selector.split(',').some(part => {
                    part = part.trim();
                    const tag = part.match(/^form/);
                    if (tag && this.tag !== 'form') return false;
                    const attribute = part.match(/\[([^\]]+)\]/);
                    if (attribute) return Object.hasOwn(this.attrs, attribute[1]);
                    if (part.startsWith('.')) return this.attrs.class?.split(' ').includes(part.slice(1)) || false;
                    return part === this.tag;
                });
            },
            closest(selector) {
                if (selector.includes('section[id]')) return this.section || null;
                return this.matches(selector) ? this : this.parent?.closest(selector) || null;
            }
        };
    }
    for (const id of [
        'delete-confirmation', 'delete-confirmation-message', 'cancel-delete', 'archive-instead', 'confirm-delete',
        'action-confirmation', 'action-confirmation-title', 'action-confirmation-message', 'cancel-action-confirmation', 'confirm-action',
        'sensitive-action-confirmation', 'sensitive-action-confirmation-title', 'sensitive-action-confirmation-message',
        'sensitive-action-confirmation-phrase', 'sensitive-action-confirmation-input', 'sensitive-action-confirmation-error',
        'cancel-sensitive-action', 'confirm-sensitive-action'
    ]) nodes[id] = node(id);
    const form = node();
    form.tag = 'form';
    form.attrs.action = 'tasks.php';
    form.section = { id: 'follow-up-work' };
    form.elements = { csrf_token: { value: 'old-token' }, action: { value: 'delete' }, delete_confirmation: { value: '' }, reset_confirmation: { value: '' } };
    form.querySelector = selector => form.elements[selector.match(/name="([^"]+)"/)?.[1]] || null;
    const button = node();
    button.tag = 'button';
    button.parent = form;
    button.attrs = { class: 'delete-button', 'aria-label': 'Delete task' };
    if (kind === 'delete') {
        form.attrs['data-delete-confirmation'] = '';
        form.dataset.deleteConfirmation = 'Permanently delete this record?';
        form.dataset.archiveAction = 'archive';
    } else if (kind === 'sensitive') {
        form.attrs['data-sensitive-action'] = '';
        form.dataset.sensitiveAction = 'delete-user';
    } else {
        button.attrs['data-confirm'] = '';
        button.dataset.confirm = 'Confirm this action?';
        if (kind === 'protected-button' || kind === 'bulk') button.attrs['data-admin-unlock-required'] = '';
        if (kind === 'protected-form') form.attrs['data-admin-unlock-required'] = '';
    }
    const nav = node();
    nav.href = 'https://app.example/admin_elevation.php?return=view_engagement.php';
    const location = new URL('https://app.example/view_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2#engagement-tasks');
    const navigations = [];
    location.assign = url => navigations.push(url);
    const requests = [];
    let submissions = 0;
    const submit = (submitter = button) => {
        const event = { target: form, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        const handlers = [...(form.events.submit || []), ...(documentEvents.submit || [])];
        const pending = handlers.map(handler => handler(event));
        if (!event.defaultPrevented) submissions++;
        return Promise.all(pending);
    };
    form.requestSubmit = submitter => { submit(submitter); };
    vm.runInNewContext(source, {
        document: {
            getElementById: id => nodes[id] || null,
            querySelectorAll: selector => selector === '[data-admin-unlock-link]' ? [nav]
                : selector === 'form[data-delete-confirmation]' && kind === 'delete' ? [form] : [],
            addEventListener: (name, fn) => { (documentEvents[name] ||= []).push(fn); }
        },
        window: { location }, URL,
        fetch: (url, options) => { requests.push({ url, options }); return request(url, options); }
    });
    return { nodes, form, button, nav, location, requests, navigations, submit, submissions: () => submissions,
        shown: () => Object.values(nodes).reduce((total, element) => total + element.shown, 0) };
}

for (const kind of ['delete', 'sensitive', 'protected-button', 'protected-form']) {
    test(`${kind}: locked actions open Admin Unlock before any confirmation or mutation`, async () => {
        const f = fixture(kind);
        await f.submit();
        assert.equal(f.requests.length, 1);
        assert.equal(f.requests[0].url, 'admin_unlock_status.php');
        assert.equal(f.requests[0].options.cache, 'no-store');
        assert.equal(f.shown(), 0);
        assert.equal(f.submissions(), 0);
        const destination = new URL(f.navigations[0]);
        assert.equal(destination.pathname, '/admin_elevation.php');
        assert.equal(destination.searchParams.get('return'), 'view_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2#follow-up-work');
    });

    test(`${kind}: an active session opens its confirmation and submits only after approval`, async () => {
        const f = fixture(kind, async () => ({ ok: true, json: async () => ({ unlocked: true, csrf_token: 'current-token' }) }));
        await f.submit();
        assert.equal(f.shown(), 1);
        assert.equal(f.submissions(), 0);
        assert.equal(f.form.elements.csrf_token.value, 'current-token');
        assert.equal(f.navigations.length, 0);
        if (kind === 'sensitive') f.nodes['sensitive-action-confirmation-input'].value = 'DELETE USER';
        await f.nodes[kind === 'delete' ? 'confirm-delete' : kind === 'sensitive' ? 'confirm-sensitive-action' : 'confirm-action'].fire('click');
        await settle();
        assert.equal(f.submissions(), 1);
        assert.equal(f.shown(), 1, 'approved resubmissions do not ask twice');
        if (kind === 'sensitive') assert.equal(f.form.elements.delete_confirmation.value, 'DELETE USER');
    });
}

test('ordinary confirmations stay available without an admin unlock', async () => {
    const f = fixture('ordinary');
    await f.submit();
    assert.equal(f.requests.length, 0);
    assert.equal(f.nodes['action-confirmation'].open, true);
});

test('expired or manually locked sessions are rechecked after canceling an earlier confirmation', async () => {
    let unlocked = true;
    const f = fixture('protected-form', async () => ({ ok: true, json: async () => ({ unlocked, csrf_token: unlocked ? 'token' : null }) }));
    await f.submit();
    await f.nodes['cancel-action-confirmation'].fire('click');
    unlocked = false;
    await f.submit();
    assert.equal(f.shown(), 1, 'the second click does not show a dialog after the unlock was cleared');
    assert.equal(f.navigations.length, 1);
    assert.equal(f.submissions(), 0);
});

test('unavailable or invalid status never permits a protected confirmation', async () => {
    for (const request of [
        async () => { throw new Error('Offline'); },
        async () => ({ ok: false }),
        async () => ({ ok: true, redirected: true }),
        async () => ({ ok: true, json: async () => ({ unlocked: true }) }),
        async () => ({ ok: true, json: async () => { throw new Error('Invalid JSON'); } })
    ]) {
        const f = fixture('protected-form', request);
        await f.submit();
        assert.equal(f.shown(), 0);
        assert.equal(f.navigations.length, 1);
    }
});

test('duplicate clicks share one in-flight unlock check', async () => {
    let resolve;
    const f = fixture('protected-form', () => new Promise(done => { resolve = done; }));
    const first = f.submit();
    await f.submit();
    assert.equal(f.requests.length, 1);
    resolve({ ok: true, json: async () => ({ unlocked: true, csrf_token: 'token' }) });
    await first;
    assert.equal(f.shown(), 1);
});

test('archive instead and cancel retain their existing behavior after unlock', async () => {
    const f = fixture('delete', async () => ({ ok: true, json: async () => ({ unlocked: true, csrf_token: 'token' }) }));
    await f.submit();
    await f.nodes['cancel-delete'].fire('click');
    assert.equal(f.submissions(), 0);
    await f.submit();
    await f.nodes['archive-instead'].fire('click');
    assert.equal(f.form.elements.action.value, 'archive');
    assert.equal(f.submissions(), 1);
});

test('proactive Admin Unlock preserves the current page, query and tab', async () => {
    const f = fixture('ordinary');
    await f.nav.fire('click');
    assert.equal(new URL(f.nav.href).searchParams.get('return'), 'view_engagement.php?id=42&return_to=engagements.php%3Fpage%3D2#engagement-tasks');
});


test('bulk deletion shows the selected count in an explicit confirmation and cancel never submits', async () => {
    const f = fixture('bulk', async () => ({ ok: true, json: async () => ({ unlocked: true, csrf_token: 'fresh-token' }) }));
    f.button.dataset.confirmTitle = 'Are you sure?';
    f.button.dataset.confirm = 'Permanently delete 3 selected items? This cannot be undone.';
    await f.submit();
    assert.equal(f.nodes['action-confirmation'].open, true);
    assert.equal(f.nodes['action-confirmation-title'].textContent, 'Are you sure?');
    assert.match(f.nodes['action-confirmation-message'].textContent, /3 selected items/);
    assert.equal(f.submissions(), 0);
    await f.nodes['cancel-action-confirmation'].fire('click');
    assert.equal(f.submissions(), 0);
    await f.submit();
    await f.nodes['confirm-action'].fire('click');
    await settle();
    assert.equal(f.submissions(), 1);
});
