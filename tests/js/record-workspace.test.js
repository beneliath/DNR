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

function affiliationFixture(initial = [{organization: '2', role: 'Chairman'}]) {
    let focused = null;
    const node = (attributes = {}) => ({
        attributes, hidden: true, value: '',
        addEventListener(type, callback) { this[type] = callback; },
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = value; },
        dispatchEvent(event) { if (this[event.type]) this[event.type](event); },
        focus() { focused = this; }
    });
    const option = (textContent, value) => ({textContent, value});
    const makeRow = (index, organization = '', role = '') => {
        const select = node({id: `additional-organization-${index}`, name: `additional_organizations[${index}][organization_id]`});
        select.value = organization;
        select.options = [option('Select', ''), option('Church', '1'), option('Center', '2')];
        select.setCustomValidity = function (message) { this.validationMessage = message; };
        select.add = function (item) { this.options.push(item); };
        const title = node({id: `additional-role-${index}`, name: `additional_organizations[${index}][role_title]`});
        title.value = role;
        const remove = node();
        const makePrimary = node();
        const label = node({for: `additional-organization-${index}`});
        return {
            select, title, remove, makePrimary, label,
            querySelector(selector) {
                return {'[data-affiliation-organization]': select, '[data-affiliation-role]': title, '[data-remove-affiliation]': remove, '[data-make-primary-affiliation]': makePrimary}[selector];
            },
            remove() { rows.items.splice(rows.items.indexOf(this), 1); }
        };
    };
    const rows = {
        items: [],
        querySelectorAll(selector) {
            return selector === '[data-contact-affiliation-row]' ? this.items : this.items.map(row => row.select);
        },
        appendChild(fragment) { this.items.push(fragment.row); }
    };
    rows.items = initial.map((data, index) => makeRow(index, data.organization, data.role));
    const primary = node();
    primary.value = '1';
    primary.options = [option('Church', '1'), option('Center', '2'), option('New inline organization', '3')];
    const primaryRole = node();
    primaryRole.value = 'pastor';
    primaryRole.options = [option('Pastor', 'pastor'), option('Administrator', 'administrator'), option('Other', 'other')];
    Object.defineProperty(primaryRole, 'selectedIndex', {get() { return this.options.findIndex(item => item.value === this.value); }});
    const primaryRoleOther = node();
    const add = node();
    const status = {textContent: ''};
    const template = {content: {cloneNode() {
        const row = makeRow('__index__');
        return {
            row,
            querySelectorAll() { return [row.select, row.title, row.label]; },
            querySelector() { return row; }
        };
    }}};
    const form = {querySelector(selector) { return {'[name="organization_id"]': primary, '[name="contact_role"]': primaryRole, '[name="contact_role_other"]': primaryRoleOther}[selector]; }};
    const panel = {
        closest() { return form; },
        querySelector(selector) {
            return {'[data-affiliation-rows]': rows, '[data-affiliation-template]': template, '[data-add-affiliation]': add, '[data-affiliation-status]': status}[selector];
        }
    };
    const document = {querySelectorAll(selector) { return selector === '[data-contact-affiliations]' ? [panel] : []; }};
    const window = {location: {hash: ''}, addEventListener() {}};
    vm.runInNewContext(source, {document, window, Option: function (text, value) { return option(text, value); }, Event: function (type) { this.type = type; }});
    return {rows, primary, primaryRole, primaryRoleOther, add, status, get focused() {return focused;}};
}

test('additional affiliations retain independent roles and unique form indices after adding and removing rows', () => {
    const f = affiliationFixture();
    const existing = f.rows.items[0];
    f.add.click();
    const added = f.rows.items[1];
    assert.equal(existing.title.value, 'Chairman');
    assert.equal(added.title.getAttribute('name'), 'additional_organizations[1][role_title]');
    assert.equal(added.label.getAttribute('for'), added.select.getAttribute('id'));
    assert.equal(added.select.options.find(option => option.value === '3').textContent, 'New inline organization');
    assert.equal(f.focused, added.select);
    added.select.value = '3';
    added.title.value = 'Trustee';
    existing.querySelector('[data-remove-affiliation]').click();
    assert.equal(f.rows.items.length, 1);
    assert.equal(added.title.value, 'Trustee');
    assert.equal(f.focused, f.add);
    f.add.click();
    assert.equal(f.rows.items[1].title.getAttribute('name'), 'additional_organizations[2][role_title]');
});

test('duplicate affiliations are blocked against both primary and additional organizations and recover after correction', () => {
    const f = affiliationFixture([{organization: '1', role: 'Pastor'}, {organization: '2', role: 'Chairman'}, {organization: '2', role: ''}]);
    assert.match(f.rows.items[0].select.validationMessage, /only be added once/);
    assert.equal(f.rows.items[1].select.validationMessage, '');
    assert.match(f.rows.items[2].select.validationMessage, /only be added once/);
    f.primary.value = '3';
    f.primary.change();
    assert.equal(f.rows.items[0].select.validationMessage, '');
    f.rows.items[1].querySelector('[data-remove-affiliation]').click();
    assert.equal(f.rows.items[1].select.validationMessage, '');
});

test('making an additional organization primary swaps its title and preserves the previous affiliation and role', () => {
    const f = affiliationFixture();
    const row = f.rows.items[0];
    row.makePrimary.click();
    assert.equal(f.primary.value, '2');
    assert.equal(f.primaryRole.value, 'other');
    assert.equal(f.primaryRoleOther.value, 'Chairman');
    assert.equal(row.select.value, '1');
    assert.equal(row.title.value, 'Pastor');
    assert.equal(row.select.validationMessage, '');
    row.makePrimary.click();
    assert.equal(f.primary.value, '1');
    assert.equal(f.primaryRole.value, 'pastor');
    assert.equal(f.primaryRoleOther.value, '');
    assert.equal(row.select.value, '2');
    assert.equal(row.title.value, 'Chairman');
});

test('making the first organization primary removes its old additional row without creating an empty affiliation', () => {
    const f = affiliationFixture();
    f.primary.value = '';
    f.rows.items[0].makePrimary.click();
    assert.equal(f.primary.value, '2');
    assert.equal(f.rows.items.length, 0);
});

test('an archived additional organization cannot replace the active primary organization', () => {
    const f = affiliationFixture([{organization: '8', role: 'Trustee'}]);
    f.rows.items[0].makePrimary.click();
    assert.equal(f.primary.value, '1');
    assert.equal(f.rows.items[0].title.value, 'Trustee');
    assert.match(f.status.textContent, /active organization/);
});
