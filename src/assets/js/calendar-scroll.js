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
    // Keep the expanded day outside the scrolling table so it cannot be clipped.
    const popover = document.createElement('section');
    popover.id = 'calendar-day-popover';
    popover.className = 'calendar-day-popover';
    popover.hidden = true;
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-labelledby', 'calendar-day-popover-title');
    popover.setAttribute('aria-describedby', 'calendar-day-popover-summary');
    popover.innerHTML = '<div class="calendar-day-popover-heading"><div>'
        + '<h3 id="calendar-day-popover-title"></h3><p id="calendar-day-popover-summary"></p>'
        + '</div><button type="button" class="calendar-day-popover-close" aria-label="Close expanded day">Close</button></div>'
        + '<div class="calendar-day-popover-items"></div>';
    document.body.appendChild(popover);
    const popoverItems = popover.querySelector('.calendar-day-popover-items');
    let dayTrigger = null;
    let dayPinned = false;
    let closeDayTimer;
    let refreshDayFrame;
    function closeDay(restoreFocus = false) {
        clearTimeout(closeDayTimer);
        if (!dayTrigger) return;
        const previous = dayTrigger;
        previous.setAttribute('aria-expanded', 'false');
        dayTrigger = null;
        dayPinned = false;
        popover.hidden = true;
        if (restoreFocus && previous.isConnected) previous.focus({ preventScroll: true });
    }
    function openDay(trigger, pin = false) {
        if (!desktop.matches || (dayPinned && !pin)) return;
        clearTimeout(closeDayTimer);
        if (dayTrigger !== trigger) {
            closeDay();
            dayTrigger = trigger;
            const items = trigger.closest('td').querySelector('.calendar-day-items');
            popoverItems.replaceChildren(...Array.from(items.children, item => item.cloneNode(true)));
            popoverItems.scrollTop = 0;
            popover.querySelector('h3').textContent = trigger.querySelector('time').getAttribute('aria-label');
            popover.querySelector('p').textContent = `${items.children.length} ${items.children.length === 1 ? 'item' : 'items'}`;
            trigger.setAttribute('aria-expanded', 'true');
            popover.hidden = false;
            const anchor = trigger.getBoundingClientRect();
            const bounds = popover.getBoundingClientRect();
            const left = Math.max(12, Math.min(anchor.left, window.innerWidth - bounds.width - 12));
            const below = anchor.bottom + 6;
            const above = anchor.top - bounds.height - 6;
            const top = below + bounds.height <= window.innerHeight - 12 ? below
                : above >= 12 ? above : Math.max(12, window.innerHeight - bounds.height - 12);
            popover.style.left = `${left}px`;
            popover.style.top = `${top}px`;
        }
        dayPinned = pin;
        if (pin) popoverItems.querySelector('a').focus({ preventScroll: true });
    }
    function scheduleCloseDay() {
        clearTimeout(closeDayTimer);
        closeDayTimer = setTimeout(() => {
            if (!dayPinned && !dayTrigger?.matches(':hover') && !popover.matches(':hover')
                && !popover.contains(document.activeElement)) closeDay();
        }, 220);
    }
    function refreshDays() {
        if (dayTrigger && (!dayTrigger.isConnected || !desktop.matches)) {
            closeDay(popover.contains(document.activeElement));
        }
        body.querySelectorAll('.calendar-month-day').forEach(cell => {
            const items = cell.querySelector('.calendar-day-items');
            const heading = cell.querySelector('.calendar-day-heading');
            let trigger = heading.querySelector('.calendar-day-toggle');
            const hasItems = desktop.matches && items.clientHeight > 0 && items.children.length > 0;
            if (!hasItems) {
                if (trigger) {
                    if (dayTrigger === trigger) closeDay(popover.contains(document.activeElement));
                    trigger.replaceWith(trigger.querySelector('time'));
                }
                return;
            }
            if (!trigger) {
                const date = heading.querySelector('time');
                trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.className = 'calendar-day-toggle';
                trigger.setAttribute('aria-haspopup', 'dialog');
                trigger.setAttribute('aria-controls', popover.id);
                trigger.setAttribute('aria-expanded', 'false');
                date.replaceWith(trigger);
                trigger.appendChild(date);
                const indicator = document.createElement('span');
                indicator.setAttribute('aria-hidden', 'true');
                indicator.textContent = '▾';
                trigger.appendChild(indicator);
            }
            const count = items.children.length;
            const label = `View ${count} ${count === 1 ? 'item' : 'items'} for ${trigger.querySelector('time').getAttribute('aria-label')}`;
            trigger.setAttribute('aria-label', label);
        });
    }
    function queueRefreshDays() {
        cancelAnimationFrame(refreshDayFrame);
        refreshDayFrame = requestAnimationFrame(refreshDays);
    }
    // Adjacent months replace overlapping weeks and append new day cells.
    new MutationObserver(queueRefreshDays).observe(body, { childList: true, subtree: true });
    viewport.addEventListener('pointerover', event => {
        const trigger = event.target.closest('.calendar-day-toggle');
        if (event.pointerType !== 'touch' && trigger && !trigger.contains(event.relatedTarget)) openDay(trigger);
    });
    viewport.addEventListener('pointerout', event => {
        const trigger = event.target.closest('.calendar-day-toggle');
        if (trigger && !trigger.contains(event.relatedTarget)) scheduleCloseDay();
    });
    viewport.addEventListener('click', event => {
        const trigger = event.target.closest('.calendar-day-toggle');
        if (!trigger) return;
        if (dayTrigger === trigger && dayPinned) closeDay();
        else openDay(trigger, true);
    });
    popover.addEventListener('pointerenter', () => clearTimeout(closeDayTimer));
    popover.addEventListener('pointerleave', scheduleCloseDay);
    popover.addEventListener('focusout', scheduleCloseDay);
    popover.querySelector('button').addEventListener('click', () => closeDay(true));
    document.addEventListener('pointerdown', event => {
        if (dayTrigger && !dayTrigger.contains(event.target) && !popover.contains(event.target)) closeDay();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && dayTrigger) {
            event.preventDefault();
            closeDay(popover.contains(document.activeElement));
        }
    });
    window.addEventListener('scroll', event => {
        if (!popover.contains(event.target)) closeDay(popover.contains(document.activeElement));
    }, true);
    window.addEventListener('resize', () => {
        closeDay(popover.contains(document.activeElement));
        queueRefreshDays();
    });
    desktop.addEventListener('change', queueRefreshDays);
    queueRefreshDays();
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
    retry.textContent = 'Retry Loading Weeks';
    retry.hidden = true;
    status.after(retry);
    function remember(source) {
        months.set(source.dataset.month, {
            title: source.querySelector('#calendar-month-title').textContent,
            summary: source.querySelector('.calendar-month-heading-copy .calendar-month-summary').textContent,
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
    function sizeWindow() {
        if (!desktop.matches || !body.rows.length) return;
        const headerHeight = viewport.querySelector('thead').getBoundingClientRect().height;
        const weekHeight = body.rows[0].getBoundingClientRect().height;
        const frameHeight = headerHeight + 6 * weekHeight + viewport.offsetHeight - viewport.clientHeight;
        viewport.style.setProperty('--calendar-window-height', `${Math.ceil(frameHeight)}px`);
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
        const headerHeight = viewport.querySelector('thead').getBoundingClientRect().height;
        sizeWindow();
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
        } else { sizeWindow(); checkEdges(); }
    }
    window.addEventListener('resize', sizeWindow);
    desktop.addEventListener('change', start);
    start();
})();
