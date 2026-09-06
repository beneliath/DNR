(function () {
    function waitingFieldState(statusValue) {
        const waiting = statusValue === 'waiting';
        return {
            clearValue: !waiting,
            hidden: !waiting,
            required: waiting
        };
    }

    function matchingSubjectResultCount(results) {
        return results.filter(function (result) {
            return result.type !== 'general';
        }).length;
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { waitingFieldState, matchingSubjectResultCount };
    }
    if (typeof document === 'undefined') return;

    const status = document.getElementById('task-status');
    const waitingGroup = document.getElementById('task-waiting-on-group');
    const waitingInput = document.getElementById('task-waiting-on');
    if (status && waitingGroup && waitingInput) {
        const updateWaitingField = function () {
            const state = waitingFieldState(status.value);
            waitingGroup.hidden = state.hidden;
            waitingInput.required = state.required;
            if (state.clearValue) waitingInput.value = '';
            const cancellationNote = document.getElementById('task-cancel-closeout-note');
            if (cancellationNote) cancellationNote.hidden = status.value !== 'canceled';
        };
        status.addEventListener('change', updateWaitingField);
        updateWaitingField();
    }

    const search = document.getElementById('task-subject-search');
    const select = document.getElementById('task-subject');
    const feedback = document.getElementById('task-subject-status');
    if (!search || !select || !feedback) return;

    const selectedCard = document.getElementById('task-selected-record');
    const selectedLabel = document.getElementById('task-selected-record-label');
    const searchPanel = document.getElementById('task-record-search-panel');
    const results = document.getElementById('task-subject-results');
    const changeButton = document.getElementById('task-change-record');
    const clearButton = document.getElementById('task-clear-record');
    if (!selectedCard || !selectedLabel || !searchPanel || !results || !changeButton) return;
    const generalOption = Array.from(select.options).find(function (option) { return option.value === 'general'; });
    function showSelection() {
        const option = select.selectedOptions[0];
        selectedLabel.textContent = option && option.value ? option.textContent : 'No record selected';
        selectedCard.hidden = false;
        if (clearButton) clearButton.hidden = !select.value || select.value === 'general';
    }
    function chooseRecord(result) {
        let option = Array.from(select.options).find(function (candidate) { return candidate.value === String(result.value); });
        if (!option) {
            option = document.createElement('option');
            option.value = String(result.value);
            option.textContent = String(result.label);
            select.appendChild(option);
        }
        select.value = option.value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        showSelection();
        searchPanel.hidden = true;
        feedback.textContent = 'Selected ' + option.textContent;
        changeButton.focus();
    }
    select.hidden = true;
    showSelection();
    searchPanel.hidden = Boolean(select.value && select.value !== 'general');
    changeButton.addEventListener('click', function () {
        searchPanel.hidden = !searchPanel.hidden;
        if (!searchPanel.hidden) search.focus();
    });
    if (clearButton && generalOption) clearButton.addEventListener('click', function () {
        chooseRecord({ value: generalOption.value, label: generalOption.textContent });
    });
    // Keep required validation reachable when no destination has been selected.
    select.addEventListener('invalid', function (event) {
        event.preventDefault();
        searchPanel.hidden = false;
        feedback.textContent = 'Choose a related record before saving.';
        search.focus();
    });
    let timer;
    let request;
    let requestNumber = 0;
    search.addEventListener('input', function () {
        window.clearTimeout(timer);
        request?.abort();
        const currentRequest = ++requestNumber;
        const query = search.value.trim();
        results.replaceChildren();
        if (query.length < 3) {
            feedback.textContent = 'Type at least three characters to search';
            return;
        }
        timer = window.setTimeout(async function () {
            request = new AbortController();
            feedback.textContent = 'Searching';
            try {
                const url = new URL(search.dataset.subjectSearchUrl, window.location.href);
                url.searchParams.set('q', query);
                const response = await fetch(url, {
                    credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: request.signal
                });
                const payload = await response.json();
                if (currentRequest !== requestNumber) return;
                if (!response.ok || !Array.isArray(payload.results)) throw new Error();
                payload.results.filter(function (result) { return result.type !== 'general'; }).forEach(function (result) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'task-subject-result';
                    button.textContent = String(result.type).replace(/^./, function (letter) { return letter.toUpperCase(); }) + ' · ' + String(result.label);
                    button.addEventListener('click', function () { chooseRecord(result); });
                    results.appendChild(button);
                });
                const count = matchingSubjectResultCount(payload.results);
                feedback.textContent = count ? count + ' matching records — choose one to replace the selected record' : 'No matching records — your selection is unchanged';
            } catch (error) {
                if (currentRequest === requestNumber && error.name !== 'AbortError') feedback.textContent = 'Search is unavailable — your selection is unchanged';
            }
        }, 250);
    });
})();
