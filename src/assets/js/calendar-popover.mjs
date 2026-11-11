import {calendarDates, isoCalendarDate} from './analytics-calendar.mjs';

const activeCalendars = new WeakMap();
const months = Array.from({length: 12}, (_, month) =>
    new Date(Date.UTC(2000, month, 1)).toLocaleDateString('en-US', {month: 'long', timeZone: 'UTC'}));

function dateString(date) {
    return isoCalendarDate(date.getUTCFullYear(), date.getUTCMonth() + 1, date.getUTCDate());
}

function keyboardDate(date, key) {
    const target = new Date(date);
    const offset = {ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7,
        Home: -date.getUTCDay(), End: 6 - date.getUTCDay()}[key];
    if (offset !== undefined) target.setUTCDate(target.getUTCDate() + offset);
    else if (key === 'PageUp' || key === 'PageDown') {
        target.setUTCDate(1);
        target.setUTCMonth(target.getUTCMonth() + (key === 'PageUp' ? -1 : 1) + 1, 0);
        target.setUTCDate(Math.min(date.getUTCDate(), target.getUTCDate()));
    } else return '';
    return dateString(target);
}

export function closeCalendar(trigger) {
    const active = activeCalendars.get(trigger.ownerDocument);
    if (active?.trigger === trigger) active.close();
}

// Mount inside the owning dialog or popover so its focus handling and light-dismiss still apply.
export function openCalendar(trigger, {label, selected, min, max, yearless, choose}) {
    const doc = trigger.ownerDocument;
    const win = doc.defaultView;
    const previous = activeCalendars.get(doc);
    previous?.close();
    if (previous?.trigger === trigger) return;
    const now = new Date();
    const today = isoCalendarDate(yearless ? 2000 : now.getFullYear(), now.getMonth() + 1, now.getDate());
    const first = min || (yearless ? '2000-01-01' : '0001-01-01');
    const last = max || (yearless ? '2000-12-31' : '9999-12-31');
    const inRange = value => value.length === 10 && value >= first && value <= last;
    const initial = selected || today;
    let focused = initial < first ? first : initial > last ? last : initial;
    let displayed = new Date(focused.slice(0, 7) + '-01T00:00:00Z');

    function make(tag, text, className) {
        const node = doc.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (className) node.className = className;
        return node;
    }

    const panel = make('div', undefined, 'date-picker-calendar');
    panel.id = trigger.getAttribute('aria-controls');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', label + ' Calendar');
    panel.setAttribute('popover', 'auto');
    const host = trigger.closest('dialog, [popover], [role="dialog"]') || doc.body;
    host.append(panel);
    const events = new win.AbortController();
    const options = {signal: events.signal};
    function close(restoreFocus = false) {
        if (events.signal.aborted) return;
        events.abort();
        detached.disconnect();
        activeCalendars.delete(doc);
        trigger.setAttribute('aria-expanded', 'false');
        panel.remove();
        if (restoreFocus && trigger.isConnected) trigger.focus();
    }
    const detached = new win.MutationObserver(() => {
        if (!trigger.isConnected || !panel.isConnected || !trigger.getClientRects().length) close();
    });
    detached.observe(doc.body, {childList: true, subtree: true});
    activeCalendars.set(doc, {trigger, close});
    function position() {
        const rect = trigger.getBoundingClientRect();
        const width = Math.min(310, win.innerWidth - 24);
        panel.style.width = width + 'px';
        panel.style.left = Math.max(12, Math.min(rect.left, win.innerWidth - width - 12)) + 'px';
        const height = panel.getBoundingClientRect().height;
        const below = rect.bottom + 8;
        panel.style.top = (below + height <= win.innerHeight - 12 ? below : Math.max(12, rect.top - height - 8)) + 'px';
    }
    function button(text, ariaLabel, className, action) {
        const control = make('button', text, className);
        control.type = 'button';
        control.setAttribute('aria-label', ariaLabel);
        control.addEventListener('click', action);
        return control;
    }
    function render(focus = 'day') {
        const month = displayed.getUTCMonth();
        const year = displayed.getUTCFullYear();
        const monthKey = dateString(displayed).slice(0, 7);
        const header = make('div', undefined, 'date-picker-header');
        function move(delta) {
            displayed.setUTCMonth(month + delta);
            render(delta < 0 ? 'previous' : 'next');
        }
        const prev = button('‹', 'Previous Month', 'date-picker-nav', () => move(-1));
        const next = button('›', 'Next Month', 'date-picker-nav', () => move(1));
        prev.disabled = monthKey <= first.slice(0, 7);
        next.disabled = monthKey >= last.slice(0, 7);
        const monthSelect = make('select');
        monthSelect.setAttribute('aria-label', 'Month');
        months.forEach((name, index) => {
            const option = make('option', name);
            const key = isoCalendarDate(year, index + 1, 1).slice(0, 7);
            option.disabled = key < first.slice(0, 7) || key > last.slice(0, 7);
            option.value = String(index);
            monthSelect.append(option);
        });
        monthSelect.value = String(month);
        monthSelect.addEventListener('change', () => { displayed.setUTCMonth(Number(monthSelect.value)); render(); });
        header.append(prev, monthSelect);
        if (!yearless) {
            const yearSelect = make('select');
            yearSelect.setAttribute('aria-label', 'Year');
            const start = Math.max(Number(first.slice(0, 4)), Math.min(1900, year - 10));
            const end = Math.min(Number(last.slice(0, 4)), Math.max(now.getFullYear() + 10, year + 10));
            for (let y = start; y <= end; y++) yearSelect.append(make('option', String(y)));
            yearSelect.value = String(year);
            yearSelect.addEventListener('change', () => {
                const key = isoCalendarDate(Number(yearSelect.value), month + 1, 1).slice(0, 7);
                const bounded = key < first.slice(0, 7) ? first.slice(0, 7) : key > last.slice(0, 7) ? last.slice(0, 7) : key;
                displayed = new Date(bounded + '-01T00:00:00Z');
                render();
            });
            header.append(yearSelect);
        }
        header.append(next);
        const grid = make('div', undefined, 'date-picker-grid');
        grid.setAttribute('role', 'group');
        grid.setAttribute('aria-label', months[month] + (yearless ? '' : ' ' + year));
        if (!yearless) ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(day => grid.append(make('span', day)));
        const days = calendarDates(year, month).filter(day => !yearless || !day.outside);
        if (!days.some(day => !day.outside && day.iso === focused && inRange(day.iso))) {
            focused = days.find(day => !day.outside && day.iso === selected && inRange(day.iso))?.iso ||
                days.find(day => !day.outside && inRange(day.iso))?.iso;
        }
        let active;
        days.forEach(day => {
            const name = day.date.toLocaleDateString('en-US', yearless
                ? {month: 'long', day: 'numeric', timeZone: 'UTC'} : {dateStyle: 'full', timeZone: 'UTC'});
            const control = button(String(day.day), name, '', () => { close(true); choose(day.iso); });
            control.dataset.date = day.iso;
            control.disabled = !inRange(day.iso);
            control.tabIndex = day.iso === focused ? 0 : -1;
            if (control.tabIndex === 0) active = control;
            if (day.outside) control.classList.add('outside-month');
            if (day.iso === selected) { control.classList.add('selected'); control.setAttribute('aria-pressed', 'true'); }
            if (day.iso === today) control.setAttribute('aria-current', 'date');
            control.addEventListener('keydown', event => {
                const target = keyboardDate(day.date, event.key);
                if (!target) return;
                event.preventDefault();
                if (!inRange(target)) return;
                focused = target;
                displayed = new Date(target.slice(0, 7) + '-01T00:00:00Z');
                render();
            });
            grid.append(control);
        });
        panel.replaceChildren(header, grid);
        position();
        const target = focus === 'previous' ? prev : focus === 'next' ? next : active;
        (target && !target.disabled ? target : active)?.focus({preventScroll: true});
    }
    trigger.setAttribute('aria-expanded', 'true');
    if (typeof panel.showPopover === 'function') {
        panel.addEventListener('toggle', event => { if (event.newState === 'closed') close(); }, options);
        panel.showPopover();
    } else {
        doc.addEventListener('pointerdown', event => {
            if (!panel.contains(event.target) && !trigger.contains(event.target)) close();
        }, {...options, capture: true});
    }
    panel.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(true); }
    }, options);
    win.addEventListener('resize', position, options);
    doc.addEventListener('scroll', position, {...options, capture: true, passive: true});
    render();
}
