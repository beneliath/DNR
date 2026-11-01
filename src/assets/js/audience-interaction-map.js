import {LngLatBounds, Map as MapLibreMap, Marker, NavigationControl, Popup, setWorkerUrl} from 'maplibre-gl';

const element = document.getElementById('interaction-location-map');
const dataElement = document.getElementById('interaction-location-map-data');
const feedback = document.getElementById('interaction-map-feedback');
const fitButton = document.getElementById('fit-interaction-pins');

if (element && dataElement && feedback && fitButton) {
    setWorkerUrl(new URL(DNR_MAPLIBRE_WORKER_URL, import.meta.url).href);
    try {
        const payload = JSON.parse(dataElement.textContent || '{}');
        const pins = Array.isArray(payload.pins) ? payload.pins : [];
        const provider = payload.mapProvider || {};
        const attribution = provider.attributionUrl
            ? `&copy; <a href="${provider.attributionUrl}">${provider.attributionText || 'Map contributors'}</a>`
            : String(provider.attributionText || 'Map contributors');
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
            container: element, style, center: [0, 20], zoom: 2, minZoom: 2,
            maxZoom: maximumZoom, attributionControl: {compact: true}, validateStyle: false
        });
        map.addControl(new NavigationControl({showCompass: false}), 'top-left');
        const bounds = new LngLatBounds();
        const markers = new Array(pins.length);
        const table = document.querySelector('.analytics-interaction-table');
        const rows = new Map(Array.from(table?.querySelectorAll('tbody tr[data-interaction-pin]') || [], (row) => [Number(row.dataset.interactionPin), row]));
        let selectedIndex = null;
        const setSelectedIndex = (index) => {
            if (selectedIndex !== null) rows.get(selectedIndex)?.classList.remove('is-popup-open');
            selectedIndex = index;
            if (index !== null) rows.get(index)?.classList.add('is-popup-open');
        };
        let visible = 0;
        for (const [index, pin] of pins.entries()) {
            const longitude = Number(pin.longitude);
            const latitude = Number(pin.latitude);
            if (!Number.isFinite(longitude) || !Number.isFinite(latitude)
                || Math.abs(longitude) > 180 || Math.abs(latitude) > 90) continue;
            const marker = document.createElement('button');
            marker.type = 'button';
            marker.className = 'analytics-interaction-pin';
            marker.textContent = Number(pin.observations).toLocaleString();
            marker.setAttribute('aria-label', `${pin.label}: ${pin.observations} interactions`);

            const popupContent = document.createElement('div');
            popupContent.className = 'analytics-interaction-popup';
            const title = document.createElement('strong');
            title.textContent = String(pin.label || pin.country || 'Location');
            popupContent.append(title);
            const count = document.createElement('p');
            count.textContent = `${Number(pin.observations).toLocaleString()} interactions`;
            popupContent.append(count);
            for (const [kind, n] of Object.entries(pin.types || {})) {
                const detail = document.createElement('span');
                detail.textContent = `${kind}: ${Number(n).toLocaleString()}`;
                popupContent.append(detail);
            }
            if (Array.isArray(pin.reported_places) && pin.reported_places.length) {
                const reported = document.createElement('small');
                const first = pin.reported_places.slice(0, 3).join(', ');
                reported.textContent = `Reported places: ${first}${pin.reported_places.length > 3 ? ' and more' : ''}`;
                popupContent.append(reported);
            }
            const accuracy = document.createElement('small');
            accuracy.textContent = pin.precision === 'city' ? 'Approximate city position' : 'Approximate country position';
            popupContent.append(accuracy);
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
            visible++;
        }
        const fitPins = () => {
            if (!visible) return;
            if (visible === 1) {
                map.easeTo({center: bounds.getCenter(), zoom: pins[0].precision === 'city' ? 7 : 3});
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
            const zoom = pins[index].precision === 'city' ? 7 : 3;
            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (reduceMotion) map.jumpTo({center: position, zoom});
            else map.flyTo({center: position, zoom, essential: true});
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
        fitButton.disabled = visible === 0;
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
