(function () {
    'use strict';
    const table = document.querySelector('.task-table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('.task-row-needs-attention'));
    if (!rows.length) return;
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const stepDuration = 1800;
    const stagger = rows.length > 1 ? stepDuration * 0.4 : stepDuration;
    const pulseTimers = new Map();
    table.style.setProperty('--task-attention-step-duration', `${stepDuration}ms`);
    let current = -1;
    let timer;

    function advance() {
        current = (current + 1) % rows.length;
        const row = rows[current];
        window.clearTimeout(pulseTimers.get(row));
        row.classList.remove('task-row-attention-current');
        // Restart the CSS animation when only one overdue row is present.
        void row.offsetWidth;
        row.classList.add('task-row-attention-current');
        pulseTimers.set(row, window.setTimeout(() => {
            row.classList.remove('task-row-attention-current');
            pulseTimers.delete(row);
        }, stepDuration));
        // A short queue must finish its first pulse before the next lap begins.
        const delay = current === rows.length - 1
            ? Math.max(stagger, stepDuration - stagger * (rows.length - 1)) : stagger;
        timer = window.setTimeout(advance, delay);
    }

    function synchronize() {
        window.clearTimeout(timer);
        pulseTimers.forEach(pulseTimer => window.clearTimeout(pulseTimer));
        pulseTimers.clear();
        rows.forEach(row => row.classList.remove('task-row-attention-current'));
        current = -1;
        const animate = !motion.matches && !document.hidden;
        table.classList.toggle('task-table-cascading', animate);
        if (animate) advance();
    }

    motion.addEventListener('change', synchronize);
    document.addEventListener('visibilitychange', synchronize);
    synchronize();
})();
