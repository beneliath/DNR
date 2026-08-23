const test = require('node:test');
const assert = require('node:assert/strict');

const { lifecycleFieldState } = require('../../src/assets/js/engagement-lifecycle.js');

test('lifecycle fields reveal only relevant cancellation and replacement controls', function () {
    assert.deepEqual(lifecycleFieldState('active'), {
        cancellationVisible: false,
        rescheduleVisible: false
    });
    assert.deepEqual(lifecycleFieldState('postponed'), {
        cancellationVisible: false,
        rescheduleVisible: true
    });
    assert.deepEqual(lifecycleFieldState('canceled'), {
        cancellationVisible: true,
        rescheduleVisible: true
    });
    assert.deepEqual(lifecycleFieldState('completed'), {
        cancellationVisible: false,
        rescheduleVisible: false
    });
});
