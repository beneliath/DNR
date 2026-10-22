'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/audience-collection-embed.js'), 'utf8');
function fixture(overrides = {}, origin = 'https://website.example') {
    let inserted = false;
    const listeners = {}, frame = {style: {}, attrs: {}, contentWindow: {}, setAttribute(k,v) { this.attrs[k] = v; }};
    const config = {kind:'embedded', key:'form-key', url:'https://forms.example/form?key=form-key', origins:['https://website.example'], popup:{}, javascript:true, ...overrides};
    vm.runInNewContext(`(function(){${source}})()`, {
        config, location:{origin}, document:{currentScript:{insertAdjacentElement() { inserted = true; }},createElement:() => frame},
        localStorage:{getItem:()=>'a'.repeat(64)}, window:{addEventListener(type,fn) { listeners[type] = fn; }}, URL
    });
    return {inserted, frame, message:listeners.message};
}
test('embedded forms install only on configured website origins', () => {
    assert.equal(fixture().inserted, true);
    assert.equal(fixture({},'https://other.example').inserted, false);
});
test('JavaScript-free embeds keep scripts outside the sandbox', () => {
    assert.match(fixture().frame.attrs.sandbox, /allow-scripts/);
    assert.doesNotMatch(fixture({javascript:false}).frame.attrs.sandbox, /allow-scripts/);
});
test('only a matching form frame and origin can resize an embed', () => {
    const f = fixture();
    const valid = {origin:'https://forms.example',source:f.frame.contentWindow,data:{type:'message-monkey-form-height',key:'form-key',height:420}};
    for(const invalid of [{origin:'https://evil.example'}, {source:{}}, {data:{...valid.data,key:'another-form'}}, {data:{...valid.data,type:'other'}}]) {
        f.message({...valid,...invalid}); assert.equal(f.frame.style.height, undefined);
    }
    f.message(valid); assert.equal(f.frame.style.height,'420px');
});
test('invalid and unbounded embed heights cannot alter the iframe', () => {
    const f = fixture();
    for(const height of [-1,0,99,20001,Infinity,100.5,'420']) {
        f.message({origin:'https://forms.example',source:f.frame.contentWindow,data:{type:'message-monkey-form-height',key:'form-key',height}});
        assert.equal(f.frame.style.height,undefined);
    }
});
