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
