(function () {
    function selectedRoleCount(container) {
        return container ? container.querySelectorAll('input[type="checkbox"]:checked').length : 0;
    }

    function selectedContactCount(container) {
        return container ? Array.from(container.querySelectorAll('.engagement-contact-card'))
            .filter(function (card) { return selectedRoleCount(card) > 0; }).length : 0;
    }

    function contactSelectionSummary(contacts, roles) {
        return contacts + ' contact' + (contacts === 1 ? '' : 's') + ' · '
            + roles + ' role' + (roles === 1 ? '' : 's');
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { selectedRoleCount, selectedContactCount, contactSelectionSummary };
    }
    if (typeof document === 'undefined') return;

    const picker = document.querySelector('[data-engagement-contact-picker]');
    const organization = document.getElementById('organization_id');
    if (!picker || !organization) return;

    const list = picker.querySelector('[data-engagement-contact-list]');
    const addedList = picker.querySelector('[data-engagement-added-contact-list]');
    const newList = picker.querySelector('[data-engagement-new-contact-list]');
    const newTemplate = picker.querySelector('[data-new-contact-template]');
    const addNew = picker.querySelector('[data-add-new-contact]');
    const status = picker.querySelector('[data-engagement-contact-status]');
    const count = picker.querySelector('[data-engagement-contact-count]');
    const retryLoad = picker.querySelector('[data-retry-contact-load]');
    const search = picker.querySelector('[data-contact-search]');
    const searchButton = picker.querySelector('[data-contact-search-button]');
    const searchStatus = picker.querySelector('[data-contact-search-status]');
    const searchResults = picker.querySelector('[data-contact-search-results]');
    if (!list || !addedList || !newList || !newTemplate || !addNew || !status || !count
        || !search || !searchButton || !searchStatus || !searchResults) return;

    let request;
    let searchRequest;
    let nextIndex = newList.querySelectorAll('[data-new-contact-row]').length;
    let displayedOrganization = organization.value;
    let organizationContactsReady = true;
    const organizationDrafts = new Map();

    function updateCount() {
        count.textContent = contactSelectionSummary(selectedContactCount(picker), selectedRoleCount(picker));
        addNew.disabled = newList.querySelectorAll('[data-new-contact-row]').length >= 20;
        newList.querySelectorAll('[data-new-contact-row]').forEach(function (card) {
            const checkbox = card.querySelector('input[type="checkbox"]');
            checkbox?.setCustomValidity(selectedRoleCount(card) ? '' : 'Select at least one event role for this new contact.');
        });
    }

    function emptyMessage(message) {
        const empty = document.createElement('p');
        empty.className = 'engagement-contact-empty';
        empty.textContent = message;
        list.replaceChildren(empty);
        updateCount();
    }

    function contactCard(contact, roles, selectedRoles, added) {
        const fieldset = document.createElement('fieldset');
        fieldset.className = 'engagement-contact-card';
        fieldset.dataset.existingContactId = String(contact.id);
        const legend = document.createElement('legend');
        const name = document.createElement('span');
        name.textContent = String(contact.name || 'Contact');
        legend.appendChild(name);
        if (contact.organization_role) {
            const organizationRole = document.createElement('small');
            organizationRole.textContent = String(contact.organization_role);
            legend.appendChild(organizationRole);
        }
        fieldset.appendChild(legend);
        if (added) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'engagement_added_contact_ids[]';
            hidden.value = String(contact.id);
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button-secondary engagement-contact-remove';
            remove.dataset.removeAddedContact = '';
            remove.textContent = 'Remove from event';
            fieldset.append(hidden, remove);
        }
        if (contact.email) {
            const email = document.createElement('p');
            email.className = 'field-help engagement-contact-email';
            email.textContent = String(contact.email);
            fieldset.appendChild(email);
        }
        const options = document.createElement('div');
        options.className = 'engagement-contact-role-options';
        Object.entries(roles).forEach(function ([value, text]) {
            const label = document.createElement('label');
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'engagement_contacts[' + String(contact.id) + '][]';
            checkbox.value = value;
            checkbox.checked = selectedRoles.includes(value);
            const span = document.createElement('span');
            span.textContent = String(text);
            label.append(checkbox, span);
            options.appendChild(label);
        });
        fieldset.appendChild(options);
        return fieldset;
    }

    function existingCard(id) {
        return Array.from(picker.querySelectorAll('[data-existing-contact-id]')).find(function (card) {
            return card.dataset.existingContactId === String(id);
        });
    }

    function rememberOrganizationDraft() {
        if (!displayedOrganization || !organizationContactsReady) return;
        const selections = new Map();
        list.querySelectorAll('[data-existing-contact-id]').forEach(function (card) {
            selections.set(card.dataset.existingContactId, Array.from(card.querySelectorAll('input[type="checkbox"]:checked'))
                .map(function (checkbox) { return checkbox.value; }));
        });
        organizationDrafts.set(displayedOrganization, selections);
    }

    function renderContacts(contacts, roles) {
        const selections = organizationDrafts.get(organization.value) || new Map();
        const addedIds = new Set(Array.from(addedList.querySelectorAll('[data-existing-contact-id]'))
            .map(function (card) { return card.dataset.existingContactId; }));
        const available = contacts.filter(function (contact) { return !addedIds.has(String(contact.id)); });
        if (available.length === 0) {
            emptyMessage('No other contacts at this organization. Search existing contacts or add a new contact below.');
            return;
        }
        const fragment = document.createDocumentFragment();
        available.forEach(function (contact) {
            fragment.appendChild(contactCard(contact, roles, selections.get(String(contact.id)) || [], false));
        });
        list.replaceChildren(fragment);
        updateCount();
    }

    async function contactOptions(signal, query) {
        const url = new URL(picker.dataset.contactOptionsUrl, window.location.href);
        url.searchParams.set('organization_id', organization.value);
        if (query) url.searchParams.set('q', query);
        const response = await fetch(url, {
            credentials: 'same-origin', headers: { Accept: 'application/json' }, signal
        });
        const payload = await response.json();
        if (!response.ok || !Array.isArray(payload.contacts) || !payload.roles) throw new Error();
        return payload;
    }

    async function loadContacts() {
        rememberOrganizationDraft();
        request?.abort();
        searchRequest?.abort();
        searchResults.replaceChildren();
        searchStatus.textContent = '';
        displayedOrganization = organization.value;
        organizationContactsReady = false;
        if (retryLoad) retryLoad.hidden = true;
        if (!organization.value) {
            status.textContent = '';
            list.removeAttribute('aria-busy');
            emptyMessage('Select an organization to load its contacts.');
            return;
        }
        const currentRequest = new AbortController();
        request = currentRequest;
        status.textContent = 'Loading contacts';
        list.setAttribute('aria-busy', 'true');
        // Old organization contacts must never be submitted for the newly selected organization.
        list.replaceChildren();
        updateCount();
        try {
            const payload = await contactOptions(currentRequest.signal);
            if (currentRequest.signal.aborted) return;
            renderContacts(payload.contacts, payload.roles);
            organizationContactsReady = true;
            status.textContent = payload.contacts.length + ' active contact'
                + (payload.contacts.length === 1 ? ' loaded' : 's loaded');
        } catch (error) {
            if (error.name !== 'AbortError') {
                status.textContent = 'Contacts could not be loaded. Try selecting the organization again.';
                emptyMessage('Contact options are temporarily unavailable.');
                if (retryLoad) retryLoad.hidden = false;
            }
        } finally {
            if (request === currentRequest) list.removeAttribute('aria-busy');
        }
    }

    async function searchContacts() {
        searchRequest?.abort();
        searchResults.replaceChildren();
        const query = search.value.trim();
        if (!organization.value || query.length < 2) {
            searchStatus.textContent = !organization.value
                ? 'Select an organization before adding existing contacts.'
                : 'Enter at least 2 characters to search.';
            return;
        }
        const currentRequest = new AbortController();
        searchRequest = currentRequest;
        searchStatus.textContent = 'Searching contacts';
        try {
            const payload = await contactOptions(currentRequest.signal, query);
            if (currentRequest.signal.aborted) return;
            payload.contacts.forEach(function (contact) {
                const row = document.createElement('div');
                row.className = 'engagement-contact-search-result';
                const details = document.createElement('span');
                details.textContent = [contact.name, contact.email, contact.organization_name].filter(Boolean).join(' · ');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'button-secondary';
                button.textContent = 'Add to event';
                button.setAttribute('aria-label', 'Add ' + String(contact.name || 'contact') + ' to event');
                button.addEventListener('click', function () {
                    let card = existingCard(contact.id);
                    if (!card) {
                        card = contactCard(contact, payload.roles, ['on_site_contact'], true);
                        addedList.appendChild(card);
                    } else if (selectedRoleCount(card) === 0) {
                        const checkbox = card.querySelector('input[value="on_site_contact"]');
                        if (checkbox) checkbox.checked = true;
                    }
                    card.querySelector('input[type="checkbox"]')?.focus();
                    button.textContent = 'Added';
                    button.disabled = true;
                    searchStatus.textContent = String(contact.name || 'Contact') + ' added. Adjust their event roles below.';
                    updateCount();
                });
                row.append(details, button);
                searchResults.appendChild(row);
            });
            searchStatus.textContent = payload.contacts.length
                ? payload.contacts.length + ' matching contacts. Select each person to add.'
                : 'No matching contacts. You can add a new contact below.';
        } catch (error) {
            if (error.name !== 'AbortError') searchStatus.textContent = 'Contacts could not be searched. Please try again.';
        }
    }

    addNew.addEventListener('click', function () {
        if (newList.querySelectorAll('[data-new-contact-row]').length >= 20) return;
        const fragment = newTemplate.content.cloneNode(true);
        fragment.querySelectorAll('*').forEach(function (element) {
            Array.from(element.attributes).forEach(function (attribute) {
                if (attribute.value.includes('__INDEX__')) {
                    element.setAttribute(attribute.name, attribute.value.replaceAll('__INDEX__', String(nextIndex)));
                }
            });
        });
        nextIndex += 1;
        newList.appendChild(fragment);
        newList.lastElementChild.querySelector('input:not([type="hidden"])')?.focus();
        updateCount();
    });
    picker.addEventListener('click', function (event) {
        const remove = event.target.closest('[data-remove-new-contact], [data-remove-added-contact]');
        if (!remove) return;
        const isNew = remove.hasAttribute('data-remove-new-contact');
        remove.closest('.engagement-contact-card')?.remove();
        (isNew ? addNew : search).focus();
        updateCount();
    });
    searchButton.addEventListener('click', searchContacts);
    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchContacts();
        }
    });
    search.addEventListener('input', function () {
        searchRequest?.abort();
        searchResults.replaceChildren();
        searchStatus.textContent = '';
    });
    organization.addEventListener('change', loadContacts);
    retryLoad?.addEventListener('click', loadContacts);
    picker.closest('form')?.addEventListener('submit', function (event) {
        if (organizationContactsReady) return;
        event.preventDefault();
        status.textContent = 'Wait for the organization contacts to load before saving. If loading failed, retry below.';
        if (retryLoad && !retryLoad.hidden) retryLoad.focus();
        else organization.focus();
    });
    picker.addEventListener('change', updateCount);
    updateCount();
})();
