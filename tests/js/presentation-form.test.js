"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");
const {
    compact24HourTime,
    shouldReleaseQrPreviews,
    validTime,
    validWholeNumber
} = require("../../src/assets/js/presentation-form.js");

test("validTime accepts complete 12-hour clock values", function () {
    assert.equal(validTime("1:00"), true);
    assert.equal(validTime("09:30"), true);
    assert.equal(validTime("12:59"), true);
    assert.equal(validTime("00:30"), false);
    assert.equal(validTime("13:00"), false);
    assert.equal(validTime("9:60"), false);
    assert.equal(validTime("930"), false);
});

test("compact24HourTime converts unambiguous military time", function () {
    assert.deepEqual(compact24HourTime("0930"), {time: "09:30", period: "AM"});
    assert.deepEqual(compact24HourTime("1300"), {time: "01:00", period: "PM"});
    assert.deepEqual(compact24HourTime("1530"), {time: "03:30", period: "PM"});
    assert.deepEqual(compact24HourTime("2359"), {time: "11:59", period: "PM"});
    assert.deepEqual(compact24HourTime("0030"), {time: "12:30", period: "AM"});
});

test("compact24HourTime leaves ambiguous or malformed input untouched", function () {
    assert.equal(compact24HourTime("1230"), null);
    assert.equal(compact24HourTime("1260"), null);
    assert.equal(compact24HourTime("2400"), null);
    assert.equal(compact24HourTime("9:30"), null);
});

test("validWholeNumber applies inclusive presentation field bounds", function () {
    assert.equal(validWholeNumber("60", 1, 1440), true);
    assert.equal(validWholeNumber("0", 0, 2147483647), true);
    assert.equal(validWholeNumber("0", 1, 1440), false);
    assert.equal(validWholeNumber("1.5", 1, 1440), false);
    assert.equal(validWholeNumber("1441", 1, 1440), false);
});

test("QR preview URLs survive bfcache navigation and release on real unload", function () {
    assert.equal(shouldReleaseQrPreviews({persisted: true}), false);
    assert.equal(shouldReleaseQrPreviews({persisted: false}), true);
    assert.equal(shouldReleaseQrPreviews(), true);
});

function presentationFormFixture(rows = [{}], statusValue = "pending") {
    const nodes = new Map();
    const entries = [];
    const periods = new Map();
    const documentEvents = {};
    const defaultSpeaker = "Default Speaker";
    function input(value = "") {
        return {
            value, validity: { badInput: false }, events: {}, validationMessage: "",
            addEventListener(name, callback) { this.events[name] = callback; },
            setCustomValidity(message) { this.validationMessage = message; },
            reportValidity() { this.reported = true; },
            focus() { this.focused = true; }
        };
    }
    const confirmedOption = { disabled: false };
    const status = input(statusValue);
    status.querySelector = () => confirmedOption;
    nodes.set("confirmation_status", status);
    nodes.set("event_start_date", input("2026-11-14"));
    nodes.set("event_end_date", input("2026-11-16"));
    nodes.set("presentations-container", { dataset: { defaultSpeaker } });

    function initializeEntry(entry, values = {}) {
        const id = Number(entry.id.replace("presentation-", ""));
        const defaults = {
            presentation_topic: "", presentation_date: "", presentation_time: "",
            presentation_time_hidden: "", duration_minutes: "60",
            speaker_name: defaultSpeaker, expected_attendance: "", actual_attendance: ""
        };
        for (const [field, value] of Object.entries({ ...defaults, ...values })) {
            if (field !== "saved") nodes.set(field + "_" + id, input(value));
        }
        periods.set(id, [Object.assign(input("AM"), { checked: true })]);
        nodes.set(entry.id, entry);
        entry.querySelectorAll = () => [];
        entry.querySelector = selector => selector === 'input[name$="[id]"]' && values.saved
            ? { value: String(values.saved) } : null;
        entry.remove = () => entries.splice(entries.indexOf(entry), 1);
    }
    rows.forEach((values, index) => {
        const entry = { id: "presentation-" + (index + 1) };
        initializeEntry(entry, values);
        entries.push(entry);
    });
    const container = { appendChild: entry => entries.push(entry) };
    const document = {
        getElementById: id => nodes.get(id) || null,
        querySelector(selector) {
            if (selector === ".presentations-inner-container") return container;
            const match = selector.match(/presentation_ampm_(\d+)/);
            return match ? periods.get(Number(match[1])).find(period => period.checked) : null;
        },
        querySelectorAll(selector) {
            if (selector === ".presentation-entry") return entries;
            const match = selector.match(/presentation_ampm_(\d+)/);
            return match ? periods.get(Number(match[1])) : [];
        },
        addEventListener: (name, callback) => { documentEvents[name] = callback; },
        createElement() {
            return {
                set innerHTML(markup) {
                    this.markup = markup;
                    initializeEntry(this);
                }
            };
        }
    };
    const window = { addEventListener() {} };
    vm.runInNewContext(fs.readFileSync(require.resolve("../../src/assets/js/presentation-form.js"), "utf8"), {
        document, window
    });
    documentEvents.DOMContentLoaded();
    return { window, nodes, entries, status, confirmedOption };
}

test("blank and partial presentations can be saved and followed by another presentation", function () {
    for (const row of [
        {},
        { duration_minutes: "" },
        { presentation_topic: "A title to finish later", duration_minutes: "" },
        { presentation_date: "2026-11-15", duration_minutes: "" },
        { presentation_time: "9:30", duration_minutes: "" },
        { duration_minutes: "45" }
    ]) {
        const fixture = presentationFormFixture([row]);
        assert.equal(fixture.window.validateEngagementPresentations(), true);
        fixture.window.addPresentation();
        assert.equal(fixture.entries.length, 2);
        assert.equal(fixture.nodes.get("duration_minutes_2").value, "60");
        assert.doesNotMatch(fixture.entries[1].markup, /class="required"/);
        assert.equal(fixture.window.validateEngagementPresentations(), true);
    }
});

test("confirmation accepts partial or previously saved presentations", function () {
    for (const row of [
        { presentation_topic: "Title only", duration_minutes: "" },
        { presentation_date: "2026-11-15", duration_minutes: "" },
        { presentation_time: "9:30", duration_minutes: "" },
        { duration_minutes: "45" },
        { speaker_name: "Another Speaker" },
        { expected_attendance: "50" },
        { actual_attendance: "0" },
        { saved: 12, duration_minutes: "" }
    ]) {
        const fixture = presentationFormFixture([row], "confirmed");
        assert.equal(fixture.confirmedOption.disabled, false);
        assert.equal(fixture.window.validateEngagementPresentations(), true);
    }
});

test("confirmation still requires content beyond a blank row's defaults", function () {
    for (const row of [{}, { duration_minutes: "" }]) {
        const fixture = presentationFormFixture([row], "confirmed");
        assert.equal(fixture.confirmedOption.disabled, true);
        assert.equal(fixture.window.validateEngagementPresentations(), false);
        assert.match(fixture.status.validationMessage, /Add at least one presentation/);
    }
});

test("changing optional attendance, speaker, or duration updates confirmation availability", function () {
    for (const [field, value] of [
        ["expected_attendance", "50"], ["actual_attendance", "0"],
        ["speaker_name", "Another Speaker"], ["duration_minutes", "45"]
    ]) {
        const fixture = presentationFormFixture();
        const input = fixture.nodes.get(field + "_1");
        const original = input.value;
        assert.equal(fixture.confirmedOption.disabled, true);
        input.value = value;
        input.events.input();
        assert.equal(fixture.confirmedOption.disabled, false);
        input.value = original;
        input.events.input();
        assert.equal(fixture.confirmedOption.disabled, true);
    }
});

test("invalid supplied values block both saving and adding presentations", function () {
    for (const [field, value] of [
        ["presentation_date", "2026-11-17"], ["presentation_time", "13:90"],
        ["duration_minutes", "0"], ["duration_minutes", "1441"],
        ["duration_minutes", "1.5"], ["expected_attendance", "0"],
        ["actual_attendance", "-1"]
    ]) {
        const fixture = presentationFormFixture([{ [field]: value }]);
        assert.equal(fixture.window.validateEngagementPresentations(), false);
        assert.notEqual(fixture.nodes.get(field + "_1").validationMessage, "");
        fixture.window.addPresentation();
        assert.equal(fixture.entries.length, 1);
    }
    for (const field of ["presentation_date", "duration_minutes"]) {
        const fixture = presentationFormFixture([{ [field]: "" }]);
        fixture.nodes.get(field + "_1").validity.badInput = true;
        assert.equal(fixture.window.validateEngagementPresentations(), false);
        fixture.window.addPresentation();
        assert.equal(fixture.entries.length, 1);
    }
});
