(function () {
    'use strict';
    function openNote() {
        if (window.location.hash !== '#add-note') return;
        const details = document.getElementById('add-note');
        if (details) {
            details.open = true;
            const textarea = details.querySelector('textarea');
            if (textarea) textarea.focus();
        }
    }
    window.addEventListener('hashchange', function () { window.setTimeout(openNote, 0); });
    document.querySelectorAll('a[href="#add-note"]').forEach(function (link) {
        link.addEventListener('click', function () { window.setTimeout(openNote, 0); });
    });
    document.querySelectorAll('[data-contact-affiliations]').forEach(function (panel) {
        const form = panel.closest('form');
        const primary = form.querySelector('[name="organization_id"]');
        const primaryRole = form.querySelector('[name="contact_role"]');
        const primaryRoleOther = form.querySelector('[name="contact_role_other"]');
        const rows = panel.querySelector('[data-affiliation-rows]');
        const template = panel.querySelector('[data-affiliation-template]');
        const addButton = panel.querySelector('[data-add-affiliation]');
        const status = panel.querySelector('[data-affiliation-status]');
        let nextIndex = rows.querySelectorAll('[data-contact-affiliation-row]').length;

        function validateOrganizations() {
            const selected = new Set(primary && primary.value ? [primary.value] : []);
            rows.querySelectorAll('[data-affiliation-organization]').forEach(function (select) {
                select.setCustomValidity(select.value && selected.has(select.value)
                    ? 'Each organization can only be added once. Use Make primary to switch its role, choose a different organization, or remove this row.'
                    : '');
                if (select.value) selected.add(select.value);
            });
        }

        function initializeRow(row) {
            const removeButton = row.querySelector('[data-remove-affiliation]');
            const makePrimaryButton = row.querySelector('[data-make-primary-affiliation]');
            makePrimaryButton.hidden = false;
            makePrimaryButton.addEventListener('click', function () {
                const select = row.querySelector('[data-affiliation-organization]');
                const title = row.querySelector('[data-affiliation-role]');
                if (!select.value) {
                    status.textContent = 'Select an organization to make primary';
                    select.focus();
                    return;
                }
                if (select.value === primary.value) {
                    status.textContent = 'This organization is already primary';
                    select.focus();
                    return;
                }
                if (!Array.from(primary.options).some(function (option) { return option.value === select.value; })) {
                    status.textContent = 'Choose an active organization to make primary';
                    select.focus();
                    return;
                }
                const previousOrganization = primary.value;
                const previousTitle = primaryRole.value === 'other'
                    ? primaryRoleOther.value
                    : primaryRole.options[primaryRole.selectedIndex].textContent;
                const nextTitle = title.value.trim();
                primary.value = select.value;
                const matchingRole = Array.from(primaryRole.options).find(function (option) {
                    return option.value !== 'other' && option.textContent.trim().toLowerCase() === nextTitle.toLowerCase();
                });
                primaryRole.value = matchingRole ? matchingRole.value : 'other';
                primaryRoleOther.value = matchingRole ? '' : nextTitle;
                if (previousOrganization) {
                    select.value = previousOrganization;
                    title.value = previousTitle;
                } else {
                    row.remove();
                }
                primaryRole.dispatchEvent(new Event('change', {bubbles: true}));
                primary.dispatchEvent(new Event('change', {bubbles: true}));
                validateOrganizations();
                status.textContent = previousOrganization
                    ? 'Primary organization updated; previous organization and role kept below'
                    : 'Primary organization updated';
                primary.focus();
            });
            removeButton.hidden = false;
            removeButton.addEventListener('click', function () {
                row.remove();
                validateOrganizations();
                status.textContent = 'Additional organization removed';
                addButton.focus();
            });
            row.querySelector('[data-affiliation-organization]').addEventListener('change', validateOrganizations);
        }

        rows.querySelectorAll('[data-contact-affiliation-row]').forEach(initializeRow);
        if (primary) primary.addEventListener('change', validateOrganizations);
        addButton.hidden = false;
        addButton.addEventListener('click', function () {
            const fragment = template.content.cloneNode(true);
            fragment.querySelectorAll('[id], [name], label[for]').forEach(function (element) {
                ['id', 'name', 'for'].forEach(function (attribute) {
                    const value = element.getAttribute(attribute);
                    if (value) element.setAttribute(attribute, value.replaceAll('__index__', String(nextIndex)));
                });
            });
            nextIndex += 1;
            const row = fragment.querySelector('[data-contact-affiliation-row]');
            const select = row.querySelector('[data-affiliation-organization]');
            // Include organizations created inline since the page was opened.
            if (primary) {
                Array.from(primary.options).forEach(function (option) {
                    if (option.value && !Array.from(select.options).some(function (existing) { return existing.value === option.value; })) {
                        select.add(new Option(option.textContent, option.value));
                    }
                });
            }
            rows.appendChild(fragment);
            initializeRow(row);
            validateOrganizations();
            status.textContent = 'Additional organization row added';
            select.focus();
        });
        validateOrganizations();
    });
    document.querySelectorAll('[data-inline-organization]').forEach(function (panel) {
        const button = panel.querySelector('[data-create-organization]');
        const name = panel.querySelector('[data-organization-name]');
        const status = panel.querySelector('[data-organization-status]');
        const form = panel.closest('form');
        const select = form.querySelector('[name="organization_id"]');
        button.addEventListener('click', async function () {
            if (!name.value.trim()) {
                status.textContent = 'Enter an organization name';
                name.focus();
                return;
            }
            button.disabled = true;
            status.textContent = 'Creating organization';
            const data = new FormData();
            const csrf = form.querySelector('[name="csrf_token"]');
            if (csrf) data.append('csrf_token', csrf.value);
            data.append('organization_name', name.value.trim());
            try {
                const response = await fetch('create_organization_inline.php', {method: 'POST', body: data, credentials: 'same-origin'});
                const result = await response.json();
                if (!response.ok || !Number.isInteger(result.id) || result.id < 1 || !result.label) {
                    throw new Error(result.error || 'Unable to create the organization. Try again.');
                }
                const option = document.createElement('option');
                option.value = String(result.id);
                option.textContent = result.label;
                select.appendChild(option);
                if (form.querySelectorAll) {
                    form.querySelectorAll('[data-affiliation-organization]').forEach(function (affiliationSelect) {
                        affiliationSelect.add(new Option(result.label, String(result.id)));
                    });
                }
                select.value = String(result.id);
                select.dispatchEvent(new Event('change', {bubbles: true}));
                status.textContent = result.label + ' created and selected';
                name.value = '';
                select.focus();
            } catch (error) {
                status.textContent = error.message || 'Unable to create the organization. Your contact draft is preserved.';
            } finally {
                button.disabled = false;
            }
        });
    });
    openNote();
}());
