(function () {
    'use strict';
    // Also clear old animation classes if a cached page resumes after an update.
    document.querySelectorAll('.task-attention-cascading, .task-row-attention-current').forEach(function (element) {
        element.classList.remove('task-attention-cascading', 'task-row-attention-current');
    });

    const layouts = [['my-work', 'upcoming-engagements'], ['event-readiness', 'financial-closeouts'], ['booking-inquiries']]
        .map(ids => ids.map(id => {
            const panel = document.getElementById?.(id);
            const list = panel?.querySelector('.dashboard-record-list, .dashboard-attention-list');
            if (!list) return null;
            if (list.children.length > 4) {
                list.classList.add('dashboard-row-window');
                list.tabIndex = 0;
                list.setAttribute('aria-label', `${panel.querySelector('h2, h3').textContent}; scroll to see more items`);
            }
            return { panel, list, rows: Array.from(list.children) };
        }).filter(Boolean)).filter(entries => entries.length);
    if (!layouts.length) return;

    function sizeRows() {
        const pairedColumns = window.matchMedia('(min-width: 1021px)').matches;
        layouts.forEach(entries => {
            // Remove the previous minimum so rows can shrink after a wider resize.
            entries.forEach(({ list }) => list.style.removeProperty('--dashboard-row-height'));
            const groups = pairedColumns ? [entries] : entries.map(entry => [entry]);
            groups.forEach(group => {
                const height = Math.ceil(Math.max(...group.flatMap(({ rows }) => rows.map(row => row.getBoundingClientRect().height))));
                // Collapsed pipeline content is measured when its disclosure opens.
                if (!(height > 0)) return;
                group.forEach(({ list }) => {
                    list.style.setProperty('--dashboard-row-height', `${height}px`);
                    list.style.setProperty('--dashboard-row-window-height', `${height * 4}px`);
                });
            });
        });
    }
    let frame;
    function queueSizeRows() {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(sizeRows);
    }
    sizeRows();
    if (typeof ResizeObserver !== 'undefined') {
        const widths = new WeakMap();
        const observer = new ResizeObserver(entries => {
            let changed = false;
            entries.forEach(entry => {
                if (widths.get(entry.target) !== entry.contentRect.width) changed = true;
                widths.set(entry.target, entry.contentRect.width);
            });
            if (changed) queueSizeRows();
        });
        layouts.flat().forEach(({ panel }) => observer.observe(panel));
    }
    layouts.flat().forEach(({ panel }) => panel.closest('details')?.addEventListener('toggle', queueSizeRows));
    window.addEventListener('resize', queueSizeRows);
    document.fonts?.ready.then(queueSizeRows);
}());
