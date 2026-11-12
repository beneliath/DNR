import {LngLatBounds, Map as MapLibreMap, Marker, NavigationControl, Popup, setWorkerUrl} from 'maplibre-gl';

const element = document.getElementById('interaction-location-map');
const dataElement = document.getElementById('interaction-location-map-data');
const feedback = document.getElementById('interaction-map-feedback');
const fitButton = document.getElementById('fit-interaction-pins');
const typeFilter = document.getElementById('interaction-map-type');
const emptyState = document.getElementById('interaction-map-empty');
const emptyRow = document.getElementById('interaction-location-table-empty');

if (element && dataElement && feedback && fitButton) {
    setWorkerUrl(new URL(DNR_MAPLIBRE_WORKER_URL, import.meta.url).href);
    try {
        const payload = JSON.parse(dataElement.textContent || '{}');
        const pins = Array.isArray(payload.pins) ? payload.pins : [];
        const provider = payload.mapProvider || {};
        const attribution = provider.attributionUrl
            ? `&copy; <a href="${provider.attributionUrl}">${provider.attributionText || 'Map Contributors'}</a>`
            : String(provider.attributionText || 'Map Contributors');
        const maximumZoom = Number(provider.maximumZoom) || 19;
        const style = provider.styleUrl || {
            version: 8,
            sources: {base: {
                type: 'raster', tiles: [String(provider.rasterTileUrl || '')], tileSize: 256,
                attribution, maxzoom: maximumZoom
            }},
            layers: [{id: 'base-map', type: 'raster', source: 'base'}]
        };
        const map = new MapLibreMap({
            container: element,
        locale: {'NavigationControl.ZoomIn': 'Zoom In', 'NavigationControl.ZoomOut': 'Zoom Out',
            'AttributionControl.ToggleAttribution': 'Toggle Attribution', 'Popup.Close': 'Close Popup', 'Marker.Title': 'Map Marker'}, style, center: [0, 20], zoom: 2, minZoom: 2,
            maxZoom: maximumZoom, attributionControl: {compact: true}, validateStyle: false
        });
        map.addControl(new NavigationControl({showCompass: false}), 'top-left');
        let bounds = new LngLatBounds();
        let countryCount = 0;
        const countryZoom = 3;
        const markers = new Array(pins.length);
        const table = document.querySelector('.analytics-interaction-table');
        const rows = new Map(Array.from(table?.querySelectorAll('tbody tr[data-interaction-pin]') || [], (row) => [Number(row.dataset.interactionPin), row]));
        let selectedIndex = null;
        const setSelectedIndex = (index) => {
            if (selectedIndex !== null) rows.get(selectedIndex)?.classList.remove('is-popup-open');
            selectedIndex = index;
            if (index !== null) rows.get(index)?.classList.add('is-popup-open');
        };
        const addPin = (index, pin) => {
            const longitude = Number(pin.longitude);
            const latitude = Number(pin.latitude);
            const marker = document.createElement('button');
            marker.type = 'button';
            marker.className = 'analytics-interaction-pin';
            marker.textContent = Number(pin.observations).toLocaleString();
            const countLabel = `${Number(pin.observations).toLocaleString()} ${pin.observations === 1 ? 'interaction' : 'interactions'}`;
            marker.setAttribute('aria-label', `${pin.label}: ${countLabel}`);

            const popupContent = document.createElement('div');
            popupContent.className = 'analytics-interaction-popup';
            const title = document.createElement('strong');
            title.textContent = String(pin.label || 'Country');
            popupContent.append(title);
            const count = document.createElement('p');
            count.textContent = countLabel;
            popupContent.append(count);
            for (const [kind, n] of Object.entries(pin.types || {})) {
                const detail = document.createElement('span');
                detail.textContent = `${kind}: ${Number(n).toLocaleString()}`;
                popupContent.append(detail);
            }
            const popup = new Popup({offset: 18, maxWidth: '320px'}).setDOMContent(popupContent);
            popup.on('open', () => {
                for (const otherMarker of markers) {
                    const otherPopup = otherMarker?.getPopup();
                    if (otherPopup && otherPopup !== popup && otherPopup.isOpen()) otherPopup.remove();
                }
                setSelectedIndex(index);
            });
            popup.on('close', () => {
                if (selectedIndex === index) setSelectedIndex(null);
            });
            markers[index] = new Marker({element: marker, anchor: 'bottom'})
                .setLngLat([longitude, latitude])
                .setPopup(popup)
                .addTo(map);
            bounds.extend([longitude, latitude]);
            countryCount++;
        };
        const renderPins = () => {
            for (const marker of markers) marker?.remove();
            markers.fill(null);
            setSelectedIndex(null);
            bounds = new LngLatBounds();
            countryCount = 0;
            const selectedType = typeFilter?.value || '';
            let observations = 0;
            for (const [index, pin] of pins.entries()) {
                const count = selectedType ? Number(pin.types?.[selectedType]) || 0 : Number(pin.observations);
                const types = selectedType ? {[selectedType]: count} : pin.types || {};
                const longitude = Number(pin.longitude);
                const latitude = Number(pin.latitude);
                const visible = count > 0 && Number.isFinite(longitude) && Number.isFinite(latitude)
                    && Math.abs(longitude) <= 180 && Math.abs(latitude) <= 90;
                const row = rows.get(index);
                if (row) {
                    row.hidden = !visible;
                    const observationsCell = row.querySelector('[data-interaction-observations]');
                    observationsCell.dataset.interactionObservations = String(count);
                    observationsCell.textContent = count.toLocaleString();
                    row.querySelector('[data-interaction-types]').textContent = Object.entries(types)
                        .map(([kind, n]) => `${kind} ${Number(n).toLocaleString()}`).join(' · ');
                }
                if (!visible) continue;
                observations += count;
                addPin(index, {...pin, observations: count, types});
            }
            const unmapped = Number(selectedType ? payload.unmappedTypes?.[selectedType] : payload.unmapped) || 0;
            feedback.textContent = `${countryCount.toLocaleString()} ${countryCount === 1 ? 'country' : 'countries'} · ${observations.toLocaleString()} mapped ${observations === 1 ? 'interaction' : 'interactions'}`
                + (unmapped ? ` · ${unmapped.toLocaleString()} without a mappable country` : '')
                + (selectedType ? ` · ${selectedType}` : '');
            if (emptyState) {
                emptyState.hidden = countryCount > 0;
                emptyState.textContent = selectedType
                    ? `No ${selectedType.toLowerCase()} with a mappable country were found in this date range.`
                    : 'No interactions with a mappable country were found in this date range.';
            }
            if (emptyRow) emptyRow.hidden = countryCount > 0;
            fitButton.disabled = countryCount === 0;
        };
        const fitPins = () => {
            if (!countryCount) return;
            if (countryCount === 1) {
                map.easeTo({center: bounds.getCenter(), zoom: countryZoom});
            } else {
                map.fitBounds(bounds, {padding: 64, maxZoom: 7});
            }
        };
        const selectPin = (index) => {
            const selectedMarker = markers[index];
            if (!selectedMarker) return;
            const position = selectedMarker.getLngLat();
            const popup = selectedMarker.getPopup();
            // MapLibre can skip a marker toggle while its pin is hidden by the globe.
            if (popup && !popup.isOpen()) popup.setLngLat(position).addTo(map);
            setSelectedIndex(index);
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduceMotion) map.jumpTo({center: position, zoom: countryZoom});
            else map.flyTo({center: position, zoom: countryZoom, essential: true});
            element.scrollIntoView({behavior: reduceMotion ? 'auto' : 'smooth', block: 'center'});
            selectedMarker.getElement().focus({preventScroll: true});
        };
        table?.addEventListener('click', (event) => {
            const row = event.target.closest('tr[data-interaction-pin]');
            if (!row) return;
            const index = Number(row.dataset.interactionPin);
            if (!markers[index]) return;
            event.preventDefault();
            selectPin(index);
        });
        renderPins();
        if (typeFilter) {
            typeFilter.disabled = false;
            typeFilter.addEventListener('change', () => {
                renderPins();
                fitPins();
            });
        }
        fitButton.addEventListener('click', () => {
            for (const marker of markers) {
                const popup = marker?.getPopup();
                if (popup?.isOpen()) popup.remove();
            }
            setSelectedIndex(null);
            fitPins();
        });
        map.once('load', () => {
            const control = element.querySelector('.maplibregl-ctrl-attrib');
            control?.classList.remove('maplibregl-compact-show');
            control?.removeAttribute('open');
            if (selectedIndex === null) fitPins();
        });
    } catch (error) {
        feedback.textContent = 'The interaction map could not be loaded.';
        fitButton.disabled = true;
    }
}
