const test = require('node:test');
const assert = require('node:assert/strict');
const { adjacentMonth } = require('../../src/assets/js/calendar-scroll.js');

test('continuous calendar crosses year boundaries in both directions', () => {
    assert.equal(adjacentMonth('2026-12', 1), '2027-01');
    assert.equal(adjacentMonth('2026-01', -1), '2025-12');
});

test('calendar month stepping stays on the month boundary through leap years', () => {
    assert.equal(adjacentMonth('2028-01', 1), '2028-02');
    assert.equal(adjacentMonth('2028-02', 1), '2028-03');
    assert.equal(adjacentMonth('2028-03', -1), '2028-02');
});
