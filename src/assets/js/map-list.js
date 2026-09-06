(function () {
    const rows = Array.from(document.querySelectorAll('[data-location-id]'));
    const buttons = Array.from(document.querySelectorAll('[data-location-filter]'));
    const empty = document.getElementById('map-list-empty');
    const labels = {found: 'On map', needs_address: 'Needs address', pending: 'Awaiting lookup', not_found: 'No matching location', failed: 'Lookup unavailable'};
    const help = {
        found: '', needs_address: 'Enter an event address to add a pin.',
        pending: 'The location lookup is queued or in progress.',
        not_found: 'The mapping service could not confidently match this address. Retry or set the pin yourself.',
        failed: 'The location service could not complete this lookup. Try again.'
    };
    const emptyLabels = {all: 'No engagements match this location view', found: 'No locations on this page are on the map yet', needs_address: 'No engagements on this page are missing an address', pending: 'No locations on this page are awaiting lookup', not_found: 'No unresolved locations on this page'};
    const stateMatches = (row, state) => row.dataset.locationState === state || (state === 'not_found' && row.dataset.locationState === 'failed');
    let active = 'all';
    function update() {
        let visible = 0;
        rows.forEach(function (row) {
            row.hidden = active !== 'all' && !stateMatches(row, active);
            if (!row.hidden) visible++;
        });
        buttons.forEach(function (button) {
            const key = button.dataset.locationFilter;
            button.setAttribute('aria-pressed', String(key === active));
            const count = button.querySelector('[data-location-count]');
            if (count) count.textContent = String(key === 'all' ? rows.length : rows.filter(row => stateMatches(row, key)).length);
        });
        if (empty) {
            empty.hidden = visible > 0;
            if (rows.length > 0) empty.textContent = emptyLabels[active];
        }
    }
    buttons.forEach(button => button.addEventListener('click', function () {
        active = button.dataset.locationFilter;
        update();
    }));
    document.addEventListener('map-location-updated', function (event) {
        if (!event.detail || !labels[event.detail.state]) return;
        const row = rows.find(item => Number(item.dataset.locationId) === Number(event.detail.id));
        if (!row) return;
        row.dataset.locationState = event.detail.state;
        const label = row.querySelector('[data-location-label]');
        if (label) label.textContent = labels[event.detail.state];
        const description = row.querySelector('[data-location-help]');
        if (description) description.textContent = event.detail.locationNote || help[event.detail.state];
        const retry = row.querySelector('[data-retry-location]');
        if (retry) retry.hidden = !['not_found', 'failed'].includes(event.detail.state);
        update();
    });
    update();
}());
