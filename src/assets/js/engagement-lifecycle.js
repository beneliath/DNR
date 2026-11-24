(function () {
    function lifecycleFieldState(status) {
        return {
            confirmationVisible: status !== 'completed',
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
    const confirmationField = section?.querySelector('[data-confirmation-field]');
    const organization = document.getElementById('organization_id');
    const cancellationFields = section?.querySelector('[data-cancellation-fields]');
    const cancellationReason = document.getElementById('cancellation_reason');
    const rescheduleFields = section?.querySelector('[data-reschedule-fields]');
    const rescheduleSelect = document.getElementById('rescheduled_to_engagement_id');
    const rescheduleStatus = section?.querySelector('[data-reschedule-status]');
    const badge = section?.querySelector('[data-lifecycle-badge]');
    if (!section || !lifecycle || !confirmationField || !organization || !cancellationFields
        || !cancellationReason || !rescheduleFields || !rescheduleSelect
        || !rescheduleStatus) return;

    let request;
    let pendingRescheduleLoad;
    let loadedOrganization = organization.value;

    function synchronizeFields() {
        const status = lifecycle.value;
        const state = lifecycleFieldState(status);
        confirmationField.hidden = !state.confirmationVisible;
        cancellationFields.hidden = !state.cancellationVisible;
        cancellationReason.disabled = !state.cancellationVisible;
        cancellationReason.required = state.cancellationVisible;
        rescheduleFields.hidden = !state.rescheduleVisible;
        rescheduleSelect.disabled = !state.rescheduleVisible;
        if (badge) {
            badge.className = 'lifecycle-badge lifecycle-' + status;
            badge.textContent = lifecycle.selectedOptions[0]?.textContent.trim() || 'Lifecycle Not Set';
        }
    }

    function replaceOptions(engagements) {
        const fragment = document.createDocumentFragment();
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'No Replacement Event Linked';
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
        loadedOrganization = null;
        if (!organization.value) {
            replaceOptions([]);
            loadedOrganization = '';
            rescheduleStatus.textContent = 'Select an organization to load replacement events.';
            return;
        }
        const currentRequest = new AbortController();
        request = currentRequest;
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
                signal: currentRequest.signal
            });
            const payload = await response.json();
            if (currentRequest.signal.aborted) return;
            if (!response.ok || !Array.isArray(payload.engagements)) throw new Error();
            replaceOptions(payload.engagements);
            loadedOrganization = organization.value;
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
    organization.dataset.draftDependency = '';
    lifecycle.dataset.draftDependency = '';
    organization.addEventListener('change', function () {
        if (organization.value === loadedOrganization && !pendingRescheduleLoad) return;
        const loading = loadRescheduleOptions();
        pendingRescheduleLoad = loading;
        loading.finally(function () { if (pendingRescheduleLoad === loading) pendingRescheduleLoad = null; });
    });
    section.closest('form')?.addEventListener('form-draft-ready', function (event) {
        if (pendingRescheduleLoad) event.detail.waitUntil(pendingRescheduleLoad);
    });
    synchronizeFields();
})();
