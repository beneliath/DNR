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
  document.querySelectorAll('.analytics-evidence-grid .analytics-table-scroll').forEach(viewport => {
    const table = viewport.querySelector('table');
    const sizeViewport = () => {
      const rows = table.tBodies[0].rows;
      const last = rows[Math.min(rows.length, 10) - 1];
      if (!last) return;
      // Include the header and ten complete rows, including wrapped/mobile rows.
      const height = last.getBoundingClientRect().bottom - viewport.getBoundingClientRect().top + viewport.scrollTop;
      viewport.style.maxHeight = `${Math.ceil(height)}px`;
    };
    sizeViewport();
    window.addEventListener('resize', sizeViewport);
    if ('ResizeObserver' in window) {
      const observer = new ResizeObserver(sizeViewport);
      observer.observe(table);
    }
  });
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
    const updatePending = () => apply.classList.toggle('analytics-range-pending',
      [preset.value, ...inputs.map(input => input.value)].some((value, index) => value !== applied[index]));
    inputs.forEach(input => input.addEventListener('input', () => { preset.value = 'custom'; updatePending(); }));
    preset.addEventListener('change', () => {
      const option = preset.selectedOptions[0];
      if (option.dataset.start) {
        inputs[0].value = option.dataset.start; inputs[1].value = option.dataset.end;
        inputs.forEach(input => input.dispatchEvent(new Event('date-picker:refresh')));
      }
      updatePending();
    });
    dateForm.addEventListener('reset', () => queueMicrotask(updatePending));
    dateForm.querySelector('[data-analytics-range-cancel]').addEventListener('click', () => {
      dateForm.reset(); document.getElementById('analytics-range').hidePopover();
      document.getElementById('analytics-range-trigger').focus();
    });
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
    const optionsList = messageForm.querySelector('.analytics-message-options');
    const messagePanel = messageForm.closest('.analytics-message-popover');
    const sizeMessageOptions = () => {
      // The scroll viewport ends between complete rows, including wrapped names.
      optionsList.style.maxHeight = '';
      const limit = parseFloat(getComputedStyle(optionsList).maxHeight) || 300;
      const visible = [...optionsList.querySelectorAll(':scope > label:not([hidden])')];
      let height = 0;
      for (const label of visible) {
        const next = height + label.getBoundingClientRect().height;
        if (height > 0 && next > limit) break;
        height = next;
      }
      if (visible.length) optionsList.style.maxHeight = `${Math.ceil(height)}px`;
    };
    messagePanel?.addEventListener('toggle', () => {
      if (messagePanel.matches(':popover-open')) requestAnimationFrame(sizeMessageOptions);
    });
    window.addEventListener('resize', () => {
      if (messagePanel?.matches(':popover-open')) requestAnimationFrame(sizeMessageOptions);
    });
    let pending; let request;
    messageForm.querySelector('[data-analytics-message-search]').addEventListener('input', event => {
      const query = event.target.value.toLocaleLowerCase();
      choices.forEach(input => { input.closest('label').hidden = !input.closest('label').textContent.toLocaleLowerCase().includes(query); });
      optionsList.scrollTop = 0;
      requestAnimationFrame(sizeMessageOptions);
    });
    const update = async () => {
      request?.abort(); request = new AbortController(); const activeRequest = request;
      const params = new URLSearchParams(new FormData(messageForm));
      const href = `${location.pathname}?${params}`;
      const results = document.querySelector('[data-analytics-results]');
      results?.setAttribute('aria-busy', 'true'); status.textContent = 'Updating…';
      const finishLoading = window.DnrPageLoading?.begin();
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
      } finally {
        finishLoading?.();
        if (activeRequest === request) results?.removeAttribute('aria-busy');
      }
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

const sortInteractionLocations = (table, key = table?.dataset.interactionSortKey || 'interactions', direction = table?.dataset.interactionDirection || 'descending') => {
  if (!table) return;
  const body = table.querySelector('tbody');
  const rows = [...body.querySelectorAll('tr[data-interaction-location]')];
  const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
  const labelOrder = (a, b) => collator.compare(a.dataset.interactionLocation, b.dataset.interactionLocation);
  const count = row => Number(row.querySelector('[data-interaction-observations]').dataset.interactionObservations);
  const multiplier = direction === 'ascending' ? 1 : -1;
  rows.sort((a, b) => key === 'location' ? multiplier * labelOrder(a, b) : multiplier * (count(a) - count(b)) || labelOrder(a, b));
  const empty = body.querySelector('#interaction-location-table-empty');
  rows.forEach(row => body.insertBefore(row, empty));
  table.dataset.interactionSortKey = key;
  table.dataset.interactionDirection = direction;
  table.querySelectorAll('button[data-interaction-sort]').forEach(button => {
    const active = button.dataset.interactionSort === key;
    button.closest('th').setAttribute('aria-sort', active ? direction : 'none');
    button.querySelector('[data-sort-arrow]').textContent = active ? (direction === 'ascending' ? '↑' : '↓') : '';
  });
};
document.addEventListener('click', event => {
  const button = event.target.closest('button[data-interaction-sort]');
  if (!button) return;
  const direction = button.closest('th').getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
  sortInteractionLocations(button.closest('table'), button.dataset.interactionSort, direction);
});
// The map updates each row's count before the filter change bubbles here.
document.addEventListener('change', event => {
  if (event.target.id === 'interaction-map-type') sortInteractionLocations(document.getElementById('interaction-location-table'));
});
