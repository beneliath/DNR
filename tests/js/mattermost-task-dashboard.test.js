'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');

test('task actions update all summary tiles without losing counts outside the visible page', async () => {
    const {updateTaskDashboard} = await import('../../mattermost-plugin/webapp/src/task_dashboard_state.mjs');
    const task = {id: 12, status: 'waiting', due_date: '2026-09-14'};
    const state = {tasks: [task], summary: {overdue: 6, waiting: 3, due_today: 4, next_seven_days: 10}};
    const completed = {...task, status: 'completed'};
    const next = updateTaskDashboard(state, completed, '2026-09-15');
    assert.deepEqual(next.summary, {overdue: 5, waiting: 2, due_today: 4, next_seven_days: 10});
    assert.deepEqual(next.tasks, [completed]);
    assert.deepEqual(updateTaskDashboard(next, completed, '2026-09-15'), next);
    const reopened = updateTaskDashboard(next, {...task, status: 'open'}, '2026-09-15');
    assert.equal(reopened.summary.overdue, 6);
    assert.equal(reopened.summary.waiting, 2);
    assert.deepEqual(state.tasks, [task]);
});

test('task summary date boundaries follow the server business date, including DST', async () => {
    const {updateTaskDashboard} = await import('../../mattermost-plugin/webapp/src/task_dashboard_state.mjs');
    for (const [due, expected] of [[null, [0, 0]], ['2026-03-08', [1, 0]], ['2026-03-15', [0, 1]], ['2026-03-16', [0, 0]]]) {
        const previous = {id: 1, status: 'completed', due_date: due};
        const result = updateTaskDashboard({tasks: [previous], summary: {}}, {...previous, status: 'open'}, '2026-03-08');
        assert.deepEqual([result.summary.due_today, result.summary.next_seven_days], expected);
        assert.equal(result.summary.overdue, 0);
    }
});
