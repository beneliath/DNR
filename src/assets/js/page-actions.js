(function () {
    function setConditionalField(select, expectedValue, container, input) {
        if (!select || !container || !input) return;
        const visible = select.value === expectedValue;
        container.hidden = !visible;
        input.required = visible;
        if (!visible) input.value = '';
    }

    function initializeContactRole() {
        const select = document.getElementById('contact_role');
        const group = document.getElementById('other_role_group');
        const input = document.getElementById('contact_role_other');
        if (!select || !group || !input) return;
        const update = function () { setConditionalField(select, 'other', group, input); };
        select.addEventListener('change', update);
        update();
    }

    function initializeMailingAddress() {
        const section = document.getElementById('mailing_address_section');
        const radios = document.querySelectorAll('input[name="same_address"]');
        if (!section || radios.length === 0) return;
        const update = function () {
            const selected = document.querySelector('input[name="same_address"]:checked');
            const separate = Boolean(selected && selected.value === 'no');
            section.hidden = !separate;
            section.querySelectorAll('input, select').forEach(function (input) {
                input.required = separate;
            });
        };
        radios.forEach(function (radio) { radio.addEventListener('change', update); });
        update();
    }

    function updatePrimaryOrganizationContactRequirements() {
        const firstName = document.getElementById('contact_first_name');
        const lastName = document.getElementById('contact_last_name');
        if (!firstName || !lastName) return;
        const fields = [
            [firstName, document.getElementById('first_name_label')],
            [lastName, document.getElementById('last_name_label')],
            [document.getElementById('contact_role'), document.getElementById('role_label')],
            [document.getElementById('contact_email'), document.getElementById('email_label')],
            [document.getElementById('contact_email_confirm'), document.getElementById('email_confirm_label')]
        ];
        const update = function () {
            const required = firstName.value.trim() !== '' || lastName.value.trim() !== '';
            fields.forEach(function (entry) {
                if (entry[0]) entry[0].required = required;
                if (entry[1]) entry[1].classList.toggle('required', required);
            });
        };
        firstName.addEventListener('input', update);
        lastName.addEventListener('input', update);
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

    function initializeAdditionalOrganizationContacts() {
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

    function initializeEngagementForm() {
        const form = document.querySelector('.engagement-form, #engagement-edit-form');
        if (!form) return;
        const eventType = document.getElementById('event_type');
        const compensation = document.getElementById('compensation_type');
        const housing = document.getElementById('housing_type');
        const updateEvent = function () {
            setConditionalField(eventType, 'other', document.getElementById('other_event_type_div'), document.getElementById('event_type_other'));
        };
        const updateCompensation = function () {
            setConditionalField(compensation, 'Other', document.getElementById('other_compensation_div'), document.getElementById('other_compensation'));
        };
        const updateHousing = function () {
            setConditionalField(housing, 'Other', document.getElementById('other_housing_div'), document.getElementById('other_housing'));
        };
        if (eventType) eventType.addEventListener('change', updateEvent);
        if (compensation) compensation.addEventListener('change', updateCompensation);
        if (housing) housing.addEventListener('change', updateHousing);
        updateEvent();
        updateCompensation();
        updateHousing();

        const chronEntry = document.getElementById('new-chron-entry');
        if (chronEntry) chronEntry.addEventListener('input', function () { chronEntry.setCustomValidity(''); });
        form.addEventListener('submit', function (event) {
            if (event.submitter && event.submitter.matches('[data-add-chron-entry]') && chronEntry && !chronEntry.value.trim()) {
                event.preventDefault();
                chronEntry.setCustomValidity('Enter a Chron entry before adding it.');
                chronEntry.reportValidity();
                chronEntry.focus();
                return;
            }
            const startDate = document.getElementById('event_start_date');
            const endDate = document.getElementById('event_end_date');
            if (startDate && endDate && startDate.value && endDate.value && endDate.value < startDate.value) {
                event.preventDefault();
                endDate.setCustomValidity('End date must be on or after the start date.');
                endDate.reportValidity();
                endDate.focus();
                return;
            }
            if (typeof window.validateEngagementPresentations === 'function' && !window.validateEngagementPresentations()) {
                event.preventDefault();
            }
        });
    }

    function initializeConfirmations() {
        document.querySelectorAll('[data-confirm]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                if (!window.confirm(button.dataset.confirm)) event.preventDefault();
            });
        });
    }

    function initializeSelectAll(selectAllId, inputName) {
        const selectAll = document.getElementById(selectAllId);
        const checkboxes = Array.from(document.querySelectorAll('input[name="' + inputName + '"]'));
        if (!selectAll || checkboxes.length === 0) return;
        const synchronize = function () {
            selectAll.checked = checkboxes.every(function (checkbox) { return checkbox.checked; });
            selectAll.indeterminate = !selectAll.checked && checkboxes.some(function (checkbox) { return checkbox.checked; });
        };
        selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
        });
        checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', synchronize); });
        synchronize();
    }

    function initializeSensitiveUserActions() {
        document.querySelectorAll('form[data-sensitive-action]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                const deleting = form.dataset.sensitiveAction === 'delete-user';
                const phrase = deleting ? 'DELETE USER' : 'RESET 2FA';
                const confirmation = window.prompt(
                    (deleting
                        ? 'Are you sure you want to delete this user? This cannot be undone.'
                        : 'Reset two-factor authentication for this user? Their current authenticator and recovery codes will stop working.')
                    + '\n\nType ' + phrase + ' to continue:'
                );
                if (confirmation !== phrase) {
                    event.preventDefault();
                    if (confirmation !== null) window.alert('Action cancelled. The confirmation phrase did not match ' + phrase + '.');
                    return;
                }
                const field = form.elements[deleting ? 'delete_confirmation' : 'reset_confirmation'];
                if (field) field.value = confirmation;
            });
        });
    }

    function initializeEngagementCopy() {
        const data = document.getElementById('engagement-export-data');
        const status = document.getElementById('copy-status');
        if (!data || !status) return;
        let exports;
        try { exports = JSON.parse(data.textContent || '{}'); } catch (error) { return; }
        function copyWithFallback(value) {
            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.readOnly = true;
            textarea.className = 'clipboard-fallback';
            document.body.appendChild(textarea);
            textarea.select();
            const copied = document.execCommand('copy');
            textarea.remove();
            if (!copied) throw new Error('The browser declined the copy command.');
        }
        document.querySelectorAll('[data-copy-format]').forEach(function (button) {
            button.addEventListener('click', async function () {
                const originalLabel = button.textContent;
                try {
                    const value = exports[button.dataset.copyFormat] || '';
                    if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(value);
                    else copyWithFallback(value);
                    button.textContent = 'Copied!';
                    status.textContent = originalLabel + ' copied to the clipboard.';
                } catch (error) {
                    button.textContent = 'Copy failed';
                    status.textContent = originalLabel + ' could not be copied.';
                }
                window.setTimeout(function () { button.textContent = originalLabel; }, 1800);
            });
        });
    }

    function initialize() {
        initializeContactRole();
        initializeMailingAddress();
        updatePrimaryOrganizationContactRequirements();
        initializeAdditionalOrganizationContacts();
        initializeEngagementForm();
        initializeConfirmations();
        initializeSelectAll('select-all-chron-entries', 'chron_entry_ids[]');
        initializeSelectAll('select-all-presentations', 'presentation_ids[]');
        initializeSensitiveUserActions();
        initializeEngagementCopy();
        const success = document.querySelector('.success');
        if (success && document.querySelector('.engagement-form')) {
            success.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
