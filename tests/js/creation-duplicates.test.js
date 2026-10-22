'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/creation-duplicates.js'), 'utf8');

test('duplicate lookup follows the enabled region control when input and select share a name', async () => {
    const control = (name, value, disabled = false) => ({
        name, value, disabled, listeners: {},
        addEventListener(event, handler) { this.listeners[event] = handler; }
    });
    const name = control('organization_name', 'Mobile fixture');
    const text = control('physical_state', 'Ontario', true);
    const select = control('physical_state', 'TX');
    const controls = [name, text, select];
    const distinct = { checked: true }, token = { value: '' };
    const panel = {
        hidden: true,
        querySelector(selector) {
            return selector === '[data-duplicate-matches]' ? { replaceChildren() {} }
                : selector === '[name="duplicate_token"]' ? token : distinct;
        }
    };
    const form = {
        dataset: { duplicateKind: 'organization' },
        elements: { namedItem() { return null; } },
        querySelector() { return panel; },
        querySelectorAll(selector) {
            const fieldName = selector.match(/name="([^"]+)"/)[1];
            return controls.filter(field => field.name === fieldName);
        }
    };
    let timer;
    const queries = [];
    vm.runInNewContext(source, {
        document: { querySelectorAll() { return [form]; } }, URLSearchParams,
        clearTimeout() {}, setTimeout(handler) { timer = handler; },
        fetch(url) {
            queries.push(new URL(url, 'http://fixture.invalid').searchParams);
            return Promise.resolve({ ok: true, json: async () => ({ matches: [], token: 'checked' }) });
        }
    });
    select.listeners.input();
    assert.equal(distinct.checked, false);
    timer();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(queries[0].get('physical_state'), 'TX');
    assert.equal(token.value, 'checked');
    select.disabled = true;
    text.disabled = false;
    text.listeners.input();
    timer();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(queries[1].get('physical_state'), 'Ontario');
});
