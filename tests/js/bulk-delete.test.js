'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/bulk-delete.js'), 'utf8');
function fixture(size = 3) {
    const node = () => ({ checked: false, events: {}, addEventListener(type, fn) { this.events[type] = fn; } });
    const items = Array.from({ length: size }, node);
    const controls = Object.fromEntries(['select-all', 'clear', 'count', 'submit'].map(key => [key, node()]));
    const form = node();
    form.querySelector = selector => controls[selector.slice(11, -1)];
    const events = {};
    vm.runInNewContext(source, { document: {
        querySelector: () => form, querySelectorAll: () => items
    }, window: { addEventListener: (name, fn) => { events[name] = fn; } } });
    return { items, controls, form, events };
}
test('individual selection, mixed select-all, and clear keep the selected count accurate', () => {
    const { items, controls } = fixture();
    assert.equal(controls.submit.disabled, true);
    items[1].checked = true;
    items[1].events.change();
    assert.equal(controls.count.textContent, '1 selected');
    assert.equal(controls['select-all'].indeterminate, true);
    assert.equal(controls.submit.textContent, 'Delete selected (1)');
    controls['select-all'].checked = true;
    controls['select-all'].events.change();
    assert.equal(items.every(item => item.checked), true);
    assert.equal(controls['select-all'].indeterminate, false);
    assert.equal(controls.count.textContent, '3 selected');
    controls.clear.events.click();
    assert.equal(items.some(item => item.checked), false);
    assert.equal(controls.submit.disabled, true);
    assert.equal(controls.clear.hidden, true);
});
test('empty lists cannot select all or submit, and a browser-restored selection is recounted', () => {
    const empty = fixture(0);
    assert.equal(empty.controls['select-all'].disabled, true);
    let prevented = false;
    empty.form.events.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    const restored = fixture();
    restored.items[0].checked = true;
    restored.events.pageshow();
    assert.equal(restored.controls.submit.disabled, false);
    assert.equal(restored.controls.count.textContent, '1 selected');
});
