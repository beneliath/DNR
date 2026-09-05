const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function queueFixture(count, reduced = false) {
    const events = {};
    const timers = new Map();
    let nextTimer = 0;
    let now = 0;
    const row = () => {
        const classes = new Set();
        const starts = [];
        return { classes, starts, offsetWidth: 100, classList: {
            add: name => { classes.add(name); starts.push(now); }, remove: name => classes.delete(name),
            toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
        } };
    };
    const queues = (Array.isArray(count) ? count : [count]).map(size => {
        const rows = Array.from({ length: size }, row);
        return { ...row(), rows, querySelectorAll: () => rows, style: { setProperty() {} } };
    });
    const listen = (name, callback) => {
        const previous = events[name];
        events[name] = () => { if (previous) previous(); callback(); };
    };
    const motion = { matches: reduced, addEventListener: (name, callback) => listen('motion', callback) };
    const document = { hidden: false, querySelectorAll: () => queues,
        addEventListener: listen };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/task-attention.js'), 'utf8'), {
        document, window: { matchMedia: () => motion,
            setTimeout: (callback, delay) => { timers.set(++nextTimer, { callback, due: now + delay }); return nextTimer; },
            clearTimeout: id => timers.delete(id) },
    });
    return { document, motion, events, timers, rows: queues[0].rows,
        active: (queueIndex = 0) => queues[queueIndex].rows.flatMap((item, index) => item.classes.has('task-row-attention-current') ? [index] : []),
        advance: milliseconds => {
            const end = now + milliseconds;
            while (timers.size) {
                const [id, next] = Array.from(timers).sort((a, b) => a[1].due - b[1].due || a[0] - b[0])[0];
                if (next.due > end) break;
                now = next.due;
                timers.delete(id);
                next.callback();
            }
            now = end;
        },
    };
}

test('successive pulses overlap by sixty percent and wrap in DOM order', () => {
    for (const count of [3, 4, 50]) {
        const fixture = queueFixture(count);
        assert.deepEqual(fixture.active(), [0]);
        fixture.advance(575);
        assert.deepEqual(fixture.active(), [0]);
        fixture.advance(1);
        assert.deepEqual(fixture.active(), [0, 1]);
        for (let step = 2; step < count * 2; step++) {
            fixture.advance(576);
            assert.deepEqual(fixture.active(), [(step - 2) % count, (step - 1) % count, step % count].sort((a, b) => a - b));
            assert.equal(fixture.timers.size, 4);
        }
        for (const row of fixture.rows) {
            assert.equal(row.starts[1] - row.starts[0], count * 576);
        }
    }
});

test('short queues complete each pulse before restarting it', () => {
    const pair = queueFixture(2);
    pair.advance(576);
    assert.deepEqual(pair.active(), [0, 1]);
    pair.advance(863);
    assert.deepEqual(pair.rows[0].starts, [0]);
    pair.advance(1);
    assert.deepEqual(pair.rows[0].starts, [0, 1440]);
    pair.advance(576);
    assert.deepEqual(pair.rows[1].starts, [576, 2016]);
    const single = queueFixture(1);
    single.advance(720);
    assert.deepEqual(single.active(), [0]);
    single.advance(720);
    assert.deepEqual(single.active(), [0]);
    assert.equal(single.timers.size, 2);
    assert.deepEqual(single.rows[0].starts, [0, 1440]);
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
    assert.equal(fixture.timers.size, 2);
    assert.equal(queueFixture(0).timers.size, 0);
});

test('dashboard inquiry and work cascades run independently and both pause when hidden', () => {
    const fixture = queueFixture([2, 4]);
    fixture.advance(576);
    assert.deepEqual(fixture.active(0), [0, 1]);
    assert.deepEqual(fixture.active(1), [0, 1]);
    fixture.advance(864);
    assert.deepEqual(fixture.active(0), [0, 1]);
    assert.deepEqual(fixture.active(1), [1, 2]);
    fixture.document.hidden = true;
    fixture.events.visibilitychange();
    assert.deepEqual(fixture.active(0), []);
    assert.deepEqual(fixture.active(1), []);
    assert.equal(fixture.timers.size, 0);
    fixture.document.hidden = false;
    fixture.events.visibilitychange();
    assert.deepEqual(fixture.active(0), [0]);
    assert.deepEqual(fixture.active(1), [0]);
});

test('an inquiry list with no eligible rows does not suppress the My Work cascade', () => {
    const fixture = queueFixture([0, 4]);
    fixture.advance(576);
    assert.deepEqual(fixture.active(0), []);
    assert.deepEqual(fixture.active(1), [0, 1]);
});
