'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/audience-collection-embed.js'), 'utf8');

function popup(options = {}) {
    const now = 1_800_000_000_000, listeners = {}, docListeners = {}, timers = [], dialogs = [];
    const stored = new Map(Object.entries(options.storage || {}));
    stored.set('message-monkey:qa-popup:visitor', 'a'.repeat(64));
    const makeElement = tag => ({tag, style: {}, attrs: {}, children: [], events: {},
        setAttribute(k, v) { this.attrs[k] = v; }, append(...children) { this.children.push(...children); },
        addEventListener(k, fn) { this.events[k] = fn; }, showModal() { this.open = true; },
        close() { this.open = false; this.events.close?.(); }, remove() { dialogs.splice(dialogs.indexOf(this), 1); },
        focus() { this.focused = true; }
    });
    const config = {key:'qa-popup', kind:'popup', url:'https://forms.example/form?key=qa-popup',
        origins:['https://website.example'], popup:{device:'all', paths:[], exclude_paths:[], frequency:7, trigger:'delay', delay:5, ...options.rule}};
    const environment = {
        config, location:{origin:options.origin || 'https://website.example', pathname:options.path || '/qa'},
        document:{currentScript:{}, createElement:makeElement, querySelector:() => dialogs[0] || null,
            documentElement:{scrollHeight:4000}, addEventListener(k, fn) { docListeners[k] = fn; },
            body:{append(dialog) { dialogs.push(dialog); }}},
        window:{addEventListener(k, fn) { listeners[k] = fn; }},
        localStorage:{getItem:k => { if(options.blockStorage) throw new Error('Storage blocked'); return stored.get(k) || null; },
            setItem:(k,v) => { if(options.blockStorage) throw new Error('Storage blocked'); stored.set(k,v); }},
        crypto:{getRandomValues:bytes => bytes.fill(1)},
        matchMedia:() => ({matches:!!options.mobile}), Date:{now:() => now},
        setTimeout(fn, ms) { timers.push({fn,ms}); }, URL, scrollY:1600, innerHeight:500
    };
    vm.runInNewContext(`(function(){${source}})()`, environment);
    return {timers, dialogs, listeners, docListeners, stored, now,
        scrollTo(y) { environment.scrollY = y; listeners.scroll?.(); }};
}

test('popup only installs on its configured origin and eligible page/device', () => {
    for (const options of [
        {origin:'https://other.example'}, {mobile:true,rule:{device:'desktop'}},
        {rule:{device:'mobile'}}, {path:'/other',rule:{paths:['/qa']}},
        {path:'/qa/private',rule:{exclude_paths:['/qa/private']}}
    ]) assert.equal(popup(options).timers.length, 0);
    assert.equal(popup({mobile:true,rule:{device:'mobile',paths:['/qa']}}).timers.length, 1);
});

test('delay opens exactly once, keeps a sandboxed frame and focuses the close control', () => {
    const p = popup({rule:{delay:7}});
    assert.equal(p.timers[0].ms, 7000); p.timers[0].fn(); p.timers[0].fn();
    assert.equal(p.dialogs.length, 1);
    const [close, frame] = p.dialogs[0].children;
    assert.equal(close.attrs['aria-label'], 'Close signup form');
    assert.equal(close.focused, true);
    assert.match(frame.src, /visitor=a{64}$/);
    assert.match(frame.attrs.sandbox, /allow-forms/);
    assert.doesNotMatch(frame.attrs.sandbox, /allow-scripts/);
    close.events.click();
    assert.equal(p.dialogs.length, 0);
    assert.equal(p.stored.get('message-monkey:qa-popup:dismissed'), String(p.now));
});

test('dismissal frequency suppresses repeat visits until its configured interval', () => {
    const key = 'message-monkey:qa-popup:dismissed', now = 1_800_000_000_000;
    assert.equal(popup({storage:{[key]:String(now - 6 * 86400000)}}).timers.length, 0);
    assert.equal(popup({storage:{[key]:String(now - 7 * 86400000)}}).timers.length, 1);
});

test('exit trigger rejects ordinary mouse transitions and does not run on mobile', () => {
    const p = popup({rule:{trigger:'exit'}});
    assert.equal(p.timers.length, 0);
    p.docListeners.mouseout({relatedTarget:{},clientY:0});
    p.docListeners.mouseout({relatedTarget:null,clientY:50});
    assert.equal(p.dialogs.length, 0);
    p.docListeners.mouseout({relatedTarget:null,clientY:0});
    assert.equal(p.dialogs.length, 1);
    assert.equal(popup({mobile:true,rule:{trigger:'exit'}}).docListeners.mouseout, undefined);
});

test('scroll trigger opens at the page threshold and never duplicates the popup', () => {
    const p = popup({rule:{trigger:'scroll'}});
    assert.equal(p.timers.length, 0);
    p.scrollTo(0); assert.equal(p.dialogs.length, 0);
    p.scrollTo(1499); assert.equal(p.dialogs.length, 0);
    p.scrollTo(1500); p.scrollTo(1600);
    assert.equal(p.dialogs.length, 1);
});

test('blocked browser storage does not prevent a visitor from opening or closing signup', () => {
    const p = popup({blockStorage:true});
    p.timers[0].fn();
    assert.equal(p.dialogs.length, 1);
    p.dialogs[0].children[0].events.click();
    assert.equal(p.dialogs.length, 0);
});
