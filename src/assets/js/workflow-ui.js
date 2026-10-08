(function () {
    'use strict';
    const taskBulk = document.querySelector('[data-task-bulk]');
    if (taskBulk) {
        const items = Array.from(document.querySelectorAll('[data-task-selection]'));
        const all = taskBulk.querySelector('[data-task-select-all]');
        const operation = taskBulk.querySelector('[data-task-operation]');
        const update = function () {
            const count = items.filter(item => item.checked).length;
            taskBulk.querySelector('[data-task-selection-count]').textContent = count + (count === 1 ? ' task selected' : ' tasks selected');
            taskBulk.querySelectorAll('[data-task-bulk-submit]').forEach(button => { button.disabled = !count; });
            all.disabled = items.length === 0 || count === items.length;
            taskBulk.querySelector('[data-task-clear]').hidden = count === 0;
            taskBulk.querySelector('[data-task-owner]').hidden = operation.value !== 'assign';
            taskBulk.querySelector('[data-task-date]').hidden = operation.value !== 'due';
        };
        all.addEventListener('click', function () { items.forEach(item => { item.checked = true; }); update(); });
        taskBulk.querySelector('[data-task-clear]').addEventListener('click', function () { items.forEach(item => { item.checked = false; }); update(); });
        items.forEach(item => item.addEventListener('change', update));
        operation.addEventListener('change', update);
        window.addEventListener('pageshow', update);
        update();
    }
    const pipeline = document.querySelector('.dashboard-pipeline-disclosure');
    if (pipeline) {
        const sidebar = document.getElementById('app-sidebar');
        const user = sidebar ? sidebar.getAttribute('data-nav-preference-user') : '';
        const storageKey = 'dnr.dashboard.' + encodeURIComponent(user) + '.booking-pipeline';
        try {
            const saved = window.DnrAccountContext.local.getItem(storageKey);
            if (saved === 'open' || saved === 'closed') pipeline.open = saved === 'open';
        } catch (_) { /* Keep the collapsed default when browser storage is unavailable. */ }
        pipeline.addEventListener('toggle', function () {
            try { window.DnrAccountContext.local.setItem(storageKey, pipeline.open ? 'open' : 'closed'); }
            catch (_) { /* The disclosure still works without browser storage. */ }
        });
    }
    document.querySelectorAll('[data-dismiss-undo]').forEach(function (button) {
        const notice = button.closest('.task-undo-notice');
        const token = notice.querySelector('[name="undo_token"]').value;
        try { if (window.DnrAccountContext.session.getItem('dismissed-task-undo') === token) notice.hidden = true; } catch (_) {}
        button.addEventListener('click', function () {
            try { window.DnrAccountContext.session.setItem('dismissed-task-undo', token); } catch (_) {}
            notice.remove();
        });
    });

    const route = window.location.pathname.split('/').pop();
    const recordLists = ['tasks.php', 'contacts.php', 'organizations.php', 'speakers.php', 'engagements.php'];
    if (recordLists.includes(route)) {
        document.querySelectorAll('main table').forEach(function (table) {
            const headers = Array.from(table.querySelectorAll('thead th')).map(function (header) { return header.textContent.trim(); });
            if (!headers.length) return;
            table.classList.add('mobile-record-table');
            table.setAttribute('role', 'table');
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.setAttribute('role', 'row');
                Array.from(row.cells).forEach(function (cell, index) {
                    cell.setAttribute('role', 'cell');
                    if (cell.colSpan === 1) cell.dataset.field = headers[index] || '';
                });
            });
        });
    }
    document.querySelectorAll('.list-search-form, .inquiry-filter-bar, .inbound-queue-search, .reimbursement-filter-form').forEach(function (form) {
        form.classList.add('unified-list-filter');
        if (!form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]')) {
            const button = document.createElement('button');
            button.type = 'submit'; button.className = 'button-secondary'; button.textContent = 'Search';
            form.appendChild(button);
        }
    });
}());
