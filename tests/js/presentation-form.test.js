"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
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
