import test from 'node:test';
import assert from 'node:assert/strict';
import {calendarDates, formatCalendarDate, parseCalendarDate} from '../../src/assets/js/analytics-calendar.mjs';

test('report dates round trip without timezone shifts', () => {
  assert.equal(formatCalendarDate('2026-10-02'), '10/02/2026');
  assert.equal(parseCalendarDate('10/02/2026', '2026-11-01'), '2026-10-02');
  assert.equal(parseCalendarDate('10/2/2026', '2026-11-01'), '2026-10-02');
  assert.equal(parseCalendarDate('2026-10-02', '2026-11-01'), '2026-10-02');
  assert.equal(parseCalendarDate('02/29/2024'), '2024-02-29');
  assert.equal(parseCalendarDate('02/29/2026'), '');
  assert.equal(parseCalendarDate('11/02/2026', '2026-11-01'), '');
  assert.equal(parseCalendarDate('13/01/2026'), '');
  assert.equal(parseCalendarDate('01/01/0000'), '');
});

test('October grid contains all seven days, including Saturdays', () => {
  const days = calendarDates(2026, 9);
  assert.equal(days.length, 42);
  assert.equal(days[0].iso, '2026-09-27');
  assert.equal(days[6].iso, '2026-10-03');
  assert.equal(days[34].iso, '2026-10-31');
  assert.equal(days[41].iso, '2026-11-07');
  assert.equal(days[34].outside, false);
  assert.equal(days[41].outside, true);
});
