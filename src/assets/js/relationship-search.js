(function () {
    'use strict';
    const initialized = new WeakSet();
    function bind(select) {
        if (initialized.has(select)) return;
        initialized.add(select);
        const kind = select.dataset?.contactSearch !== undefined ? 'contact' : 'organization';
        // Reuse server-rendered or cloned search controls. Cloning does not
        // copy listeners, so each new selector still needs its own binding.
        const existingInput = select.previousElementSibling?.matches?.('[data-relationship-search-input]')
            ? select.previousElementSibling : null;
        const existingStatus = select.nextElementSibling?.matches?.('[data-relationship-search-status]')
            ? select.nextElementSibling : null;
        const input = existingInput || document.createElement('input');
        input.type = 'search';
        input.placeholder = kind === 'contact' ? 'Find a contact…' : 'Find an organization…';
        input.setAttribute('aria-label', 'Find a ' + kind + ' for ' + (select.labels?.[0]?.textContent || 'this field'));
        input.setAttribute('data-relationship-search-input', '');
        const status = existingStatus || document.createElement('p');
        status.className = 'field-help';
        status.setAttribute('role', 'status');
        status.setAttribute('data-relationship-search-status', '');
        if (!existingInput) select.before(input);
        if (!existingStatus) select.after(status);
        let timer, controller, revision = 0;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            controller?.abort();
            const current = ++revision;
            timer = setTimeout(async () => {
                controller = new AbortController();
                status.textContent = 'Searching…';
                try {
                    const query = new URLSearchParams({ kind, q: input.value, selected_id: select.value });
                    const response = await fetch('inquiry_relationship_search.php?' + query, { credentials: 'same-origin', signal: controller.signal });
                    if (!response.ok) throw new Error('Search failed');
                    const result = await response.json();
                    if (revision !== current || !select.isConnected) return;
                    const chosen = select.selectedOptions[0]?.cloneNode(true);
                    const blank = select.querySelector('option[value=""]')?.cloneNode(true) || new Option('Choose a ' + kind, '');
                    const options = [blank];
                    if (chosen && chosen.value) options.push(chosen);
                    for (const row of result.results) {
                        if (String(row.id) === chosen?.value) continue;
                        const label = kind === 'contact'
                            ? [
                                [row.contact_first_name, row.contact_last_name].filter(Boolean).join(' '),
                                row.contact_email || '',
                                row.organization_name || ''
                            ].filter(Boolean).join(' · ')
                            : row.organization_name;
                        options.push(new Option(label, String(row.id)));
                    }
                    const value = select.value;
                    select.replaceChildren(...options);
                    select.value = value;
                    status.textContent = result.has_more ? 'Showing 25 matches. Keep typing to narrow the results.' : `${result.results.length} matching ${kind === 'contact' ? 'contacts' : 'organizations'}.`;
                } catch (error) {
                    if (error.name !== 'AbortError' && revision === current) status.textContent = 'Search is unavailable. Your current selection is unchanged; try again.';
                }
            }, 250);
        });
    }
    function scan() { document.querySelectorAll('select[data-organization-search], select[data-contact-search]').forEach(bind); }
    scan();
    new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
}());
