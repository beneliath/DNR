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
    const allOrganizations = Array.from(org.options).map(function (o) { return o.cloneNode(true); });
    const allContacts = Array.from(contact.options).map(function (o) { return o.cloneNode(true); });
    function renderOptions(select, options, search, compatible) {
        const selected = select.value;
        const query = search.value.trim().toLocaleLowerCase();
        select.replaceChildren();
        options.forEach(function (option) {
            if (!option.value || (compatible(option) && (option.value === selected || option.textContent.toLocaleLowerCase().includes(query)))) select.appendChild(option.cloneNode(true));
        });
        select.value = Array.from(select.options).some(function (o) { return o.value === selected; }) ? selected : '';
    }
    function refreshContacts() {
        const previous = contact.value;
        renderOptions(contact, allContacts, contactSearch, function (option) { return !org.value || option.dataset.organizationId === org.value; });
        if (previous && !contact.value) feedback.textContent = 'The previous contact does not belong to this organization — choose a compatible contact';
        document.querySelectorAll('[data-inquiry-create="contact"]').forEach(function (link) {
            const url = new URL(link.href);
            if (org.value) url.searchParams.set('organization_id', org.value); else url.searchParams.delete('organization_id');
            link.href = url.href;
        });
    }
    try {
        const draft = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
        if (draft && form.dataset.inquiryFormSubmitted !== 'true') {
            Object.entries(draft).forEach(function (entry) {
                const input = form.elements.namedItem(entry[0]);
                if (input && entry[0] !== 'csrf_token') input.value = entry[1];
            });
            sessionStorage.removeItem(draftKey);
            feedback.textContent = 'Inquiry draft restored';
        }
    } catch (error) { /* Ordinary saving remains available when browser storage is unavailable. */ }
    const parameters = new URLSearchParams(window.location.search);
    const createdOrg = parameters.get('created_organization_id');
    const createdContact = parameters.get('created_contact_id');
    if (createdOrg && allOrganizations.some(function (o) { return o.value === createdOrg; })) org.value = createdOrg;
    if (createdContact) {
        const option = allContacts.find(function (o) { return o.value === createdContact; });
        if (option) { contact.value = option.value; org.value = option.dataset.organizationId === '0' ? '' : option.dataset.organizationId; }
    }
    orgSearch.addEventListener('input', function () { renderOptions(org, allOrganizations, orgSearch, function () { return true; }); });
    contactSearch.addEventListener('input', refreshContacts);
    org.addEventListener('change', refreshContacts);
    contact.addEventListener('change', function () {
        const selected = contact.selectedOptions[0];
        if (!org.value && selected && selected.dataset.organizationId && selected.dataset.organizationId !== '0') {
            org.value = selected.dataset.organizationId;
            feedback.textContent = 'Organization selected from the contact';
            refreshContacts();
        }
    });
    refreshContacts();
    document.querySelectorAll('[data-inquiry-create]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            try {
                const values = {};
                Array.from(form.elements).forEach(function (input) {
                    // Keep the original edit version so returning from record creation
                    // cannot silently overwrite an intervening edit with this draft.
                    if (input.name === 'inquiry_version' || (input.name && !input.disabled && !['csrf_token', 'save_inquiry'].includes(input.name) && !['submit', 'button', 'file', 'hidden'].includes(input.type))) values[input.name] = input.value;
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
