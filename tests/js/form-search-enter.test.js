'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function element(properties = {}) {
    return Object.assign({
        listeners: {}, dataset: {}, children: [], textContent: '', value: '',
        addEventListener(event, callback) { this.listeners[event] = callback; },
        appendChild(child) { this.children.push(child); },
        replaceChildren(...children) { this.children = children; },
        setAttribute() {}, removeAttribute() {}, focus() {}
    }, properties);
}

function loadSearch(kind) {
    const search = element({
        value: 'Annual conference', disabled: true,
        dataset: { subjectSearchUrl: 'task_subject_search.php', engagementSearchUrl: 'inbound_engagement_search.php' }
    });
    const selected = { value: '12', textContent: 'Existing record' };
    const select = element({ value: '12', options: [selected], selectedOptions: [selected] });
    const status = element();
    const save = element({ disabled: false });
    const nodes = kind === 'task' ? {
        'task-subject-search': search, 'task-subject': select, 'task-subject-status': status,
        'task-selected-record': element(), 'task-selected-record-label': element(),
        'task-record-search-panel': element(), 'task-subject-results': element(),
        'task-change-record': element()
    } : {
        'inbound-engagement-search': search, 'inbound-engagement-id': select,
        'inbound-engagement-search-status': status, 'inbound-selection-summary': element()
    };
    const review = element({
        querySelector: selector => selector.startsWith('button') ? save : select,
        querySelectorAll: () => [{}]
    });
    let nextTimer = 0;
    const timers = new Map();
    const requests = [];
    vm.runInNewContext(fs.readFileSync(require.resolve(
        '../../src/assets/js/' + (kind === 'task' ? 'task-form' : 'inbound-mail') + '.js'
    ), 'utf8'), {
        document: {
            getElementById: id => nodes[id] || null,
            querySelector: () => kind === 'inbound' ? review : null,
            createElement: () => element(), createDocumentFragment: () => element()
        },
        window: {
            location: { href: 'https://example.test/edit.php' },
            setTimeout(callback, delay) { const id = ++nextTimer; timers.set(id, { callback, delay }); return id; },
            clearTimeout(id) { timers.delete(id); }
        },
        URL, AbortController,
        fetch: async (url, options) => {
            requests.push({ url, options });
            return { ok: true, json: async () => ({ results: [], engagements: [] }) };
        }
    });
    function keydown(key, isComposing = false) {
        const event = { key, isComposing, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        search.listeners.keydown(event);
        return event;
    }
    return { search, select, selected, save, status, timers, requests, keydown };
}

for (const kind of ['task', 'inbound']) {
    test(kind + ' search Enter runs one immediate lookup without submitting or changing the saved destination', async () => {
        const fixture = loadSearch(kind);
        assert.equal(fixture.search.disabled, false);
        fixture.search.listeners.input();
        assert.equal([...fixture.timers.values()][0].delay, 250);
        const enter = fixture.keydown('Enter');
        assert.equal(enter.defaultPrevented, true, 'Enter must not trigger the form’s Save button');
        assert.equal(fixture.timers.size, 1, 'Enter replaces the pending debounced search');
        const pending = [...fixture.timers.values()][0];
        assert.equal(pending.delay, 0);
        await pending.callback();
        assert.equal(fixture.requests.length, 1);
        assert.equal(fixture.requests[0].url.searchParams.get('q'), fixture.search.value);
        assert.equal(fixture.requests[0].options.method || 'GET', 'GET');
        assert.equal(fixture.select.value, fixture.selected.value);
        assert.equal(fixture.save.disabled, false, 'Explicit saving remains available');
    });

    test(kind + ' search Enter cannot save even with an empty or too-short query', () => {
        const fixture = loadSearch(kind);
        for (const query of ['', 'a']) {
            fixture.search.value = query;
            assert.equal(fixture.keydown('Enter').defaultPrevented, true);
            assert.equal(fixture.timers.size, 0);
        }
        assert.equal(fixture.requests.length, 0);
        assert.equal(fixture.select.value, fixture.selected.value);
    });

    test(kind + ' search preserves other keys and composition Enter', () => {
        const fixture = loadSearch(kind);
        assert.equal(fixture.keydown('Tab').defaultPrevented, false);
        assert.equal(fixture.keydown('ArrowDown').defaultPrevented, false);
        assert.equal(fixture.keydown('Enter', true).defaultPrevented, false);
        assert.equal(fixture.timers.size, 0);
    });
}
