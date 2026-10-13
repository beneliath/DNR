'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/form-drafts.js'), 'utf8');

function fixture(countryCode, regionValue, regionType = 'select-one', version = '[]') {
    function node(tagName = 'INPUT', attributes = []) {
        return {tagName, name: '', type: 'text', value: '', disabled: false, checked: false,
            events: {}, children: [], hasAttribute: name => attributes.includes(name),
            setAttribute() {}, addEventListener(type, handler) {this.events[type] = handler;},
            dispatchEvent(event) {this.events[event.type]?.(event);},
            replaceChildren(...children) {this.children = children;}, contains() {return false;}};
    }
    const region = node('SELECT');
    Object.assign(region, {name: 'event_state', type: 'select-one', options: [{value: ''}, {value: 'IL'}]});
    const textRegion = node();
    Object.assign(textRegion, {name: 'event_state', disabled: true});
    const country = node('SELECT', ['data-address-country-native']);
    Object.assign(country, {name: 'event_country', type: 'select-one', value: 'US',
        options: ['US', 'CA', 'GB'].map(value => ({value}))});
    country.addEventListener('change', () => {
        region.disabled = country.value === 'GB';
        textRegion.disabled = !region.disabled;
        region.value = '';
        region.options = [{value: ''}, {value: country.value === 'CA' ? 'ON' : 'IL'}];
    });
    const body = node('TEXTAREA');
    Object.assign(body, {name: 'body', value: 'Current body'});
    const template = node('SELECT', ['data-email-template']);
    Object.assign(template, {name: 'template', type: 'select-one', options: [{value: 'saved'}]});
    template.addEventListener('change', () => {body.value = 'Overwritten by template';});
    const panel = node('SECTION');
    const form = node('FORM');
    form.elements = [region, textRegion, country, body, template];
    form.dataset = {recoverableDraft: 'inquiry'};
    form.querySelector = () => panel;
    const fields = [
        {name: 'event_state', type: regionType, value: regionValue},
        {name: 'event_country', type: 'select-one', value: countryCode},
        {name: 'body', type: 'text', value: 'Saved body'},
        {name: 'template', type: 'select-one', value: 'saved'}
    ];
    const stored = JSON.stringify({fields, version, at: Date.now(), expires: Date.now() + 100000});
    const window = {DnrAccountContext: {local: {getItem: () => stored, removeItem() {}, setItem() {}}}, addEventListener() {}};
    const document = {getElementById: () => ({dataset: {navPreferenceUser: 'user'}}),
        querySelectorAll: selector => selector.startsWith('form') ? [form] : [], createElement: node};
    vm.runInNewContext(source, {window, document, Event: class {constructor(type) {this.type = type;}},
        setTimeout: () => 1, clearTimeout() {}});
    const [status, , restore, , , recovery] = panel.children;
    return {country, region, textRegion, body, restore, status, recovery};
}

test('restoring Canada rebuilds province options before restoring Ontario', () => {
    const f = fixture('CA', 'ON', 'select-one', '[["record",""]]');
    f.restore.events.click();
    assert.equal(f.country.value, 'CA');
    assert.equal(f.region.value, 'ON');
    assert.equal(f.body.value, 'Saved body');
    assert.equal(f.recovery.hidden, true);
    assert.match(f.status.textContent, /^Draft restored\./);
});

test('restoring a country with a free-text region discovers the newly enabled input', () => {
    const f = fixture('GB', 'Scotland', 'text', '[["record",""]]');
    f.restore.events.click();
    assert.equal(f.textRegion.disabled, false);
    assert.equal(f.textRegion.value, 'Scotland');
    assert.equal(f.recovery.hidden, true);
});

test('unavailable region values remain visible for manual recovery', () => {
    const f = fixture('CA', 'INVALID', 'select-one', '[["record",""]]');
    f.restore.events.click();
    assert.equal(f.recovery.hidden, false);
    assert.equal(f.recovery.value, 'event_state: INVALID');
});

test('a stale record version prevents draft restoration', () => {
    const f = fixture('CA', 'ON', 'select-one', 'older-version');
    assert.equal(f.restore.disabled, true);
    f.restore.events.click();
    assert.equal(f.country.value, 'US');
});
