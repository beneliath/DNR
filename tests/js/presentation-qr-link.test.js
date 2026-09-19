'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function fixture({ secure = true, fail = false } = {}) {
    const copied = [], clicks = [], classes = () => ({ remove() {}, toggle() {} });
    const cards = ['https://example.test/surls/0123456789abcdef', 'https://example.test/surls/fedcba9876543210'].map(url => {
        const status = { textContent: '', classList: classes() };
        const button = { dataset: { copyQrLink: url }, classList: classes(), hidden: false, disabled: false,
            closest: selector => selector === '[data-copy-qr-link]' ? button : selector.includes('.presentation-qr-display') ? { querySelector: () => status } : null };
        return { button, status };
    });
    let fallback;
    const document = { readyState: 'loading', querySelectorAll: () => [], querySelector: () => null, getElementById: () => null,
        addEventListener(name, cb) { if (name === 'DOMContentLoaded') this.start = cb; if (name === 'click') clicks.push(cb); },
        createElement: () => ({ select() { fallback = this.value; }, remove() {} }), body: { appendChild() {} },
        execCommand() { if (fail) return false; copied.push(fallback); return true; }
    };
    const window = { isSecureContext: secure, addEventListener() {}, setTimeout() {}, clearTimeout() {} };
    const navigator = { clipboard: { async writeText(value) { if (fail) throw Error('Denied'); copied.push(value); } } };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/page-actions.js'), 'utf8'), { document, window, navigator });
    document.start();
    return { cards, copied, async click(index) { for (const cb of clicks) await cb({ target: cards[index].button }); } };
}
test('copies each QR encoded URL, clears prior feedback, and leaves buttons usable', async () => {
    const f = fixture();
    await f.click(0); await f.click(1);
    assert.deepEqual(f.copied, f.cards.map(card => card.button.dataset.copyQrLink));
    assert.equal(f.cards[0].status.textContent, '');
    assert.match(f.cards[1].status.textContent, /QR link copied/);
    assert.equal(f.cards[1].button.disabled, false);
});
test('QR link copying works on local HTTP and reports denied clipboard access', async () => {
    const local = fixture({ secure: false }); await local.click(0);
    assert.deepEqual(local.copied, [local.cards[0].button.dataset.copyQrLink]);
    const failed = fixture({ fail: true }); await failed.click(1);
    assert.deepEqual(failed.copied, []);
    assert.match(failed.cards[1].status.textContent, /could not be copied/);
    assert.equal(failed.cards[1].button.disabled, false);
});
