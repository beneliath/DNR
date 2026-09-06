'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/record-workspace.js'), 'utf8');

function fixture(response = {ok: true, data: {id: 27, label: 'New organization'}}, hash = '') {
    let focused = null;
    const button = {disabled: false, addEventListener(type, handler) {this[type] = handler;}};
    const name = {value: ' New organization ', focus() {focused = this;}};
    const status = {textContent: ''};
    const email = {value: 'draft@example.org'};
    const photo = {files: [{name: 'draft-photo.png'}]};
    const select = {value: '12', children: [], appendChild(option) {this.children.push(option);}, dispatchEvent() {}, focus() {focused = this;}};
    const csrf = {value: 'csrf-token'};
    const form = {querySelector(selector) {return {'[name="organization_id"]': select, '[name="csrf_token"]': csrf}[selector];}};
    const panel = {closest() {return form;}, querySelector(selector) {return {'[data-create-organization]': button, '[data-organization-name]': name, '[data-organization-status]': status}[selector];}};
    const note = {focus() {focused = this;}};
    const details = {open: false, querySelector() {return note;}};
    const events = {};
    const window = {location: {hash}, addEventListener(type, callback) {events[type] = callback;}, setTimeout(callback) {callback();}};
    const requests = [];
    class FormData {constructor() {this.entries = [];} append(key, value) {this.entries.push([key, value]);}}
    const document = {getElementById() {return details;}, querySelectorAll(selector) {return selector === '[data-inline-organization]' ? [panel] : [];}, createElement() {return {};}};
    vm.runInNewContext(source, {window, document, FormData, Event: class {}, fetch: async (url, options) => {
        requests.push({url, options});
        if (response instanceof Error) throw response;
        return {ok: response.ok, json: async () => response.data};
    }});
    return {button, name, status, email, photo, select, requests, details, note, window, events, get focused() {return focused;}};
}

test('inline organization creation selects only the new organization and preserves the contact draft/photo', async () => {
    const f = fixture();
    const photo = f.photo.files[0];
    await f.button.click();
    assert.equal(f.select.value, '27');
    assert.equal(f.select.children[0].textContent, 'New organization');
    assert.equal(f.email.value, 'draft@example.org');
    assert.equal(f.photo.files[0], photo);
    assert.deepEqual(f.requests[0].options.body.entries, [['csrf_token', 'csrf-token'], ['organization_name', 'New organization']]);
    assert.equal(f.requests[0].options.credentials, 'same-origin');
    assert.equal(f.button.disabled, false);
    assert.equal(f.focused, f.select);
});

test('server validation errors preserve both contact and organization drafts for correction', async () => {
    const f = fixture({ok: false, data: {error: 'Choose the existing organization'}});
    await f.button.click();
    assert.equal(f.select.value, '12');
    assert.equal(f.select.children.length, 0);
    assert.equal(f.name.value, ' New organization ');
    assert.equal(f.email.value, 'draft@example.org');
    assert.equal(f.status.textContent, 'Choose the existing organization');
    assert.equal(f.button.disabled, false);
});

test('an empty name never submits and moves focus to its field', async () => {
    const f = fixture();
    f.name.value = '  ';
    await f.button.click();
    assert.equal(f.requests.length, 0);
    assert.equal(f.focused, f.name);
});

test('network failures and invalid server IDs do not replace the selected organization', async () => {
    for (const response of [new Error('Offline'), {ok: true, data: {id: 0, label: 'Invalid'}}]) {
        const f = fixture(response);
        await f.button.click();
        assert.equal(f.select.value, '12');
        assert.equal(f.name.value, ' New organization ');
        assert.equal(f.button.disabled, false);
    }
});

test('direct and changed Add Note fragments open and focus the scoped composer', () => {
    const direct = fixture(undefined, '#add-note');
    assert.equal(direct.details.open, true);
    assert.equal(direct.focused, direct.note);
    const changed = fixture();
    changed.window.location.hash = '#add-note';
    changed.events.hashchange();
    assert.equal(changed.details.open, true);
    assert.equal(changed.focused, changed.note);
});
