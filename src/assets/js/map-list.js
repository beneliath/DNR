(function () {
    const rows = Array.from(document.querySelectorAll('[data-location-id]'));
    const buttons = Array.from(document.querySelectorAll('[data-location-filter]'));
    const empty = document.getElementById('map-list-empty');
    const labels = {found: 'On map', needs_address: 'Needs address', pending: 'Awaiting lookup', not_found: 'Address not located'};
    let active = 'all';
    function update() {
        let visible = 0;
        rows.forEach(function (row) {
            row.hidden = active !== 'all' && row.dataset.locationState !== active;
            if (!row.hidden) visible++;
        });
        buttons.forEach(function (button) {
            const key = button.dataset.locationFilter;
            button.setAttribute('aria-pressed', String(key === active));
            const count = button.querySelector('[data-location-count]');
            if (count) count.textContent = String(key === 'all' ? rows.length : rows.filter(row => row.dataset.locationState === key).length);
        });
        if (empty) empty.hidden = visible > 0;
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
        update();
    });
    update();
}());
