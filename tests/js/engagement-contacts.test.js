const test = require('node:test');
const assert = require('node:assert/strict');
const { selectedRoleCount } = require('../../src/assets/js/engagement-contacts.js');

test('selectedRoleCount reports checked event contact roles', () => {
    const container = {
        querySelectorAll(selector) {
            assert.equal(selector, 'input[type="checkbox"]:checked');
            return [{}, {}, {}];
        }
    };
    assert.equal(selectedRoleCount(container), 3);
    assert.equal(selectedRoleCount(null), 0);
});
