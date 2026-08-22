"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const { waitingFieldState } = require("../../src/assets/js/task-form.js");

test("waiting tasks reveal and require the waiting-on field", function () {
    assert.deepEqual(waitingFieldState("waiting"), {
        clearValue: false,
        hidden: false,
        required: true
    });
});

test("other task states hide and clear the waiting-on field", function () {
    for (const status of ["not_started", "in_progress", "completed", "cancelled", ""]) {
        assert.deepEqual(waitingFieldState(status), {
            clearValue: true,
            hidden: true,
            required: false
        });
    }
});
