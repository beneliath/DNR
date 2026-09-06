'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { receiptSummary } = require('../../src/assets/js/financial-draft.js');

test('receipt totals distinguish blank from zero and use exact cents', function () {
    assert.deepEqual(receiptSummary(['', '0', '12.30']), { valid: true, entered: 2, total: '12.30' });
    assert.deepEqual(receiptSummary(['0.10', '0.20', '']), { valid: true, entered: 2, total: '0.30' });
    assert.deepEqual(receiptSummary(['', '', '']), { valid: true, entered: 0, total: '0.00' });
    assert.equal(receiptSummary(['-1']).valid, false);
    assert.equal(receiptSummary(['1.234']).valid, false);
});

test('inquiry create-and-return preserves a free-text region and the original edit version', function () {
    const storage = new Map();
    class Element {
        constructor(name = '', value = '', type = 'text') {
            Object.assign(this, { name, value, type, dataset: {}, children: [], listeners: {}, textContent: '', disabled: false });
        }
        get options() { return this.children; }
        appendChild(option) { this.children.push(option); }
        replaceChildren() { this.children = []; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        cloneNode() { const option = new Element(this.name, this.value, this.type); option.dataset = { ...this.dataset }; option.textContent = this.textContent; return option; }
    }
    function renderForm(savedRegion, includeDisabledSelector, version) {
        const region = new Element('event_state', savedRegion);
        const country = new Element('event_country', 'GB', 'select-one');
        const org = new Element('organization_id', '', 'select-one');
        const contact = new Element('primary_contact_id', '', 'select-one');
        org.children = [new Element()];
        contact.children = [new Element()];
        const form = new Element();
        form.dataset = { inquiryDraftKey: 'test-user:new', inquiryFormSubmitted: 'false' };
        const inquiryVersion = new Element('inquiry_version', version, 'hidden');
        form.elements = [country, region, org, contact, inquiryVersion, new Element('csrf_token', 'session-secret', 'hidden')];
        if (includeDisabledSelector) {
            const disabledRegion = new Element('event_state', 'TX', 'select-one');
            disabledRegion.disabled = true;
            form.elements.push(disabledRegion);
        }
        form.elements.namedItem = (name) => form.elements.find((element) => element.name === name);
        const createLink = new Element();
        const elements = { 'inquiry-organization': org, 'inquiry-contact': contact,
            'inquiry-relationship-status': new Element(), 'inquiry-organization-search': new Element(),
            'inquiry-contact-search': new Element() };
        vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/inquiry-workflow.js'), 'utf8'), {
            document: {
                getElementById: (id) => elements[id],
                querySelector: (selector) => selector === '[data-inquiry-draft-key]' ? form : null,
                querySelectorAll: (selector) => selector === '[data-inquiry-create]' ? [createLink] : []
            },
            sessionStorage: { getItem: (key) => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: (key) => storage.delete(key) },
            window: { location: { search: '' } }, URL, URLSearchParams
        });
        return { region, country, createLink, inquiryVersion };
    }
    const original = renderForm('Scotland', true, 'original-version');
    original.createLink.listeners.click({ preventDefault() { throw new Error('storage unexpectedly failed'); } });
    const draft = JSON.parse(storage.get('moed:inquiry-related-draft:test-user:new'));
    assert.equal(draft.event_state, 'Scotland');
    assert.equal(draft.event_country, 'GB');
    assert.equal(draft.csrf_token, undefined);
    assert.equal(draft.inquiry_version, 'original-version');
    const restored = renderForm('', false, 'newer-edit-by-another-user');
    assert.equal(restored.region.value, 'Scotland');
    assert.equal(restored.country.value, 'GB');
    assert.equal(restored.inquiryVersion.value, 'original-version');
    assert.equal(storage.size, 0);
});

test('searching, clearing and failed searches preserve a task relation until explicit selection', async function () {
    class Element {
        constructor() { this.value = ''; this.children = []; this.listeners = {}; this.dataset = {}; this.textContent = ''; this.hidden = false; }
        get options() { return this.children; }
        get selectedOptions() { return this.children.filter((option) => option.value === this.value); }
        appendChild(element) { this.children.push(element); }
        replaceChildren() { this.children = []; }
        addEventListener(name, callback) { this.listeners[name] = callback; }
        dispatchEvent(event) { if (this.listeners[event.type]) this.listeners[event.type](event); }
        focus() { this.focused = true; }
    }
    const ids = ['task-subject-search', 'task-subject', 'task-subject-status', 'task-selected-record', 'task-selected-record-label', 'task-record-search-panel', 'task-subject-results', 'task-change-record', 'task-clear-record'];
    const elements = Object.fromEntries(ids.map((id) => [id, Object.assign(new Element(), { id })]));
    const select = elements['task-subject'];
    select.children = [{ value: 'general', textContent: 'General work' }, { value: 'engagement:42', textContent: 'Original event' }];
    select.value = 'engagement:42';
    elements['task-subject-search'].dataset.subjectSearchUrl = 'task_subject_search.php';
    let scheduled;
    let payload = { results: [{ type: 'general', value: 'general', label: 'General work' }] };
    let failure = false;
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/task-form.js'), 'utf8'), {
        document: { getElementById: (id) => elements[id], createElement: () => new Element() },
        window: { setTimeout: (callback) => { scheduled = callback; }, clearTimeout: () => {}, location: { href: 'https://app.test/edit_task.php?id=42' } },
        URL, AbortController, Event,
        fetch: async () => { if (failure) throw new Error('offline'); return { ok: true, json: async () => payload }; }
    });
    async function search(query) { elements['task-subject-search'].value = query; elements['task-subject-search'].listeners.input(); if (query.length >= 3) await scheduled(); }
    await search('no match');
    assert.equal(select.value, 'engagement:42');
    assert.equal(select.options.length, 2);
    await search('');
    assert.equal(select.value, 'engagement:42');
    failure = true;
    await search('offline');
    assert.equal(select.value, 'engagement:42');
    failure = false;
    payload.results.push({ type: 'engagement', value: 'engagement:99', label: 'Replacement event' });
    await search('replacement');
    assert.equal(select.value, 'engagement:42');
    elements['task-subject-results'].children[0].listeners.click();
    assert.equal(select.value, 'engagement:99');
    elements['task-clear-record'].listeners.click();
    assert.equal(select.value, 'general');
    select.value = '';
    elements['task-record-search-panel'].hidden = true;
    let invalidPrevented = false;
    select.listeners.invalid({ preventDefault() { invalidPrevented = true; } });
    assert.equal(invalidPrevented, true);
    assert.equal(elements['task-record-search-panel'].hidden, false);
    assert.equal(elements['task-subject-search'].focused, true);
    assert.equal(elements[select.dataset.errorTarget], elements['task-subject-search']);
});
