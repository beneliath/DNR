'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const code = require('./helpers/browser-module.js')('message-monkey/connections.mjs');

test('an overflow-shifted action tooltip keeps its caret over the icon', () => {
    const caretLeft = vm.runInNewContext(code + 'BrowserModule.iconTooltipCaretLeft');
    const icon = {left: 180, width: 40};
    const tooltipLeft = 62, tooltipWidth = 518;
    const arrowCenter = tooltipLeft + caretLeft(icon, tooltipLeft, tooltipWidth) + 7;
    assert.equal(arrowCenter, icon.left + icon.width / 2);
});
