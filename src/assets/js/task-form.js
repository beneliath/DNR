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
    const searchDialog = document.getElementById('task-record-search-dialog');
    const cancelSearch = document.getElementById('task-record-search-cancel');
    const results = document.getElementById('task-subject-results');
    const changeButton = document.getElementById('task-change-record');
    const clearButton = document.getElementById('task-clear-record');
    if (!selectedCard || !selectedLabel || !searchDialog || !cancelSearch || !results || !changeButton) return;
    const generalOption = Array.from(select.options).find(function (option) { return option.value === 'general'; });
    function showSelection() {
        const option = select.selectedOptions[0];
        selectedLabel.textContent = option && option.value ? option.textContent : 'No Record Selected';
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
        feedback.textContent = 'Selected ' + option.textContent;
        if (searchDialog.open) searchDialog.close();
        changeButton.focus();
    }
    select.dataset.errorTarget = changeButton.id;
    select.hidden = true;
    showSelection();
    const initialFeedback = feedback.textContent;
    function openSearch() {
        if (!searchDialog.open) {
            search.value = '';
            results.replaceChildren();
            feedback.textContent = initialFeedback;
            searchDialog.showModal();
        }
        search.focus();
    }
    changeButton.addEventListener('click', openSearch);
    cancelSearch.addEventListener('click', function () { searchDialog.close(); });
    searchDialog.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !event.isComposing) {
            event.preventDefault();
            searchDialog.close();
        }
    });
    searchDialog.addEventListener('click', function (event) {
        if (event.target !== searchDialog) return;
        const bounds = searchDialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right
            || event.clientY < bounds.top || event.clientY > bounds.bottom) searchDialog.close();
    });
    searchDialog.addEventListener('close', function () {
        window.clearTimeout(timer);
        request?.abort();
        requestNumber++;
        changeButton.focus();
    });
    if (clearButton && generalOption) clearButton.addEventListener('click', function () {
        chooseRecord({ value: generalOption.value, label: generalOption.textContent });
    });
    // Keep required validation reachable when no destination has been selected.
    select.addEventListener('invalid', function (event) {
        event.preventDefault();
        openSearch();
        feedback.textContent = 'Choose a related record before saving.';
    });
    let timer;
    let request;
    let requestNumber = 0;
    function sizeResults() {
        const rows = results.children;
        results.style.maxHeight = rows.length > 10
            ? (rows[9].offsetTop + rows[9].offsetHeight - rows[0].offsetTop + 6) + 'px'
            : '';
    }
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(sizeResults).observe(results);
    }
    function searchRecords(delay) {
        window.clearTimeout(timer);
        request?.abort();
        const currentRequest = ++requestNumber;
        const query = search.value.trim();
        results.replaceChildren();
        results.scrollTop = 0;
        sizeResults();
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
                sizeResults();
                const count = matchingSubjectResultCount(payload.results);
                feedback.textContent = count ? count + ' matching records — choose one to replace the selected record' : 'No matching records — your selection is unchanged';
            } catch (error) {
                if (currentRequest === requestNumber && error.name !== 'AbortError') feedback.textContent = 'Search is unavailable — your selection is unchanged';
            }
        }, delay);
    }
    search.addEventListener('input', function () { searchRecords(250); });
    search.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || event.isComposing) return;
        // Searching for a replacement must not save the task with its old record.
        event.preventDefault();
        searchRecords(0);
    });
    search.disabled = false;
})();
