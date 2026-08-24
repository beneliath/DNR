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
