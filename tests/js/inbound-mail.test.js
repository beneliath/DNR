"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const { mergeEngagementOptions } = require("../../src/assets/js/inbound-mail.js");

test("engagement search preserves the selected route outside the new result window", function () {
    assert.deepEqual(
        mergeEngagementOptions(
            [{ id: 81, marker: "[MOED#81]", label: "New result" }],
            { id: 12, label: "[MOED#12] · Existing selection" }
        ),
        [
            { id: 12, label: "[MOED#12] · Existing selection" },
            { id: 81, label: "[MOED#81] · New result" }
        ]
    );
});

test("engagement search normalizes IDs and de-duplicates refreshed selections", function () {
    assert.deepEqual(
        mergeEngagementOptions(
            [
                { id: "12", marker: "[MOED#12]", label: "Refreshed selection" },
                { id: 0, label: "Invalid" }
            ],
            { id: 12, label: "Old selection" }
        ),
        [{ id: 12, label: "[MOED#12] · Refreshed selection" }]
    );
});

const vm = require('node:vm');
const fs = require('node:fs');

test('Inbox Search highlights only while either filter differs from the applied values', () => {
    const query = {value: 'report', defaultValue: 'report'};
    const order = {selectedOptions: [{defaultSelected: true}]};
    const classes = new Set();
    const button = {classList: {
        toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        remove(name) { classes.delete(name); }
    }};
    const form = Object.assign(new EventTarget(), {
        elements: {namedItem: name => ({q: query, sort: order})[name]},
        querySelector: () => button
    });
    const window = new EventTarget();
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/inbound-mail.js'), 'utf8'), {
        document: {
            getElementById: id => id === 'inbound-queue-search' ? form : null,
            querySelector: () => null
        },
        window
    });
    const pending = () => classes.has('inbound-search-pending');
    assert.equal(pending(), false);
    query.value = '';
    form.dispatchEvent(new Event('input'));
    assert.equal(pending(), true);
    order.selectedOptions = [{defaultSelected: false}];
    form.dispatchEvent(new Event('change'));
    query.value = 'report';
    form.dispatchEvent(new Event('input'));
    assert.equal(pending(), true);
    order.selectedOptions = [{defaultSelected: true}];
    form.dispatchEvent(new Event('change'));
    assert.equal(pending(), false);
    order.selectedOptions = [{defaultSelected: false}];
    form.dispatchEvent(new Event('change'));
    assert.equal(pending(), true);
    form.dispatchEvent(new Event('submit'));
    assert.equal(pending(), false);
    window.dispatchEvent(new Event('pageshow'));
    assert.equal(pending(), true);
});

test('filing requires a destination and updates the count as routes change', function () {
    let checked = 0;
    const engagement = { value: '' };
    const summary = { textContent: '' };
    const approve = { disabled: false };
    let onChange;
    const form = {
        querySelector: selector => selector.startsWith('button') ? approve : engagement,
        querySelectorAll: () => Array(checked).fill({}),
        addEventListener: (event, listener) => { if (event === 'change') onChange = listener; }
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/inbound-mail.js'), 'utf8'), {
        document: {
            querySelector: () => form,
            getElementById: id => id === 'inbound-selection-summary' ? summary : null
        }
    });
    assert.equal(approve.disabled, true);
    assert.match(summary.textContent, /Choose at least one/);
    checked = 2;
    engagement.value = '31';
    onChange();
    assert.equal(approve.disabled, false);
    assert.equal(summary.textContent, '3 Chron logs selected');
    checked = 0;
    onChange();
    assert.equal(summary.textContent, '1 Chron log selected');
    engagement.value = '';
    onChange();
    assert.equal(approve.disabled, true);
});
