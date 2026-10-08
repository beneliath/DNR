'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(require.resolve('../../src/assets/js/page-actions.js'), 'utf8');
const initialize = source.slice(source.indexOf('    function initializeEngagementForm()'), source.indexOf('    function initializeSelectAll('));

function fixture() {
    const documentEvents = {};
    const formEvents = {};
    const entryEvents = {};
    const form = {id: 'contact-edit-form', addEventListener(type, fn) { formEvents[type] = fn; }};
    const entry = {
        value: '', validationMessage: '',
        addEventListener(type, fn) { entryEvents[type] = fn; },
        setCustomValidity(message) { this.validationMessage = message; },
        reportValidity() {}, focus() {}
    };
    const document = {
        querySelector() { return form; },
        getElementById(id) { return id === 'new-chron-entry' ? entry : null; },
        addEventListener(type, fn, capture) { documentEvents[type] = {fn, capture}; }
    };
    vm.runInNewContext(initialize + '\ninitializeEngagementForm();', {document, window: {}, setConditionalField() {}});
    function submit(addChron) {
        const button = {type: 'submit', form, matches() { return addChron; }};
        documentEvents.click.fn({target: {closest() { return button; }}});
        // Native constraint validation happens after click but before submit.
        if (entry.validationMessage) return false;
        const event = {submitter: button, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }};
        formEvents.submit(event);
        return !event.defaultPrevented;
    }
    return {entry, form, documentEvents, entryEvents, submit};
}

test('normal contact save does not require a new Chron entry', () => {
    assert.equal(fixture().submit(false), true);
});

test('an empty Add Chron attempt cannot block a subsequent Save Changes', () => {
    const f = fixture();
    assert.equal(f.submit(true), false);
    assert.equal(f.entry.validationMessage, 'Enter a Chron entry before adding it.');
    assert.equal(f.documentEvents.click.capture, true);
    assert.equal(f.submit(false), true);
    assert.equal(f.entry.validationMessage, '');
});

test('explicit Chron additions still reject blank text and accept an entry', () => {
    const f = fixture();
    f.entry.value = '   ';
    assert.equal(f.submit(true), false);
    assert.equal(f.submit(true), false);
    f.entry.value = 'Called to confirm contact details';
    f.entryEvents.input();
    assert.equal(f.submit(true), true);
});

test('unrelated forms and non-submit buttons do not change Chron validation', () => {
    const f = fixture();
    f.submit(true);
    for (const button of [{type: 'submit', form: {}}, {type: 'button', form: f.form}, null]) {
        f.documentEvents.click.fn({target: {closest() { return button; }}});
        assert.equal(f.entry.validationMessage, 'Enter a Chron entry before adding it.');
    }
});
