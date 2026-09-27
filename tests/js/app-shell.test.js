'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/app-shell.js'), 'utf8');

function fixture(width = 390, saved = new Map(), storageFails = false) {
    const events = {}, windowEvents = {};
    const doc = { readyState: 'complete', activeElement: null, addEventListener(name, fn) { events[name] = fn; } };
    function node(name) {
        return { name, id: '', attrs: {}, children: [], events: {}, inert: false,
            classList: { values: new Set(), add(v) { this.values.add(v); }, toggle(v, on) { on ? this.values.add(v) : this.values.delete(v); } },
            getAttribute(k) { return this.attrs[k] ?? null; }, setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; },
            addEventListener(k, fn) { this.events[k] = fn; }, focus() { doc.activeElement = this; },
            matches() { return false; }, closest() { return null; }, getClientRects() { return [1]; },
            contains(target) { return this === target || this.children.includes(target); },
            querySelector() { return null; }, querySelectorAll() { return this.children; }
        };
    }
    const body = node('body'), header = node('header'), sidebar = node('sidebar'), toggle = node('toggle');
    const close = node('close'), last = node('last'), backdrop = node('backdrop'), main = node('main'), footer = node('footer'), skip = node('skip');
    const sections = ['work', 'schedule', 'relationships', 'administration'].map(name => {
        const section = node(name);
        section.attrs['data-nav-group'] = name;
        section.open = name !== 'administration';
        return section;
    });
    sidebar.attrs['data-nav-preference-user'] = '42';
    sidebar.children = [close, last];
    sidebar.querySelectorAll = selector => selector === '[data-nav-group]' ? sections : sidebar.children;
    header.children = [skip, toggle, sidebar, backdrop];
    body.children = [header, main, footer];
    footer.inert = true;
    doc.body = body;
    doc.getElementById = id => id === 'app-sidebar' ? sidebar : null;
    doc.querySelector = selector => ({ '[data-nav-toggle]': toggle, '[data-nav-close]': close, '[data-nav-backdrop]': backdrop, '.app-shell-header': header, 'main, [role="main"]': main, '[data-skip-link]': skip }[selector] || null);
    const win = { innerWidth: width, addEventListener(k, fn) { windowEvents[k] = fn; } };
    const localStorage = {
        getItem(key) { if (storageFails) throw new Error('unavailable'); return saved.get(key) ?? null; },
        setItem(key, value) { if (storageFails) throw new Error('unavailable'); saved.set(key, value); }
    };
    vm.runInNewContext(source, { document: doc, window: win, localStorage });
    return { sections, doc, sidebar, toggle, close, last, main, footer, skip, win,
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
