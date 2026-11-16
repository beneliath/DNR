'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('responsive tables retain server labels when sortable headers contain arrows', () => {
    const cell = (label, colSpan = 1) => ({
        colSpan, dataset: label === undefined ? {} : {field: label},
        hasAttribute(name) { return name === 'data-field' && this.dataset.field !== undefined; },
        setAttribute() {}
    });
    const cells = [cell('Event Date(s)'), cell(undefined), cell(undefined, 7)];
    const table = {
        classList: {add() {}}, setAttribute() {},
        querySelectorAll(selector) {
            return selector === 'thead th'
                ? [{textContent: 'Event Date(s) ↑'}, {textContent: 'Actions'}, {textContent: 'Empty'}]
                : [{cells, setAttribute() {}}];
        }
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/workflow-ui.js'), 'utf8'), {
        window: {location: {pathname: '/engagements.php'}},
        document: {querySelector: () => null, querySelectorAll: selector => selector === 'main table' ? [table] : []}
    });
    assert.equal(cells[0].dataset.field, 'Event Date(s)');
    assert.equal(cells[1].dataset.field, 'Actions');
    assert.equal(cells[2].dataset.field, undefined);
});
