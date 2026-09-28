(function () {
    'use strict';
    const initialized = new WeakSet();
    function bind(select) {
        if (initialized.has(select)) return;
        initialized.add(select);
        const input = document.createElement('input');
        input.type = 'search';
        input.placeholder = 'Find an organization…';
        input.setAttribute('aria-label', 'Find an organization for ' + (select.labels?.[0]?.textContent || 'this field'));
        const status = document.createElement('p');
        status.className = 'field-help';
        status.setAttribute('role', 'status');
        select.before(input);
        select.after(status);
        let timer, controller, revision = 0;
        input.addEventListener('input', () => {
            clearTimeout(timer);
            controller?.abort();
            const current = ++revision;
            timer = setTimeout(async () => {
                controller = new AbortController();
                status.textContent = 'Searching…';
                try {
                    const query = new URLSearchParams({ kind: 'organization', q: input.value, selected_id: select.value });
                    const response = await fetch('inquiry_relationship_search.php?' + query, { credentials: 'same-origin', signal: controller.signal });
                    if (!response.ok) throw new Error('Search failed');
                    const result = await response.json();
                    if (revision !== current || !select.isConnected) return;
                    const chosen = select.selectedOptions[0]?.cloneNode(true);
                    const blank = select.querySelector('option[value=""]')?.cloneNode(true) || new Option('Choose an organization', '');
                    const options = [blank];
                    if (chosen && chosen.value) options.push(chosen);
                    for (const row of result.results) if (String(row.id) !== chosen?.value) options.push(new Option(row.organization_name, String(row.id)));
                    const value = select.value;
                    select.replaceChildren(...options);
                    select.value = value;
                    status.textContent = result.has_more ? 'Showing 25 matches. Keep typing to narrow the results.' : `${result.results.length} matching organizations.`;
                } catch (error) {
                    if (error.name !== 'AbortError' && revision === current) status.textContent = 'Search is unavailable. Your current selection is unchanged; try again.';
                }
            }, 250);
        });
    }
    function scan() { document.querySelectorAll('select[data-organization-search]').forEach(bind); }
    scan();
    new MutationObserver(scan).observe(document.body, { childList: true, subtree: true });
}());
