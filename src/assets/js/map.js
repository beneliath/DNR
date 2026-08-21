import * as L from 'leaflet';

(function () {
    const mapElement = document.getElementById('engagement-map');
    const dataElement = document.getElementById('engagement-map-data');
    const feedbackElement = document.getElementById('map-feedback');
    const fitButton = document.getElementById('fit-map-pins');
    if (!mapElement || !dataElement || !feedbackElement || !fitButton) return;

    let payload;
    try {
        payload = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        feedbackElement.textContent = 'The map data could not be loaded.';
        return;
    }

    const events = Array.isArray(payload.events) ? payload.events : [];
    const map = L.map(mapElement, {
        center: [20, 0],
        zoom: 2,
        minZoom: 2,
        zoomControl: false,
        scrollWheelZoom: true,
        dragging: true,
        touchZoom: true,
        worldCopyJump: true
    });
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    const zoomControl = L.control({position: 'topleft'});
    zoomControl.onAdd = function () {
        const container = L.DomUtil.create('div', 'map-zoom-controls leaflet-control');
        const zoomInButton = L.DomUtil.create('button', 'map-zoom-button button-secondary', container);
        const zoomOutButton = L.DomUtil.create('button', 'map-zoom-button button-secondary', container);
        zoomInButton.type = 'button';
        zoomOutButton.type = 'button';
        zoomInButton.textContent = '+';
        zoomOutButton.textContent = '\u2212';
        zoomInButton.setAttribute('aria-label', 'Zoom in');
        zoomOutButton.setAttribute('aria-label', 'Zoom out');
        zoomInButton.title = 'Zoom in';
        zoomOutButton.title = 'Zoom out';

        L.DomEvent.disableClickPropagation(container);
        L.DomEvent.disableScrollPropagation(container);
        L.DomEvent.on(zoomInButton, 'click', function () { map.zoomIn(); });
        L.DomEvent.on(zoomOutButton, 'click', function () { map.zoomOut(); });

        const updateZoomButtons = function () {
            zoomInButton.disabled = map.getZoom() >= map.getMaxZoom();
            zoomOutButton.disabled = map.getZoom() <= map.getMinZoom();
        };
        map.on('zoomend', updateZoomButtons);
        updateZoomButtons();
        return container;
    };
    zoomControl.addTo(map);

    const markers = L.featureGroup().addTo(map);
    const coordinateCounts = new Map();
    let pinCount = 0;
    const pendingCount = Number(payload.pendingGeocodeCount) || 0;
    const notFoundCount = Number(payload.notFoundCount) || 0;
    const withoutAddressCount = Number(payload.withoutAddressCount) || 0;
    const queueFailureCount = Number(payload.queueFailureCount) || 0;

    function plural(count, singular, pluralForm) {
        return count + ' ' + (count === 1 ? singular : (pluralForm || singular + 's'));
    }

    function updateFeedback() {
        const parts = [plural(pinCount, 'visible pin')];
        if (pendingCount > 0) parts.push(plural(pendingCount, 'location') + ' queued');
        if (notFoundCount > 0) parts.push(plural(notFoundCount, 'address') + ' not found');
        if (withoutAddressCount > 0) parts.push(plural(withoutAddressCount, 'event') + ' without an address');
        if (queueFailureCount > 0) parts.push(plural(queueFailureCount, 'lookup') + ' unavailable');
        feedbackElement.textContent = parts.join(' · ');
        fitButton.disabled = pinCount === 0;
    }

    function fitMapToPins() {
        const bounds = markers.getBounds();
        if (!bounds.isValid()) return;
        if (pinCount === 1) {
            map.setView(bounds.getCenter(), 11);
            return;
        }
        map.fitBounds(bounds, {padding: [42, 42], maxZoom: 12});
    }

    fitButton.addEventListener('click', function () {
        fitMapToPins();
    });

    function pinClass(status) {
        if (status === 'confirmed') return 'status-confirmed-pin';
        if (status === 'under_review') return 'status-under-review-pin';
        return 'status-work-in-progress-pin';
    }

    function offsetOverlappingCoordinates(latitude, longitude) {
        const key = latitude.toFixed(5) + ',' + longitude.toFixed(5);
        const occurrence = coordinateCounts.get(key) || 0;
        coordinateCounts.set(key, occurrence + 1);
        if (occurrence === 0) return [latitude, longitude];

        const angle = occurrence * 2.399963229728653;
        const ring = Math.ceil(occurrence / 6);
        const latitudeOffset = Math.sin(angle) * 0.00016 * ring;
        const longitudeScale = Math.max(0.25, Math.cos(latitude * Math.PI / 180));
        const longitudeOffset = Math.cos(angle) * 0.00016 * ring / longitudeScale;
        return [latitude + latitudeOffset, longitude + longitudeOffset];
    }

    function popupContent(event) {
        const content = document.createElement('div');
        content.className = 'map-popup';

        const title = document.createElement('strong');
        title.className = 'map-popup-title';
        title.textContent = event.title;
        content.appendChild(title);

        if (event.organization && event.organization !== event.title) {
            const organization = document.createElement('span');
            organization.className = 'map-popup-organization';
            organization.textContent = event.organization;
            content.appendChild(organization);
        }

        const status = document.createElement('span');
        status.className = 'map-popup-status ' + pinClass(event.status);
        status.textContent = event.statusLabel;
        content.appendChild(status);

        const date = document.createElement('span');
        date.className = 'map-popup-detail';
        date.textContent = event.dateLabel;
        content.appendChild(date);

        const address = document.createElement('span');
        address.className = 'map-popup-detail';
        address.textContent = event.address;
        content.appendChild(address);

        const link = document.createElement('a');
        link.className = 'map-popup-link';
        link.href = event.viewUrl;
        link.textContent = 'View engagement';
        content.appendChild(link);
        return content;
    }

    function addPin(event, latitude, longitude) {
        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return false;
        const coordinates = offsetOverlappingCoordinates(latitude, longitude);
        const statusClass = pinClass(event.status);
        const icon = L.divIcon({
            className: 'engagement-map-pin ' + statusClass,
            html: '<span class="engagement-map-pin-shape"><span class="engagement-map-pin-center"></span></span>',
            iconSize: [30, 38],
            iconAnchor: [15, 38],
            popupAnchor: [0, -34]
        });
        const marker = L.marker(coordinates, {
            icon: icon,
            title: event.title + ' — ' + event.statusLabel,
            alt: event.title + ' — ' + event.statusLabel,
            keyboard: true,
            riseOnHover: true
        });
        marker.bindPopup(popupContent(event), {maxWidth: 320});
        marker.addTo(markers);
        pinCount++;
        return true;
    }

    events.forEach(function (event) {
        if (event.latitude !== null && event.longitude !== null) {
            addPin(event, Number(event.latitude), Number(event.longitude));
        }
    });
    updateFeedback();
    if (pinCount > 0) fitMapToPins();
})();
