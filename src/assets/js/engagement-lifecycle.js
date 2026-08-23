(function () {
    function lifecycleFieldState(status) {
        return {
            cancellationVisible: status === 'canceled',
            rescheduleVisible: status === 'postponed' || status === 'canceled'
        };
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { lifecycleFieldState };
    }
    if (typeof document === 'undefined') return;

    const section = document.querySelector('[data-engagement-lifecycle]');
    const lifecycle = document.getElementById('lifecycle_status');
    const organization = document.getElementById('organization_id');
    const cancellationFields = section?.querySelector('[data-cancellation-fields]');
    const cancellationReason = document.getElementById('cancellation_reason');
    const rescheduleFields = section?.querySelector('[data-reschedule-fields]');
    const rescheduleSelect = document.getElementById('rescheduled_to_engagement_id');
    const rescheduleStatus = section?.querySelector('[data-reschedule-status]');
    const badge = section?.querySelector('[data-lifecycle-badge]');
    if (!section || !lifecycle || !organization || !cancellationFields
        || !cancellationReason || !rescheduleFields || !rescheduleSelect
        || !rescheduleStatus || !badge) return;

    const labels = {
        active: 'Active',
        postponed: 'Postponed',
        canceled: 'Canceled',
        completed: 'Completed'
    };
    let request;

    function synchronizeFields() {
        const status = lifecycle.value;
        const state = lifecycleFieldState(status);
        cancellationFields.hidden = !state.cancellationVisible;
        cancellationReason.disabled = !state.cancellationVisible;
        cancellationReason.required = state.cancellationVisible;
        rescheduleFields.hidden = !state.rescheduleVisible;
        rescheduleSelect.disabled = !state.rescheduleVisible;
        badge.className = 'lifecycle-badge lifecycle-' + status;
        badge.textContent = labels[status] || 'Lifecycle not set';
    }

    function replaceOptions(engagements) {
        const fragment = document.createDocumentFragment();
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'No replacement event linked';
        fragment.appendChild(empty);
        engagements.forEach(function (engagement) {
            const option = document.createElement('option');
            option.value = String(engagement.id);
            option.textContent = String(engagement.label)
                + (engagement.lifecycle ? ' · ' + String(engagement.lifecycle) : '');
            fragment.appendChild(option);
        });
        rescheduleSelect.replaceChildren(fragment);
    }

    async function loadRescheduleOptions() {
        request?.abort();
        if (!organization.value) {
            replaceOptions([]);
            rescheduleStatus.textContent = 'Select an organization to load replacement events.';
            return;
        }
        request = new AbortController();
        rescheduleStatus.textContent = 'Loading replacement events…';
        try {
            const url = new URL(section.dataset.rescheduleOptionsUrl, window.location.href);
            url.searchParams.set('organization_id', organization.value);
            if (Number(section.dataset.engagementId) > 0) {
                url.searchParams.set('exclude_id', section.dataset.engagementId);
            }
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: request.signal
            });
            const payload = await response.json();
            if (!response.ok || !Array.isArray(payload.engagements)) throw new Error();
            replaceOptions(payload.engagements);
            rescheduleStatus.textContent = payload.engagements.length === 0
                ? 'No eligible replacement events are available for this organization.'
                : payload.engagements.length + ' replacement event'
                    + (payload.engagements.length === 1 ? ' available.' : 's available.');
        } catch (error) {
            if (error.name !== 'AbortError') {
                replaceOptions([]);
                rescheduleStatus.textContent = 'Replacement events could not be loaded.';
            }
        }
    }

    lifecycle.addEventListener('change', synchronizeFields);
    organization.addEventListener('change', loadRescheduleOptions);
    synchronizeFields();
})();
