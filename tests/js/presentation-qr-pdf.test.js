'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/page-actions.js'), 'utf8');

function fixture() {
    let focused;
    function element(properties = {}) {
        return Object.assign({
            hidden: false, disabled: false, checked: false, textContent: '',
            addEventListener(name, callback) { this[name] = callback; },
            focus() { focused = this; }
        }, properties);
    }
    const nodes = Object.fromEntries([
        'presentation-qr-pdf-dialog', 'presentation-qr-pdf-form', 'qr-pdf-options', 'qr-pdf-select-all',
        'qr-pdf-selection-count', 'prepare-qr-pdf', 'qr-pdf-empty', 'qr-pdf-error', 'cancel-qr-pdf',
        'qr-pdf-presentation-id', 'presentation-qr-pdf-context', 'qr-pdf-order-status'
    ].map(id => [id, element()]));
    const dialog = nodes['presentation-qr-pdf-dialog'];
    const events = {};
    dialog.showModal = function () { this.open = true; };
    dialog.close = function () { this.open = false; events.close(); };
    dialog.addEventListener = (name, callback) => { events[name] = callback; };
    const options = nodes['qr-pdf-options'];
    options.rows = [];
    options.scrollTop = 0;
    Object.defineProperty(options, 'inputs', { get: () => options.rows.map(row => row.input) });
    options.replaceChildren = children => {
        options.rows = children.map(input => {
            const handle = element();
            const position = element();
            const label = element({ textContent: 'Code ' + input.value });
            const classes = new Set();
            const row = {
                input, handle, position,
                classList: { add: name => classes.add(name), remove: name => classes.delete(name) },
                scrollIntoView() {},
                closest: selector => selector === '[data-qr-pdf-option]' ? row : null,
                querySelector: selector => ({ input, '[data-qr-pdf-drag]': handle, '[data-qr-pdf-position]': position, '.qr-pdf-option-label': label })[selector]
            };
            handle.closest = selector => selector === '[data-qr-pdf-drag]' ? handle : selector === '[data-qr-pdf-option]' ? row : null;
            Object.defineProperty(row, 'nextSibling', { get: () => options.rows[options.rows.indexOf(row) + 1] || null });
            return row;
        });
    };
    options.querySelectorAll = selector => selector === '[data-qr-pdf-option]' ? options.rows : options.inputs.filter(input => !input.disabled);
    options.insertBefore = (row, target) => {
        options.rows.splice(options.rows.indexOf(row), 1);
        options.rows.splice(target ? options.rows.indexOf(target) : options.rows.length, 0, row);
    };
    options.getBoundingClientRect = () => ({ left: 0, right: 500, top: 0, bottom: 300 });
    let captured;
    let pointerTarget;
    options.setPointerCapture = id => { captured = id; };
    options.hasPointerCapture = id => captured === id;
    options.releasePointerCapture = () => { captured = undefined; options.lostpointercapture(); };
    const rows = [
        [{ value: '11' }, { value: '12' }, { value: '13', disabled: true }],
        [{ value: '21' }],
        [],
        [
            { value: '31', checked: false }, { value: '32', checked: false },
            { value: '33' }, { value: '34' }, { value: '35' }, { value: '36', checked: false }
        ]
    ];
    const buttons = rows.map((entries, index) => {
        const id = String(index + 1);
        nodes['presentation-qr-pdf-options-' + id] = {
            dataset: { presentationTitle: 'Presentation ' + id },
            content: { cloneNode: () => entries.map(row => element({ checked: !row.disabled, ...row })) }
        };
        return element({ dataset: { qrPdfPresentationId: id } });
    });
    const documentEvents = {};
    const document = {
        readyState: 'loading', getElementById: id => nodes[id] || null,
        elementFromPoint: () => pointerTarget,
        querySelector: () => null, querySelectorAll: () => [],
        addEventListener(name, callback) { (documentEvents[name] ||= []).push(callback); }
    };
    const frames = new Map();
    let nextFrame = 0;
    vm.runInNewContext(source, { document, window: {
        addEventListener() {},
        requestAnimationFrame(callback) { frames.set(++nextFrame, callback); return nextFrame; },
        cancelAnimationFrame(id) { frames.delete(id); }
    } });
    documentEvents.DOMContentLoaded.forEach(callback => callback());
    function open(index) {
        let prevented = false;
        const event = {
            target: { closest: selector => selector === '[data-qr-pdf-presentation-id]' ? buttons[index] : null },
            preventDefault() { prevented = true; }
        };
        documentEvents.click.forEach(callback => callback(event));
        assert.equal(prevented, true);
    }
    function submit() {
        let prevented = false;
        nodes['presentation-qr-pdf-form'].submit({ preventDefault() { prevented = true; } });
        return prevented ? null : {
            presentation: nodes['qr-pdf-presentation-id'].value,
            ids: options.inputs.filter(input => input.checked && !input.disabled).map(input => input.value)
        };
    }
    function drag(from, to) {
        const row = options.rows[from];
        pointerTarget = options.rows[to];
        options.pointerdown({ target: row.handle, button: 0, pointerId: 1, clientX: 200, clientY: 60, preventDefault() {} });
        options.pointermove({ pointerId: 1, clientX: 200, clientY: 180 });
        options.pointerup();
    }
    return { nodes, options, dialog, buttons, open, submit, drag, frames, focused: () => focused };
}

test('QR selection opens with available codes and submits only checked resources', () => {
    const f = fixture();
    f.open(0);
    assert.equal(f.dialog.open, true);
    assert.equal(f.nodes['presentation-qr-pdf-context'].textContent, 'Presentation 1');
    assert.equal(f.nodes['qr-pdf-selection-count'].textContent, '2 of 2 selected');
    assert.equal(f.focused(), f.nodes['qr-pdf-select-all']);
    f.options.inputs[0].checked = false;
    f.options.change();
    assert.equal(f.nodes['qr-pdf-select-all'].indeterminate, true);
    assert.deepEqual(f.submit(), { presentation: '1', ids: ['12'] });
    assert.equal(f.dialog.open, false);
    assert.equal(f.focused(), f.buttons[0]);
});

test('clearing all codes blocks an empty PDF, and select all restores available codes', () => {
    const f = fixture();
    f.open(0);
    const selectAll = f.nodes['qr-pdf-select-all'];
    selectAll.checked = false;
    selectAll.change();
    assert.equal(f.nodes['prepare-qr-pdf'].disabled, true);
    assert.equal(f.nodes['qr-pdf-error'].hidden, false);
    assert.equal(f.submit(), null);
    assert.equal(f.dialog.open, true);
    selectAll.checked = true;
    selectAll.change();
    assert.equal(f.nodes['prepare-qr-pdf'].disabled, false);
    assert.equal(f.nodes['qr-pdf-error'].hidden, true);
    assert.deepEqual(f.submit().ids, ['11', '12']);
});

test('cancel and reopening a different presentation do not reuse the previous selection', () => {
    const f = fixture();
    f.open(0);
    f.options.inputs[0].checked = false;
    f.nodes['cancel-qr-pdf'].click();
    assert.equal(f.dialog.open, false);
    assert.equal(f.focused(), f.buttons[0]);
    f.open(1);
    assert.deepEqual(f.submit(), { presentation: '2', ids: ['21'] });
    f.open(0);
    assert.deepEqual(f.submit(), { presentation: '1', ids: ['11', '12'] });
});

test('presentations without ready QR codes show an empty state and cannot submit', () => {
    const f = fixture();
    f.open(2);
    assert.equal(f.nodes['qr-pdf-empty'].hidden, false);
    assert.equal(f.nodes['prepare-qr-pdf'].disabled, true);
    assert.equal(f.nodes['qr-pdf-select-all'].disabled, true);
    assert.equal(f.focused(), f.nodes['cancel-qr-pdf']);
    assert.equal(f.submit(), null);
});

test('dragging selected codes changes the submission order and announces their new positions', () => {
    const f = fixture();
    f.open(0);
    f.drag(0, 1);
    assert.equal(f.nodes['qr-pdf-order-status'].textContent, 'Code 11 moved to list position 2 of 3.');
    assert.deepEqual(f.options.rows.map(row => row.position.textContent), ['1', '2', '']);
    assert.equal(f.frames.size, 0);
    assert.deepEqual(f.submit(), { presentation: '1', ids: ['12', '11'] });
});

test('arrow keys move through the whole list and preserve focus at its boundaries', () => {
    const f = fixture();
    f.open(0);
    const handle = f.options.rows[1].handle;
    const key = value => f.options.keydown({ target: handle, key: value, preventDefault() {} });
    key('ArrowUp');
    key('ArrowUp');
    assert.equal(f.focused(), handle);
    assert.deepEqual(f.options.inputs.map(input => input.value), ['12', '11', '13']);
    key('End');
    assert.deepEqual(f.options.inputs.map(input => input.value), ['11', '13', '12']);
    key('Home');
    assert.deepEqual(f.submit().ids, ['12', '11']);
});

test('unchecked and unavailable rows can be moved without being included in the PDF', () => {
    const f = fixture();
    f.open(0);
    f.options.inputs[0].checked = false;
    f.options.change();
    assert.equal(f.options.rows[0].handle.disabled, false);
    assert.equal(f.options.rows[2].handle.disabled, false);
    f.drag(0, 1);
    f.drag(2, 0);
    assert.deepEqual(f.options.inputs.map(input => input.value), ['13', '12', '11']);
    assert.deepEqual(f.submit().ids, ['12']);
});

test('a checked code can move above and below unchecked rows while PDF numbering follows checked order', () => {
    const f = fixture();
    f.open(3);
    // Blog, Website, Speaker Notes, Connection, Donate, Bio: only the middle three are checked.
    f.drag(2, 0);
    assert.deepEqual(f.options.inputs.map(input => input.value), ['33', '31', '32', '34', '35', '36']);
    assert.deepEqual(f.options.rows.map(row => row.position.textContent), ['1', '', '', '2', '3', '']);
    f.drag(0, 5);
    assert.deepEqual(f.options.inputs.map(input => input.value), ['31', '32', '34', '35', '36', '33']);
    assert.deepEqual(f.options.rows.map(row => row.position.textContent), ['', '', '1', '2', '', '3']);
    assert.equal(f.nodes['qr-pdf-selection-count'].textContent, '3 of 6 selected');
    assert.deepEqual(f.submit().ids, ['34', '35', '33']);
});

test('rows can be arranged before any are selected and keep that order when selected', () => {
    const f = fixture();
    f.open(0);
    const selectAll = f.nodes['qr-pdf-select-all'];
    selectAll.checked = false;
    selectAll.change();
    f.drag(0, 1);
    assert.deepEqual(f.options.inputs.map(input => input.value), ['12', '11', '13']);
    assert.equal(f.nodes['prepare-qr-pdf'].disabled, true);
    selectAll.checked = true;
    selectAll.change();
    assert.deepEqual(f.submit().ids, ['12', '11']);
});
