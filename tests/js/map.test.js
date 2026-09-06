'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function harness(initialEvents) {
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
    elements['engagement-map'].hidden = !initialEvents.some(event => event.locationState === 'found');
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
    const markers = [], popups = [];
    class FakeMap {
        constructor() { mapCalls.created++; }
        addControl() {}
        once(type, fn) { fn(); }
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
        events: initialEvents, emptyTitle: 'No missing addresses', emptyDescription: 'No addresses need to be entered.',
        locationLookup: {enqueueUrl: 'map_geocode.php', statusUrl: 'map_geocode_status.php', csrfToken: 'test-token', maximumPolls: 3}
    });
    const context = {
        document: {
            getElementById: id => elements[id], createElement: () => new Element(), visibilityState: 'visible',
            querySelectorAll: selector => ({'[data-location-id]': rows, '[data-location-filter]': filters, '[data-retry-location]': rows.map(row => row.retry)})[selector] || [],
            addEventListener: (type, fn) => { documentListeners[type] = fn; },
            dispatchEvent: event => documentListeners[event.type]?.(event)
        },
        window: {setTimeout: fn => { timers.set(++timerId, fn); return timerId; }, clearTimeout: id => timers.delete(id)},
        MapLibreMap: FakeMap, Marker, Popup, LngLatBounds: Bounds, NavigationControl: class {},
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
    vm.runInContext(fs.readFileSync('src/assets/js/map.js', 'utf8').replace(/^import[\s\S]*?from 'maplibre-gl';/, ''), context);
    return {elements, rows, filters, requests, mapCalls, markers, popups, responder: fn => { responder = fn; },
        poll: async () => { const first = timers.entries().next().value; assert.ok(first, 'A poll should be scheduled'); timers.delete(first[0]); await first[1](); }};
}
const unresolved = {id: 4, title: 'Conference', address: '960 S. US Highway 41', locationState: 'not_found', latitude: null, longitude: null};

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

test('empty server results show guidance without initializing a world map or requesting lookups', () => {
    const h = harness([]);
    assert.equal(h.mapCalls.created, 0);
    assert.equal(h.requests.length, 0);
    assert.equal(h.elements['engagement-map'].hidden, true);
    assert.equal(h.elements['map-feedback'].textContent, 'No missing addresses');
    assert.match(h.elements['map-list-empty'].textContent, /Every engagement/);
});

test('cached misses are not automatically retried or double counted', () => {
    const h = harness([unresolved]);
    assert.equal(h.requests.length, 0);
    assert.equal(h.elements['map-feedback'].textContent, '0 visible pins · 1 address not found');
    assert.equal(h.elements['engagement-map'].hidden, true);
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
    assert.equal(h.mapCalls.resized, 1);
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
