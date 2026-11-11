const test = require('node:test');
const assert = require('node:assert/strict');

test('reference labels use Title Case for current and fallback status values', async () => {
    const {referenceLabel} = await import('../../src/assets/js/message-monkey/dom.mjs');
    for (const [value, expected] of [
        ['work_in_progress', 'Work in Progress'],
        ['delivery_uncertain', 'Delivery Uncertain'],
        ['non-subscribed', 'Non-Subscribed'],
        ['API request', 'API Request'],
        ['PPT slidedeck and QR codes', 'PPT Slidedeck and QR Codes'],
        [null, ''],
    ]) {
        assert.equal(referenceLabel(value), expected);
    }
});
