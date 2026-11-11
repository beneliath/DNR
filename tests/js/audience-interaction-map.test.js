'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function mapHarness(pins = [
    {label: 'Chicago', latitude: 41.9, longitude: -87.6, observations: 2, precision: 'city', types: {Signups: 2}},
    {label: 'Sydney', latitude: -33.9, longitude: 151.2, observations: 1, precision: 'city', types: {'Email Clicks': 1}}
], extraPayload = {}) {
    class Element {
        constructor() {
            this.listeners = {}; this.dataset = {}; this.children = []; this.textContent = ''; this.hidden = false;
            const classes = new Set();
            this.classList = {add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name)};
        }
        addEventListener(type, callback) { this.listeners[type] = callback; }
        append(child) { this.children.push(child); }
        setAttribute(name, value) { this[name] = value; }
        querySelector(selector) { return this.cells?.[selector] || null; }
        querySelectorAll() { return this.rows || []; }
        scrollIntoView(options) { this.scrolled = options; }
        focus() { this.focused = true; }
    }
    const elements = Object.fromEntries(['interaction-location-map', 'interaction-location-map-data', 'interaction-map-feedback', 'fit-interaction-pins', 'interaction-map-type', 'interaction-map-empty', 'interaction-location-table-empty'].map(id => [id, new Element()]));
    const table = new Element();
    table.rows = pins.map((_, index) => Object.assign(new Element(), {
        dataset: {interactionPin: String(index)},
        cells: {'[data-interaction-observations]': new Element(), '[data-interaction-types]': new Element()}
    }));
    elements['interaction-location-map-data'].textContent = JSON.stringify({pins, mapProvider: {rasterTileUrl: 'https://example.test/{z}/{x}/{y}.png'}, ...extraPayload});
    const markers = [], popups = [], flights = [], fits = [];
    let onLoad;
    let fitCount = 0;
    class FakeMap {
        addControl() {}
        once(type, callback) { if (type === 'load') onLoad = callback; }
        flyTo(options) { flights.push(options); }
        jumpTo(options) { flights.push(options); }
        fitBounds(bounds, options) { fits.push({points: bounds.points, ...options}); fitCount++; }
        easeTo(options) { fits.push(options); fitCount++; }
    }
    class Marker {
        constructor({element}) { this.element = element; markers.push(this); }
        setLngLat(point) { this.point = point; return this; }
        setPopup(popup) { this.popup = popup; return this; }
        addTo() { this.onMap = true; return this; }
        remove() { this.onMap = false; this.popup.remove(); return this; }
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
    class Bounds {
        constructor() { this.points = []; }
        extend(point) { this.points.push(point); }
        getCenter() { return this.points[0]; }
    }
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
    return {
        elements, table, markers, popups, flights, fits,
        onLoad: () => onLoad(),
        get fitCount() { return fitCount; },
        filter(value) { elements['interaction-map-type'].value = value; elements['interaction-map-type'].listeners.change(); },
        visibleMarkers: () => markers.filter(marker => marker.onMap)
    };
}

test('a location row opens its globe-hidden pin on the first click', () => {
    const harness = mapHarness();
    const {elements, table, markers, popups, flights, onLoad} = harness;

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
    assert.equal(harness.fitCount, 0, 'Initial map fitting preserves the selected location');

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
    assert.equal(harness.fitCount, 1, 'Fit pins still fits the visible locations');
});

const mixedPins = [
    {label: 'Chicago', latitude: 41.9, longitude: -87.6, observations: 5, precision: 'city', types: {'Email Opens': 3, 'Email Clicks': 1, Signups: 1}},
    {label: 'Sydney', latitude: -33.9, longitude: 151.2, observations: 2, precision: 'city', types: {Signups: 2}},
    {label: 'Canada', latitude: 60.5, longitude: -101.9, observations: 4, precision: 'country', types: {Unsubscribes: 4}}
];

test('filtering recounts mixed pins, popup details, rows, and unmapped interactions, and resets fully', () => {
    const harness = mapHarness(mixedPins, {unmapped: 3, unmappedTypes: {'Email Opens': 2, 'Preference Updates': 1}});
    const {elements, table, markers, popups} = harness;
    assert.equal(elements['interaction-map-type'].disabled, false);
    popups[1].addTo();
    harness.filter('Email Opens');
    assert.equal(popups[1].isOpen(), false, 'Changing type closes the old popup');
    assert.equal(table.rows.some(row => row.classList.contains('is-popup-open')), false);
    assert.equal(markers.slice(0, 3).some(marker => marker.onMap), false, 'Previous markers are removed');
    const [marker] = harness.visibleMarkers();
    assert.equal(harness.visibleMarkers().length, 1);
    assert.equal(marker.element.textContent, '3');
    assert.equal(marker.element['aria-label'], 'Chicago: 3 interactions');
    assert.deepEqual(marker.popup.content.children.map(child => child.textContent), ['Chicago', '3 interactions', 'Email Opens: 3', 'Approximate City Position']);
    assert.deepEqual(table.rows.map(row => row.hidden), [false, true, true]);
    assert.equal(table.rows[0].cells['[data-interaction-observations]'].textContent, '3');
    assert.equal(table.rows[0].cells['[data-interaction-observations]'].dataset.interactionObservations, '3', 'Sorting uses the filtered numeric count');
    assert.equal(table.rows[0].cells['[data-interaction-types]'].textContent, 'Email Opens 3');
    assert.equal(elements['interaction-map-feedback'].textContent, '1 location · 3 interactions with a mapped location · 2 without a mappable country · Email Opens');
    assert.equal(elements['interaction-map-empty'].hidden, true);
    assert.equal(elements['interaction-location-table-empty'].hidden, true);
    assert.equal(elements['fit-interaction-pins'].disabled, false);

    harness.filter('');
    assert.equal(harness.visibleMarkers().length, 3);
    assert.deepEqual(harness.visibleMarkers().map(pin => pin.element.textContent), ['5', '2', '4']);
    assert.equal(table.rows.every(row => !row.hidden), true);
    assert.equal(table.rows[0].cells['[data-interaction-types]'].textContent, 'Email Opens 3 · Email Clicks 1 · Signups 1');
    assert.equal(elements['interaction-map-feedback'].textContent, '3 locations · 11 interactions with a mapped location · 3 without a mappable country');
});

test('Fit pins and table selection use only matching locations and their correct precision', () => {
    const harness = mapHarness(mixedPins);
    harness.filter('Signups');
    assert.deepEqual(Array.from(harness.fits.at(-1).points, point => Array.from(point)), [[-87.6, 41.9], [151.2, -33.9]]);
    assert.equal(harness.elements['interaction-map-feedback'].textContent, '2 locations · 3 interactions with a mapped location · Signups');
    harness.filter('Unsubscribes');
    assert.deepEqual(Array.from(harness.fits.at(-1).center), [-101.9, 60.5]);
    assert.equal(harness.fits.at(-1).zoom, 3, 'A country pin uses country zoom even when the first unfiltered pin is a city');
    harness.elements['fit-interaction-pins'].listeners.click();
    assert.equal(harness.fits.at(-1).zoom, 3);
    harness.table.listeners.click({target: {closest: () => harness.table.rows[2]}, preventDefault() {}});
    assert.equal(harness.visibleMarkers()[0].popup.isOpen(), true, 'A filtered row keeps its original pin index');
    assert.equal(harness.table.rows[2].classList.contains('is-popup-open'), true);
});

test('a type with no mapped results shows an empty state and recovers when reset', () => {
    const harness = mapHarness(mixedPins, {unmapped: 1, unmappedTypes: {'Preference Updates': 1}});
    harness.filter('Preference Updates');
    assert.equal(harness.visibleMarkers().length, 0);
    assert.equal(harness.elements['interaction-map-empty'].hidden, false);
    assert.match(harness.elements['interaction-map-empty'].textContent, /No preference updates/);
    assert.equal(harness.elements['interaction-location-table-empty'].hidden, false);
    assert.equal(harness.table.rows.every(row => row.hidden), true);
    assert.equal(harness.elements['fit-interaction-pins'].disabled, true);
    assert.equal(harness.fitCount, 0, 'Empty results never fit empty bounds');
    assert.equal(harness.elements['interaction-map-feedback'].textContent, '0 locations · 0 interactions with a mapped location · 1 without a mappable country · Preference Updates');
    harness.onLoad();
    assert.equal(harness.fitCount, 0);
    harness.filter('');
    assert.equal(harness.visibleMarkers().length, 3);
    assert.equal(harness.elements['interaction-map-empty'].hidden, true);
    assert.equal(harness.elements['interaction-location-table-empty'].hidden, true);
    assert.equal(harness.elements['fit-interaction-pins'].disabled, false);
});

test('empty reports and invalid coordinates never create pins or fit empty bounds', () => {
    for (const pins of [[], [{...mixedPins[0], latitude: 91}]]) {
        const harness = mapHarness(pins);
        harness.filter('Email Clicks');
        harness.onLoad();
        assert.equal(harness.visibleMarkers().length, 0);
        assert.equal(harness.fitCount, 0);
        assert.equal(harness.elements['fit-interaction-pins'].disabled, true);
        assert.equal(harness.elements['interaction-map-empty'].hidden, false);
    }
});
