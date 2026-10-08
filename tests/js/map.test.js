'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function harness(initialEvents, mapProvider = {}, moduleUrl = 'http://localhost:8080/assets/js/map.min.js') {
    class Element {
        constructor() { this.listeners = {}; this.dataset = {}; this.hidden = false; this.textContent = ''; this.disabled = false; this.children = []; }
        addEventListener(type, listener) { this.listeners[type] = listener; }
        appendChild(child) { this.children.push(child); }
        setAttribute(key, value) { this[key] = value; }
        matches(selector) { return selector === ':focus-visible' && this.focusVisible === true; }
        querySelector(selector) { return this.children.find(child => child.className?.split(' ').includes(selector.slice(1))); }
        focus() { this.focused = true; }
    }
    const ids = ['engagement-map', 'engagement-map-data', 'map-feedback', 'fit-map-pins', 'map-empty-state', 'map-empty-title', 'map-empty-description', 'map-list-empty', 'map-retry-feedback'];
    const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
    const filterForm = new Element(), applyButton = new Element();
    const filterFields = ['active', '', '2026-09-30', '2027-01-01', 'all'].map(value => ({value}));
    const applyClasses = new Set();
    applyButton.classList = {
        toggle: (name, enabled) => enabled ? applyClasses.add(name) : applyClasses.delete(name),
        contains: name => applyClasses.has(name)
    };
    filterForm.querySelectorAll = selector => selector === 'input, select' ? filterFields : [];
    filterForm.querySelector = selector => selector === 'button[type="submit"]' ? applyButton : null;
    elements['map-list-empty'].textContent = 'Every engagement matching your other filters has an address entered.';
    const rows = initialEvents.map(event => {
        const row = new Element();
        row.dataset = {locationId: String(event.id), locationState: event.locationState};
        const label = new Element(), help = new Element(), retry = new Element();
        retry.dataset.retryLocation = String(event.id);
        retry.hidden = !['not_found', 'failed'].includes(event.locationState);
        row.querySelector = selector => ({'[data-location-label]': label, '[data-location-help]': help, '[data-retry-location]': retry})[selector];
        row.retry = retry;
        return row;
    });
    const filters = ['all', 'found', 'pending', 'needs_address', 'not_found'].map(state => {
        const button = new Element(), count = new Element();
        button.dataset.locationFilter = state;
        button.querySelector = () => count;
        button.count = count;
        return button;
    });
    const requests = [], timers = new Map(), documentListeners = {};
    let timerId = 0, responder = async () => ({status: 200, locations: []});
    const mapCalls = {created: 0, resized: 0, pins: 0};
    const markers = [], popups = [], loadCallbacks = [];
    class FakeMap {
        constructor(options) { mapCalls.created++; mapCalls.options = options; }
        addControl() {}
        once(type, fn) { if (type === 'load') loadCallbacks.push(fn); else fn(); }
        on() {}
        resize() { mapCalls.resized++; }
        easeTo() {}
        fitBounds() {}
    }
    class Marker {
        constructor({element}) { this.element = element; markers.push(this); }
        setLngLat(point) { this.point = point; return this; }
        setPopup() { return this; }
        addTo() { mapCalls.pins++; return this; }
        getLngLat() { return this.point; }
    }
    class Popup {
        constructor(options) { this.options = options; this.listeners = {}; popups.push(this); }
        setDOMContent(content) { this.content = content; return this; }
        on(type, listener) { this.listeners[type] = listener; return this; }
    }
    class Bounds { extend() {} getCenter() { return [0, 0]; } }
    elements['engagement-map-data'].textContent = JSON.stringify({
        events: initialEvents, mapProvider, emptyTitle: 'No missing addresses', emptyDescription: 'No addresses need to be entered.',
        locationLookup: {enqueueUrl: 'map_geocode.php', statusUrl: 'map_geocode_status.php', csrfToken: 'test-token', maximumPolls: 3}
    });
    const context = {
        document: {
            getElementById: id => elements[id], createElement: () => new Element(), visibilityState: 'visible',
            querySelector: selector => selector === '.map-filters' ? filterForm : null,
            querySelectorAll: selector => ({'[data-location-id]': rows, '[data-location-filter]': filters, '[data-retry-location]': rows.map(row => row.retry)})[selector] || [],
            addEventListener: (type, fn) => { documentListeners[type] = fn; },
            dispatchEvent: event => documentListeners[event.type]?.(event)
        },
        window: {setTimeout: fn => { timers.set(++timerId, fn); return timerId; }, clearTimeout: id => timers.delete(id)},
        MapLibreMap: FakeMap, Marker, Popup, LngLatBounds: Bounds, NavigationControl: class {},
        setWorkerUrl: url => { mapCalls.workerUrl = url; }, DNR_MAPLIBRE_WORKER_URL: './maplibre-worker.min.js?v=test',
        URL, moduleUrl,
        CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
        URLSearchParams,
        fetch: async (url, options) => {
            const request = {url, fields: Object.fromEntries(options.body)};
            requests.push(request);
            const result = await responder(request);
            return {ok: result.status >= 200 && result.status < 300, status: result.status, json: async () => result};
        }
    };
    vm.createContext(context);
    vm.runInContext(fs.readFileSync('src/assets/js/map-list.js', 'utf8'), context);
    vm.runInContext(fs.readFileSync('src/assets/js/map.js', 'utf8').replace(/^import[\s\S]*?from 'maplibre-gl';/, '').replaceAll('import.meta.url', 'moduleUrl'), context);
    return {elements, rows, filters, filterForm, filterFields, applyButton, requests, mapCalls, markers, popups, load: () => loadCallbacks.splice(0).forEach(fn => fn()), responder: fn => { responder = fn; },
        poll: async () => { const first = timers.entries().next().value; assert.ok(first, 'A poll should be scheduled'); timers.delete(first[0]); await first[1](); }};
}
const unresolved = {id: 4, title: 'Conference', address: '960 S. US Highway 41', locationState: 'not_found', latitude: null, longitude: null};

test('each unapplied map filter draws attention until all original selections are restored', () => {
    const h = harness([]);
    const reminding = () => h.applyButton.classList.contains('map-apply-reminder');
    assert.equal(reminding(), false, 'applied filters should not flash on load');
    for (const field of h.filterFields) {
        const original = field.value;
        field.value = original + '-changed';
        h.filterForm.listeners.change();
        assert.equal(reminding(), true, 'each filter should trigger the reminder');
        field.value = original;
        h.filterForm.listeners.input();
        assert.equal(reminding(), false, 'restoring the selection should stop the reminder');
    }
    const originals = h.filterFields.slice(0, 2).map(field => field.value);
    h.filterFields[0].value = 'all';
    h.filterFields[1].value = 'under_review';
    h.filterForm.listeners.change();
    h.filterFields[0].value = originals[0];
    h.filterForm.listeners.change();
    assert.equal(reminding(), true, 'another unapplied filter should keep the reminder active');
    h.filterFields[1].value = originals[1];
    h.filterForm.listeners.input();
    assert.equal(reminding(), false);
});

test('Amazon style replaces the raster source without changing engagement coordinates', () => {
    const styleUrl = 'https://maps.geo.us-east-2.amazonaws.com/v2/styles/Standard/descriptor?key=test';
    const h = harness([{...unresolved, locationState: 'found', latitude: 28.8, longitude: -82.3}],
        {type: 'amazon', styleUrl});
    assert.equal(h.mapCalls.options.style, styleUrl);
    assert.equal(h.markers.length, 1);
    assert.deepEqual(Array.from(h.markers[0].point), [-82.3, 28.8]);
    assert.equal(h.elements['engagement-map'].dataset.mapProvider, 'amazon');
});

test('pointer-opened popups leave the action unfocused while keyboard-opened popups focus it', () => {
    const h = harness([{...unresolved, locationState: 'found', latitude: 32.9, longitude: -96.4, viewUrl: 'view_engagement.php?id=4'}]);
    const popup = h.popups[0];
    const link = popup.content.querySelector('.map-popup-link');
    assert.equal(popup.options.focusAfterOpen, false);
    popup.listeners.open();
    assert.notEqual(link.focused, true, 'Opening a pin with a pointer must not apply keyboard focus styling');
    h.markers[0].element.focusVisible = true;
    popup.listeners.open();
    assert.equal(link.focused, true, 'Opening a pin with the keyboard must move focus to the action');
    assert.equal(link.href, 'view_engagement.php?id=4');
});

test('empty server results keep the base map and guidance visible without requesting lookups', () => {
    const h = harness([]);
    assert.equal(h.mapCalls.created, 1);
    assert.equal(h.requests.length, 0);
    assert.equal(h.elements['engagement-map'].hidden, false);
    assert.equal(h.elements['fit-map-pins'].disabled, true);
    assert.equal(h.elements['map-feedback'].textContent, 'No missing addresses');
    assert.match(h.elements['map-list-empty'].textContent, /Every engagement/);
});

test('cached misses are not automatically retried or double counted', () => {
    const h = harness([unresolved]);
    assert.equal(h.requests.length, 0);
    assert.equal(h.elements['map-feedback'].textContent, '0 visible pins · 1 address not found');
    assert.equal(h.elements['engagement-map'].hidden, false);
    assert.equal(h.elements['fit-map-pins'].disabled, true);
    assert.equal(h.rows[0].retry.hidden, false);
});

test('explicit retry updates queue, filters, counters and map without navigating away', async () => {
    const h = harness([unresolved]);
    h.filters.find(button => button.dataset.locationFilter === 'not_found').listeners.click();
    h.responder(async () => ({status: 202, locations: [{id: 4, status: 'pending'}]}));
    await h.rows[0].retry.listeners.click();
    assert.deepEqual(h.requests[0], {url: 'map_geocode.php', fields: {csrf_token: 'test-token', engagement_ids: '4', retry: '1'}});
    assert.equal(h.rows[0].dataset.locationState, 'pending');
    assert.equal(h.rows[0].retry.hidden, true);
    assert.equal(h.rows[0].hidden, true, 'Preserve the selected unresolved filter');
    assert.equal(h.elements['map-feedback'].textContent, '0 visible pins · 1 location awaiting lookup');
    h.responder(async () => ({status: 200, locations: [{id: 4, status: 'found', latitude: 28.83, longitude: -82.34}]}));
    await h.poll();
    assert.equal(h.mapCalls.pins, 1);
    assert.equal(h.mapCalls.resized, 0, 'The map stays visible while waiting for pins');
    assert.equal(h.elements['engagement-map'].hidden, false);
    assert.equal(h.elements['map-empty-state'].hidden, true);
    assert.equal(h.elements['map-feedback'].textContent, '1 visible pin');
    assert.match(h.elements['map-retry-feedback'].textContent, /Location found/);
    assert.equal(h.rows[0].dataset.locationState, 'found');
});

test('failed retry remains usable and explains the error', async () => {
    const h = harness([unresolved]);
    h.responder(async () => ({status: 503, message: 'The location service is temporarily unavailable.'}));
    await h.rows[0].retry.listeners.click();
    assert.equal(h.rows[0].retry.disabled, false);
    assert.equal(h.rows[0].retry.hidden, false);
    assert.equal(h.rows[0].dataset.locationState, 'not_found');
    assert.match(h.elements['map-retry-feedback'].textContent, /temporarily unavailable/);
});

test('a completed miss remains one unresolved location and can be retried again', async () => {
    const h = harness([unresolved]);
    h.responder(async () => ({status: 202, locations: [{id: 4, status: 'pending'}]}));
    await h.rows[0].retry.listeners.click();
    h.responder(async () => ({status: 200, locations: [{id: 4, status: 'not_found'}]}));
    await h.poll();
    assert.equal(h.elements['map-feedback'].textContent, '0 visible pins · 1 address not found');
    assert.equal(h.rows[0].retry.hidden, false);
    assert.equal(h.mapCalls.pins, 0);
    assert.match(h.elements['map-retry-feedback'].textContent, /No matching location/);
});

test('credits collapse after asynchronous map load, not before source attribution arrives', () => {
    const h = harness([{...unresolved, locationState: 'found', latitude: 28.8, longitude: -82.3}]);
    const classes = new Set(['maplibregl-compact-show']);
    const attribution = {open: true, classList: {remove: name => classes.delete(name)}, removeAttribute(name) { if (name === 'open') this.open = false; }};
    h.elements['engagement-map'].querySelector = () => attribution;
    assert.equal(attribution.open, true);
    h.load();
    assert.equal(attribution.open, false);
    assert.equal(classes.has('maplibregl-compact-show'), false);
    attribution.open = true;
    h.load();
    assert.equal(attribution.open, true, 'subsequent user expansion must be preserved');
});

test('map worker follows the bundle path at root and within each Account', () => {
    for (const prefix of ['', '/a/shalom-in-messiah', '/a/test-account']) {
        const h = harness([], {}, `http://localhost:8080${prefix}/assets/js/map.min.js?rev=123`);
        assert.equal(h.mapCalls.workerUrl, `http://localhost:8080${prefix}/assets/js/maplibre-worker.min.js?v=test`);
    }
});
