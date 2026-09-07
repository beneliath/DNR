'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/page-actions.js'), 'utf8');

function fixture({ secure = true, fail = false, firstData } = {}) {
    const copied = [];
    const timers = new Map();
    let nextTimer = 0;
    let fallbackValue;
    const groups = ['First', 'Second'].map((name, index) => {
        const status = { textContent: '' };
        const data = { textContent: index === 0 && firstData !== undefined ? firstData : JSON.stringify({ text: name + ' presentation', markdown: '# ' + name + ' presentation' }) };
        const buttons = ['text', 'markdown'].map(format => ({
            dataset: { copyFormat: format }, textContent: format === 'text' ? 'Copy Text' : 'Copy MD', disabled: false,
            addEventListener(event, callback) { this[event] = callback; }
        }));
        return { buttons, status,
            querySelector: selector => selector === '[data-presentation-export-data]' ? data : status,
            querySelectorAll: () => buttons
        };
    });
    const document = {
        readyState: 'loading', getElementById: () => null, querySelector: () => null,
        querySelectorAll: selector => selector === '[data-presentation-export]' ? groups : [],
        addEventListener(name, callback) { if (name === 'DOMContentLoaded') this.initialize = callback; },
        createElement: () => ({ select() { fallbackValue = this.value; }, remove() {} }),
        body: { appendChild() {} },
        execCommand() { if (fail) return false; copied.push(fallbackValue); return true; }
    };
    const window = { isSecureContext: secure, addEventListener() {},
        setTimeout(callback) { const id = ++nextTimer; timers.set(id, callback); return id; },
        clearTimeout(id) { timers.delete(id); }
    };
    const navigator = { clipboard: { async writeText(value) { if (fail) throw new Error('Denied'); copied.push(value); } } };
    vm.runInNewContext(source, { document, window, navigator });
    document.initialize();
    return { groups, copied, timers };
}

test('copying from two presentations keeps formats, payloads, and feedback independent', async () => {
    const f = fixture();
    await f.groups[1].buttons[1].click();
    assert.deepEqual(f.copied, ['# Second presentation']);
    assert.equal(f.groups[0].status.textContent, '');
    await f.groups[0].buttons[0].click();
    assert.deepEqual(f.copied, ['# Second presentation', 'First presentation']);
    await f.groups[0].buttons[0].click();
    for (const callback of f.timers.values()) callback();
    assert.equal(f.groups[0].buttons[0].textContent, 'Copy Text');
    assert.equal(f.groups[1].buttons[1].textContent, 'Copy MD');
});

test('local HTTP fallback copies the selected presentation', async () => {
    const f = fixture({ secure: false });
    await f.groups[1].buttons[0].click();
    assert.deepEqual(f.copied, ['Second presentation']);
});

test('copy failures are local and controls remain usable', async () => {
    const f = fixture({ fail: true });
    await f.groups[1].buttons[0].click();
    assert.equal(f.groups[1].buttons[0].textContent, 'Copy failed');
    assert.equal(f.groups[1].buttons[0].disabled, false);
    assert.equal(f.groups[0].status.textContent, '');
    assert.deepEqual(f.copied, []);
});

test('bad or missing copy data cannot replace the clipboard with an empty export', async () => {
    for (const firstData of ['{', '{}', 'null']) {
        const f = fixture({ firstData });
        if (f.groups[0].buttons[0].click) await f.groups[0].buttons[0].click();
        assert.deepEqual(f.copied, []);
        await f.groups[1].buttons[0].click();
        assert.deepEqual(f.copied, ['Second presentation']);
    }
});
