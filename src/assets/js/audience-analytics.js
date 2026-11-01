import {calendarDates, formatCalendarDate, isoCalendarDate, parseCalendarDate} from './analytics-calendar.mjs';

(() => {
  'use strict';
  const updateMetricCarousels = () => document.querySelectorAll('.analytics-metric-carousel').forEach(carousel => {
    const strip = carousel.querySelector('.analytics-metric-strip'); const button = carousel.querySelector('[data-analytics-scroll]');
    if (!strip || !button) return;
    button.hidden = strip.scrollWidth <= strip.clientWidth + 1;
    const end = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 1;
    button.textContent = end ? '‹' : '›'; button.setAttribute('aria-label', end ? 'Scroll metrics left' : 'Scroll metrics right');
  });
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-analytics-scroll]');
    if (!button) return;
    const strip = button.closest('.analytics-metric-carousel').querySelector('.analytics-metric-strip');
    const end = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 1;
    strip.scrollBy({ left: end ? -strip.scrollWidth : 330, behavior: 'smooth' });
  });
  document.addEventListener('scroll', event => { if (event.target.matches?.('.analytics-metric-strip')) updateMetricCarousels(); }, true);
  window.addEventListener('resize', updateMetricCarousels); updateMetricCarousels();
  const measureReportHeader = () => {
    const bar = document.querySelector('.audience-topbar'); const heading = document.querySelector('.audience-page-heading');
    document.body.style.setProperty('--analytics-topbar-height', `${bar?.offsetHeight || 68}px`);
    document.body.style.setProperty('--analytics-heading-height', `${heading?.offsetHeight || 81}px`);
  };
  measureReportHeader();
  if ('ResizeObserver' in window) {
    const observer = new ResizeObserver(measureReportHeader);
    document.querySelectorAll('.audience-topbar,.audience-page-heading').forEach(node => observer.observe(node));
  }
  const dateForm = document.querySelector('[data-analytics-range]');
  const make = (tag, text, className) => {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
  };
  if (dateForm) {
    const preset = dateForm.querySelector('[data-analytics-preset]');
    const inputs = ['start', 'end'].map(key => dateForm.elements.namedItem(key));
    const apply = dateForm.querySelector('.analytics-range-actions button[type="submit"]');
    const applied = [preset.value, ...inputs.map(input => input.value)];
    const calendars = [];
    const updatePending = () => apply.classList.toggle('analytics-range-pending',
      [preset.value, ...inputs.map(input => input.value)].some((value, index) => value !== applied[index]));
    const closeCalendars = () => calendars.forEach(({ panel }) => {
      if (panel.matches(':popover-open')) panel.hidePopover();
    });
    const fields = inputs.map((input, index) => {
      const label = index ? 'End date' : 'Start date';
      const field = input.closest('.analytics-date-field');
      const wrapper = field.querySelector('.analytics-date-input');
      const max = input.max;
      const id = input.id;
      input.id = `${id}-iso`;
      input.type = 'hidden';
      input.required = false;
      const text = make('input');
      text.type = 'text'; text.id = id; text.inputMode = 'numeric'; text.autocomplete = 'off';
      text.placeholder = 'MM/DD/YYYY'; text.required = true;
      const trigger = make('button', undefined, 'analytics-calendar-trigger');
      trigger.type = 'button'; trigger.setAttribute('aria-label', `Choose ${label.toLowerCase()}`);
      trigger.setAttribute('aria-haspopup', 'dialog'); trigger.setAttribute('aria-expanded', 'false');
      trigger.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="17" rx="2"/><path d="M3 10h18M7 2v6M17 2v6"/></svg>';
      const panel = make('div', undefined, 'analytics-calendar');
      panel.id = `${id}-calendar`; panel.popover = 'auto';
      panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', `${label} calendar`);
      trigger.setAttribute('aria-controls', panel.id);
      wrapper.append(text, trigger); field.append(panel);
      const syncText = () => { text.value = formatCalendarDate(input.value); text.setCustomValidity(''); };
      const syncValue = () => {
        const previous = input.value;
        input.value = parseCalendarDate(text.value, max);
        text.setCustomValidity(input.value ? '' : `Enter a valid date on or before ${formatCalendarDate(max)}.`);
        if (input.value) text.value = formatCalendarDate(input.value);
        if (input.value !== previous) preset.value = 'custom';
        updatePending();
      };
      syncText();
      text.addEventListener('input', syncValue);
      text.addEventListener('change', syncValue);
      let displayed;
      const position = () => {
        if (!panel.matches(':popover-open')) return;
        const rect = trigger.getBoundingClientRect();
        const width = Math.min(310, window.innerWidth - 24);
        panel.style.width = `${width}px`;
        panel.style.left = `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`;
        const height = panel.getBoundingClientRect().height;
        const below = rect.bottom + 8;
        panel.style.top = `${below + height <= window.innerHeight - 12 ? below : Math.max(12, rect.top - height - 8)}px`;
      };
      const render = focus => {
        panel.replaceChildren();
        const header = make('div', undefined, 'analytics-calendar-header');
        const previous = make('button', '‹', 'analytics-calendar-nav'); previous.type = 'button';
        previous.setAttribute('aria-label', 'Previous month');
        const month = make('select'); month.setAttribute('aria-label', 'Month');
        const year = make('select'); year.setAttribute('aria-label', 'Year');
        const next = make('button', '›', 'analytics-calendar-nav'); next.type = 'button';
        next.setAttribute('aria-label', 'Next month');
        const maxYear = Number(max.slice(0, 4));
        const maxMonth = Number(max.slice(5, 7)) - 1;
        for (let m = 0; m < 12; m++) {
          const option = make('option', new Date(Date.UTC(2000, m, 1)).toLocaleDateString('en-US', { month: 'long', timeZone: 'UTC' }));
          option.value = String(m); option.disabled = displayed.getUTCFullYear() === maxYear && m > maxMonth;
          month.append(option);
        }
        for (let y = Math.min(1900, displayed.getUTCFullYear()); y <= maxYear; y++) {
          const option = make('option', String(y)); option.value = String(y); year.append(option);
        }
        month.value = String(displayed.getUTCMonth()); year.value = String(displayed.getUTCFullYear());
        previous.disabled = displayed.getUTCFullYear() === 1 && displayed.getUTCMonth() === 0;
        next.disabled = displayed.getUTCFullYear() === maxYear && displayed.getUTCMonth() >= maxMonth;
        header.append(previous, month, year, next); panel.append(header);
        const moveMonth = delta => {
          displayed.setUTCMonth(displayed.getUTCMonth() + delta);
          render(delta < 0 ? 'previous' : 'next');
        };
        previous.addEventListener('click', () => moveMonth(-1));
        next.addEventListener('click', () => moveMonth(1));
        month.addEventListener('change', () => {
          displayed.setUTCMonth(Number(month.value)); render('day');
        });
        year.addEventListener('change', () => {
          displayed.setUTCFullYear(Number(year.value));
          if (displayed.getUTCFullYear() === maxYear && displayed.getUTCMonth() > maxMonth) displayed.setUTCMonth(maxMonth);
          render('day');
        });
        const grid = make('div', undefined, 'analytics-calendar-grid');
        grid.setAttribute('role', 'group');
        grid.setAttribute('aria-label', displayed.toLocaleDateString('en-US', { month: 'long', year: 'numeric', timeZone: 'UTC' }));
        ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(day => grid.append(make('span', day)));
        const days = calendarDates(displayed.getUTCFullYear(), displayed.getUTCMonth());
        const active = days.some(day => day.iso === input.value && !day.outside) ? input.value
          : days.find(day => !day.outside && day.iso <= max)?.iso;
        days.forEach(day => {
          const button = make('button', String(day.day)); button.type = 'button';
          button.dataset.date = day.iso; button.disabled = day.iso > max;
          button.tabIndex = day.iso === active ? 0 : -1;
          button.setAttribute('aria-label', day.date.toLocaleDateString('en-US', { dateStyle: 'full', timeZone: 'UTC' }));
          if (day.outside) button.classList.add('outside-month');
          if (day.iso === input.value) { button.classList.add('selected'); button.setAttribute('aria-pressed', 'true'); }
          if (day.iso === max) button.classList.add('today');
          button.addEventListener('click', () => {
            input.value = day.iso; syncText(); preset.value = 'custom'; updatePending();
            panel.hidePopover(); trigger.focus();
          });
          button.addEventListener('keydown', event => {
            const delta = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[event.key];
            if (delta === undefined && !['PageUp', 'PageDown', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const target = new Date(day.date);
            if (delta !== undefined) target.setUTCDate(target.getUTCDate() + delta);
            else if (event.key === 'PageUp' || event.key === 'PageDown') target.setUTCMonth(target.getUTCMonth() + (event.key === 'PageUp' ? -1 : 1));
            else target.setUTCDate(target.getUTCDate() + (event.key === 'Home' ? -target.getUTCDay() : 6 - target.getUTCDay()));
            const value = isoCalendarDate(target.getUTCFullYear(), target.getUTCMonth() + 1, target.getUTCDate());
            if (value > max || target.getUTCFullYear() < 1) return;
            displayed = new Date(target); displayed.setUTCDate(1);
            render(value);
          });
          grid.append(button);
        });
        panel.append(grid);
        if (focus === 'previous' || focus === 'next') header.querySelector(`button[aria-label="${focus === 'previous' ? 'Previous' : 'Next'} month"]`).focus();
        else if (focus) (panel.querySelector(`[data-date="${focus === 'day' ? active : focus}"]`) || panel.querySelector(`[data-date="${active}"]`))?.focus();
        position();
      };
      trigger.addEventListener('click', () => {
        if (panel.matches(':popover-open')) { panel.hidePopover(); return; }
        closeCalendars();
        const chosen = parseCalendarDate(text.value, max) || input.value || max;
        displayed = new Date(`${chosen.slice(0, 7)}-01T00:00:00Z`);
        render(); panel.showPopover(); position();
        panel.querySelector(`[data-date="${chosen}"]`)?.focus();
      });
      panel.addEventListener('toggle', () => trigger.setAttribute('aria-expanded', String(panel.matches(':popover-open'))));
      panel.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault(); event.stopPropagation(); panel.hidePopover(); trigger.focus();
      });
      calendars.push({ panel, position });
      return { text, syncText, syncValue };
    });
    preset.addEventListener('change', () => {
      const option = preset.selectedOptions[0];
      if (option.dataset.start) {
        inputs[0].value = option.dataset.start; inputs[1].value = option.dataset.end;
        fields.forEach(field => field.syncText());
      }
      closeCalendars(); updatePending();
    });
    dateForm.addEventListener('submit', event => {
      fields.forEach(field => field.syncValue());
      if (fields.some(field => !field.text.reportValidity())) event.preventDefault();
    });
    dateForm.addEventListener('reset', () => queueMicrotask(() => {
      fields.forEach(field => field.syncText()); updatePending();
    }));
    dateForm.querySelector('[data-analytics-range-cancel]').addEventListener('click', () => {
      dateForm.reset(); closeCalendars(); document.getElementById('analytics-range').hidePopover();
      document.getElementById('analytics-range-trigger').focus();
    });
    document.getElementById('analytics-range').addEventListener('toggle', () => {
      if (!document.getElementById('analytics-range').matches(':popover-open')) closeCalendars();
    });
    window.addEventListener('resize', () => requestAnimationFrame(() => calendars.forEach(({ position }) => position())));
    document.addEventListener('scroll', () => calendars.forEach(({ position }) => position()), true);
    updatePending();
  }
  ['analytics-range', 'analytics-messages'].forEach(id => {
    const panel = document.getElementById(id); const trigger = document.getElementById(`${id === 'analytics-range' ? 'analytics-range' : 'analytics-message'}-trigger`);
    if (!panel || !trigger) return;
    const position = () => {
      if (!panel.matches(':popover-open')) return;
      const rect = trigger.getBoundingClientRect(); const width = Math.min(id === 'analytics-range' ? 680 : 380, window.innerWidth - 32);
      panel.style.width = `${width}px`; panel.style.left = `${Math.max(16, Math.min(rect.left, window.innerWidth - width - 16))}px`;
      panel.style.top = `${Math.min(rect.bottom + 8, window.innerHeight - 100)}px`;
      panel.style.maxHeight = `${Math.max(100, window.innerHeight - rect.bottom - 24)}px`;
    };
    panel.addEventListener('toggle', position); window.addEventListener('resize', position);
  });
  document.querySelectorAll('[data-analytics-submit]').forEach(control => control.addEventListener('change', () => control.form.requestSubmit()));
  const messageForm = document.querySelector('[data-analytics-messages]');
  if (messageForm) {
    const all = messageForm.querySelector('[data-analytics-all]');
    const choices = [...messageForm.querySelectorAll('[name="messages[]"]')];
    const status = messageForm.querySelector('[data-analytics-filter-status]');
    let pending; let request;
    messageForm.querySelector('[data-analytics-message-search]').addEventListener('input', event => {
      const query = event.target.value.toLocaleLowerCase();
      choices.forEach(input => { input.closest('label').hidden = !input.closest('label').textContent.toLocaleLowerCase().includes(query); });
    });
    const update = async () => {
      request?.abort(); request = new AbortController(); const activeRequest = request;
      const params = new URLSearchParams(new FormData(messageForm));
      const href = `${location.pathname}?${params}`;
      const results = document.querySelector('[data-analytics-results]');
      results?.setAttribute('aria-busy', 'true'); status.textContent = 'Updating…';
      try {
        const response = await fetch(href, { signal: activeRequest.signal, credentials: 'same-origin', headers: { Accept: 'text/html' } });
        if (!response.ok) throw new Error('Unable to update report filters.');
        const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
        if (activeRequest !== request) return;
        const replacement = parsed.querySelector('[data-analytics-results]');
        if (!replacement || !results) throw new Error('Report response is unavailable.');
        results.replaceChildren(...replacement.childNodes); updateMetricCarousels();
        const aiTools = document.querySelector('.analytics-ai-tools');
        const updatedAiTools = parsed.querySelector('.analytics-ai-tools');
        if (aiTools && updatedAiTools) aiTools.replaceChildren(...updatedAiTools.childNodes);
        document.getElementById('analytics-message-trigger').firstChild.textContent = all.checked ? 'All messages ' : `${choices.filter(input => input.checked).length} messages selected `;
        document.querySelectorAll('.analytics-reference-filters form').forEach(form => {
          if (form === messageForm) return;
          form.querySelectorAll('input[type="hidden"]').forEach(input => { if (['messages[]', 'messages_mode'].includes(input.name)) input.remove(); });
          for (const [name, value] of params) if (['messages[]', 'messages_mode'].includes(name)) {
            const input = make('input'); input.type = 'hidden'; input.name = name; input.value = value; form.append(input);
          }
        });
        history.replaceState(null, '', href); status.textContent = 'Report updated.';
      } catch (error) {
        if (error.name !== 'AbortError') status.textContent = 'Unable to update. Use Apply to retry.';
      } finally { if (activeRequest === request) results?.removeAttribute('aria-busy'); }
    };
    messageForm.addEventListener('change', event => {
      if (event.target === all) choices.forEach(input => { input.checked = all.checked; });
      else all.checked = choices.length > 0 && choices.every(input => input.checked);
      messageForm.elements.namedItem('messages_mode').value = all.checked ? 'all' : 'selected';
      clearTimeout(pending); pending = setTimeout(update, 200);
    });
  }
})();
// Report links contain only an internal definition ID; account authorization is checked on every visit.
document.addEventListener('click', async event => {
  if (!event.target.closest('[data-copy-report-link]')) return;
  const field = document.querySelector('[data-report-share-url]'); const status = document.querySelector('[data-report-copy-status]');
  if (!field || !status) return;
  try { await navigator.clipboard.writeText(new URL(field.value, window.location.href).href); status.textContent = 'Report link copied. Login is required.'; }
  catch { field.focus(); field.select(); status.textContent = 'Select and copy this link. Login is required.'; }
});
// A selected chart metric must still belong to the table after a metric is unchecked.
document.addEventListener('change', event => {
  const input = event.target;
  if (!input.matches('.analytics-builder-controls input[name="columns[]"]')) return;
  const plot = document.querySelector(`.analytics-builder-controls input[name="plots[]"][value="${input.value}"]`);
  if (plot) { if (!input.checked) plot.checked = false; plot.disabled = !input.checked; }
});
