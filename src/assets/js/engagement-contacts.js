(function () {
    function selectedRoleCount(container) {
        return container
            ? container.querySelectorAll('input[type="checkbox"]:checked').length
            : 0;
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { selectedRoleCount };
    }
    if (typeof document === 'undefined') return;

    const picker = document.querySelector('[data-engagement-contact-picker]');
    const organization = document.getElementById('organization_id');
    if (!picker || !organization) return;

    const list = picker.querySelector('[data-engagement-contact-list]');
    const status = picker.querySelector('[data-engagement-contact-status]');
    const count = picker.querySelector('[data-engagement-contact-count]');
    if (!list || !status || !count) return;

    let request;

    function updateCount() {
        const selected = selectedRoleCount(list);
        count.textContent = selected === 0
            ? 'No roles assigned'
            : selected + ' role' + (selected === 1 ? ' assigned' : 's assigned');
    }

    function emptyMessage(message) {
        const empty = document.createElement('p');
        empty.className = 'engagement-contact-empty';
        empty.textContent = message;
        list.replaceChildren(empty);
        updateCount();
    }

    function renderContacts(contacts, roles) {
        if (contacts.length === 0) {
            emptyMessage('This organization has no active contacts to assign.');
            return;
        }

        const fragment = document.createDocumentFragment();
        contacts.forEach(function (contact) {
            const fieldset = document.createElement('fieldset');
            fieldset.className = 'engagement-contact-card';

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

            const options = document.createElement('div');
            options.className = 'engagement-contact-role-options';
            Object.entries(roles).forEach(function (entry) {
                const label = document.createElement('label');
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'engagement_contacts[' + String(contact.id) + '][]';
                checkbox.value = entry[0];
                const text = document.createElement('span');
                text.textContent = String(entry[1]);
                label.append(checkbox, text);
                options.appendChild(label);
            });
            fieldset.appendChild(options);
            fragment.appendChild(fieldset);
        });
        list.replaceChildren(fragment);
        updateCount();
    }

    async function loadContacts() {
        request?.abort();
        const organizationId = organization.value;
        if (!organizationId) {
            status.textContent = '';
            emptyMessage('Select an organization to load its contacts.');
            return;
        }

        request = new AbortController();
        status.textContent = 'Loading contacts…';
        list.setAttribute('aria-busy', 'true');
        try {
            const url = new URL(picker.dataset.contactOptionsUrl, window.location.href);
            url.searchParams.set('organization_id', organizationId);
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: request.signal
            });
            const payload = await response.json();
            if (!response.ok || !Array.isArray(payload.contacts) || !payload.roles) {
                throw new Error();
            }
            renderContacts(payload.contacts, payload.roles);
            status.textContent = payload.contacts.length + ' active contact'
                + (payload.contacts.length === 1 ? ' loaded.' : 's loaded.');
        } catch (error) {
            if (error.name !== 'AbortError') {
                status.textContent = 'Contacts could not be loaded. Try selecting the organization again.';
                emptyMessage('Contact options are temporarily unavailable.');
            }
        } finally {
            list.removeAttribute('aria-busy');
        }
    }

    organization.addEventListener('change', loadContacts);
    list.addEventListener('change', updateCount);
    updateCount();
})();
