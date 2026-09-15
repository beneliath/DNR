function contribution(task, today) {
    const active = ['open', 'in_progress', 'waiting'].includes(task.status);
    const due = task.due_date;
    const end = new Date(`${today}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 7);
    return {
        overdue: Number(active && Boolean(due) && due < today),
        due_today: Number(active && due === today),
        next_seven_days: Number(active && Boolean(due) && due > today && due <= end.toISOString().slice(0, 10)),
        waiting: Number(active && task.status === 'waiting'),
    };
}

// The server summary includes tasks outside the displayed page. Apply only the
// changed row's contribution instead of rebuilding totals from the visible rows.
export function updateTaskDashboard(state, next, businessDate) {
    const previous = state.tasks.find((task) => task.id === next.id);
    if (!previous) return state;
    const before = contribution(previous, businessDate);
    const after = contribution(next, businessDate);
    const summary = {...state.summary};
    for (const key of Object.keys(before)) {
        summary[key] = Math.max(0, Number(summary[key] || 0) + after[key] - before[key]);
    }
    return {summary, tasks: state.tasks.map((task) => task.id === next.id ? next : task)};
}
