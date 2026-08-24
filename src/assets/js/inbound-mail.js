(function () {
    function mergeEngagementOptions(results, current) {
        const options = new Map();
        if (current && Number(current.id) > 0) {
            options.set(Number(current.id), {
                id: Number(current.id),
                label: String(current.label || ''),
            });
        }
        (Array.isArray(results) ? results : []).forEach(function (result) {
            const id = Number(result && result.id);
            if (!Number.isInteger(id) || id < 1) return;
            options.set(id, {
                id,
                label: String(result.marker || '[MOED#' + id + ']')
                    + ' · ' + String(result.label || ''),
            });
        });
        return Array.from(options.values());
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { mergeEngagementOptions };
    }
    if (typeof document === 'undefined') return;

    const search = document.getElementById('inbound-engagement-search');
    const select = document.getElementById('inbound-engagement-id');
    const status = document.getElementById('inbound-engagement-search-status');
    if (!search || !select || !status) return;

    let timer;
    let request;

    function currentSelection() {
        const option = select.selectedOptions[0];
        if (!option || Number(option.value) < 1) return null;
        return { id: Number(option.value), label: option.textContent || '' };
    }

    function renderOptions(results, current) {
        const fragment = document.createDocumentFragment();
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = 'No engagement selected';
        fragment.appendChild(empty);

        mergeEngagementOptions(results, current).forEach(function (engagement) {
            const option = document.createElement('option');
            option.value = String(engagement.id);
            option.textContent = engagement.label;
            option.selected = current !== null && engagement.id === Number(current.id);
            fragment.appendChild(option);
        });
        select.replaceChildren(fragment);
    }

    search.addEventListener('input', function () {
        window.clearTimeout(timer);
        request?.abort();
        const query = search.value.trim();
        if (query.length < 2 && !/^\d+$/.test(query)) {
            status.textContent = 'Type at least two characters, an engagement ID, or a subject marker.';
            return;
        }

        timer = window.setTimeout(async function () {
            const controller = new AbortController();
            request = controller;
            const selected = currentSelection();
            status.textContent = 'Searching engagements…';
            select.setAttribute('aria-busy', 'true');
            try {
                const url = new URL(search.dataset.engagementSearchUrl, window.location.href);
                url.searchParams.set('q', query);
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                const payload = await response.json();
                if (!response.ok || !Array.isArray(payload.engagements)) throw new Error();
                renderOptions(payload.engagements, selected);
                status.textContent = payload.engagements.length === 0
                    ? 'No matching active engagements.'
                    : payload.engagements.length + ' matching engagement'
                        + (payload.engagements.length === 1 ? ' loaded.' : 's loaded.');
            } catch (error) {
                if (error.name !== 'AbortError') {
                    status.textContent = 'Engagement search is temporarily unavailable.';
                }
            } finally {
                if (request === controller) {
                    select.removeAttribute('aria-busy');
                }
            }
        }, 250);
    });
})();
