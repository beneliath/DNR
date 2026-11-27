(function () {
    'use strict';
    const initialized = new WeakSet();
    function relationshipLabel(kind, row) {
        return kind === 'contact' ? [
            [row.contact_first_name, row.contact_last_name].filter(Boolean).join(' '),
            row.contact_email || '',
            row.organization_name || ''
        ].filter(Boolean).join(' · ') : row.organization_name;
    }
    function bind(select) {
        if (initialized.has(select)) return;
        initialized.add(select);
        const kind = select.dataset?.contactSearch !== undefined ? 'contact' : 'organization';
        // Reuse server-rendered or cloned search controls. Cloning does not
        // copy listeners, so each new selector still needs its own binding.
        const existingInput = (select.dataset?.relationshipSearchInputId
            ? document.getElementById(select.dataset.relationshipSearchInputId) : null)
            || (select.previousElementSibling?.matches?.('[data-relationship-search-input]')
                ? select.previousElementSibling : null);
        const existingStatus = select.nextElementSibling?.matches?.('[data-relationship-search-status]')
            ? select.nextElementSibling : null;
        const input = existingInput || document.createElement('input');
        input.type = 'search';
        input.placeholder = kind === 'contact' ? 'Find a contact…' : 'Find an Organization…';
        input.setAttribute('aria-label', 'Find a ' + kind.charAt(0).toUpperCase() + kind.slice(1) + ' for ' + (select.labels?.[0]?.textContent || 'This Field'));
        input.setAttribute('data-relationship-search-input', '');
        const status = existingStatus || document.createElement('p');
        status.className = 'field-help';
        status.setAttribute('role', 'status');
        status.setAttribute('data-relationship-search-status', '');
        if (!existingInput) select.before(input);
        if (!existingStatus) select.after(status);
        let timer, controller, pendingSearch, revision = 0;
        function queueSearch(current) {
            const loading = search(current);
            pendingSearch = loading;
            loading.finally(() => { if (pendingSearch === loading) pendingSearch = null; });
            return loading;
        }
        async function search(current) {
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
                    options.push(new Option(relationshipLabel(kind, row), String(row.id)));
                }
                const value = select.value;
                select.replaceChildren(...options);
                select.value = value;
                status.textContent = result.has_more ? 'Showing 25 matches. Keep typing to narrow the results.' : `${result.results.length} matching ${kind === 'contact' ? 'contacts' : 'organizations'}.`;
            } catch (error) {
                if (error.name !== 'AbortError' && revision === current) status.textContent = 'Search is unavailable. Your current selection is unchanged; try again.';
            }
        }
        input.addEventListener('input', () => {
            clearTimeout(timer);
            controller?.abort();
            const current = ++revision;
            timer = setTimeout(() => { timer = null; queueSearch(current); }, 250);
        });
        select.form?.addEventListener('form-draft-ready', event => {
            if (timer) { clearTimeout(timer); timer = null; queueSearch(revision); }
            if (pendingSearch) event.detail.waitUntil(pendingSearch);
        });
    }
    function scan() { document.querySelectorAll('select[data-organization-search], select[data-contact-search]').forEach(bind); }
    // Fetch saved selections that are outside the initial page of results.
    document.addEventListener('form-draft-restore', event => {
        const form = event.target;
        if (!form.hasAttribute('data-draft-relationships')) return;
        const fields = new Map(event.detail.fields.map(field => [field.name, field.value]));
        form.querySelectorAll('select[data-organization-search], select[data-contact-search]').forEach(select => {
            const value = fields.get(select.name);
            if (!/^[1-9]\d*$/.test(value) || Array.from(select.options).some(option => option.value === value)) return;
            const kind = select.hasAttribute('data-contact-search') ? 'contact' : 'organization';
            const query = new URLSearchParams({kind, selected_id: value});
            event.detail.waitUntil(fetch('inquiry_relationship_search.php?' + query, {credentials: 'same-origin'})
                .then(response => response.ok ? response.json() : null)
                .then(result => {
                    const selected = result?.selected;
                    if (String(selected?.id) === value && select.isConnected
                        && !Array.from(select.options).some(option => option.value === value)) {
                        select.add(new Option(relationshipLabel(kind, selected), value));
                    }
                }));
        });
    });
    scan();
    new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
}());
