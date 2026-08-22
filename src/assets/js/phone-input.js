(function () {
    function phoneGroup(input) {
        return input.closest('[data-phone-input-group]');
    }

    function countryInputFor(input) {
        const group = phoneGroup(input);
        return group ? group.querySelector('[data-phone-country-code]') : null;
    }

    function nationalInputFor(input) {
        const group = phoneGroup(input);
        return group ? group.querySelector('[data-phone-number]') : null;
    }

    function normalizedCountryCode(value) {
        const compact = String(value || '').trim().replace(/[\s()-]+/g, '');
        return /^\+[1-9][0-9]{0,2}$/.test(compact) ? compact : null;
    }

    function localDigits(value, countryCode) {
        let digits = String(value || '').replace(/[^0-9]/g, '');
        const countryDigits = countryCode.slice(1);
        if (String(value || '').trim().startsWith('+') && digits.startsWith(countryDigits)) {
            digits = digits.slice(countryDigits.length);
        }
        return digits;
    }

    function formatNationalInput(input) {
        const countryInput = countryInputFor(input);
        if (!countryInput || input.value.trim() === '') {
            input.setCustomValidity('');
            return true;
        }

        const countryCode = normalizedCountryCode(countryInput.value);
        if (!countryCode) {
            countryInput.setCustomValidity('Enter a country code such as +1.');
            input.setCustomValidity('');
            return false;
        }

        countryInput.value = countryCode;
        countryInput.setCustomValidity('');
        let digits = localDigits(input.value, countryCode);
        if (countryCode === '+1' && digits.length === 11 && digits.startsWith('1')) {
            digits = digits.slice(1);
        }
        const totalDigits = countryCode.length - 1 + digits.length;
        if ((countryCode === '+1' && digits.length !== 10)
            || (countryCode !== '+1' && (digits.length < 4 || totalDigits > 15))) {
            input.setCustomValidity('Enter a valid telephone number for the selected country.');
            return false;
        }

        if (countryCode === '+1') {
            input.value = `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
        } else {
            input.value = input.value.trim();
        }
        input.setCustomValidity('');
        return true;
    }

    function validateCountryInput(input) {
        const nationalInput = nationalInputFor(input);
        if (!nationalInput || nationalInput.value.trim() === '') {
            input.setCustomValidity('');
            return true;
        }
        return formatNationalInput(nationalInput);
    }

    function closeCountryPicker(picker) {
        const trigger = picker ? picker.querySelector('[data-phone-country-toggle]') : null;
        const menu = picker ? picker.querySelector('[data-phone-country-menu]') : null;
        if (!trigger || !menu) return;
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    }

    function closeOtherCountryPickers(activePicker) {
        document.querySelectorAll('[data-phone-country-picker]').forEach(function (picker) {
            if (picker !== activePicker) closeCountryPicker(picker);
        });
    }

    function toggleCountryPicker(trigger) {
        const picker = trigger.closest('[data-phone-country-picker]');
        const menu = picker ? picker.querySelector('[data-phone-country-menu]') : null;
        if (!picker || !menu) return;

        const shouldOpen = menu.hidden;
        closeOtherCountryPickers(picker);
        menu.hidden = !shouldOpen;
        trigger.setAttribute('aria-expanded', String(shouldOpen));
        if (shouldOpen) {
            const selected = menu.querySelector('[aria-selected="true"]');
            if (selected) selected.scrollIntoView({ block: 'nearest' });
        }
    }

    function selectCountryOption(option) {
        const picker = option.closest('[data-phone-country-picker]');
        const group = option.closest('[data-phone-input-group]');
        if (!picker || !group) return;

        const countryInput = picker.querySelector('[data-phone-country-code]');
        const trigger = picker.querySelector('[data-phone-country-toggle]');
        const flag = picker.querySelector('[data-phone-country-flag]');
        const dialCode = picker.querySelector('[data-phone-country-dial-code]');
        if (!countryInput || !trigger || !flag || !dialCode) return;

        const countryCode = option.dataset.countryCode || '+1';
        const countryFlag = option.dataset.countryFlag || '🌐';
        const countryName = option.dataset.countryName || 'International';
        countryInput.value = countryCode;
        flag.textContent = countryFlag;
        dialCode.textContent = countryCode;
        trigger.setAttribute(
            'aria-label',
            `${trigger.dataset.phoneCountryLabel || 'Phone country code'}: ${countryName} ${countryCode}`
        );
        picker.querySelectorAll('[data-phone-country-option]').forEach(function (candidate) {
            candidate.setAttribute('aria-selected', String(candidate === option));
        });
        closeCountryPicker(picker);
        validateCountryInput(countryInput);
        const nationalInput = group.querySelector('[data-phone-number]');
        if (nationalInput) nationalInput.focus();
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = {
            formatNationalInput,
            localDigits,
            normalizedCountryCode
        };
    }
    if (typeof document === 'undefined') return;

    document.addEventListener('click', function (event) {
        const option = event.target.closest('[data-phone-country-option]');
        if (option) {
            selectCountryOption(option);
            return;
        }

        const trigger = event.target.closest('[data-phone-country-toggle]');
        if (trigger) {
            toggleCountryPicker(trigger);
            return;
        }

        closeOtherCountryPickers(null);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') return;
        const picker = event.target.closest('[data-phone-country-picker]');
        if (!picker) return;
        closeCountryPicker(picker);
        const trigger = picker.querySelector('[data-phone-country-toggle]');
        if (trigger) trigger.focus();
    });

    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-phone-number], [data-phone-country-code]')) {
            event.target.setCustomValidity('');
        }
    });

    document.addEventListener('focusout', function (event) {
        if (event.target.matches('[data-phone-number]')) {
            formatNationalInput(event.target);
        } else if (event.target.matches('[data-phone-country-code]')) {
            validateCountryInput(event.target);
        }
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-phone-country-code]')) {
            validateCountryInput(event.target);
        }
    });

    document.addEventListener('submit', function (event) {
        const phoneInputs = event.target.querySelectorAll('[data-phone-number]');
        for (const input of phoneInputs) {
            if (!formatNationalInput(input)) {
                event.preventDefault();
                const group = phoneGroup(input);
                const invalidInput = group ? group.querySelector(':invalid') : input;
                (invalidInput || input).reportValidity();
                (invalidInput || input).focus();
                break;
            }
        }
    });
})();
