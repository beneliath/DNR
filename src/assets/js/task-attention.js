(function () {
    'use strict';
    const table = document.querySelector('.task-table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('.task-row-needs-attention'));
    if (!rows.length) return;
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const stepDuration = 1800;
    table.style.setProperty('--task-attention-step-duration', `${stepDuration}ms`);
    let current = -1;
    let timer;

    function advance() {
        if (current >= 0) rows[current].classList.remove('task-row-attention-current');
        current = (current + 1) % rows.length;
        // Restart the CSS animation when only one overdue row is present.
        void rows[current].offsetWidth;
        rows[current].classList.add('task-row-attention-current');
        timer = window.setTimeout(advance, stepDuration);
    }

    function synchronize() {
        window.clearTimeout(timer);
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
