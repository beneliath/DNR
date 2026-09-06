'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function harness(coordinates = {latitude: null, longitude: null}) {
    const elements = Object.fromEntries(['pin-editor-data', 'pin-editor-map', 'pin-latitude', 'pin-longitude', 'confirm-pin', 'pin-editor-feedback']
        .map(id => [id, {value: '', checked: false, listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; }}]));
    elements['pin-editor-data'].textContent = JSON.stringify(coordinates);
    const maps = [], markers = [];
    class FakeMap {
        constructor(options) { this.options = options; this.listeners = {}; maps.push(this); }
        addControl() {}
        on(type, fn) { this.listeners[type] = fn; }
        getZoom() { return this.options.zoom; }
        easeTo(options) { this.lastMove = options; }
    }
    class Marker {
        constructor() { this.listeners = {}; markers.push(this); }
        setLngLat(point) { this.point = point; return this; }
        addTo() { return this; }
        on(type, fn) { this.listeners[type] = fn; }
        getLngLat() { return {lat: this.point[1], lng: this.point[0]}; }
    }
    const context = {document: {getElementById: id => elements[id]}, MapLibreMap: FakeMap, Marker, NavigationControl: class {}};
    vm.createContext(context);
    vm.runInContext(fs.readFileSync('src/assets/js/map-pin.js', 'utf8').replace(/^import[^\n]*\n/, ''), context);
    return {elements, map: maps[0], markers, latitude: elements['pin-latitude'], longitude: elements['pin-longitude'], confirmation: elements['confirm-pin']};
}

test('unknown or invalid coordinates never create an accidental zero-location pin', () => {
    for (const coordinates of [{latitude: null, longitude: null}, {latitude: 91, longitude: 181}]) {
        const h = harness(coordinates);
        assert.equal(h.markers.length, 0);
        assert.equal(h.latitude.value, '');
        assert.equal(h.longitude.value, '');
        assert.equal(h.confirmation.checked, false);
    }
});

test('map clicks and marker drags require a fresh location confirmation', () => {
    const h = harness({latitude: 28.819406, longitude: -82.315708});
    assert.equal(h.markers.length, 1);
    assert.equal(h.latitude.value, '28.8194060');
    h.confirmation.checked = true;
    h.map.listeners.click({lngLat: {lat: 48.8598, lng: 2.297}});
    assert.equal(h.confirmation.checked, false);
    assert.equal(h.longitude.value, '2.2970000');
    h.confirmation.checked = true;
    h.markers[0].setLngLat([2.3, 48.86]);
    h.markers[0].listeners.dragend();
    assert.equal(h.confirmation.checked, false);
    assert.equal(h.latitude.value, '48.8600000');
});

test('keyboard coordinate changes reset confirmation and reject incomplete coordinates', () => {
    const h = harness();
    h.confirmation.checked = true;
    h.latitude.value = '0';
    h.latitude.listeners.input();
    h.latitude.listeners.change();
    assert.equal(h.confirmation.checked, false);
    assert.equal(h.markers.length, 0);
    h.longitude.value = '0';
    h.longitude.listeners.change();
    assert.equal(h.markers.length, 1, 'Explicit zero coordinates are valid');
    assert.equal(h.map.lastMove.zoom, 16);
    h.latitude.value = '100';
    h.latitude.listeners.change();
    assert.equal(h.markers[0].point[1], 0, 'Out-of-range values cannot move the pin');
});

test('tile errors leave coordinate entry available with an explanation', () => {
    const h = harness();
    h.map.listeners.error();
    assert.match(h.elements['pin-editor-feedback'].textContent, /still enter known coordinates/);
    h.latitude.value = '28.819406';
    h.longitude.value = '-82.315708';
    h.longitude.listeners.change();
    assert.equal(h.markers.length, 1);
});
