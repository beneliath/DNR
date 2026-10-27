/** Shared country and region pickers for organization forms and public audience forms. */
export function initializeAddressInputs(document = globalThis.document) {
    if (document.documentElement?.dataset.addressInputs === 'native') return;
    function typeaheadIndex(state, options, event) {
        if (event.key.length !== 1 || event.key === ' ' || event.ctrlKey || event.metaKey || event.altKey) return null;
        const now = Date.now();
        state.typeahead = now - (state.lastTypedAt || 0) > 700 ? event.key : (state.typeahead || '') + event.key;
        state.lastTypedAt = now;
        const index = options.findIndex(function (option) {
            return (option.dataset.countryName || option.textContent).trim().toLocaleLowerCase().startsWith(state.typeahead.toLocaleLowerCase());
        });
        return index >= 0 ? index : null;
    }

    function initializeAddressCountries() {
        const selects = Array.from(document.querySelectorAll('[data-address-country-native]'));
        if (selects.length === 0) return;
        const states = [];

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

        function optionForValue(state) {
            return Array.from(state.menu.querySelectorAll('[data-address-country-option]')).find(function (option) {
                return option.dataset.countryCode === state.select.value;
            }) || state.menu.querySelector('[data-address-country-option]');
        }

        function openPicker(state, focusOption) {
            closeOtherPickers(state);
            state.typeahead = '';
            state.lastTypedAt = 0;
            state.menu.hidden = false;
            state.trigger.setAttribute('aria-expanded', 'true');
            const selected = optionForValue(state);
            if (selected) {
                selected.scrollIntoView({ block: 'nearest' });
                if (focusOption) selected.focus();
            }
        }

        function updatePicker(state) {
            const option = optionForValue(state);
            if (!option) return;
            const countryCode = option.dataset.countryCode || '';
            const countryFlag = option.dataset.countryFlag || '🌐';
            const countryName = option.dataset.countryName || countryCode;
            state.flag.textContent = countryFlag;
            state.label.textContent = countryName;
            state.trigger.setAttribute('aria-label', `Country: ${countryName} (${countryCode})`);
            state.trigger.setAttribute('aria-required', String(state.select.required));
            state.menu.querySelectorAll('[data-address-country-option]').forEach(function (candidate) {
                candidate.setAttribute('aria-selected', String(candidate === option));
            });
        }

        function selectOption(state, option) {
            const countryCode = option.dataset.countryCode || '';
            if (!countryCode) return;
            state.select.value = countryCode;
            updatePicker(state);
            closePicker(state, true);
            state.select.dispatchEvent(new document.defaultView.Event('change', { bubbles: true }));
        }

        selects.forEach(function (select) {
            if (select.dataset.addressInitialized === 'true') return;
            const picker = select.closest('[data-address-country-picker]');
            const trigger = picker ? picker.querySelector('[data-address-country-toggle]') : null;
            const menu = picker ? picker.querySelector('[data-address-country-menu]') : null;
            const flag = picker ? picker.querySelector('[data-address-country-flag]') : null;
            const label = picker ? picker.querySelector('[data-address-country-label]') : null;
            if (!picker || !trigger || !menu || !flag || !label) return;
            select.dataset.addressInitialized = 'true';
            picker.dataset.addressEnhanced = 'true';
            select.tabIndex = -1;
            select.setAttribute('aria-hidden', 'true');
            trigger.hidden = false;

            const state = { select, picker, trigger, menu, flag, label };
            states.push(state);
            updatePicker(state);

            trigger.addEventListener('click', function () {
                if (menu.hidden) openPicker(state, true);
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
                const option = event.target.closest('[data-address-country-option]');
                if (option) selectOption(state, option);
            });
            menu.addEventListener('keydown', function (event) {
                const option = event.target.closest('[data-address-country-option]');
                if (!option) return;
                const options = Array.from(menu.querySelectorAll('[data-address-country-option]'));
                const currentIndex = options.indexOf(option);
                let targetIndex = null;
                if (event.key === 'ArrowDown') targetIndex = Math.min(options.length - 1, currentIndex + 1);
                else if (event.key === 'ArrowUp') targetIndex = Math.max(0, currentIndex - 1);
                else if (event.key === 'Home') targetIndex = 0;
                else if (event.key === 'End') targetIndex = options.length - 1;
                else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    selectOption(state, option);
                    return;
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closePicker(state, true);
                    return;
                } else if (event.key === 'Tab') {
                    closePicker(state, true);
                    return;
                }
                if (targetIndex === null) targetIndex = typeaheadIndex(state, options, event);
                if (targetIndex !== null) {
                    event.preventDefault();
                    options[targetIndex].focus();
                }
            });
            select.addEventListener('change', function () { updatePicker(state); });
            picker.addEventListener('focusout', function (event) {
                if (!picker.contains(event.relatedTarget)) closePicker(state, false);
            });
        });

        document.addEventListener('click', function (event) {
            states.forEach(function (state) {
                if (!state.picker.contains(event.target)) closePicker(state, false);
            });
        });
        document.querySelectorAll('input[name="same_address"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                states.forEach(function (state) {
                    state.trigger.setAttribute('aria-required', String(state.select.required));
                    closePicker(state, false);
                });
            });
        });
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
            state.typeahead = '';
            state.lastTypedAt = 0;
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
                option.setAttribute('aria-selected', String(Boolean(choice && option.dataset.regionCode === choice.code)));
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
            state.select.dispatchEvent(new document.defaultView.Event('change', { bubbles: true }));
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
            if (control.dataset.addressRegionInitialized === 'true') return;
            const prefix = control.dataset.addressRegionFor;
            const countrySelect = document.querySelector(`[data-address-country="${prefix}"]`);
            const textInput = control.querySelector('[data-address-region-input]');
            if (!prefix || !countrySelect || !textInput) return;
            control.dataset.addressRegionInitialized = 'true';

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
            trigger.id = `address-region-trigger-${prefix}-${index}`;
            select.dataset.errorTarget = trigger.id;
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
                if (menu.hidden) openPicker(state, true);
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
                } else if (event.key === 'Tab') {
                    closePicker(state, true);
                    return;
                }
                if (targetIndex === null) targetIndex = typeaheadIndex(state, options, event);
                if (targetIndex !== null) {
                    event.preventDefault();
                    options[targetIndex].focus();
                }
            });
            select.addEventListener('change', function () {
                const configuration = regionData[state.currentCountry];
                if (!configuration) return;
                const choice = choiceForValue(configuration, select.value);
                state.values[state.currentCountry] = choice ? choice.code : '';
                updatePickerLabel(state, configuration, choice);
                clearRegionError(state);
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
            picker.addEventListener('focusout', function (event) {
                if (!picker.contains(event.relatedTarget)) closePicker(state, false);
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

    initializeAddressCountries();
    initializeAddressRegions();
}
