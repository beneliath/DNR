(function () {
    'use strict';
    function adjacentMonth(month, offset) {
        const [year, number] = month.split('-').map(Number);
        const date = new Date(Date.UTC(year, number - 1 + offset, 1));
        return date.toISOString().slice(0, 7);
    }
    if (typeof module !== 'undefined') module.exports = { adjacentMonth };
    if (typeof document === 'undefined') return;
    const viewer = document.getElementById('event-calendar');
    const viewport = viewer?.querySelector('.calendar-month-scroll');
    if (!viewport) return;
    const desktop = window.matchMedia('(min-width: 861px)');
    const body = viewport.querySelector('tbody');
    const months = new Map();
    let first = viewer.dataset.month;
    let last = first;
    let active = first;
    let loading = false;
    let failed = false;
    let frame;
    let pendingJump = null;
    let retryAction = null;
    let started = false;
    const status = document.createElement('p');
    status.className = 'calendar-scroll-status';
    status.setAttribute('role', 'status');
    viewport.after(status);
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'button-secondary';
    retry.textContent = 'Retry loading weeks';
    retry.hidden = true;
    status.after(retry);
    function remember(source) {
        months.set(source.dataset.month, {
            title: source.querySelector('#calendar-month-title').textContent,
            summary: source.querySelector('.calendar-month-heading-copy .calendar-month-summary').textContent,
            lastWeek: source.querySelector('.calendar-month-table tbody tr:last-child').dataset.week,
        });
    }
    remember(viewer);
    function updateHeading() {
        const top = viewport.getBoundingClientRect().top + viewport.querySelector('thead').getBoundingClientRect().height;
        // Ignore a thin sliver of the preceding week after fractional-pixel
        // layout or an overlapping row refresh changes its height slightly.
        const row = Array.from(body.rows).find(item => item.getBoundingClientRect().bottom > top + 12);
        const month = row?.querySelectorAll('.calendar-day-heading time')[6]?.dateTime.slice(0, 7);
        if (!months.has(month) || month === active) return;
        active = month;
        viewer.querySelectorAll('.calendar-month-day').forEach(cell => {
            cell.classList.toggle('is-outside-month', cell.querySelector('time').dateTime.slice(0, 7) !== month);
        });
        const info = months.get(month);
        viewer.querySelector('#calendar-month-title').textContent = info.title;
        viewer.querySelector('.calendar-month-heading-copy .calendar-month-summary').textContent = info.summary;
        viewport.setAttribute('aria-label', `${info.title} calendar; scroll to explore adjacent weeks`);
        viewport.querySelector('caption').textContent = `${info.title} and surrounding weeks`;
        const links = viewer.querySelectorAll('.calendar-desktop-navigation a');
        [ [links[0], adjacentMonth(month, -1)], [links[2], adjacentMonth(month, 1)] ].forEach(([link, target]) => {
            const url = new URL(link.href);
            url.searchParams.set('month', target);
            link.href = url.href;
            link.setAttribute('aria-label', `View ${target}`);
        });
        viewer.querySelectorAll('.calendar-view-filter').forEach(link => {
            const url = new URL(link.href);
            url.searchParams.delete('day');
            url.searchParams.set('month', month);
            link.href = url.href;
        });
    }
    async function load(direction, requestedMonth = null) {
        if (loading || failed || !desktop.matches) return;
        loading = true;
        status.textContent = 'Loading more weeks…';
        viewport.setAttribute('aria-busy', 'true');
        const month = requestedMonth || adjacentMonth(direction < 0 ? first : last, direction);
        try {
            const url = new URL('view_calendar.php', window.location.href);
            url.searchParams.set('month', month);
            url.searchParams.set('show', viewer.dataset.mode);
            url.searchParams.set('fragment', 'calendar');
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok || response.redirected) throw new Error('Calendar unavailable');
            const page = new DOMParser().parseFromString(await response.text(), 'text/html');
            const source = page.getElementById('event-calendar');
            if (!source || source.dataset.month !== month || !source.querySelector('tbody')) throw new Error('Calendar unavailable');
            const rows = Array.from(source.querySelectorAll('.calendar-month-table tbody tr[data-week]'));
            if (!rows.length) throw new Error('Calendar unavailable');
            const existing = new Map(Array.from(body.rows, row => [row.dataset.week, row]));
            const top = viewport.getBoundingClientRect().top + viewport.querySelector('thead').getBoundingClientRect().height;
            const anchor = Array.from(body.rows).find(row => row.getBoundingClientRect().bottom > top + 12) || body.firstElementChild;
            const anchorWeek = anchor.dataset.week;
            const anchorTop = anchor.getBoundingClientRect().top;
            remember(source);
            const fragment = document.createDocumentFragment();
            rows.forEach(row => {
                const previous = existing.get(row.dataset.week);
                if (direction !== 0 && previous) previous.replaceWith(row);
                else fragment.appendChild(row);
            });
            if (direction === 0) {
                body.replaceChildren(fragment);
                first = last = month;
                viewport.scrollTop = 0;
                pendingJump ??= month;
            } else {
                if (direction < 0) { body.prepend(fragment); first = month; }
                else { body.appendChild(fragment); last = month; }
                const restoredAnchor = Array.from(body.rows).find(row => row.dataset.week === anchorWeek);
                viewport.scrollTop += restoredAnchor.getBoundingClientRect().top - anchorTop;
            }
            body.querySelectorAll('.calendar-month-day').forEach(cell => {
                cell.classList.toggle('is-outside-month', cell.querySelector('time').dateTime.slice(0, 7) !== active);
            });
            status.textContent = 'Scroll up or down to explore adjacent weeks.';
            updateHeading();
        } catch (error) {
            failed = true;
            retryAction = () => load(direction, requestedMonth);
            status.textContent = 'More weeks could not be loaded. Retry or use Previous and Next.';
            retry.hidden = false;
        } finally {
            loading = false;
            viewport.removeAttribute('aria-busy');
            if (pendingJump !== null) {
                const target = pendingJump;
                pendingJump = null;
                goToMonth(target);
            } else if (!failed) {
                // A scroll can reach an edge while the previous request is in flight.
                // Recheck after insertion so that edge never remains an empty end.
                frame = requestAnimationFrame(checkEdges);
            }
        }
    }
    function goToMonth(month) {
        if (loading) { pendingJump = month; return; }
        failed = false;
        retry.hidden = true;
        const day = body.querySelector(`time[datetime="${month}-01"]`);
        // Fetch even when the first day happens to be in an adjacent month's
        // trailing week: that does not mean this month's items are loaded.
        if (!day || month < first || month > last) {
            load(0, month);
            return;
        }
        const row = day.closest('tr');
        const info = months.get(month);
        const lastRow = body.querySelector(`tr[data-week="${info.lastWeek}"]`);
        const headerHeight = viewport.querySelector('thead').getBoundingClientRect().height;
        const frameHeight = lastRow.getBoundingClientRect().bottom - row.getBoundingClientRect().top
            + headerHeight + viewport.offsetHeight - viewport.clientHeight;
        viewport.style.setProperty('--calendar-window-height', `${Math.ceil(frameHeight)}px`);
        viewport.scrollTop += row.getBoundingClientRect().top - viewport.getBoundingClientRect().top
            - headerHeight + 1;
        updateHeading();
        checkEdges();
    }
    viewer.querySelectorAll('.calendar-desktop-navigation a').forEach((link, index) => {
        link.addEventListener('click', event => {
            if (!desktop.matches || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            const target = index === 1 ? viewer.dataset.today.slice(0, 7) : adjacentMonth(active, index === 0 ? -1 : 1);
            goToMonth(target);
        });
    });
    function checkEdges() {
        if (!desktop.matches) return;
        updateHeading();
        if (viewport.scrollTop < 280) load(-1);
        else if (viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight < 280) load(1);
    }
    viewport.addEventListener('scroll', () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(checkEdges);
    }, { passive: true });
    retry.addEventListener('click', () => {
        failed = false;
        retry.hidden = true;
        if (retryAction) retryAction();
        else checkEdges();
    });
    function start() {
        if (!desktop.matches) return;
        viewport.classList.add('is-continuous');
        status.textContent = 'Scroll up or down to explore adjacent weeks.';
        if (!started) {
            started = true;
            viewport.scrollTop = 0;
            goToMonth(viewer.dataset.month);
        } else checkEdges();
    }
    desktop.addEventListener('change', start);
    start();
})();
