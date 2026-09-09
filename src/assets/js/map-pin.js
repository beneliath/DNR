import {Map as MapLibreMap, Marker, NavigationControl, setWorkerUrl} from 'maplibre-gl';
(function () {
    const data = document.getElementById('pin-editor-data');
    const mapElement = document.getElementById('pin-editor-map');
    const latitude = document.getElementById('pin-latitude');
    const longitude = document.getElementById('pin-longitude');
    const confirmation = document.getElementById('confirm-pin');
    const feedback = document.getElementById('pin-editor-feedback');
    if (!data || !mapElement || !latitude || !longitude || !confirmation || !feedback) return;
    setWorkerUrl(DNR_MAPLIBRE_WORKER_URL);
    const payload = JSON.parse(data.textContent || '{}');
    const valid = (lat, lon) => Number.isFinite(lat) && lat >= -90 && lat <= 90 && Number.isFinite(lon) && lon >= -180 && lon <= 180;
    const hasCoordinates = valid(payload.latitude, payload.longitude);
    const map = new MapLibreMap({container: mapElement,
        style: {version: 8, sources: {base: {type: 'raster', tiles: [payload.tileUrl], tileSize: 256,
            attribution: '&copy; <a href="' + payload.attributionUrl + '">' + payload.attributionText + '</a>'}},
        layers: [{id: 'base-map', type: 'raster', source: 'base'}]},
        center: hasCoordinates ? [payload.longitude, payload.latitude] : [0, 20], zoom: hasCoordinates ? 16 : 2,
        maxZoom: payload.maximumZoom || 19});
    map.addControl(new NavigationControl({showCompass: false}));
    let marker;
    function select(lat, lon, recenter = false) {
        if (!valid(lat, lon)) return;
        if (!marker) {
            marker = new Marker({draggable: true}).setLngLat([lon, lat]).addTo(map);
            marker.on('dragend', () => { const point = marker.getLngLat(); select(point.lat, point.lng); });
        } else marker.setLngLat([lon, lat]);
        latitude.value = lat.toFixed(7);
        longitude.value = lon.toFixed(7);
        confirmation.checked = false;
        feedback.textContent = 'Pin selected. Check the venue location, then confirm and save.';
        if (recenter) map.easeTo({center: [lon, lat], zoom: Math.max(map.getZoom(), 16)});
    }
    map.on('click', event => select(event.lngLat.lat, event.lngLat.lng));
    map.on('error', () => { feedback.textContent = 'Map tiles could not load. You can still enter known coordinates below.'; });
    [latitude, longitude].forEach(input => {
        input.addEventListener('input', () => { confirmation.checked = false; });
        input.addEventListener('change', () => {
            if (latitude.value.trim() !== '' && longitude.value.trim() !== '') select(Number(latitude.value), Number(longitude.value), true);
        });
    });
    if (hasCoordinates) select(payload.latitude, payload.longitude);
})();
