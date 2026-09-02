import {
    LngLatBounds,
    Map as MapLibreMap,
    Marker,
    NavigationControl,
    Popup
} from 'maplibre-gl';

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
    const mapProvider = payload.mapProvider && typeof payload.mapProvider === 'object'
        ? payload.mapProvider
        : {};
    const locationLookup = payload.locationLookup && typeof payload.locationLookup === 'object'
        ? payload.locationLookup
        : {};
    mapElement.dataset.mapProvider = String(mapProvider.type || 'openstreetmap');
    const attributionText = String(mapProvider.attributionText || 'Map contributors');
    const attributionUrl = String(mapProvider.attributionUrl || '');
    const maximumZoom = Number(mapProvider.maximumZoom) || 19;
    const rasterTileUrl = String(mapProvider.rasterTileUrl || '');
    const rasterAttribution = attributionUrl
        ? '&copy; <a href="' + attributionUrl + '">' + attributionText + '</a>'
        : attributionText;
    const style = {
        version: 8,
        sources: {
            base: {
                type: 'raster',
                tiles: [rasterTileUrl],
                tileSize: 256,
                attribution: rasterAttribution,
                maxzoom: maximumZoom
            }
        },
        layers: [{id: 'base-map', type: 'raster', source: 'base'}]
    };
    const map = new MapLibreMap({
        container: mapElement,
        style: style,
        center: [0, 20],
        zoom: 2,
        minZoom: 2,
        maxZoom: maximumZoom,
        attributionControl: true,
        validateStyle: false
    });
    map.addControl(new NavigationControl({showCompass: false}), 'top-left');

    const markers = [];
    const coordinateCounts = new globalThis.Map();
    const pendingEvents = new globalThis.Map();
    let pinCount = 0;
    let pendingCount = Number(payload.pendingGeocodeCount) || 0;
    let notFoundCount = Number(payload.notFoundCount) || 0;
    const withoutAddressCount = Number(payload.withoutAddressCount) || 0;
    const resultsTruncated = payload.resultsTruncated === true;
    let providerError = '';

    function plural(count, singular, pluralForm) {
        return count + ' ' + (count === 1 ? singular : (pluralForm || singular + 's'));
    }

    function updateFeedback() {
        const parts = [plural(pinCount, 'visible pin')];
        if (pendingCount > 0) parts.push(plural(pendingCount, 'location') + ' awaiting lookup');
        if (notFoundCount > 0) parts.push(plural(notFoundCount, 'address') + ' not found');
        if (withoutAddressCount > 0) parts.push(plural(withoutAddressCount, 'event') + ' without an address');
        if (resultsTruncated) parts.push('more matching events are outside the display limit');
        if (providerError) parts.push(providerError);
        feedbackElement.textContent = parts.join(' · ');
        fitButton.disabled = pinCount === 0;
    }

    function markerBounds() {
        const bounds = new LngLatBounds();
        markers.forEach(function (marker) {
            bounds.extend(marker.getLngLat());
        });
        return bounds;
    }

    function fitMapToPins() {
        if (pinCount === 0) return;
        const bounds = markerBounds();
        if (pinCount === 1) {
            map.easeTo({center: bounds.getCenter(), zoom: 11});
            return;
        }
        map.fitBounds(bounds, {padding: 42, maxZoom: 12});
    }

    fitButton.addEventListener('click', fitMapToPins);

    function pinClass(status) {
        if (status === 'confirmed') return 'status-confirmed-pin';
        if (status === 'under_review') return 'status-under-review-pin';
        return 'status-work-in-progress-pin';
    }

    function offsetOverlappingCoordinates(latitude, longitude) {
        const key = latitude.toFixed(5) + ',' + longitude.toFixed(5);
        const occurrence = coordinateCounts.get(key) || 0;
        coordinateCounts.set(key, occurrence + 1);
        if (occurrence === 0) return [longitude, latitude];

        const angle = occurrence * 2.399963229728653;
        const ring = Math.ceil(occurrence / 6);
        const latitudeOffset = Math.sin(angle) * 0.00016 * ring;
        const longitudeScale = Math.max(0.25, Math.cos(latitude * Math.PI / 180));
        const longitudeOffset = Math.cos(angle) * 0.00016 * ring / longitudeScale;
        return [longitude + longitudeOffset, latitude + latitudeOffset];
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

        if (event.lifecycleLabel && event.lifecycle !== 'active') {
            const lifecycle = document.createElement('span');
            lifecycle.className = 'map-popup-detail';
            lifecycle.textContent = 'Lifecycle: ' + event.lifecycleLabel;
            content.appendChild(lifecycle);
        }

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
        const markerElement = document.createElement('button');
        markerElement.type = 'button';
        markerElement.className = 'engagement-map-pin ' + statusClass;
        markerElement.setAttribute('aria-label', event.title + ' — ' + event.statusLabel);
        markerElement.title = event.title + ' — ' + event.statusLabel;
        markerElement.innerHTML = '<span class="engagement-map-pin-shape"><span class="engagement-map-pin-center"></span></span>';
        const popup = new Popup({offset: 30, maxWidth: '320px'}).setDOMContent(popupContent(event));
        const marker = new Marker({element: markerElement, anchor: 'bottom'})
            .setLngLat(coordinates)
            .setPopup(popup)
            .addTo(map);
        markers.push(marker);
        pinCount++;
        return true;
    }

    events.forEach(function (event) {
        if (event.latitude !== null && event.longitude !== null) {
            addPin(event, Number(event.latitude), Number(event.longitude));
        } else if (Number(event.id) > 0 && event.address) {
            pendingEvents.set(Number(event.id), event);
        }
    });
    updateFeedback();
    map.once('load', function () {
        if (pinCount > 0) fitMapToPins();
    });
    map.on('error', function () {
        providerError = 'map provider unavailable';
        updateFeedback();
    });

    const lookupUrl = String(locationLookup.url || '');
    const csrfToken = String(locationLookup.csrfToken || '');
    const pollInterval = Math.max(750, Number(locationLookup.pollIntervalMilliseconds) || 1500);
    const maximumPolls = Math.max(1, Number(locationLookup.maximumPolls) || 40);
    let pollCount = 0;
    let polling = false;

    async function pollPendingLocations() {
        if (polling || pendingEvents.size === 0 || pollCount >= maximumPolls) return;
        if (document.visibilityState === 'hidden') {
            window.setTimeout(pollPendingLocations, pollInterval);
            return;
        }
        polling = true;
        pollCount++;
        const lookups = Array.from(pendingEvents.entries()).map(async function ([eventId, event]) {
            try {
                const response = await fetch(lookupUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: new URLSearchParams({
                        csrf_token: csrfToken,
                        engagement_id: String(eventId)
                    })
                });
                if (!response.ok && response.status !== 202) return;
                const result = await response.json();
                if (result.status === 'found'
                    && addPin(event, Number(result.latitude), Number(result.longitude))
                ) {
                    pendingEvents.delete(eventId);
                    pendingCount = Math.max(0, pendingCount - 1);
                    fitMapToPins();
                } else if (result.status === 'not_found' || result.status === 'no_address') {
                    pendingEvents.delete(eventId);
                    pendingCount = Math.max(0, pendingCount - 1);
                    notFoundCount++;
                }
            } catch (error) {
                // Keep the location pending. The worker and the next poll can recover.
            }
        });
        await Promise.all(lookups);
        polling = false;
        updateFeedback();
        if (pendingEvents.size > 0 && pollCount < maximumPolls) {
            window.setTimeout(pollPendingLocations, pollInterval);
        }
    }

    if (lookupUrl && csrfToken && pendingEvents.size > 0) {
        window.setTimeout(pollPendingLocations, 250);
    }
})();
