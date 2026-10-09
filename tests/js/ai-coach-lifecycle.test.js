const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('./helpers/account-context.js');
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
    scrollIntoView() {}
    matches() { return false; }
    querySelector() { return null; }
    querySelectorAll(selector) {
        if (selector === '.coach-message-user') return this.children.filter(node => node.className === 'coach-message coach-message-user');
        return [];
    }
}

function createPage({ ageMs = 0, responses = [], pendingRequest = true, pageName = 'dashboard.php', savedState,
    narrow = false, stepDefinitions = {}, controlSelectors = {}, pageTargets = {} } = {}) {
    const fields = Object.fromEntries([
        'steps', 'context', 'step', 'workflow-title', 'step-message', 'show', 'go', 'source', 'step-note',
        'acknowledge', 'end', 'missing', 'welcome', 'messages', 'send', 'stop', 'status', 'close', 'reset', 'form'
    ].map(name => [name, new Element(name)]));
    fields.steps.textContent = JSON.stringify(stepDefinitions);
    fields.controls = new Element('controls');
    fields.controls.textContent = JSON.stringify(controlSelectors);
    const panel = new Element('panel');
    const question = new Element('question');
    const scroll = new Element('scroll');
    const body = new Element('body');
    const main = new Element('main');
    const opener = new Element('opener');
    const manualLink = new Element('manual-link');
    const selectedTab = new Element('selected-tab');
    panel.hidden = true;
    panel.dataset = { page: pageName, role: 'editor', storageKey: 'test-key', paneKey: 'test-pane-key', endpoint: 'ai_coach.php', csrfToken: 'test-csrf' };
    panel.querySelector = selector => selector === 'textarea' ? question : selector === '.coach-scroll' ? scroll
        : fields[selector.match(/\[data-coach-(.*?)\]/)?.[1]];
    fields.step.querySelector = panel.querySelector;
    body.append(main, panel);
    const initial = {
        key: 'test-key', messages: [{ id: 'a50fd519-d9a7-4d83-b6d6-00358ac3c134', role: 'user', content: 'What does MOED do?' }],
        pending: { id: requestId, startedAt: Date.now() - ageMs, request: { request_id: requestId, question: 'What does MOED do?', page: 'dashboard.php' } }
    };
    if (!pendingRequest) { initial.pending = null; initial.messages = []; }
    let stored = JSON.stringify(savedState || initial);
    let timerId = 0;
    const timers = new Map();
    const listeners = new Map();
    const requests = [];
    const transportTimeouts = [];
    const window = {
        crypto: webcrypto,
        sessionStorage: { getItem: () => stored, setItem: (_, value) => { stored = value; }, removeItem: () => { stored = null; } },
        matchMedia: () => ({ matches: narrow, addEventListener() {} }),
        location: { href: 'http://localhost/' + pageName, hash: '', assign(url) { this.assigned = url; }, reload() { throw new Error('Unexpected reload'); } },
        open(url) { this.opened = url; },
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
        querySelector: selector => selector === '[data-coach]' ? panel
            : selector === '[role="tab"][aria-selected="true"]' && selectedTab.id ? selectedTab : null,
        querySelectorAll: selector => selector === '[data-coach-open]' ? [opener]
            : selector === '#app-sidebar a[href="help.php"]' ? [manualLink] : pageTargets[selector] || [],
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
        fields, question, panel, main, opener, manualLink, timers, requests, transportTimeouts, window,
        stored: () => JSON.parse(stored),
        restoreStorage: value => { stored = JSON.stringify(value); },
        selectTab: name => { selectedTab.id = 'engagement-' + name + '-tab'; },
        dispatch: (name, event) => (listeners.get(name) || []).forEach(listener => listener(event)),
        assistantMessages: () => fields.messages.children.filter(node => node.className === 'coach-message coach-message-assistant')
    };
}

const settle = () => new Promise(resolve => setImmediate(resolve));
const complete = { state: 'complete', response: { message: 'MOED helps coordinate engagements and follow-up work.', question: '', sources: [], mode: 'conversation', history_saved: true } };

test('ordinary navigation preserves the chosen pane state at desktop and narrow widths', () => {
    for (const narrow of [false, true]) {
        for (const open of [false, true]) {
            let savedState = { key: 'test-key', open, messages: [{ role: 'user', content: 'Keep this conversation' }] };
            for (const pageName of ['dashboard.php', 'engagements.php', 'view_engagement.php', 'admin_elevation.php', 'other']) {
                const page = createPage({ narrow, pageName, pendingRequest: false, savedState });
                assert.equal(page.panel.hidden, !open, pageName + ' preserves the current pane state');
                assert.equal(page.opener.getAttribute('aria-expanded'), String(open));
                assert.equal(page.main.inert, narrow && open, 'Narrow restoration retains modal accessibility');
                page.dispatch('pagehide', { persisted: false });
                savedState = page.stored();
                assert.equal(savedState.open, open);
                assert.equal(savedState.messages[0].content, 'Keep this conversation');
            }
        }
    }
    assert.equal(createPage({ pendingRequest: false }).panel.hidden, true, 'The first visit does not automatically open the pane');
});

test('Back/Forward restores the latest pane choice, including a close from visiting the manual', () => {
    for (const narrow of [false, true]) {
        const page = createPage({ narrow, pendingRequest: false });
        for (const open of [true, false, true]) {
            page.dispatch('pagehide', { persisted: true });
            page.restoreStorage({ ...page.stored(), open });
            page.dispatch('pageshow', { persisted: true });
            assert.equal(page.panel.hidden, !open);
            assert.equal(page.stored().open, open, 'A cached page must not overwrite the latest choice');
            assert.equal(page.main.inert, narrow && open);
        }
        const manual = createPage({ narrow, pageName: 'help.php', savedState: page.stored() });
        assert.equal(manual.panel.hidden, true, 'Any route into the manual closes the pane');
        page.restoreStorage(manual.stored());
        page.dispatch('pageshow', { persisted: true });
        assert.equal(page.panel.hidden, true, 'Back from the manual does not reopen a cached pane');
    }
});

test('role preview navigation preserves the pane choice while a new login resets it', () => {
    for (const open of [false, true]) {
        const rolePreview = createPage({ pendingRequest: false, savedState: { key: 'previous-role', paneKey: 'test-pane-key', open,
            messages: [{ role: 'assistant', content: 'Previous role conversation' }] } });
        assert.equal(rolePreview.panel.hidden, !open);
        assert.equal(rolePreview.stored().messages.length, 0, 'Conversations remain isolated by role');
    }
    const newLogin = createPage({ pendingRequest: false, savedState: { key: 'previous-login', paneKey: 'previous-pane-key', open: true,
        messages: [{ role: 'assistant', content: 'Previous login conversation' }] } });
    assert.equal(newLogin.panel.hidden, true, 'A new login does not automatically open the pane');
    assert.equal(newLogin.stored().messages.length, 0);
});

test('Show Me highlights the page without automatically minimizing a narrow pane', () => {
    const target = new Element('organization');
    const page = createPage({ narrow: true, pageName: 'index.php', pendingRequest: false,
        savedState: { key: 'test-key', open: true, workflow: 'engagement', messages: [] },
        stepDefinitions: { 'engagement-organization': { message: 'Choose the organization', target: 'new-organization', source: 'manual-topic-engagements-create-or-edit-an-engagement' } },
        controlSelectors: { 'new-organization': { selector: '#organization_id' } }, pageTargets: { '#organization_id': [target] }
    });
    page.fields.show.handlers.get('click')[0]();
    assert.equal(page.panel.hidden, false);
    assert.equal(page.stored().open, true);
    assert.equal(page.main.inert, true);
    assert.match(page.fields['step-note'].textContent, /Minimize AI Coach to view it/);
});

test('manual source navigation closes the pane while a separate PDF tab preserves its state', () => {
    for (const narrow of [false, true]) {
        for (const source_page of [undefined, 42]) {
            const page = createPage({ narrow, pendingRequest: false,
                savedState: { key: 'test-key', open: true, workflow: 'engagement', messages: [] },
                stepDefinitions: { 'engagement-start': { message: 'Open New Engagement', source: 'manual-topic-engagements-create-or-edit-an-engagement', source_page } }
            });
            page.fields.source.handlers.get('click')[0]();
            assert.equal(page.panel.hidden, source_page === undefined);
            if (source_page) assert.match(page.window.opened, /manual.pdf#page=42$/);
            else assert.match(page.window.location.assigned, /^help.php#manual-topic-/);
        }
    }
});

test('one conversation carries prior exchange locations while each new question uses the destination page', async () => {
    let savedState;
    const visited = [];
    for (const pageName of ['dashboard.php', 'engagements.php', 'contacts.php']) {
        const page = createPage({ pageName, pendingRequest: false, savedState, responses: [complete.response] });
        page.question.value = 'What can I do here?';
        page.fields.form.handlers.get('submit')[0]({ preventDefault() {} });
        await settle();
        const request = JSON.parse(page.requests[0].options.body);
        assert.equal(request.page, pageName);
        assert.deepEqual(request.history.map(message => message.page), visited.flatMap(name => [name, name]));
        visited.push(pageName);
        savedState = page.stored();
        assert.deepEqual(savedState.messages.map(message => message.page), visited.flatMap(name => [name, name]));
        assert.equal(savedState.messages.length, visited.length * 2, 'Navigation does not reset the conversation');
        page.dispatch('pagehide', { persisted: false });
    }
});

test('an answer arriving after navigation retains the question origin, then the next request uses the new page', async () => {
    const original = createPage({ responses: [{state:'pending'}] });
    await settle();
    original.dispatch('pagehide', { persisted:true });
    const destination = createPage({ pageName:'contacts.php', savedState:original.stored(), responses:[complete, complete.response] });
    await settle();
    assert.equal(destination.stored().messages.at(-1).page, 'dashboard.php');
    destination.question.value = 'What can I do here?';
    destination.fields.form.handlers.get('submit')[0]({preventDefault() {}});
    await settle();
    const request = JSON.parse(destination.requests[1].options.body);
    assert.equal(request.page, 'contacts.php');
    assert.equal(request.history.at(-1).page, 'dashboard.php');
    assert.equal(destination.stored().messages.at(-1).page, 'contacts.php');
});

test('each question captures the selected record tab while retaining the prior tab in history', async () => {
    const page = createPage({ pageName:'view_engagement.php', pendingRequest:false, responses:[complete.response, complete.response] });
    for (const name of ['tasks', 'presentations']) {
        page.selectTab(name);
        page.question.value = 'What does this tab do?';
        page.fields.form.handlers.get('submit')[0]({preventDefault() {}});
        await settle();
        const request = JSON.parse(page.requests.at(-1).options.body);
        assert.equal(request.ui.active_tab, name);
        assert.equal(page.stored().messages.at(-1).activeTab, name);
        if (name === 'presentations') assert.ok(request.history.every(message => message.active_tab === 'tasks'));
    }
});

test('sidebar User Manual navigation closes the coach on every visit and preserves the conversation', async () => {
    let page = createPage({ responses: [complete] });
    await settle();
    const messages = page.stored().messages;
    for (let visit = 0; visit < 2; visit++) {
        page.opener.handlers.get('click')[0]();
        assert.equal(page.panel.hidden, false);
        page.manualLink.handlers.get('click')[0]({ button: 0 });
        assert.equal(page.panel.hidden, true);
        assert.equal(page.opener.getAttribute('aria-expanded'), 'false');
        assert.equal(page.stored().open, false);
        assert.deepEqual(page.stored().messages, messages);
        page.dispatch('pagehide', { persisted: false });
        page = createPage({ pageName: 'help.php', savedState: page.stored() });
        assert.equal(page.panel.hidden, true, 'The manual must not restore the open panel');
    }
    page.opener.handlers.get('click')[0]();
    assert.equal(page.panel.hidden, false, 'The coach can still be opened while reading the manual');
});

test('closing for User Manual navigation preserves a pending answer without reopening the panel', async () => {
    const page = createPage({ responses: [{ state: 'pending' }, complete] });
    await settle();
    page.opener.handlers.get('click')[0]();
    page.manualLink.handlers.get('click')[0]({ button: 0 });
    assert.equal(page.stored().pending.id, requestId);
    [...page.timers.values()][0]();
    await settle();
    assert.equal(page.assistantMessages().length, 1);
    assert.equal(page.stored().pending, null);
    assert.equal(page.panel.hidden, true);
});

test('modified or cancelled sidebar clicks do not close the coach in the current tab', () => {
    const page = createPage({ pendingRequest: false });
    page.opener.handlers.get('click')[0]();
    for (const event of [{ button: 0, ctrlKey: true }, { button: 0, metaKey: true }, { button: 0, shiftKey: true },
        { button: 0, altKey: true }, { button: 1 }, { button: 0, defaultPrevented: true }]) {
        page.manualLink.handlers.get('click')[0](event);
        assert.equal(page.panel.hidden, false);
    }
    const manual = createPage({ pageName: 'help.php', savedState: page.stored() });
    assert.equal(manual.panel.hidden, true, 'A new manual tab must not inherit the open coach');
});

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
