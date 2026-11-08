import {formatCalendarDate, parseCalendarDate} from './analytics-calendar.mjs';
import {closeCalendar, openCalendar} from './calendar-popover.mjs';

const selector = 'input[data-date-picker],input[type=date],input[type=datetime-local]';
const fields = new WeakMap();
const installed = new WeakSet();
let nextId = 0;

function birthdayDate(value) {
    const parts = /^(\d{1,2})[/-](\d{1,2})$/.exec(value.trim());
    return parts ? parseCalendarDate(parts[1] + '/' + parts[2] + '/2000') : '';
}

// The original named control remains the form's value and validation target. Only the
// formatted editor and our calendar are interactive. Existing drafts and input/change
// listeners continue to see ISO dates, local date-times or the original yearless value.
export function attachDatePicker(source) {
    if (fields.has(source)) return;
    const doc = source.ownerDocument;
    const win = doc.defaultView;
    const kind = source.dataset.datePicker || source.type;
    source.dataset.datePicker = kind;
    const birthday = kind === 'birthday';
    const withTime = kind === 'datetime-local';
    const labels = Array.from(source.labels || []);
    const label = source.getAttribute('aria-label') || labels.map(node => node.textContent.trim()).join(' ') || 'Date';
    const group = doc.createElement('span');
    group.className = 'date-picker-field';
    const box = doc.createElement('span');
    box.className = 'date-picker-box';
    const text = doc.createElement('input');
    text.id = (source.id || 'date-picker-' + ++nextId) + '-display';
    text.className = source.className;
    text.type = 'text';
    text.placeholder = birthday ? 'MM/DD' : 'MM/DD/YYYY';
    text.autocomplete = 'off';
    for (const attr of ['aria-label', 'aria-labelledby', 'aria-describedby', 'form']) {
        if (source.hasAttribute(attr)) text.setAttribute(attr, source.getAttribute(attr));
    }
    if (!labels.length && !text.hasAttribute('aria-labelledby')) text.setAttribute('aria-label', label);
    const trigger = doc.createElement('button');
    trigger.type = 'button';
    trigger.className = 'date-picker-trigger';
    trigger.setAttribute('aria-label', 'Choose ' + label.toLowerCase());
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-controls', text.id + '-calendar');
    trigger.setAttribute('popovertarget', text.id + '-calendar');
    trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="17" rx="2"/><path d="M3 10h18M7 2v6M17 2v6"/></svg>';
    const time = withTime ? doc.createElement('input') : null;
    if (time) {
        time.type = 'time';
        time.className = 'date-picker-time';
        time.setAttribute('aria-label', label + ' time');
        if (source.hasAttribute('form')) time.setAttribute('form', source.getAttribute('form'));
    }

    source.before(group);
    box.append(text, trigger);
    group.append(box);
    if (time) group.append(time);
    source.hidden = true;
    group.append(source);
    // Retain native constraint validation and input types used by saved drafts, behind the editor.
    if (!birthday) source.type = kind;
    if (!source.hasAttribute('aria-label')) source.setAttribute('aria-label', label);
    source.dataset.errorTarget = text.id;
    labels.forEach(node => { node.htmlFor = text.id; });

    // Let the actual text input size the row under the host form's CSS. Position
    // only the icon inside its border, including when a dialog or mobile layout opens.
    const alignIcon = () => {
        const style = win.getComputedStyle(text);
        box.style.setProperty('--date-input-top', style.marginTop);
        box.style.setProperty('--date-input-bottom', style.marginBottom);
    };
    new win.ResizeObserver(alignIcon).observe(box);
    alignIcon();

    const datePart = () => birthday ? birthdayDate(source.value) : source.value.slice(0, 10);
    function display(iso) {
        const formatted = formatCalendarDate(iso);
        return birthday ? formatted.slice(0, 5) : formatted;
    }
    function readDate() {
        return birthday ? birthdayDate(text.value) : parseCalendarDate(text.value);
    }
    function syncFlags() {
        text.disabled = source.disabled;
        text.readOnly = source.readOnly;
        text.setAttribute('aria-required', String(source.required));
        trigger.disabled = source.disabled || source.readOnly;
        if (time) {
            time.disabled = source.disabled;
            time.readOnly = source.readOnly;
            time.step = source.step || '60';
            time.setAttribute('aria-required', String(source.required));
        }
    }
    let lastCommitted;
    function refresh() {
        lastCommitted = source.value;
        text.value = display(datePart());
        text.setCustomValidity('');
        if (time) { time.value = source.value.split('T')[1] || ''; time.setCustomValidity(''); }
        syncFlags();
    }
    fields.set(source, refresh);
    refresh();
    new win.MutationObserver(() => {
        syncFlags();
        closeCalendar(trigger);
    }).observe(source, {attributes: true, attributeFilter: ['disabled', 'readonly', 'required', 'step', 'min', 'max']});
    let updating = false;
    function commit(type) {
        const iso = readDate();
        const previous = source.value;
        text.setCustomValidity(text.value && !iso ? 'Enter a valid date as ' + text.placeholder + '.' : '');
        let value = iso;
        if (time) {
            if (time.value && !text.value) text.setCustomValidity('Enter a date for this time.');
            time.setCustomValidity(iso && !time.value ? 'Enter a time for this date.' : '');
            value = iso && time.value ? iso + 'T' + time.value : '';
        } else if (birthday && iso) {
            value = source.placeholder === 'MM-DD' ? iso.slice(5) : display(iso);
        }
        source.value = value;
        if (type === 'change' && iso) text.value = display(iso);
        updating = true;
        try {
            if (source.value !== previous) source.dispatchEvent(new win.Event('input', {bubbles: true}));
            if (type === 'change' && lastCommitted !== source.value) {
                lastCommitted = source.value;
                source.dispatchEvent(new win.Event('change', {bubbles: true}));
            }
        } finally { updating = false; }
    }
    [text, time].filter(Boolean).forEach(editor => {
        for (const type of ['input', 'change']) editor.addEventListener(type, event => {
            event.stopPropagation();
            commit(type);
        });
        editor.addEventListener('blur', () => commit('change'));
    });
    for (const type of ['input', 'change', 'date-picker:refresh']) source.addEventListener(type, () => {
        if (!updating) refresh();
    });
    source.addEventListener('invalid', event => {
        // MOED's error summary already maps the canonical field to this editor.
        if (event.defaultPrevented) return;
        event.preventDefault();
        const target = time?.validationMessage ? time : text;
        const message = target.validationMessage;
        if (!message) target.setCustomValidity(source.validationMessage);
        target.reportValidity();
        if (!message) target.setCustomValidity('');
    });

    const editable = () => !source.matches(':disabled') && !source.readOnly;
    function open() {
        if (!editable()) return;
        openCalendar(trigger, {
            label, selected: readDate(), min: source.min.slice(0, 10), max: source.max.slice(0, 10), yearless: birthday,
            choose(iso) {
                if (!editable()) return;
                text.value = display(iso);
                commit('change');
                if (time && !time.value) time.focus();
            },
        });
    }
    trigger.addEventListener('click', event => { event.preventDefault(); open(); });
    text.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown') { event.preventDefault(); open(); }
    });
}

export function initializeDatePickers(doc = document) {
    if (installed.has(doc) || !doc.body) return;
    installed.add(doc);
    function scan(root) {
        if (root.matches?.(selector)) attachDatePicker(root);
        if (root.childElementCount) root.querySelectorAll(selector).forEach(attachDatePicker);
    }
    scan(doc);
    new doc.defaultView.MutationObserver(records => {
        for (const record of records) for (const node of record.addedNodes) {
            if (node.nodeType === 1) scan(node);
        }
    }).observe(doc.body, {childList: true, subtree: true});
    doc.addEventListener('reset', event => queueMicrotask(() => {
        for (const input of event.target.elements) fields.get(input)?.();
    }));
    doc.defaultView.addEventListener('pageshow', event => {
        if (event.persisted) doc.querySelectorAll(selector).forEach(input => fields.get(input)?.());
    });
}
