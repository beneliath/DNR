'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/form-drafts.js'), 'utf8');

function fixture({countryCode = 'CA', regionValue = 'ON', regionType = 'select-one',
    version = '[["record",""]]', loading = false, configure = () => {}} = {}) {
    function node(tagName = 'INPUT', attributes = []) {
        return {tagName: tagName.toUpperCase(), name: '', type: 'text', value: '', disabled: false, checked: false,
            dataset: {}, labels: [], textContent: '', attributes: {},
            events: {}, children: [], hasAttribute: name => attributes.includes(name),
            setAttribute(name, value) {this.attributes[name] = String(value);},
            getAttribute(name) {return this.attributes[name] ?? null;}, closest() {return null;},
            focus() {this.focused = true;},
            querySelector(selector) {
                for (const child of this.children) {
                    if (selector === 'button:not(:disabled)' && child.tagName === 'BUTTON' && !child.disabled) return child;
                    const found = child.querySelector?.(selector);
                    if (found) return found;
                }
                return null;
            },
            addEventListener(type, handler) {this.events[type] = handler;},
            dispatchEvent(event) {
                this.events[event.type]?.(event);
                if (event.bubbles && this.form) this.form.events[event.type]?.({type: event.type, target: this});
            },
            append(...children) {this.children.push(...children);},
            replaceChildren(...children) {this.children = children;}, contains() {return false;}};
    }
    const region = node('SELECT');
    Object.assign(region, {name: 'event_state', type: 'select-one', labels: [{textContent: 'State'}],
        options: [{value: '', textContent: 'Choose a state'}, {value: 'IL', textContent: 'Illinois'}]});
    const textRegion = node();
    Object.assign(textRegion, {name: 'event_state', disabled: true});
    const country = node('SELECT', ['data-address-country-native']);
    Object.assign(country, {name: 'event_country', type: 'select-one', value: 'US',
        labels: [{textContent: 'Country'}],
        options: [{value: 'US', textContent: 'United States'}, {value: 'CA', textContent: 'Canada'},
            {value: 'GB', textContent: 'United Kingdom'}]});
    country.addEventListener('change', () => {
        region.disabled = country.value === 'GB';
        textRegion.disabled = !region.disabled;
        region.value = '';
        region.options = [{value: '', textContent: 'Choose a state'},
            {value: country.value === 'CA' ? 'ON' : 'IL'}];
    });
    const body = node('TEXTAREA');
    Object.assign(body, {name: 'body', value: 'Current body', labels: [{textContent: 'Event Description'}]});
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
    configure({form, fields, node, region, country, body, template});
    form.elements.forEach(el => {el.form = form;});
    let stored = JSON.stringify({fields, version, at: Date.now(), expires: Date.now() + 100000});
    const windowEvents = {}, documentEvents = {};
    const window = {DnrUserTime: require('../../src/assets/js/user-time.js'), DnrAccountContext: {local: {
        getItem: () => stored, removeItem() {stored = null;}, setItem(key, value) {stored = value;}}},
        addEventListener(type, handler) {(windowEvents[type] ||= []).push(handler);}};
    const document = {readyState: loading ? 'loading' : 'complete',
        addEventListener(type, handler) {(documentEvents[type] ||= []).push(handler);},
        getElementById: () => ({dataset: {navPreferenceUser: 'user'}}),
        querySelectorAll: selector => selector.startsWith('form') ? [form] : [], createElement: node};
    const timers = new Map();
    let nextTimer = 0;
    vm.runInNewContext(source, {window, document, Event: class {
        constructor(type, options) {this.type = type; this.bubbles = options?.bubbles;}
    }, setTimeout(handler) {timers.set(++nextTimer, handler); return nextTimer;}, clearTimeout(timer) {timers.delete(timer);}});
    if (loading) window.DnrFormDrafts.initialize(form);
    const [status, save, restore, discard, , recovery] = panel.children;
    return {country, region, textRegion, body, form, save, restore, discard, status, recovery,
        savedDraft() {return JSON.parse(stored);},
        runTimers() {for (const handler of timers.values()) handler(); timers.clear();},
        restoreField(label) {
            const row = recovery.children[0]?.children[1].children.find(row => row.children[0].textContent === label);
            return row?.children[3].children[0];
        },
        rows() {
            const table = recovery.children[0];
            return table ? table.children[1].children.map(row => row.children.slice(0, 3).map(cell => cell.textContent)) : [];
        },
        finishParsing() {
            document.readyState = 'interactive';
            documentEvents.DOMContentLoaded.forEach(handler => handler());
            assert.equal(panel.children[2], restore, 'The ready scan reuses the already rendered draft controls');
        },
        warnsOnUnload() {
            let warned = false;
            windowEvents.beforeunload.forEach(handler => handler({preventDefault() {warned = true;}}));
            return warned;
        }};
}

test('restoring Canada rebuilds province options before restoring Ontario', () => {
    const f = fixture();
    f.restore.events.click();
    assert.equal(f.country.value, 'CA');
    assert.equal(f.region.value, 'ON');
    assert.equal(f.body.value, 'Saved body');
    assert.equal(f.recovery.hidden, true);
    assert.match(f.status.textContent, /^Draft restored\./);
});

test('restoring a country with a free-text region discovers the newly enabled input', () => {
    const f = fixture({countryCode: 'GB', regionValue: 'Scotland', regionType: 'text'});
    f.restore.events.click();
    assert.equal(f.textRegion.disabled, false);
    assert.equal(f.textRegion.value, 'Scotland');
    assert.equal(f.recovery.hidden, true);
});

test('unavailable region values remain visible for manual recovery', () => {
    const f = fixture({regionValue: 'INVALID'});
    f.restore.events.click();
    assert.equal(f.recovery.hidden, false);
    assert.deepEqual(f.rows(), [['State', 'Choose a state', 'INVALID']]);
});

test('a stale record version prevents draft restoration', () => {
    const f = fixture({version: 'older-version'});
    assert.equal(f.restore.disabled, true);
    f.restore.events.click();
    assert.equal(f.country.value, 'US');
});

test('early draft controls stay stable and do not warn while an untouched form is still loading', () => {
    const f = fixture({loading: true});
    assert.equal(f.warnsOnUnload(), false);
    f.finishParsing();
    assert.equal(f.warnsOnUnload(), false);
    f.body.value = 'Unsaved body';
    assert.equal(f.warnsOnUnload(), true);
    f.body.value = 'Current body';
    assert.equal(f.warnsOnUnload(), false);
});

test('legacy drafts show only differing values using form labels and dropdown text', () => {
    const f = fixture({countryCode: 'US', regionValue: '', version: 'older-version',
        configure({template}) {template.value = 'saved';}});
    assert.deepEqual(f.rows(), [['Event Description', 'Current body', 'Saved body']]);
    const changedCountry = fixture({version: 'older-version'});
    assert.ok(changedCountry.rows().some(row => row.join('|') === 'Country|United States|Canada'));
});

test('matching stale drafts have an empty state and editing a difference removes its row', () => {
    const f = fixture({countryCode: 'US', regionValue: '', version: 'older-version',
        configure({template}) {template.value = 'saved';}});
    f.body.value = 'Saved body';
    f.form.events.input({target: f.body});
    assert.deepEqual(f.rows(), []);
    assert.equal(f.recovery.hidden, true);
    assert.equal(f.status.textContent, 'The saved draft matches the current form. You can discard it.');
    assert.equal(f.restore.disabled, true);
    f.discard.events.click();
    assert.equal(f.status.textContent, 'No saved draft');
});

test('checkbox options match by value and changed radios appear once per group', () => {
    const f = fixture({version: 'older-version', configure({form, fields, node}) {
        const checkbox = value => Object.assign(node(), {name: 'roles[]', type: 'checkbox', value,
            checked: value === 'travel', labels: [{textContent: value === 'travel' ? 'Travel' : 'Billing'}]});
        const radio = value => Object.assign(node(), {name: 'travel_covered', type: 'radio', value,
            checked: value === 'no', labels: [{textContent: value === 'yes' ? 'Yes' : 'No'}]});
        form.elements = [checkbox('travel'), checkbox('billing'), radio('yes'), radio('no')];
        fields.splice(0, fields.length,
            {name: 'roles[]', type: 'checkbox', value: 'billing', checked: true},
            {name: 'roles[]', type: 'checkbox', value: 'travel', checked: true},
            {name: 'travel_covered', type: 'radio', value: 'yes', checked: true},
            {name: 'travel_covered', type: 'radio', value: 'no', checked: false});
    }});
    assert.deepEqual(f.rows(), [['Billing', 'No', 'Yes'], ['Travel Covered', 'No', 'Yes']]);
});

test('removed fields keep saved labels and values, and repeated rows include their context', () => {
    const f = fixture({version: 'older-version', configure({form, fields, node}) {
        const group = {querySelector: () => ({textContent: 'Presentation 1'})};
        const topic = Object.assign(node(), {name: 'presentations[1][topic_title]', value: 'Opening',
            labels: [{textContent: 'Topic/Title *'}], closest: () => group});
        form.elements = [topic];
        fields.splice(0, fields.length,
            {name: topic.name, type: 'text', value: 'Closing'},
            {name: 'presentations[2][speaker_id]', type: 'select-one', value: '42',
                label: 'Presentation 2 · Speaker', displayValue: 'Sample Speaker'});
    }});
    assert.deepEqual(f.rows(), [['Presentation 1 · Topic/Title', 'Opening', 'Closing'],
        ['Presentation 2 · Speaker', 'Field no longer available', 'Sample Speaker']]);
});

test('differences wait for the rest of an early initialized form to finish parsing', () => {
    const f = fixture({countryCode: 'US', regionValue: '', version: 'older-version', loading: true,
        configure({form, template}) {template.value = 'saved'; form.elements = [];}});
    assert.deepEqual(f.rows(), []);
    f.form.elements = [f.region, f.country, f.body];
    f.finishParsing();
    assert.deepEqual(f.rows().filter(row => row[0] !== 'Template'), [['Event Description', 'Current body', 'Saved body']]);
});

test('date widget type changes and line endings do not create false differences', () => {
    const f = fixture({version: 'older-version', configure({form, fields, node, body}) {
        const date = Object.assign(node(), {name: 'event_start_date', type: 'date', value: '2026-11-14'});
        body.value = 'First line\nSecond line';
        form.elements = [date, body];
        fields.splice(0, fields.length, {name: date.name, type: 'text', value: date.value},
            {name: body.name, type: 'text', value: 'First line\r\nSecond line'});
    }});
    assert.equal(f.recovery.hidden, true);
    assert.deepEqual(f.rows(), []);
});

test('saving preserves readable labels for future recovery without marking the form unsaved', () => {
    const f = fixture();
    f.save.events.click();
    const saved = f.savedDraft();
    assert.equal(saved.fields.find(field => field.name === 'body').label, 'Event Description');
    assert.equal(saved.fields.find(field => field.name === 'event_country').displayValue, 'United States');
    assert.equal(f.warnsOnUnload(), false);
});

test('restoring one stale draft value updates the form, keeps other values and preserves the saved draft', () => {
    const changes = [];
    const f = fixture({version: 'older-version', configure({body}) {
        body.addEventListener('input', () => changes.push('input'));
        body.addEventListener('change', () => changes.push('change'));
    }});
    const savedBefore = f.savedDraft();
    f.restoreField('Event Description').events.click();
    assert.equal(f.body.value, 'Saved body');
    assert.equal(f.country.value, 'US');
    assert.equal(f.region.value, '');
    assert.deepEqual(changes, ['input', 'change']);
    assert.ok(!f.rows().some(row => row[0] === 'Event Description'));
    assert.ok(f.rows().some(row => row[0] === 'Country'));
    f.runTimers();
    assert.deepEqual(f.savedDraft(), savedBefore);
    assert.equal(f.warnsOnUnload(), true);
    assert.match(f.status.textContent, /before saving/);
    assert.equal(f.restore.disabled, true);
});

test('restoring a country enables its saved region for a separate Restore action', () => {
    const f = fixture({version: 'older-version'});
    assert.equal(f.restoreField('State').disabled, true);
    f.restoreField('Country').events.click();
    assert.equal(f.country.value, 'CA');
    assert.equal(f.region.value, '');
    assert.equal(f.restoreField('State').disabled, false);
    f.restoreField('State').events.click();
    assert.equal(f.region.value, 'ON');
    assert.equal(f.body.value, 'Current body');
});

test('individual checkbox and radio restores apply checked states and clear an empty radio choice', () => {
    const f = fixture({version: 'older-version', configure({form, fields, node}) {
        const checkbox = Object.assign(node(), {name: 'book_table', type: 'checkbox', value: 'on', checked: true});
        const radio = value => Object.assign(node(), {name: 'travel_covered', type: 'radio', value,
            checked: value === 'no', labels: [{textContent: value}]});
        const emptyRadio = Object.assign(node(), {name: 'optional_choice', type: 'radio', value: 'yes', checked: true});
        form.elements = [checkbox, radio('yes'), radio('no'), emptyRadio];
        fields.splice(0, fields.length,
            {name: 'book_table', type: 'checkbox', value: 'on', checked: false},
            {name: 'travel_covered', type: 'radio', value: 'yes', checked: true},
            {name: 'travel_covered', type: 'radio', value: 'no', checked: false},
            {name: 'optional_choice', type: 'radio', value: 'yes', checked: false});
    }});
    f.restoreField('Book Table').events.click();
    assert.equal(f.form.elements[0].checked, false);
    assert.equal(f.form.elements[2].checked, true);
    f.restoreField('Travel Covered').events.click();
    assert.equal(f.form.elements[1].checked, true);
    assert.equal(f.form.elements[2].checked, false);
    f.restoreField('Optional Choice').events.click();
    assert.equal(f.form.elements[3].checked, false);
    assert.equal(f.recovery.hidden, true);
    assert.equal(f.status.focused, true);
    assert.match(f.status.textContent, /before saving/);
});

test('missing controls, disabled dropdown options and read-only fields cannot be restored', () => {
    const f = fixture({version: 'older-version', configure({country, body, fields}) {
        country.options.find(option => option.value === 'CA').disabled = true;
        body.readOnly = true;
        fields.push({name: 'removed_field', type: 'text', value: 'Saved value'});
    }});
    assert.equal(f.restoreField('Country').disabled, true);
    assert.equal(f.restoreField('Event Description').disabled, true);
    assert.equal(f.restoreField('Removed Field').disabled, true);
    f.restoreField('Country').events.click();
    assert.equal(f.country.value, 'US');
});

test('restoring a cleared text value removes the row and keeps the form unsaved', () => {
    const f = fixture({countryCode: 'US', regionValue: '', version: 'older-version', configure({fields, template}) {
        fields.find(field => field.name === 'body').value = '';
        template.value = 'saved';
    }});
    f.restoreField('Event Description').events.click();
    assert.equal(f.body.value, '');
    assert.equal(f.recovery.hidden, true);
    assert.equal(f.warnsOnUnload(), true);
});

test('partial automatic recovery keeps unavailable draft values until they can be individually restored', () => {
    const f = fixture({regionValue: 'NEW'});
    f.restore.events.click();
    assert.equal(f.restoreField('State').disabled, true);
    f.region.options.push({value: 'NEW', textContent: 'New region'});
    f.form.events.change({target: f.region});
    assert.equal(f.restoreField('State').disabled, false);
    f.restoreField('State').events.click();
    f.runTimers();
    assert.equal(f.region.value, 'NEW');
    assert.equal(f.savedDraft().fields.find(field => field.name === 'event_state').value, 'NEW');
    assert.equal(f.recovery.hidden, true);
});

test('initializing widgets cannot overwrite a saved draft before the recovery panel is ready', () => {
    for (const version of ['older-version', '[["record",""]]']) {
        const f = fixture({version, loading: true});
        const before = f.savedDraft();
        f.form.events.input({target: f.country});
        f.form.events.change({target: f.country});
        f.runTimers();
        assert.deepEqual(f.savedDraft(), before);
        f.finishParsing();
        assert.equal(f.restore.disabled, version === 'older-version');
        assert.equal(f.rows().length > 0, version === 'older-version');
    }
});
