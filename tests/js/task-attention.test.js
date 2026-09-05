const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function queueFixture(count, reduced = false) {
    const events = {};
    const timers = new Map();
    let nextTimer = 0;
    const row = () => {
        const classes = new Set();
        return { classes, offsetWidth: 100, classList: {
            add: name => classes.add(name), remove: name => classes.delete(name),
            toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
        } };
    };
    const rows = Array.from({ length: count }, row);
    const table = { ...row(), querySelectorAll: () => rows, style: { setProperty() {} } };
    const motion = { matches: reduced, addEventListener: (name, callback) => { events.motion = callback; } };
    const document = { hidden: false, querySelector: () => table,
        addEventListener: (name, callback) => { events[name] = callback; } };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/task-attention.js'), 'utf8'), {
        document, window: { matchMedia: () => motion,
            setTimeout: callback => { timers.set(++nextTimer, callback); return nextTimer; },
            clearTimeout: id => timers.delete(id) },
    });
    return { document, motion, events, timers,
        active: () => rows.flatMap((item, index) => item.classes.has('task-row-attention-current') ? [index] : []),
        advance: () => { const [id, callback] = timers.entries().next().value; timers.delete(id); callback(); },
    };
}

test('eligible rows highlight one at a time in DOM order and wrap', () => {
    for (const count of [1, 4, 50]) {
        const fixture = queueFixture(count);
        for (let step = 0; step < count * 2; step++) {
            assert.deepEqual(fixture.active(), [step % count]);
            assert.equal(fixture.timers.size, 1);
            fixture.advance();
        }
    }
});

test('reduced motion and hidden pages stop the cascade without accumulating timers', () => {
    const fixture = queueFixture(4, true);
    assert.deepEqual(fixture.active(), []);
    assert.equal(fixture.timers.size, 0);
    fixture.motion.matches = false;
    fixture.events.motion();
    assert.deepEqual(fixture.active(), [0]);
    fixture.document.hidden = true;
    fixture.events.visibilitychange();
    assert.deepEqual(fixture.active(), []);
    assert.equal(fixture.timers.size, 0);
    fixture.document.hidden = false;
    fixture.events.visibilitychange();
    assert.deepEqual(fixture.active(), [0]);
    assert.equal(fixture.timers.size, 1);
    assert.equal(queueFixture(0).timers.size, 0);
});
