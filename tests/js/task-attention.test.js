const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('overdue work remains static, including when an older page resumes', () => {
    const classes = new Set(['task-row-overdue', 'task-row-needs-attention', 'task-row-attention-current']);
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/task-attention.js'), 'utf8'), {
        document: { querySelectorAll: () => [{ classList: { remove: (...names) => names.forEach(name => classes.delete(name)) } }] },
        setTimeout: () => assert.fail('Overdue work must not schedule motion'),
        setInterval: () => assert.fail('Overdue work must not schedule motion'),
    });
    assert.deepEqual([...classes], ['task-row-overdue', 'task-row-needs-attention']);
});
