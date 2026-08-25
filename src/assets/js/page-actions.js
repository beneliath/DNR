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

    function initializeAddressRegions() {
        const dataElement = document.getElementById('address-region-data');
        const controls = Array.from(document.querySelectorAll('[data-address-region-control]'));
        if (!dataElement || controls.length === 0) return;

        let regionData;
        try {
            regionData = JSON.parse(dataElement.textContent || '{}');
        } catch (error) {
            return;
        }

        const states = [];

        function requiredFor(state) {
            const section = state.control.closest('.address-section');
            return state.control.dataset.regionRequired === 'true'
                && !(section && section.hidden);
        }

        function synchronizeRequired(state) {
            const required = requiredFor(state);
            const custom = !state.picker.hidden;
            state.textInput.required = required && !custom;
            state.select.required = required && custom;
            state.trigger.setAttribute('aria-required', String(required));
            if (!required) clearRegionError(state);
        }

        function clearRegionError(state) {
            state.error.hidden = true;
            state.trigger.removeAttribute('aria-invalid');
            state.select.setCustomValidity('');
        }

        function closePicker(state, focusTrigger) {
            state.menu.hidden = true;
            state.trigger.setAttribute('aria-expanded', 'false');
            if (focusTrigger) state.trigger.focus();
        }

        function closeOtherPickers(activeState) {
            states.forEach(function (state) {
                if (state !== activeState) closePicker(state, false);
            });
        }

        function selectedOptionButton(state) {
            return Array.from(state.menu.querySelectorAll('[data-address-region-option]')).find(function (option) {
                return option.dataset.regionCode === state.select.value;
            }) || state.menu.querySelector('[data-address-region-option]');
        }

        function openPicker(state, focusOption) {
            closeOtherPickers(state);
            state.menu.hidden = false;
            state.trigger.setAttribute('aria-expanded', 'true');
            const selected = selectedOptionButton(state);
            if (selected) {
                selected.scrollIntoView({ block: 'nearest' });
                if (focusOption) selected.focus();
            }
        }

        function choiceForValue(configuration, value) {
            const normalized = String(value || '').trim();
            const upper = normalized.toUpperCase();
            const lower = normalized.toLocaleLowerCase();
            return configuration.choices.find(function (choice) {
                return choice.code === upper || choice.name.toLocaleLowerCase() === lower;
            }) || null;
        }

        function updatePickerLabel(state, configuration, choice) {
            state.label.textContent = choice ? choice.name : configuration.label;
            state.trigger.setAttribute(
                'aria-label',
                choice ? `${configuration.label}: ${choice.name}` : `Select ${configuration.label.toLowerCase()}`
            );
            state.menu.querySelectorAll('[data-address-region-option]').forEach(function (option) {
                option.setAttribute('aria-selected', String(choice && option.dataset.regionCode === choice.code));
            });
        }

        function selectRegion(state, code, focusTrigger) {
            const configuration = regionData[state.currentCountry];
            if (!configuration) return;
            const choice = configuration.choices.find(function (candidate) {
                return candidate.code === code;
            }) || null;
            state.select.value = choice ? choice.code : '';
            state.values[state.currentCountry] = state.select.value;
            updatePickerLabel(state, configuration, choice);
            clearRegionError(state);
            closePicker(state, focusTrigger);
        }

        function populatePicker(state, configuration, value) {
            state.select.replaceChildren();
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = configuration.label;
            state.select.appendChild(placeholder);
            state.menu.replaceChildren();

            configuration.choices.forEach(function (choice) {
                const nativeOption = document.createElement('option');
                nativeOption.value = choice.code;
                nativeOption.textContent = choice.name;
                state.select.appendChild(nativeOption);

                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'address-region-option';
                option.dataset.addressRegionOption = '';
                option.dataset.regionCode = choice.code;
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');
                option.tabIndex = -1;

                const name = document.createElement('span');
                name.textContent = choice.name;
                option.appendChild(name);
                state.menu.appendChild(option);
            });

            const selected = choiceForValue(configuration, value);
            state.select.value = selected ? selected.code : '';
            state.values[state.currentCountry] = state.select.value;
            state.select.setAttribute('aria-label', configuration.label);
            state.menu.setAttribute('aria-label', `${configuration.label} options`);
            updatePickerLabel(state, configuration, selected);
        }

        function currentValue(state) {
            return state.picker.hidden ? state.textInput.value : state.select.value;
        }

        function configureForCountry(state, countryCode, initial) {
            if (state.currentCountry !== null) {
                state.values[state.currentCountry] = currentValue(state);
            }
            const value = initial ? state.textInput.value : (state.values[countryCode] || '');
            state.currentCountry = countryCode;
            const configuration = regionData[countryCode];

            if (configuration) {
                state.textInput.hidden = true;
                state.textInput.disabled = true;
                state.textInput.required = false;
                state.picker.hidden = false;
                state.select.disabled = false;
                populatePicker(state, configuration, value);
            } else {
                state.picker.hidden = true;
                state.select.disabled = true;
                state.select.required = false;
                state.textInput.hidden = false;
                state.textInput.disabled = false;
                state.textInput.placeholder = 'State/Province';
                state.textInput.value = value;
                closePicker(state, false);
            }
            clearRegionError(state);
            synchronizeRequired(state);
        }

        controls.forEach(function (control, index) {
            const prefix = control.dataset.addressRegionFor;
            const countrySelect = document.querySelector(`[data-address-country="${prefix}"]`);
            const textInput = control.querySelector('[data-address-region-input]');
            if (!prefix || !countrySelect || !textInput) return;

            const picker = document.createElement('div');
            picker.className = 'address-region-picker';
            picker.hidden = true;

            const select = document.createElement('select');
            select.name = textInput.name;
            select.className = 'address-region-native-select';
            select.disabled = true;
            select.tabIndex = -1;
            select.setAttribute('aria-hidden', 'true');
            picker.appendChild(select);

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'address-region-trigger';
            trigger.dataset.addressRegionToggle = '';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');

            const triggerLabel = document.createElement('span');
            triggerLabel.className = 'address-region-label';
            trigger.appendChild(triggerLabel);

            const chevron = document.createElement('span');
            chevron.className = 'address-region-chevron';
            chevron.setAttribute('aria-hidden', 'true');
            trigger.appendChild(chevron);
            picker.appendChild(trigger);

            const menu = document.createElement('div');
            menu.className = 'address-region-menu';
            menu.id = `address-region-menu-${prefix}-${index}`;
            menu.dataset.addressRegionMenu = '';
            menu.setAttribute('role', 'listbox');
            menu.hidden = true;
            trigger.setAttribute('aria-controls', menu.id);
            picker.appendChild(menu);

            const error = document.createElement('span');
            error.className = 'address-region-error';
            error.id = `address-region-error-${prefix}-${index}`;
            error.textContent = 'Select a valid region.';
            error.hidden = true;
            error.setAttribute('role', 'alert');
            trigger.setAttribute('aria-describedby', error.id);
            picker.appendChild(error);
            control.appendChild(picker);

            const state = {
                control,
                countrySelect,
                textInput,
                picker,
                select,
                trigger,
                label: triggerLabel,
                menu,
                error,
                currentCountry: null,
                values: {}
            };
            states.push(state);

            trigger.addEventListener('click', function () {
                if (menu.hidden) openPicker(state, false);
                else closePicker(state, false);
            });
            trigger.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    openPicker(state, true);
                } else if (event.key === 'Escape') {
                    closePicker(state, false);
                }
            });
            menu.addEventListener('click', function (event) {
                const option = event.target.closest('[data-address-region-option]');
                if (option) selectRegion(state, option.dataset.regionCode || '', true);
            });
            menu.addEventListener('keydown', function (event) {
                const option = event.target.closest('[data-address-region-option]');
                if (!option) return;
                const options = Array.from(menu.querySelectorAll('[data-address-region-option]'));
                const currentIndex = options.indexOf(option);
                let targetIndex = null;
                if (event.key === 'ArrowDown') targetIndex = Math.min(options.length - 1, currentIndex + 1);
                else if (event.key === 'ArrowUp') targetIndex = Math.max(0, currentIndex - 1);
                else if (event.key === 'Home') targetIndex = 0;
                else if (event.key === 'End') targetIndex = options.length - 1;
                else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectRegion(state, option.dataset.regionCode || '', true);
                    return;
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closePicker(state, true);
                    return;
                }
                if (targetIndex !== null) {
                    event.preventDefault();
                    options[targetIndex].focus();
                }
            });
            select.addEventListener('invalid', function (event) {
                event.preventDefault();
                const configuration = regionData[state.currentCountry];
                const label = configuration ? configuration.label.toLowerCase() : 'region';
                error.textContent = `Select a ${label}.`;
                error.hidden = false;
                trigger.setAttribute('aria-invalid', 'true');
                trigger.focus();
            });
            countrySelect.addEventListener('change', function () {
                configureForCountry(state, countrySelect.value, false);
            });
            configureForCountry(state, countrySelect.value, true);
        });

        document.addEventListener('click', function (event) {
            states.forEach(function (state) {
                if (!state.picker.contains(event.target)) closePicker(state, false);
            });
        });
        document.querySelectorAll('input[name="same_address"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                states.forEach(synchronizeRequired);
            });
        });
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
        const form = document.querySelector('.engagement-form, #engagement-edit-form, [data-chron-form]');
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

    async function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(value);
            return;
        }
        copyWithFallback(value);
    }

    function initializeCopyTextButtons() {
        document.querySelectorAll('[data-copy-text]').forEach(function (button) {
            const status = document.getElementById(button.dataset.copyStatus || '');
            const idleLabel = button.getAttribute('aria-label') || 'Copy';
            const idleTitle = button.getAttribute('title') || idleLabel;
            const idleTooltip = button.dataset.tooltip || idleTitle;
            let feedbackTimer;

            button.addEventListener('click', async function () {
                window.clearTimeout(feedbackTimer);
                button.classList.remove('is-copied', 'is-copy-failed');
                try {
                    await copyText(button.dataset.copyText || '');
                    button.classList.add('is-copied');
                    button.setAttribute('aria-label', 'Email subject marker copied');
                    button.setAttribute('title', 'Copied');
                    button.dataset.tooltip = 'Copied';
                    if (status) status.textContent = 'Email subject marker copied to the clipboard.';
                } catch (error) {
                    button.classList.add('is-copy-failed');
                    button.setAttribute('aria-label', 'Email subject marker could not be copied');
                    button.setAttribute('title', 'Copy failed');
                    button.dataset.tooltip = 'Copy failed';
                    if (status) status.textContent = 'The email subject marker could not be copied.';
                }

                feedbackTimer = window.setTimeout(function () {
                    button.classList.remove('is-copied', 'is-copy-failed');
                    button.setAttribute('aria-label', idleLabel);
                    button.setAttribute('title', idleTitle);
                    button.dataset.tooltip = idleTooltip;
                }, 2000);
            });
        });
    }

    async function qrImageAsPng(url) {
        const response = await fetch(url, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('The QR code image could not be loaded.');
        const source = await response.blob();
        if (!source.type.startsWith('image/')) throw new Error('The QR code response is not an image.');
        if (source.type === 'image/png') return source;
        if (typeof createImageBitmap !== 'function') throw new Error('Image conversion is unavailable.');

        const bitmap = await createImageBitmap(source);
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Image conversion is unavailable.');
        context.drawImage(bitmap, 0, 0);
        if (typeof bitmap.close === 'function') bitmap.close();
        return await new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob);
                else reject(new Error('The QR code could not be converted.'));
            }, 'image/png');
        });
    }

    function qrCopyStatus(button, message, failed) {
        const container = button.closest('.presentation-qr-card, .presentation-qr-display');
        const status = container ? container.querySelector('[data-copy-status]') : null;
        if (status) {
            status.textContent = message;
            status.classList.toggle('is-error', Boolean(failed));
        }
        button.classList.toggle('is-copied', !failed);
        button.classList.toggle('is-copy-failed', Boolean(failed));
        window.setTimeout(function () {
            button.classList.remove('is-copied', 'is-copy-failed');
        }, 2200);
    }

    function openQrFallback(button, url) {
        window.open(url, '_blank', 'noopener');
        qrCopyStatus(button, 'QR code opened in a new tab.', false);
    }

    function initializeQrCopy() {
        document.addEventListener('click', async function (event) {
            const button = event.target.closest('[data-copy-qr-url]');
            if (!button || button.hidden) return;
            const url = button.dataset.copyQrUrl || '';
            if (!url) return;

            if (!navigator.clipboard
                || typeof navigator.clipboard.write !== 'function'
                || typeof ClipboardItem !== 'function'
                || !window.isSecureContext
            ) {
                openQrFallback(button, url);
                return;
            }

            button.disabled = true;
            try {
                const png = await qrImageAsPng(url);
                await navigator.clipboard.write([new ClipboardItem({ 'image/png': png })]);
                qrCopyStatus(button, 'QR code copied to the clipboard.', false);
            } catch (error) {
                openQrFallback(button, url);
            } finally {
                button.disabled = false;
            }
        });
    }

    function initializeEngagementCopy() {
        const data = document.getElementById('engagement-export-data');
        const status = document.getElementById('copy-status');
        if (!data || !status) return;
        let exports;
        try { exports = JSON.parse(data.textContent || '{}'); } catch (error) { return; }
        document.querySelectorAll('[data-copy-format]').forEach(function (button) {
            button.addEventListener('click', async function () {
                const originalLabel = button.textContent;
                try {
                    const value = exports[button.dataset.copyFormat] || '';
                    await copyText(value);
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

    function initializeInvitationSubmission() {
        document.querySelectorAll('[data-invitation-form]').forEach(function (form) {
            const button = form.querySelector('[data-invitation-submit]');
            const status = form.querySelector('[data-invitation-submit-status]');
            if (!button || !status) return;
            const idleLabel = button.textContent;
            const submittingLabel = button.dataset.submittingLabel || 'Sending invitation…';

            const reset = function () {
                delete form.dataset.submitting;
                form.removeAttribute('aria-busy');
                button.disabled = false;
                button.removeAttribute('aria-busy');
                button.textContent = idleLabel;
                status.hidden = true;
            };

            form.addEventListener('submit', function (event) {
                if (form.dataset.submitting === 'true') {
                    event.preventDefault();
                    return;
                }
                form.dataset.submitting = 'true';
                form.setAttribute('aria-busy', 'true');
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.textContent = submittingLabel;
                status.hidden = false;
            });
            window.addEventListener('pageshow', reset);
        });
    }

    function initialize() {
        initializeContactRole();
        initializeMailingAddress();
        initializeAddressRegions();
        updatePrimaryOrganizationContactRequirements();
        initializeAdditionalOrganizationContacts();
        initializeEngagementForm();
        initializeConfirmations();
        initializeSelectAll('select-all-chron-entries', 'chron_entry_ids[]');
        initializeSelectAll('select-all-presentations', 'presentation_ids[]');
        initializeSensitiveUserActions();
        initializeCopyTextButtons();
        initializeQrCopy();
        initializeEngagementCopy();
        initializeInvitationSubmission();
        const success = document.querySelector('.success');
        if (success && document.querySelector('.engagement-form')) {
            success.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
    else initialize();
})();
