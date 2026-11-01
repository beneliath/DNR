'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('a location row opens its globe-hidden pin on the first click', () => {
    class Element {
        constructor() {
            this.listeners = {}; this.dataset = {}; this.children = []; this.textContent = '';
            const classes = new Set();
            this.classList = {add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name)};
        }
        addEventListener(type, callback) { this.listeners[type] = callback; }
        append(child) { this.children.push(child); }
        setAttribute(name, value) { this[name] = value; }
        querySelector() { return null; }
        querySelectorAll() { return this.rows || []; }
        scrollIntoView(options) { this.scrolled = options; }
        focus() { this.focused = true; }
    }
    const elements = Object.fromEntries(['interaction-location-map', 'interaction-location-map-data', 'interaction-map-feedback', 'fit-interaction-pins'].map(id => [id, new Element()]));
    const table = new Element();
    const pins = [
        {label: 'Chicago', latitude: 41.9, longitude: -87.6, observations: 2, precision: 'city', types: {Signups: 2}},
        {label: 'Sydney', latitude: -33.9, longitude: 151.2, observations: 1, precision: 'city', types: {Clicks: 1}}
    ];
    table.rows = pins.map((_, index) => Object.assign(new Element(), {dataset: {interactionPin: String(index)}}));
    elements['interaction-location-map-data'].textContent = JSON.stringify({pins, mapProvider: {rasterTileUrl: 'https://example.test/{z}/{x}/{y}.png'}});
    const markers = [], popups = [], flights = [];
    let onLoad;
    let fitCount = 0;
    class FakeMap {
        addControl() {}
        once(type, callback) { if (type === 'load') onLoad = callback; }
        flyTo(options) { flights.push(options); }
        jumpTo(options) { flights.push(options); }
        fitBounds() { fitCount++; }
        easeTo() { fitCount++; }
    }
    class Marker {
        constructor({element}) { this.element = element; markers.push(this); }
        setLngLat(point) { this.point = point; return this; }
        setPopup(popup) { this.popup = popup; return this; }
        addTo() { return this; }
        getLngLat() { return this.point; }
        getPopup() { return this.popup; }
        getElement() { return this.element; }
        togglePopup() { if (!this.element.covered) this.popup.addTo(); return this; }
    }
    class Popup {
        constructor() { this.open = false; this.listeners = {}; popups.push(this); }
        on(type, callback) { this.listeners[type] = callback; return this; }
        setDOMContent(content) { this.content = content; return this; }
        setLngLat(point) { this.point = point; return this; }
        addTo() { if (!this.open) { this.open = true; this.listeners.open?.(); } return this; }
        isOpen() { return this.open; }
        remove() { if (this.open) { this.open = false; this.listeners.close?.(); } }
    }
    class Bounds { extend() {} getCenter() { return [0, 0]; } }
    const context = {
        document: {
            getElementById: id => elements[id],
            querySelector: selector => selector === '.analytics-interaction-table' ? table : null,
            createElement: () => new Element()
        },
        window: {matchMedia: () => ({matches: false})},
        LngLatBounds: Bounds, MapLibreMap: FakeMap, Marker, NavigationControl: class {}, Popup,
        setWorkerUrl() {}, DNR_MAPLIBRE_WORKER_URL: './maplibre-worker.min.js',
        URL, moduleUrl: 'http://localhost:8080/assets/js/audience-interaction-map.min.js'
    };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync('src/assets/js/audience-interaction-map.js', 'utf8')
        .replace(/^import .*?from 'maplibre-gl';/, '')
        .replaceAll('import.meta.url', 'moduleUrl'), context);

    popups[0].addTo();
    assert.equal(table.rows[0].classList.contains('is-popup-open'), true, 'Opening a pin highlights its row');
    popups[1].addTo();
    assert.equal(popups[0].isOpen(), false, 'Opening another pin closes the previous popup');
    assert.equal(table.rows[0].classList.contains('is-popup-open'), false);
    assert.equal(table.rows[1].classList.contains('is-popup-open'), true);
    popups[1].remove();
    assert.equal(table.rows[1].classList.contains('is-popup-open'), false, 'Closing the popup clears the row highlight');

    markers[1].element.covered = true;
    let prevented = false;
    const selectedRow = {dataset: {interactionPin: '1'}};
    table.listeners.click({target: {closest: () => selectedRow}, preventDefault: () => { prevented = true; }});
    assert.equal(prevented, true);
    assert.equal(popups[1].isOpen(), true, 'The popup opens before a second click or map load');
    assert.equal(table.rows[1].classList.contains('is-popup-open'), true);
    assert.deepEqual(Array.from(flights[0].center), [151.2, -33.9]);
    assert.equal(markers[1].element.focused, true);
    assert.equal(elements['interaction-location-map'].scrolled.block, 'center');
    onLoad();
    assert.equal(fitCount, 0, 'Initial map fitting preserves the selected location');

    table.listeners.click({target: {closest: () => ({dataset: {interactionPin: '0'}})}, preventDefault() {}});
    assert.equal(popups[0].isOpen(), true);
    assert.equal(popups[1].isOpen(), false);
    assert.equal(table.rows[0].classList.contains('is-popup-open'), true);
    assert.equal(table.rows[1].classList.contains('is-popup-open'), false);

    popups[1].addTo();
    elements['fit-interaction-pins'].listeners.click();
    assert.equal(popups[0].isOpen(), false, 'Fit pins closes the selected card');
    assert.equal(popups[1].isOpen(), false, 'Fit pins closes any other open card');
    assert.equal(table.rows.some(row => row.classList.contains('is-popup-open')), false, 'Fit pins clears the row highlight');
    assert.equal(fitCount, 1, 'Fit pins still fits the visible locations');
});
