'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function harness(kind = 'organization') {
    class Element {
        constructor(text = '', value = '') { this.textContent = text; this.value = value; this.listeners = {}; }
        addEventListener(event, callback) { this.listeners[event] = callback; }
        setAttribute() {}
        cloneNode() { return new Element(this.textContent, this.value); }
    }
    const chosen = new Element('Saved ' + kind, '999');
    let input, status, timer;
    const select = {value: '999', isConnected: true,
        dataset: kind === 'contact' ? {contactSearch: ''} : {},
        labels: [{textContent: kind === 'contact' ? 'Existing Contact' : 'Primary Organization'}],
        selectedOptions: [chosen], children: [chosen],
        before: (node) => { input = node; }, after: (node) => { status = node; },
        querySelector: () => new Element('None', ''),
        replaceChildren(...options) { this.children = options; }};
    const pending = [];
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/relationship-search.js'), 'utf8'), {
        document: {body: {}, querySelectorAll: () => [select], createElement: () => new Element()},
        MutationObserver: class { observe() {} }, Option: Element, URLSearchParams, AbortController,
        setTimeout(callback) { timer = callback; return 1; }, clearTimeout() {},
        fetch(url, options) { return new Promise((resolve, reject) => pending.push({url, options, resolve, reject})); },
    });
    return {select, pending, status, search(value) { input.value = value; input.listeners.input(); return timer(); }};
}
test('organization lookup preserves a selected record outside the first page and ignores stale responses', async () => {
    const h = harness();
    const first = h.search('one');
    const second = h.search('two');
    assert.equal(h.pending[0].options.signal.aborted, true);
    h.pending[1].resolve({ok:true,json:async()=>({results:[{id:2,organization_name:'New match'}],has_more:true})});
    await second;
    h.pending[0].resolve({ok:true,json:async()=>({results:[{id:3,organization_name:'Stale'}],has_more:false})});
    await first;
    assert.equal(h.select.value, '999');
    assert.deepEqual(h.select.children.map(o=>o.value), ['', '999', '2']);
    assert.match(h.status.textContent, /25 matches/);
});
test('failed organization lookup leaves saved choices intact', async () => {
    const h = harness(); const request = h.search('offline');
    h.pending[0].reject(new Error('offline')); await request;
    assert.equal(h.select.value, '999');
    assert.equal(h.select.children[0].textContent, 'Saved organization');
    assert.match(h.status.textContent, /unchanged/);
});
test('contact lookup keeps a selected contact and displays bounded results with identifying details', async () => {
    const h = harness('contact');
    const request = h.search('Taylor');
    assert.match(h.pending[0].url, /kind=contact/);
    h.pending[0].resolve({ok:true,json:async()=>({
        results:[{id:2,contact_first_name:'Taylor',contact_last_name:'Smith',contact_email:'taylor@example.test',organization_name:'Host'}],
        has_more:false
    })});
    await request;
    assert.equal(h.select.value, '999');
    assert.deepEqual(h.select.children.map(o=>o.value), ['', '999', '2']);
    assert.match(h.select.children[2].textContent, /Taylor Smith · taylor@example\.test · Host/);
    assert.match(h.status.textContent, /1 matching contacts/);
});
