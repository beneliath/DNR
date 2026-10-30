import {setConditionalField} from './form-fields.mjs';

export function initializeEngagementForm() {
    const form = document.querySelector('.engagement-form, #engagement-edit-form, .inquiry-form, [data-chron-form]');
    if (!form) return;
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
