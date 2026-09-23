const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');

const script = fs.readFileSync(path.join(__dirname, '../../src/assets/js/ai-coach.js'), 'utf8');
const requestId = 'b50fd519-d9a7-4d83-b6d6-00358ac3c134';

// A small DOM fixture for request lifecycle behavior. Execute the real browser
// script, while making navigation, transport responses, and timers deterministic.
class Element {
    constructor(name = '') {
        this.name = name;
        this.children = [];
        this.handlers = new Map();
        this.attributes = new Map();
        this.dataset = {};
        this.classList = { add() {}, remove() {}, toggle() {} };
        this.value = '';
        this.textContent = '';
        this.hidden = false;
        this.disabled = false;
        this.inert = false;
        this.scrollTop = 0;
        this.scrollHeight = 300;
    }
    addEventListener(name, listener) {
        const listeners = this.handlers.get(name) || [];
        listeners.push(listener);
        this.handlers.set(name, listeners);
    }
    append(...nodes) {
        for (const node of nodes) {
            node.remove();
            this.children.push(node);
            node.parent = this;
        }
    }
    remove() {
        if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this);
        this.parent = null;
    }
    replaceChildren(...nodes) {
        this.children.forEach(node => { node.parent = null; });
        this.children = [];
        this.append(...nodes);
    }
    get firstElementChild() { return this.children[0]; }
    setAttribute(name, value) { this.attributes.set(name, value); }
    removeAttribute(name) { this.attributes.delete(name); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    getClientRects() { return this.hidden ? [] : [{}]; }
    getBoundingClientRect() { return { top: 0 }; }
    closest() { return null; }
    contains(node) { return node === this || this.children.some(child => child.contains(node)); }
    focus() {}
    matches() { return false; }
    querySelector() { return null; }
    querySelectorAll(selector) {
        if (selector === '.coach-message-user') return this.children.filter(node => node.className === 'coach-message coach-message-user');
        return [];
    }
}

function createPage({ ageMs = 0, responses = [], pendingRequest = true } = {}) {
    const fields = Object.fromEntries([
        'steps', 'context', 'step', 'workflow-title', 'step-message', 'show', 'go', 'source', 'step-note',
        'acknowledge', 'end', 'missing', 'welcome', 'messages', 'send', 'stop', 'status', 'close', 'reset', 'form'
    ].map(name => [name, new Element(name)]));
    fields.steps.textContent = '{}';
    const panel = new Element('panel');
    const question = new Element('question');
    const scroll = new Element('scroll');
    const body = new Element('body');
    const opener = new Element('opener');
    panel.hidden = true;
    panel.dataset = { page: 'dashboard.php', role: 'editor', storageKey: 'test-key', endpoint: 'ai_coach.php', csrfToken: 'test-csrf' };
    panel.querySelector = selector => selector === 'textarea' ? question : selector === '.coach-scroll' ? scroll
        : fields[selector.match(/\[data-coach-(.*?)\]/)?.[1]];
    fields.step.querySelector = panel.querySelector;
    body.append(panel);
    const initial = {
        key: 'test-key', messages: [{ id: 'a50fd519-d9a7-4d83-b6d6-00358ac3c134', role: 'user', content: 'What does MOED do?' }],
        pending: { id: requestId, startedAt: Date.now() - ageMs, request: { request_id: requestId, question: 'What does MOED do?', page: 'dashboard.php' } }
    };
    if (!pendingRequest) { initial.pending = null; initial.messages = []; }
    let stored = JSON.stringify(initial);
    let timerId = 0;
    const timers = new Map();
    const listeners = new Map();
    const requests = [];
    const transportTimeouts = [];
    const window = {
        crypto: webcrypto,
        sessionStorage: { getItem: () => stored, setItem: (_, value) => { stored = value; }, removeItem: () => { stored = null; } },
        matchMedia: () => ({ matches: false, addEventListener() {} }),
        location: { href: 'http://localhost/dashboard.php', hash: '', reload() { throw new Error('Unexpected reload'); } },
        requestAnimationFrame: callback => callback(),
        setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
        clearTimeout: id => timers.delete(id),
        addEventListener: (name, listener) => {
            const registered = listeners.get(name) || [];
            registered.push(listener);
            listeners.set(name, registered);
        }
    };
    const document = {
        body, activeElement: null,
        querySelector: selector => selector === '[data-coach]' ? panel : null,
        querySelectorAll: selector => selector === '[data-coach-open]' ? [opener] : [],
        getElementById: () => null, addEventListener() {}, createElement: name => new Element(name)
    };
    const context = {
        window, document, MutationObserver: class { observe() {} }, AbortController,
        AbortSignal: { any: AbortSignal.any, timeout: ms => { const control = new AbortController(); transportTimeouts.push({ ms, control }); return control.signal; } }, URL, Date, Map, JSON,
        fetch: async (url, options) => {
            requests.push({ url, options });
            assert.ok(responses.length, 'Every unexpected transport request fails the test');
            const response = responses.shift();
            if (typeof response === 'function') return response(url, options);
            return { ok: true, status: 200, json: async () => response };
        }
    };
    vm.runInNewContext(script, context);
    return {
        fields, question, timers, requests, transportTimeouts,
        stored: () => JSON.parse(stored),
        dispatch: (name, event) => (listeners.get(name) || []).forEach(listener => listener(event)),
        assistantMessages: () => fields.messages.children.filter(node => node.className === 'coach-message coach-message-assistant')
    };
}

const settle = () => new Promise(resolve => setImmediate(resolve));
const complete = { state: 'complete', response: { message: 'MOED helps coordinate engagements and follow-up work.', question: '', sources: [], mode: 'conversation', history_saved: true } };

test('a stalled submission times out and recovers the same durable request without cancelling it', async () => {
    const page = createPage({ pendingRequest: false, responses: [
        (_url, options) => new Promise((_resolve,reject) => options.signal.addEventListener('abort', () => reject(new Error('submission timeout')))), complete
    ] });
    page.question.value = 'What does MOED do?';
    page.fields.form.handlers.get('submit')[0]({ preventDefault() {} });
    await settle();
    assert.equal(page.requests.length,1);
    const id = JSON.parse(page.requests[0].options.body).request_id;
    assert.equal(page.transportTimeouts[0].ms,5000);
    page.transportTimeouts[0].control.abort();
    await settle();
    assert.equal(page.timers.size,1, 'A lost acknowledgement schedules status recovery');
    [...page.timers.values()][0]();
    await settle();
    assert.equal(page.requests.length,2);
    assert.ok(page.requests[1].url.endsWith('?request_id='+id));
    assert.equal(page.assistantMessages().length,1);
    assert.equal(page.stored().pending,null);
    assert.equal(page.question.readOnly,false);
});

test('Back/Forward cache restoration restarts polling and appends the answer once', async () => {
    const page = createPage({ responses: [{ state: 'pending', stage: 'generating' }, complete] });
    await settle();
    assert.equal(page.requests.length, 1);
    assert.equal(page.timers.size, 1);
    assert.equal(page.question.readOnly, true);

    page.dispatch('pagehide', { persisted: true });
    assert.equal(page.timers.size, 0);
    assert.equal(page.stored().pending.id, requestId);
    page.dispatch('pageshow', { persisted: true });
    await settle();

    assert.equal(page.requests.length, 2);
    assert.ok(page.requests.every(request => request.url.endsWith('?request_id=' + requestId)));
    assert.equal(page.assistantMessages().length, 1);
    assert.equal(page.question.readOnly, false);
    assert.equal(page.fields.send.disabled, false);
    assert.equal(page.stored().pending, null);
    assert.equal(page.timers.size, 0);

    page.dispatch('pageshow', { persisted: true });
    await settle();
    assert.equal(page.requests.length, 2, 'Restoring a completed exchange must not request it again');
    assert.equal(page.assistantMessages().length, 1);
});

test('an answer completed during a long suspension is retrieved before applying the client deadline', async () => {
    const page = createPage({ ageMs: 30000, responses: [complete] });
    await settle();
    assert.equal(page.requests.length, 1, 'Check the durable result even after the browser waiting budget expired');
    assert.equal(page.assistantMessages().length, 1);
    assert.equal(page.stored().pending, null);
    assert.equal(page.question.readOnly, false);
    assert.equal(page.question.value, '', 'A completed question must not be restored for duplicate submission');
    assert.doesNotMatch(page.fields.status.textContent, /interrupted/);
});

test('an expired request that is still pending is stopped after checking the server', async () => {
    const page = createPage({ ageMs: 30000, responses: [{ state: 'pending', stage: 'queued' }] });
    await settle();
    assert.equal(page.requests.length, 1);
    assert.equal(page.stored().pending, null);
    assert.equal(page.question.readOnly, false);
    assert.equal(page.question.value, 'What does MOED do?');
    assert.match(page.fields.status.textContent, /interrupted/);
    assert.equal(page.timers.size, 0);
});
