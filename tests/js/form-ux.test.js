'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { connectFieldError, fieldLabel, initialize } = require('../../src/assets/js/form-ux.js');

function field(attributes = {}, labels = []) {
    return { attributes, labels, getAttribute(k) { return this.attributes[k] ?? null; }, setAttribute(k, v) { this.attributes[k] = v; } };
}

test('field errors preserve help associations and avoid duplicate error references', () => {
    const input = field({ 'aria-describedby': 'date-help' });
    connectFieldError(input, { id: 'date-error' });
    connectFieldError(input, { id: 'date-error' });
    assert.equal(input.attributes['aria-describedby'], 'date-help date-error');
    assert.equal(input.attributes['aria-invalid'], 'true');
});

test('validation summary uses visible labels before accessible fallbacks', () => {
    assert.equal(fieldLabel(field({ 'aria-label': 'Fallback' }, [{ textContent: 'Event dates *' }])), 'Event dates');
    assert.equal(fieldLabel(field({ 'aria-label': 'Country' })), 'Country');
    assert.equal(fieldLabel(field()), 'This field');
});

test('general server error is focused without marking unrelated inputs invalid', () => {
    let focused = false;
    const error = { hidden: false, textContent: 'The server could not save these changes', attrs: {},
        classList: { add() {} }, setAttribute(k, v) { this.attrs[k] = v; },
        querySelectorAll() { return []; }, closest() { return null; }, focus() { focused = true; }
    };
    const doc = {
        addEventListener() {},
        querySelectorAll(selector) { return selector.startsWith('[data-form-errors]') ? [error] : []; },
        getElementById() { throw new Error('Must not guess a field for a general error'); }
    };
    initialize(doc, {});
    assert.equal(error.attrs.role, 'alert');
    assert.equal(error.tabIndex, -1);
    assert.equal(focused, true);
});
