import {setConditionalField} from './form-fields.mjs';

export function initializeEngagementForm() {
    const form = document.querySelector('.engagement-form, #engagement-edit-form, .inquiry-form, [data-chron-form]');
    if (!form) return;
    initializeOrganizationAddress();
    const startDate = document.getElementById('event_start_date');
    const endDate = document.getElementById('event_end_date');
    if (form.id === 'new-engagement-form' && startDate && endDate) {
        const synchronizeEndDateCalendar = function () {
            endDate.min = startDate.value;
            endDate.setCustomValidity('');
        };
        startDate.addEventListener('input', synchronizeEndDateCalendar);
        endDate.addEventListener('input', function () { endDate.setCustomValidity(''); });
        synchronizeEndDateCalendar();
    }
    const inquiryDateRanges = [
        {
            start: document.getElementById('preferred-start'),
            end: document.getElementById('preferred-end'),
            label: 'Preferred Range'
        },
        {
            start: document.getElementById('alternate-start'),
            end: document.getElementById('alternate-end'),
            label: 'Alternate Range'
        }
    ].filter(function (range) { return range.start && range.end; });
    inquiryDateRanges.forEach(function (range) {
        range.synchronize = function () {
            if (range.start.value) range.end.min = range.start.value;
            else range.end.removeAttribute('min');
            const invalid = Boolean(
                range.start.value && range.end.value && range.end.value < range.start.value
            );
            range.end.setCustomValidity(
                invalid ? range.label + ' end date must be on or after its start date.' : ''
            );
        };
        range.start.addEventListener('input', range.synchronize);
        range.end.addEventListener('input', range.synchronize);
        range.synchronize();
    });
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
    if (chronEntry) {
        chronEntry.addEventListener('input', function () { chronEntry.setCustomValidity(''); });
        // Clear action-specific validation before native form validation runs.
        // These submit buttons can live outside the form via their form attribute.
        document.addEventListener('click', function (event) {
            const submitter = event.target.closest('button, input[type="submit"], input[type="image"]');
            if (submitter && submitter.form === form && ['submit', 'image'].includes(submitter.type)) {
                chronEntry.setCustomValidity('');
            }
        }, true);
    }
    form.addEventListener('submit', function (event) {
        if (event.submitter && event.submitter.matches('[data-add-chron-entry]') && chronEntry && !chronEntry.value.trim()) {
            event.preventDefault();
            chronEntry.setCustomValidity('Enter a Chron entry before adding it.');
            chronEntry.reportValidity();
            chronEntry.focus();
            return;
        }
        if (startDate && endDate && startDate.value && endDate.value && endDate.value < startDate.value) {
            event.preventDefault();
            endDate.setCustomValidity('End date must be on or after the start date.');
            endDate.reportValidity();
            endDate.focus();
            return;
        }
        const invalidInquiryRange = inquiryDateRanges.find(function (range) {
            range.synchronize();
            return !range.end.checkValidity();
        });
        if (invalidInquiryRange) {
            event.preventDefault();
            invalidInquiryRange.end.reportValidity();
            invalidInquiryRange.end.focus();
            return;
        }
        if (typeof window.validateEngagementPresentations === 'function' && !window.validateEngagementPresentations()) {
            event.preventDefault();
        }
    });
}

function initializeOrganizationAddress() {
    const organization = document.getElementById('organization_id');
    const option = document.getElementById('organization-address-option');
    const button = document.getElementById('use-organization-address');
    const status = document.getElementById('organization-address-status');
    const confirmation = document.getElementById('organization-address-confirmation');
    if (!organization || !option || !button || !status || !confirmation) return;

    // Set country first because its change handler switches the active state control.
    const fields = ['event_country', 'event_address_line_1', 'event_address_line_2', 'event_city', 'event_state', 'event_zipcode'];
    const controlFor = name => document.querySelector('[name="' + name + '"]:not(:disabled)');
    let controller;

    function updateOption() {
        controller?.abort();
        option.hidden = !organization.value || Boolean(organization.selectedOptions[0]?.disabled);
        button.disabled = false;
        status.textContent = '';
        if (confirmation.open) confirmation.close();
    }

    organization.addEventListener('change', updateOption);
    updateOption();

    button.addEventListener('click', function () {
        const hasLocation = fields.some(name => name !== 'event_country' && controlFor(name).value.trim() !== '');
        if (hasLocation) confirmation.showModal();
        else copyOrganizationAddress();
    });
    confirmation.querySelector('[data-cancel-organization-address]').addEventListener('click', function () {
        confirmation.close();
    });
    confirmation.querySelector('[data-confirm-organization-address]').addEventListener('click', function () {
        confirmation.close();
        copyOrganizationAddress();
    });

    async function copyOrganizationAddress() {
        const request = new AbortController();
        controller = request;
        const selectedOrganization = organization.value;
        const previousValues = fields.map(name => controlFor(name).value);
        button.disabled = true;
        status.textContent = 'Loading the organization address…';
        try {
            const parameters = new URLSearchParams({organization_id: selectedOrganization});
            const response = await fetch('organization_address.php?' + parameters, {
                credentials: 'same-origin', signal: request.signal
            });
            const result = await response.json();
            if (request.signal.aborted || organization.value !== selectedOrganization) return;
            if (response.status === 404 || response.status === 422) {
                status.textContent = result.error;
                return;
            }
            if (!response.ok) throw new Error('Address request failed');
            if (!result.address) {
                status.textContent = 'This organization has no saved physical address. Enter the event location below.';
                return;
            }
            if (fields.some((name, index) => controlFor(name).value !== previousValues[index])) {
                status.textContent = 'The event location changed while the address was loading. Select Use Organization Address again to replace it.';
                return;
            }
            fields.forEach(function (name) {
                const control = controlFor(name);
                control.value = result.address[name];
                control.dispatchEvent(new Event('input', {bubbles: true}));
                control.dispatchEvent(new Event('change', {bubbles: true}));
            });
            status.textContent = 'Organization address copied. You can edit the event location below.';
        } catch (error) {
            if (!request.signal.aborted) {
                status.textContent = 'The organization address could not be loaded. Try again or enter the event location below.';
            }
        } finally {
            if (!request.signal.aborted) button.disabled = false;
        }
    }
}
