(function () {
    'use strict';
    const taskCount = document.querySelector('[data-conversion-task-count]');
    if (taskCount) {
        const taskChecks = Array.from(document.querySelectorAll('input[name="task_ids[]"]'));
        const countTasks = function () { taskCount.textContent = String(taskChecks.filter(function (input) { return input.checked; }).length); };
        taskChecks.forEach(function (input) { input.addEventListener('change', countTasks); });
        countTasks();
    }
    const resolution = document.getElementById('next-action-reason');
    if (resolution) {
        const updateResolution = function () { resolution.required = Boolean(document.querySelector('[name="next_action_decision"][value="resolved"]:checked')); };
        document.querySelectorAll('[name="next_action_decision"]').forEach(function (input) { input.addEventListener('change', updateResolution); });
        updateResolution();
    }
    const form = document.querySelector('[data-inquiry-draft-key]');
    if (!form) return;
    const org = document.getElementById('inquiry-organization');
    const contact = document.getElementById('inquiry-contact');
    const feedback = document.getElementById('inquiry-relationship-status');
    const orgSearch = document.getElementById('inquiry-organization-search');
    const contactSearch = document.getElementById('inquiry-contact-search');
    if (!org || !contact) return;
    const draftKey = 'moed:inquiry-related-draft:' + form.dataset.inquiryDraftKey;
    function selectedOption(select) {
        return Array.from(select.options).find(function (option) { return option.value === select.value; });
    }
    function optionFromRecord(record) {
        const option = document.createElement('option');
        option.value = String(record.id);
        option.textContent = record.label;
        option.dataset.organizationId = String(record.organization_id || 0);
        option.dataset.organizationName = record.organization_name || '';
        return option;
    }
    function restoreSelection(select, id, snapshot) {
        if (id && !Array.from(select.options).some(function (option) { return option.value === String(id); })) {
            select.appendChild(optionFromRecord(snapshot || {id: id, label: 'Selected record #' + id}));
        }
        select.value = String(id || '');
    }
    function updateCreateContactLink() {
        document.querySelectorAll('[data-inquiry-create="contact"]').forEach(function (link) {
            const url = new URL(link.href);
            if (org.value) url.searchParams.set('organization_id', org.value); else url.searchParams.delete('organization_id');
            link.href = url.href;
        });
    }
    const requests = {};
    async function searchRecords(kind, validateSelection) {
        const select = kind === 'organization' ? org : contact;
        const search = kind === 'organization' ? orgSearch : contactSearch;
        if (requests[kind]) requests[kind].abort();
        const controller = new AbortController();
        requests[kind] = controller;
        const selectedId = select.value;
        const organizationId = org.value;
        const url = new URL(search.dataset.searchUrl, window.location.href);
        url.searchParams.set('kind', kind);
        url.searchParams.set('q', search.value.trim());
        if (selectedId) url.searchParams.set('selected_id', selectedId);
        if (kind === 'contact' && organizationId) url.searchParams.set('organization_id', organizationId);
        try {
            const response = await fetch(url.href, {signal: controller.signal, headers: {Accept: 'application/json'}});
            if (!response.ok) throw new Error('Search failed');
            const data = await response.json();
            if (requests[kind] !== controller || (kind === 'contact' && org.value !== organizationId)) return;
            let current = selectedOption(select);
            let value = select.value;
            // Only a successful compatibility check may clear a selected contact.
            // Typing a different search or a failed request must retain it.
            if (value === selectedId && data.selected) current = optionFromRecord(data.selected);
            else if (validateSelection && value === selectedId && value && !data.selected) {
                current = null;
                value = '';
                feedback.textContent = 'The previous selection is no longer available for this organization. Choose a compatible contact.';
            }
            const blank = Array.from(select.options).find(function (option) { return !option.value; });
            select.replaceChildren();
            if (blank) select.appendChild(blank);
            if (current && value) select.appendChild(current);
            data.results.forEach(function (record) {
                if (String(record.id) !== value) select.appendChild(optionFromRecord(record));
            });
            select.value = value;
            if (!validateSelection || value) feedback.textContent = data.has_more
                ? 'Showing 25 matches. Refine the search to find more.' : 'Search complete. Choose a record from the list.';
        } catch (error) {
            if (requests[kind] === controller && error.name !== 'AbortError') {
                feedback.textContent = 'Search is temporarily unavailable. Your selections are preserved; type again to retry.';
            }
        }
    }
    const initialOrganizations = Array.from(org.options);
    const initialContacts = Array.from(contact.options);
    let restored = false;
    try {
        const draft = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
        if (draft && form.dataset.inquiryFormSubmitted !== 'true') {
            Object.entries(draft).forEach(function (entry) {
                const input = form.elements.namedItem(entry[0]);
                if (input && entry[0] !== 'csrf_token') {
                    if (input === org || input === contact) restoreSelection(input, entry[1], (draft._relationships || {})[entry[0]]);
                    else input.value = entry[1];
                }
            });
            sessionStorage.removeItem(draftKey);
            restored = true;
            feedback.textContent = 'Inquiry draft restored';
        }
    } catch (error) { /* Ordinary saving remains available when browser storage is unavailable. */ }
    const parameters = new URLSearchParams(window.location.search);
    const createdOrg = parameters.get('created_organization_id');
    const createdContact = parameters.get('created_contact_id');
    if (createdOrg && initialOrganizations.some(function (option) { return option.value === createdOrg; })) org.value = createdOrg;
    if (createdContact) {
        const option = initialContacts.find(function (candidate) { return candidate.value === createdContact; });
        if (option) {
            contact.value = option.value;
            restoreSelection(org, option.dataset.organizationId === '0' ? '' : option.dataset.organizationId,
                {id: option.dataset.organizationId, label: option.dataset.organizationName});
        }
    }
    [ ['organization', orgSearch], ['contact', contactSearch] ].forEach(function (entry) {
        let timer;
        entry[1].addEventListener('input', function () {
            window.clearTimeout(timer);
            // Invalidate the previous request immediately, including while debouncing.
            if (requests[entry[0]]) requests[entry[0]].abort();
            delete requests[entry[0]];
            timer = window.setTimeout(function () { return searchRecords(entry[0], false); }, 250);
        });
        entry[1].addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                window.clearTimeout(timer);
                searchRecords(entry[0], false);
            }
        });
    });
    org.addEventListener('change', function () {
        updateCreateContactLink();
        searchRecords('contact', true);
    });
    contact.addEventListener('change', function () {
        const selected = selectedOption(contact);
        if (!org.value && selected && selected.dataset.organizationId && selected.dataset.organizationId !== '0') {
            restoreSelection(org, selected.dataset.organizationId,
                {id: selected.dataset.organizationId, label: selected.dataset.organizationName});
            updateCreateContactLink();
            searchRecords('contact', true);
        }
    });
    updateCreateContactLink();
    if ((restored || createdOrg || createdContact) && (org.value || contact.value)) {
        searchRecords('organization', true);
        searchRecords('contact', true);
    }
    document.querySelectorAll('[data-inquiry-create]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            try {
                const values = {};
                Array.from(form.elements).forEach(function (input) {
                    // Keep the original edit version so returning from record creation
                    // cannot silently overwrite an intervening edit with this draft.
                    if (input.name === 'inquiry_version' || (input.name && !input.disabled && !['csrf_token', 'save_inquiry'].includes(input.name) && !['submit', 'button', 'file', 'hidden'].includes(input.type))) values[input.name] = input.value;
                });
                values._relationships = {};
                [org, contact].forEach(function (select) {
                    const option = selectedOption(select);
                    if (option && option.value) values._relationships[select.name] = {
                        id: option.value, label: option.textContent,
                        organization_id: option.dataset.organizationId,
                        organization_name: option.dataset.organizationName
                    };
                });
                sessionStorage.setItem(draftKey, JSON.stringify(values));
            } catch (error) {
                event.preventDefault();
                feedback.textContent = 'Your browser cannot preserve this draft — save the inquiry before creating another record';
            }
        });
    });
    form.addEventListener('submit', function () { try { sessionStorage.removeItem(draftKey); } catch (error) {} });
})();
