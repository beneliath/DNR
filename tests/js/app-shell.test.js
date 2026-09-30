'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/app-shell.js'), 'utf8');

function fixture(width = 390, saved = new Map(), storageFails = false, readyState = 'complete') {
    const events = {}, windowEvents = {};
    const doc = { readyState, activeElement: null, addEventListener(name, fn) { events[name] = fn; } };
    function node(name) {
        return { name, id: '', attrs: {}, children: [], events: {}, inert: false,
            classList: { values: new Set(), add(v) { this.values.add(v); }, remove(v) { this.values.delete(v); }, toggle(v, on) { on ? this.values.add(v) : this.values.delete(v); } },
            getAttribute(k) { return this.attrs[k] ?? null; }, setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; },
            addEventListener(k, fn) { this.events[k] = fn; }, focus() { doc.activeElement = this; },
            matches() { return false; }, closest() { return null; }, getClientRects() { return [1]; },
            contains(target) { return this === target || this.children.includes(target); },
            querySelector() { return null; }, querySelectorAll() { return this.children; }
        };
    }
    const body = node('body'), header = node('header'), sidebar = node('sidebar'), toggle = node('toggle');
    const close = node('close'), last = node('last'), backdrop = node('backdrop'), main = node('main'), footer = node('footer'), skip = node('skip');
    const desktopBrand = node('desktop-brand'), mobileBrand = node('mobile-brand');
    desktopBrand.href = mobileBrand.href = 'dashboard.php';
    const sections = ['work', 'schedule', 'relationships', 'administration'].map(name => {
        const section = node(name);
        section.attrs['data-nav-group'] = name;
        section.open = name !== 'administration';
        return section;
    });
    sidebar.attrs['data-nav-preference-user'] = '42';
    sidebar.attrs['data-nav-preference-cookie'] = 'dnr_sidebar_test';
    sidebar.children = [close, last];
    sidebar.querySelectorAll = selector => selector === '[data-nav-group]' ? sections : sidebar.children;
    header.children = [skip, toggle, sidebar, backdrop];
    body.children = [header, main, footer];
    footer.inert = true;
    doc.body = body;
    doc.getElementById = id => id === 'app-sidebar' ? sidebar : null;
    doc.querySelector = selector => ({ '[data-nav-toggle]': toggle, '[data-nav-close]': close, '[data-nav-backdrop]': backdrop, '.app-shell-header': header, 'main, [role="main"]': main, '[data-skip-link]': skip }[selector] || null);
    doc.querySelectorAll = selector => selector === '.app-brand, .mobile-brand' ? [desktopBrand, mobileBrand] : [];
    const win = { innerWidth: width, location: { assigned: null, assign(url) { this.assigned = url; } }, addEventListener(k, fn) { windowEvents[k] = fn; } };
    const localStorage = {
        getItem(key) { if (storageFails) throw new Error('unavailable'); return saved.get(key) ?? null; },
        setItem(key, value) { if (storageFails) throw new Error('unavailable'); saved.set(key, value); }
    };
    vm.runInNewContext(source, { document: doc, window: win, localStorage });
    return { sections, doc, sidebar, toggle, close, last, main, footer, skip, win, desktopBrand, mobileBrand,
        cookieState() { return decodeURIComponent(doc.cookie.split(';', 1)[0].split('=', 2)[1]); },
        domReady() { events.DOMContentLoaded(); },
        key(key, shiftKey = false) { const e = { key, shiftKey, prevented: false, preventDefault() { this.prevented = true; } }; events.keydown(e); return e; },
        resize(width) { win.innerWidth = width; windowEvents.resize(); }
    };
}

test('mobile drawer removes closed links and contains keyboard focus while open', () => {
    const f = fixture();
    assert.equal(f.sidebar.inert, true);
    f.toggle.events.click();
    assert.equal(f.sidebar.inert, false);
    assert.equal(f.sidebar.attrs['aria-modal'], 'true');
    assert.equal(f.main.inert, true);
    assert.equal(f.doc.activeElement, f.close);
    f.last.focus();
    assert.equal(f.key('Tab').prevented, true);
    assert.equal(f.doc.activeElement, f.close);
    f.key('Tab', true);
    assert.equal(f.doc.activeElement, f.last);
    f.key('Escape');
    assert.equal(f.sidebar.inert, true);
    assert.equal(f.doc.activeElement, f.toggle);
    assert.equal(f.main.inert, false);
    assert.equal(f.footer.inert, true, 'preserves elements that were already inert');
});

test('desktop resizing restores ordinary navigation and skip link targets main', () => {
    const f = fixture(860);
    f.toggle.events.click();
    f.resize(1200);
    assert.equal(f.sidebar.inert, false);
    assert.equal(f.main.inert, false);
    assert.equal(f.sidebar.attrs['aria-modal'], undefined);
    assert.equal(f.toggle.attrs['aria-expanded'], 'false');
    f.skip.events.click();
    assert.equal(f.doc.activeElement, f.main);
    assert.equal(f.skip.href, '#app-main');
    f.resize(390);
    assert.equal(f.sidebar.inert, true);
});


test('sidebar sections use defaults and restore user preferences across page loads', () => {
    const saved = new Map();
    const first = fixture(1200, saved);
    assert.deepEqual(first.sections.map(section => section.open), [true, true, true, false]);
    first.sections[0].open = false;
    first.sections[0].events.toggle();
    first.sections[3].open = true;
    first.sections[3].events.toggle();
    const next = fixture(1200, saved);
    assert.deepEqual(next.sections.map(section => section.open), [false, true, true, true]);
    assert.deepEqual(fixture(1200, saved, true).sections.map(section => section.open), [true, true, true, false]);
});

test('saved sidebar state restores while the document is still loading', () => {
    const saved = new Map([
        ['dnr.sidebar.42.work', 'closed'],
        ['dnr.sidebar.42.schedule', 'closed'],
        ['dnr.sidebar.42.relationships', 'closed'],
        ['dnr.sidebar.42.administration', 'open']
    ]);
    const f = fixture(1200, saved, false, 'loading');
    assert.deepEqual(f.sections.map(section => section.open), [false, false, false, true]);
    assert.equal(f.cookieState(), 'work=0,schedule=0,relationships=0,administration=1');
    f.domReady();
    assert.deepEqual(f.sections.map(section => section.open), [false, false, false, true]);
});

test('modified logo clicks collapse every sidebar group without navigating', () => {
    for (const [brand, modifier] of [['desktopBrand', 'metaKey'], ['mobileBrand', 'ctrlKey']]) {
        const saved = new Map();
        const f = fixture(1200, saved);
        f.sections.forEach(section => { section.open = true; });
        const event = { button: 0, [modifier]: true, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        f[brand].events.click(event);
        assert.equal(event.defaultPrevented, true);
        assert.equal(f.win.location.assigned, null);
        assert.deepEqual(f.sections.map(section => section.open), [false, false, false, false]);
        assert.equal(f.cookieState(), 'work=0,schedule=0,relationships=0,administration=0');
        assert.deepEqual(fixture(1200, saved).sections.map(section => section.open), [false, false, false, false]);
    }
    const normalClick = fixture(1200);
    const normalEvent = { button: 0, metaKey: false, ctrlKey: false, defaultPrevented: false };
    normalClick.desktopBrand.events.click(normalEvent);
    assert.equal(normalEvent.defaultPrevented, false);
    assert.equal(normalClick.desktopBrand.href, 'dashboard.php');
    assert.deepEqual(normalClick.sections.map(section => section.open), [true, true, true, false]);

    const macControlClick = fixture(1200);
    const contextEvent = { ctrlKey: true, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    macControlClick.desktopBrand.events.contextmenu(contextEvent);
    assert.equal(contextEvent.defaultPrevented, true);
    assert.equal(macControlClick.win.location.assigned, null);
    assert.deepEqual(macControlClick.sections.map(section => section.open), [false, false, false, false]);
});

test('sidebar link navigation saves the latest disclosure state before loading the next page', () => {
    const f = fixture(1200);
    f.sections[1].open = false;
    f.sidebar.events.click({ target: { closest(selector) { return selector === '.site-navigation a[href]' ? {} : null; } } });
    assert.equal(f.cookieState(), 'work=1,schedule=0,relationships=1,administration=0');
});
