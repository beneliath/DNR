(function () {
    const windowFields = document.querySelector('[data-engagement-date-window]');
    if (!windowFields) return;

    const dates = Array.from(windowFields.querySelectorAll('input[type="date"]'));
    const apply = windowFields.querySelector('[data-engagement-date-apply]');
    if (!apply || dates.length !== 2) return;

    const appliedValues = dates.map(function (date) { return date.value; });
    function updateApplyReminder() {
        apply.classList.toggle('engagement-apply-reminder', dates.some(function (date, index) {
            return date.value !== appliedValues[index];
        }));
    }

    windowFields.addEventListener('input', updateApplyReminder);
    windowFields.addEventListener('change', updateApplyReminder);
    window.addEventListener('pageshow', updateApplyReminder);
})();
