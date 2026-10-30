import {setConditionalField} from './form-fields.mjs';

export function initializeContactRole() {
    const select = document.getElementById('contact_role');
    const group = document.getElementById('other_role_group');
    const input = document.getElementById('contact_role_other');
    if (!select || !group || !input) return;
    const update = function () { setConditionalField(select, 'other', group, input); };
    select.addEventListener('change', update);
    update();
}

export function initializeMailingAddress() {
    const section = document.getElementById('mailing_address_section');
    const radios = document.querySelectorAll('input[name="same_address"]');
    if (!section || radios.length === 0) return;
    const update = function () {
        const selected = document.querySelector('input[name="same_address"]:checked');
        const separate = Boolean(selected && selected.value === 'no');
        section.hidden = !separate;
        section.querySelectorAll('input, select').forEach(function (input) {
            input.required = separate && !section.hasAttribute('data-address-optional');
        });
    };
    radios.forEach(function (radio) { radio.addEventListener('change', update); });
    update();
}

export function updatePrimaryOrganizationContactRequirements() {
    const firstName = document.getElementById('contact_first_name');
    const lastName = document.getElementById('contact_last_name');
    const photo = document.getElementById('contact_photo');
    if (!firstName || !lastName) return;
    const fields = [
        [firstName, document.getElementById('first_name_label')],
        [lastName, document.getElementById('last_name_label')],
        [document.getElementById('contact_role'), document.getElementById('role_label')],
        [document.getElementById('contact_email'), document.getElementById('email_label')],
        [document.getElementById('contact_email_confirm'), document.getElementById('email_confirm_label')]
    ];
    const update = function () {
        const required = firstName.value.trim() !== '' || lastName.value.trim() !== ''
            || Boolean(photo && photo.files && photo.files.length);
        fields.forEach(function (entry) {
            if (entry[0]) entry[0].required = required;
            if (entry[1]) entry[1].classList.toggle('required', required);
        });
    };
    firstName.addEventListener('input', update);
    lastName.addEventListener('input', update);
    if (photo) photo.addEventListener('change', update);
    update();
}

function updateAdditionalRole(select) {
    const entry = select.closest('.contact-entry');
    const group = entry ? entry.querySelector('[data-additional-other-role]') : null;
    const input = group ? group.querySelector('input') : null;
    setConditionalField(select, 'other', group, input);
}

function updateCountryPicker(group, countryCode) {
    if (!group || !countryCode) return;
    const option = Array.from(group.querySelectorAll('[data-phone-country-option]')).find(function (candidate) {
        return candidate.dataset.countryCode === countryCode;
    });
    const input = group.querySelector('[data-phone-country-code]');
    if (input) input.value = countryCode;
    if (!option) return;
    const flag = group.querySelector('[data-phone-country-flag]');
    const dialCode = group.querySelector('[data-phone-country-dial-code]');
    if (flag) flag.textContent = option.dataset.countryFlag || '🌐';
    if (dialCode) dialCode.textContent = countryCode;
    group.querySelectorAll('[data-phone-country-option]').forEach(function (candidate) {
        candidate.setAttribute('aria-selected', String(candidate === option));
    });
}

export function initializeExistingOrganizationContacts() {
    const section = document.querySelector('[data-existing-organization-contacts]');
    if (!section) return;
    const rows = section.querySelector('[data-existing-organization-contact-rows]');
    const add = section.querySelector('[data-add-existing-organization-contact]');
    if (!rows || !add || !rows.firstElementChild) return;
    const template = rows.firstElementChild.cloneNode(true);
    template.querySelectorAll('option').forEach(function (option) {
        if (option.textContent === 'Previously Selected Contact Is Unavailable') option.remove();
    });
    function update() {
        Array.from(rows.children).forEach(function (row, index) {
            const select = row.querySelector('[data-existing-contact-select]');
            const role = row.querySelector('[data-existing-contact-role]');
            const filled = select.value !== '' || role.value.trim() !== '';
            select.id = 'existing-contact-' + index;
            select.name = 'existing_contacts[' + index + '][contact_id]';
            role.id = 'existing-contact-role-' + index;
            role.name = 'existing_contacts[' + index + '][role_title]';
            [select, role].forEach(function (field) {
                field.required = filled;
                const label = field.closest('.form-group').querySelector('label');
                label.htmlFor = field.id;
                label.classList.toggle('required', filled);
            });
        });
        add.disabled = rows.children.length >= 20;
    }
    function append() {
        const row = template.cloneNode(true);
        row.querySelector('[data-existing-contact-select]').value = '';
        row.querySelector('[data-existing-contact-role]').value = '';
        rows.appendChild(row);
        update();
        row.querySelector('select').focus();
    }
    add.addEventListener('click', function () { if (rows.children.length < 20) append(); });
    rows.addEventListener('input', update);
    rows.addEventListener('change', update);
    rows.addEventListener('click', function (event) {
        const remove = event.target.closest('[data-remove-existing-organization-contact]');
        if (!remove) return;
        remove.closest('[data-existing-organization-contact-row]').remove();
        if (rows.children.length === 0) append();
        else { update(); add.focus(); }
    });
    update();
}

export function initializeAdditionalOrganizationContacts() {
    const container = document.getElementById('contacts-container');
    const template = document.getElementById('contact-entry-template');
    if (!container || !template) return;
    let contactCount = 1;

    function addContact(values) {
        contactCount += 1;
        const index = contactCount - 1;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = template.innerHTML
            .replaceAll('__CONTACT_ID__', String(contactCount))
            .replaceAll('__CONTACT_INDEX__', String(index));
        const entry = wrapper.firstElementChild;
        container.appendChild(entry);
        if (values) {
            Object.entries(values).forEach(function (pair) {
                const input = entry.querySelector('[name="contacts[' + index + '][' + pair[0] + ']"]');
                if (input) input.value = pair[1] || '';
            });
            updateCountryPicker(entry.querySelector('[data-phone-input-group]'), values.phone_country_code || '+1');
        }
        const role = entry.querySelector('[data-contact-role-id]');
        if (role) updateAdditionalRole(role);
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-add-contact]')) {
            addContact(null);
            return;
        }
        const remove = event.target.closest('[data-remove-contact]');
        if (remove) {
            const entry = remove.closest('.contact-entry');
            if (entry) entry.remove();
        }
    });
    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-contact-role-id]')) updateAdditionalRole(event.target);
    });

    const submitted = document.getElementById('submitted-additional-contacts');
    if (submitted) {
        try {
            JSON.parse(submitted.textContent || '[]').forEach(addContact);
        } catch (error) {
            // Server validation remains authoritative if stale form data cannot be restored.
        }
    }
}
