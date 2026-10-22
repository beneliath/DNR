'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const preview = import('../../src/assets/js/audience-template-preview.mjs');

test('preview keeps an edge in whitespace or after a complete line', async () => {
  const {safePreviewBoundary} = await preview;
  const lines = [{top: 10, bottom: 30}, {top: 40, bottom: 60}];
  assert.equal(safePreviewBoundary(35, lines), 35);
  assert.equal(safePreviewBoundary(60, lines), 60);
  assert.equal(safePreviewBoundary(50, lines), 40);
});

test('preview excludes an image that would be cut in half', async () => {
  const {safePreviewBoundary} = await preview;
  assert.equal(safePreviewBoundary(100, [{top: 70, bottom: 200}]), 70);
  assert.equal(safePreviewBoundary(200, [{top: 70, bottom: 200}]), 200);
});

test('preview finds a shared safe edge for overlapping columns and floated images', async () => {
  const {safePreviewBoundary} = await preview;
  const ranges = [{top: 40, bottom: 75}, {top: 70, bottom: 110}, {top: 20, bottom: 55}];
  assert.equal(safePreviewBoundary(100, ranges), 20);
  assert.equal(safePreviewBoundary(100, ranges.reverse()), 20);
});

test('preview can omit an oversized first element without extending past the cap', async () => {
  const {safePreviewBoundary} = await preview;
  assert.equal(safePreviewBoundary(100, [{top: -1, bottom: 2000}]), 0);
  assert.equal(safePreviewBoundary(0, []), 0);
  assert.equal(safePreviewBoundary(100.8, []), 100);
});
