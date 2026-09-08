const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function form(myChecked = false, allChecked = false) {
    const my = { checked: myChecked, disabled: false };
    const all = { checked: allChecked, addEventListener: (_, callback) => { all.change = callback; } };
    const elements = { 'calendar-content-my_work': my, 'calendar-content-all_work': all };
    vm.runInNewContext(fs.readFileSync('src/assets/js/calendar-subscription.js', 'utf8'), {
        document: { getElementById: (id) => elements[id] ?? null },
    });
    return { my, all };
}

test('All Active Work disables My Active Work and restores its prior selection when cleared', () => {
    const { my, all } = form(true);
    all.checked = true;
    all.change();
    assert.equal(my.disabled, true);
    assert.equal(my.checked, false);
    all.checked = false;
    all.change();
    assert.equal(my.disabled, false);
    assert.equal(my.checked, true);
});

test('Returned forms apply all-work precedence immediately and do not select my work unexpectedly', () => {
    const { my, all } = form(false, true);
    assert.equal(my.disabled, true);
    all.checked = false;
    all.change();
    assert.equal(my.checked, false);
    assert.equal(my.disabled, false);
});
