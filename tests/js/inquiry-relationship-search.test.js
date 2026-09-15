'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function harness(draft = null, search = '') {
    class Element {
        constructor(name = '', value = '', type = 'text') {
            Object.assign(this, {name, _value: value, type, children: [], dataset: {}, listeners: {}, textContent: ''});
        }
        get value() { return this.type === 'select-one' && !this.children.some((o) => o.value === this._value) ? '' : this._value; }
        set value(value) { this._value = String(value); }
        get options() { return this.children; }
        appendChild(child) { this.children.push(child); }
        replaceChildren() { this.children = []; this._value = ''; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
    }
    const option = (id, label, orgId = '0', orgName = '') => Object.assign(new Element('', String(id)), {textContent: label, dataset: {organizationId: orgId, organizationName: orgName}});
    const org = new Element('organization_id', '2', 'select-one');
    org.children = [option('', 'None'), option('2', 'Secondary host'), option('3', 'New host')];
    const contact = new Element('primary_contact_id', '8', 'select-one');
    contact.children = [option('', 'None'), option('8', 'Existing contact', '1', 'Primary host'), option('9', 'Created contact', '7', 'Created host')];
    const orgSearch = new Element('organization_search');
    const contactSearch = new Element('contact_search');
    orgSearch.dataset.searchUrl = contactSearch.dataset.searchUrl = 'inquiry_relationship_search.php';
    const feedback = new Element();
    const createLink = Object.assign(new Element(), {href: 'https://app.test/add_contact.php'});
    const form = new Element();
    form.dataset = {inquiryDraftKey: 'user:42', inquiryFormSubmitted: 'false'};
    form.elements = [org, contact, orgSearch, contactSearch, new Element('inquiry_version', 'old-version', 'hidden')];
    form.elements.namedItem = (name) => form.elements.find((input) => input.name === name);
    const storage = new Map(draft ? [['moed:inquiry-related-draft:user:42', JSON.stringify(draft)]] : []);
    const ids = {'inquiry-organization': org, 'inquiry-contact': contact, 'inquiry-organization-search': orgSearch, 'inquiry-contact-search': contactSearch, 'inquiry-relationship-status': feedback};
    const pending = [];
    let scheduled;
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/inquiry-workflow.js'), 'utf8'), {
        document: {querySelector: (selector) => selector === '[data-inquiry-draft-key]' ? form : null,
            getElementById: (id) => ids[id], createElement: () => new Element(),
            querySelectorAll: (selector) => selector.includes('data-inquiry-create') ? [createLink] : []},
        sessionStorage: {getItem: (key) => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: (key) => storage.delete(key)},
        window: {location: {href: 'https://app.test/edit_inquiry.php?id=42' + search, search},
            setTimeout: (callback) => { scheduled = callback; }, clearTimeout() {}},
        URL, URLSearchParams, AbortController,
        fetch: (url, options) => new Promise((resolve, reject) => pending.push({url: new URL(url), options, resolve, reject}))
    });
    const answer = async (request, results, selected = null, has_more = false) => {
        request.resolve({ok: true, json: async () => ({results, selected, has_more})});
        await new Promise(setImmediate);
    };
    return {org, contact, orgSearch, contactSearch, feedback, createLink, storage, pending, answer,
        search: async (input, query) => { input.value = query; input.listeners.input(); const promise = scheduled(); await Promise.resolve(); return {promise}; }};
}

test('inquiry searches preserve selections and secondary affiliations, and ignore stale responses', async () => {
    const h = harness();
    await h.search(h.contactSearch, 'first');
    const first = h.pending[0];
    await h.search(h.contactSearch, 'second');
    const second = h.pending[1];
    assert.equal(first.options.signal.aborted, true);
    await h.answer(second, [{id: 20, label: 'Second match'}]);
    assert.equal(h.contact.value, '8');
    await h.answer(first, [{id: 21, label: 'Stale match'}]);
    assert.equal(h.contact.options.some((o) => o.value === '21'), false);
    h.org.listeners.change();
    const check = h.pending.at(-1);
    assert.equal(check.url.searchParams.get('organization_id'), '2');
    await h.answer(check, [], {id: 8, label: 'Secondary affiliated contact', organization_id: 1});
    assert.equal(h.contact.value, '8');
    h.org.value = '3'; h.org.listeners.change();
    await h.answer(h.pending.at(-1), [], null);
    assert.equal(h.contact.value, '');
});

test('failed inquiry searches retain the selected records', async () => {
    const h = harness();
    const {promise} = await h.search(h.orgSearch, 'offline');
    h.pending.at(-1).reject(new Error('offline'));
    await promise;
    assert.equal(h.org.value, '2');
    assert.equal(h.contact.value, '8');
    assert.match(h.feedback.textContent, /preserved/);
});

test('draft selections outside the first 25 results are restored and retained on lookup failure', async () => {
    const h = harness({organization_id: '999', primary_contact_id: '998', inquiry_version: 'original-version',
        _relationships: {organization_id: {id: '999', label: 'Distant organization'}, primary_contact_id: {id: '998', label: 'Distant contact', organization_id: 999}}});
    assert.equal(h.org.value, '999');
    assert.equal(h.contact.value, '998');
    h.pending.forEach((r) => r.reject(new Error('offline')));
    await new Promise(setImmediate);
    assert.equal(h.org.value, '999');
    assert.equal(h.contact.value, '998');
    h.createLink.listeners.click({preventDefault() { throw new Error('Draft save failed'); }});
    const saved = JSON.parse(h.storage.get('moed:inquiry-related-draft:user:42'));
    assert.equal(saved.inquiry_version, 'original-version');
    assert.equal(saved._relationships.organization_id.label, 'Distant organization');
});

test('a created contact overrides the restored draft and hydrates its primary organization', () => {
    const h = harness({organization_id: '2', primary_contact_id: '8'}, '?created_contact_id=9');
    assert.equal(h.contact.value, '9');
    assert.equal(h.org.value, '7');
    assert.equal(h.org.options.find((o) => o.value === '7').textContent, 'Created host');
});
