'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { initialize, downloadKind } = require('../../src/assets/js/button-feedback.js');

function node(tag = 'BUTTON', text = 'Save', initial = {}) {
    const attrs = new Map(Object.entries(initial));
    const item = {
        tagName: tag, dataset: {}, disabled: false, name: 'action', value: 'save',
        childNodes: [{ textContent: text }], hidden: false,
        classList: { contains: name => (attrs.get('class') || '').split(' ').includes(name) },
        getAttribute: name => attrs.get(name) ?? null,
        setAttribute: (name, value) => attrs.set(name, String(value)),
        removeAttribute: name => attrs.delete(name), hasAttribute: name => attrs.has(name),
        replaceChildren(...children) { this.childNodes = children; },
        appendChild(child) { child.parentNode = this; this.childNodes.push(child); },
        remove() { if (this.parentNode) this.parentNode.childNodes = this.parentNode.childNodes.filter(child => child !== this); },
        closest() { return this; }, matches() { return true; }
    };
    Object.defineProperty(item, 'textContent', {
        get() { return this.childNodes.map(child => child.textContent).join(''); },
        set(value) { this.childNodes = [{ textContent: value }]; }
    });
    Object.defineProperty(item, 'href', {
        get() { return new URL(attrs.get('href'), 'https://example.test/accounts/demo/').href; },
        set(value) { attrs.set('href', value); }
    });
    Object.defineProperty(item, 'target', { configurable: true, get: () => attrs.get('target') || '' });
    return item;
}

function fixture() {
    const listeners = { document: {}, window: {} }, timers = new Map(), body = node('BODY');
    let timerId = 0, token = 0;
    const listen = surface => (type, callback, capture = false) => {
        (listeners[surface][type] ||= []).push({ callback, capture });
    };
    const doc = { body, cookie: '', createElement: tag => node(tag.toUpperCase(), ''), addEventListener: listen('document') };
    const win = {
        location: { href: 'https://example.test/accounts/demo/page.php' },
        crypto: { getRandomValues: bytes => bytes.fill(++token) },
        addEventListener: listen('window'),
        setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
        clearTimeout(id) { timers.delete(id); }
    };
    const api = initialize(doc, win);
    function emit(surface, type, target, extra = {}, capture = false) {
        const event = { target, button: 0, defaultPrevented: false,
            preventDefault() { this.defaultPrevented = true; },
            stopImmediatePropagation() { this.stopped = true; }, ...extra };
        for (const listener of listeners[surface][type] || []) {
            if (listener.capture === capture) listener.callback(event);
            if (event.stopped) break;
        }
        return event;
    }
    return { api, doc, win, body, timers, emit,
        tick() { const callbacks = [...timers.values()]; timers.clear(); callbacks.forEach(callback => callback()); },
        status: () => body.childNodes.at(-1)?.textContent,
    };
}
function form(button, attrs = { method: 'post' }) {
    return Object.assign(node('FORM', '', attrs), { elements: [button], querySelector: () => button });
}

test('busy feedback preserves submitter payloads, original nodes, and accessible state', () => {
    const page = fixture(), button = node('BUTTON', 'Save', { 'aria-label': 'Save contact' });
    const original = button.childNodes[0];
    const finish = page.api.begin(button, 'Saving…');
    assert.equal(button.textContent, 'Saving…');
    assert.equal(button.getAttribute('aria-busy'), 'true');
    assert.equal(button.getAttribute('aria-disabled'), 'true');
    assert.equal(button.disabled, false, 'named submitter stays in the native POST');
    assert.equal(button.value, 'save');
    assert.equal(page.emit('document', 'click', button, {}, true).defaultPrevented, true);
    finish();
    assert.equal(button.childNodes[0], original);
    assert.equal(button.getAttribute('aria-label'), 'Save contact');
    const secondFinish = page.api.begin(button, 'Saving Again…');
    finish();
    assert.equal(button.getAttribute('aria-busy'), 'true', 'stale completion cannot clear a new action');
    secondFinish();
    const input = node('INPUT');
    const restoreInput = page.api.begin(input, 'Working…');
    assert.equal(input.value, 'save'); restoreInput();
    const icon = node('BUTTON', '', { class: 'action-icon-button' });
    const child = icon.childNodes[0];
    page.api.begin(icon, 'Working…')();
    assert.equal(icon.childNodes[0], child);
});

test('confirmation cancellation stays usable; accepted native submissions block repeats and reset on return', () => {
    const page = fixture(), button = node(), owner = form(button);
    Object.defineProperty(owner, 'target', { value: node('INPUT', '', { name: 'target' }) }); // named controls can shadow DOM properties
    page.emit('window', 'submit', owner, { submitter: button, defaultPrevented: true });
    assert.equal(button.getAttribute('aria-busy'), null);
    page.emit('window', 'submit', owner, { submitter: button });
    assert.equal(button.textContent, 'Working…');
    assert.equal(page.timers.size, 0, 'a target field is not a new-tab target');
    assert.equal(page.emit('window', 'submit', owner, { submitter: button }, true).defaultPrevented, true);
    page.emit('window', 'pageshow', page.win);
    assert.equal(button.textContent, 'Save');
    assert.equal(owner.getAttribute('aria-busy'), null);
});

test('native uploads acknowledge files, while existing managed submissions retain their own feedback', () => {
    const page = fixture(), button = node(), owner = form(button);
    owner.elements.push({ type: 'file', files: [{}] });
    page.emit('window', 'submit', owner, { submitter: button });
    assert.equal(button.textContent, 'Uploading…');
    page.emit('window', 'pageshow', page.win);
    owner.setAttribute('aria-busy', 'true'); button.textContent = 'Creating Backup…';
    page.emit('window', 'submit', owner, { submitter: button });
    assert.equal(button.textContent, 'Creating Backup…');
    assert.equal(button.getAttribute('aria-busy'), null);
});

test('generated downloads stay busy until the server confirms response headers, then restore their URL', () => {
    const page = fixture(), link = node('A', 'Download ZIP', { href: 'download_reimbursement.php?id=3&format=zip' });
    const original = link.getAttribute('href');
    page.emit('window', 'click', link);
    const token = new URL(link.href).searchParams.get('_download_feedback');
    assert.match(token, /^[a-f0-9]{32}$/);
    assert.equal(link.textContent, 'Preparing Download…');
    assert.equal(page.emit('document', 'click', link, {}, true).defaultPrevented, true);
    page.tick(); assert.equal(link.getAttribute('aria-busy'), 'true');
    page.doc.cookie = 'dnr_download_' + token + '=started'; page.tick();
    assert.equal(link.getAttribute('href'), original);
    assert.equal(link.textContent, 'Download ZIP');
    assert.match(page.status(), /File ready.*Downloads or the opened tab/);
    assert.match(page.doc.cookie, /Path=\/accounts\/demo\//);
});

test('download errors and missing confirmations release the control with truthful retry guidance', () => {
    for (const result of ['failed', 'timeout']) {
        const page = fixture(), link = node('A', 'Download', { href: 'download_engagement_pdf.php?id=4' });
        page.emit('window', 'click', link);
        if (result === 'failed') {
            const token = new URL(link.href).searchParams.get('_download_feedback');
            page.doc.cookie = 'dnr_download_' + token + '=failed';
        }
        for (let i = 0; i < 362 && page.timers.size; i++) page.tick();
        assert.equal(link.getAttribute('aria-busy'), null);
        assert.equal(new URL(link.href).searchParams.has('_download_feedback'), false);
        assert.match(page.status(), result === 'failed' ? /could not be opened or downloaded/ : /confirmation was not received/);
    }
});

test('Back navigation restores a pending download; modified clicks retain native behavior', () => {
    const page = fixture(), link = node('A', 'Download', { href: 'download_engagement_pdf.php?id=4' });
    page.emit('window', 'click', link, { ctrlKey: true });
    assert.equal(link.getAttribute('aria-busy'), null);
    page.emit('window', 'click', link);
    page.emit('window', 'pageshow', page.win);
    assert.equal(link.getAttribute('href'), 'download_engagement_pdf.php?id=4');
    assert.equal(page.timers.size, 0);
    assert.equal(link.getAttribute('aria-disabled'), null);
});

test('static downloads and new-tab actions acknowledge activation without claiming completion', () => {
    const page = fixture(), link = node('A', 'Download Manual', { href: 'assets/manual.pdf', download: '' });
    page.emit('window', 'click', link);
    assert.match(page.status(), /Download requested/);
    assert.equal(link.textContent, 'Download Requested');
    page.tick(); assert.equal(link.textContent, 'Download Manual');
    const view = node('A', 'Manage Speakers', { href: 'speakers.php', target: '_blank', class: 'export-button' });
    page.emit('window', 'click', view);
    assert.equal(view.textContent, 'Opening…'); page.tick(); assert.equal(view.textContent, 'Manage Speakers');
});

test('PDF viewer links and simultaneous exports each clear feedback when their own response arrives', () => {
    const page = fixture();
    page.win.location.href = 'https://example.test/a/shalom-in-messiah/reimbursement_request.php';
    const links = ['download_reimbursement.php?format=pdf', 'download_reimbursement.php?format=zip',
        'presentation_pdf_view.php?id=2', 'presentation_asset.php?id=2&type=notes',
        'short_link_qr.php?code=x&download=1', 'inquiries.php?export=csv', 'ai_coach_improvements.php?export=1']
        .map(href => node('A', href, { href: 'https://example.test/a/shalom-in-messiah/' + href, target: '_blank' }));
    links.forEach(link => page.emit('window', 'click', link));
    links.forEach(link => {
        const token = new URL(link.href).searchParams.get('_download_feedback');
        page.doc.cookie = 'dnr_download_' + token + '=started';
        page.tick();
        assert.equal(link.getAttribute('aria-busy'), null);
        assert.equal(link.getAttribute('aria-disabled'), null);
        assert.equal(new URL(link.href).searchParams.has('_download_feedback'), false);
        assert.match(page.doc.cookie, /Path=\/a\/shalom-in-messiah\//);
    });
    assert.equal(page.timers.size, 0);
});

test('GET PDF forms preserve selections and reset on success, failure, timeout, and Back navigation', () => {
    for (const result of ['started', 'failed', 'timeout', 'back']) {
        const page = fixture(), button = node('BUTTON', 'Prepare PDF');
        const owner = form(button, { method: 'get', action: 'presentation_qr_pdf_view.php', target: '_blank' });
        const selection = { type: 'checkbox', checked: true, name: 'selected[]', value: 'notes:2' };
        owner.elements.push(selection);
        page.emit('window', 'submit', owner, { submitter: button });
        const field = owner.childNodes.find(child => child.name === '_download_feedback');
        assert.match(field.value, /^[a-f0-9]{32}$/);
        assert.equal(selection.checked, true);
        assert.equal(selection.value, 'notes:2');
        assert.equal(page.emit('window', 'submit', owner, { submitter: button }, true).defaultPrevented, true);
        if (result === 'back') page.emit('window', 'pageshow', page.win);
        else {
            if (result !== 'timeout') page.doc.cookie = 'dnr_download_' + field.value + '=' + result;
            for (let i = 0; i < 362 && page.timers.size; i++) page.tick();
        }
        assert.equal(button.textContent, 'Prepare PDF');
        assert.equal(button.getAttribute('aria-busy'), null);
        assert.equal(owner.getAttribute('aria-busy'), null);
        assert.equal(owner.childNodes.includes(field), false, 'temporary feedback field is removed');
        assert.equal(page.timers.size, 0);
    }
});

test('download detection covers app exports and ignores remote links', () => {
    for (const href of ['download_reimbursement.php?id=1', 'inquiries.php?export=csv', 'ai_coach_improvements.php?export=1',
        'short_link_qr.php?code=x&download=1', 'short_link.php?code=x&download=slidedeck',
        'presentation_asset.php?id=3&type=slidedeck', 'presentation_asset.php?id=3&type=notes',
        'presentation_pdf_view.php?id=3', 'presentation_qr_pdf_view.php?id=3', 'reimbursement_receipt.php?id=2',
        'calendar.php?token=x', 'reimbursement_submit.php?id=1&preview=package']) {
        assert.equal(downloadKind(node('A', '', { href }), 'https://example.test/accounts/demo/'), 'tracked', href);
    }
    assert.equal(downloadKind(node('A', '', { href: 'https://elsewhere.test/download_reimbursement.php' }), 'https://example.test/'), null);
});
