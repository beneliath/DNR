(function () {
    function waitingFieldState(statusValue) {
        const waiting = statusValue === 'waiting';
        return {
            clearValue: !waiting,
            hidden: !waiting,
            required: waiting
        };
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { waitingFieldState };
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
        };
        status.addEventListener('change', updateWaitingField);
        updateWaitingField();
    }

    const search = document.getElementById('task-subject-search');
    const select = document.getElementById('task-subject');
    const feedback = document.getElementById('task-subject-status');
    if (!search || !select || !feedback) return;

    let timer;
    let request;
    search.addEventListener('input', function () {
        window.clearTimeout(timer);
        request?.abort();
        const query = search.value.trim();
        if (query.length < 3) {
            feedback.textContent = 'Type at least three characters to search.';
            return;
        }
        timer = window.setTimeout(async function () {
            request = new AbortController();
            feedback.textContent = 'Searching…';
            try {
                const url = new URL(search.dataset.subjectSearchUrl, window.location.href);
                url.searchParams.set('q', query);
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: request.signal
                });
                const payload = await response.json();
                if (!response.ok || !Array.isArray(payload.results)) throw new Error();
                const selectedValue = select.value;
                select.replaceChildren();
                payload.results.forEach(function (result) {
                    const option = document.createElement('option');
                    option.value = String(result.value);
                    option.textContent = result.type === 'general'
                        ? String(result.label)
                        : String(result.type).replace(/^./, function (letter) { return letter.toUpperCase(); }) + ' · ' + String(result.label);
                    option.selected = option.value === selectedValue;
                    select.appendChild(option);
                });
                feedback.textContent = (payload.results.length - 1) + ' matching records loaded.';
            } catch (error) {
                if (error.name !== 'AbortError') feedback.textContent = 'Search is temporarily unavailable.';
            }
        }, 250);
    });
})();
