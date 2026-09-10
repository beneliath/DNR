const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function editorHarness() {
    const field = () => ({
        value: '', selectionStart: 0, selectionEnd: 0, maxLength: 255, readOnly: false, listeners: {},
        addEventListener(event, callback) { this.listeners[event] = callback; },
        focus() { this.listeners.focus(); },
        setRangeText(text, start, end) {
            this.value = this.value.slice(0, start) + text + this.value.slice(end);
            this.selectionStart = this.selectionEnd = start + text.length;
        },
        dispatchEvent(event) { this.lastEvent = event.type; },
    });
    const subject = field();
    const body = field();
    const status = {};
    const buttons = ['event_name', 'presentation_schedule'].map(key => ({
        dataset: { insertEmailField: key },
        addEventListener(_event, callback) { this.click = callback; },
    }));
    const controls = { '[data-template-subject]': subject, '[data-template-body]': body,
        '[data-email-field-status]': status };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/email-template-editor.js'), 'utf8'), {
        document: { querySelector: () => ({ querySelector: selector => controls[selector], querySelectorAll: () => buttons }) },
        Event: class { constructor(type) { this.type = type; } },
    });
    return { subject, body, status, buttons };
}

test('event fields replace the selection in the last focused field', () => {
    const { subject, body, buttons } = editorHarness();
    subject.value = 'Hello old event!';
    subject.selectionStart = 6;
    subject.selectionEnd = 15;
    subject.focus();
    buttons[0].click();
    assert.equal(subject.value, 'Hello {{event_name}}!');
    assert.equal(subject.lastEvent, 'input');
    assert.equal(body.value, '');
    body.focus();
    buttons[1].click();
    assert.equal(body.value, '{{presentation_schedule}}');
});

test('insertion respects subject restrictions, length limits, and read-only fields', () => {
    const { subject, body, status, buttons } = editorHarness();
    subject.focus();
    buttons[1].click();
    assert.equal(subject.value, '');
    assert.match(status.textContent, /message only/);
    subject.maxLength = 3;
    buttons[0].click();
    assert.equal(subject.value, '');
    assert.match(status.textContent, /not enough space/);
    body.readOnly = true;
    body.focus();
    buttons[0].click();
    assert.equal(body.value, '');
});
