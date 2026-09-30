'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/page-actions.js'), 'utf8');
const code = source.slice(source.indexOf('    function initializeIconTooltips()'), source.indexOf('    function initialize()'));
function icon(attributes) {
  return {nodeType:1, textContent:'', attributes,
    matches:()=>true, querySelectorAll:()=>[],
    getAttribute(name){return this.attributes[name] ?? null;},
    setAttribute(name,value){this.attributes[name]=value;},
    removeAttribute(name){delete this.attributes[name];}
  };
}
test('themed icon tooltips remove native titles and preserve accessible names for existing and dynamic controls', () => {
  const existing=icon({title:'Edit', 'data-tooltip':'Edit', 'aria-label':'Edit expense'});
  let observe;
  vm.runInNewContext(code+'initializeIconTooltips();', {document:{body:{},querySelectorAll:()=>[existing]},MutationObserver:class {constructor(fn){observe=fn;} observe(){}}});
  assert.equal(existing.getAttribute('title'),null);
  assert.equal(existing.getAttribute('aria-label'),'Edit expense');
  const added=icon({title:'Delete'});
  observe([{type:'childList',addedNodes:[added]}]);
  assert.equal(added.getAttribute('title'),null); assert.equal(added.getAttribute('data-tooltip'),'Delete'); assert.equal(added.getAttribute('aria-label'),'Delete');
  existing.setAttribute('title','Copied');existing.setAttribute('data-tooltip','Copied');
  observe([{type:'attributes',target:existing}]);
  assert.equal(existing.getAttribute('title'),null); assert.equal(existing.getAttribute('data-tooltip'),'Copied');
});
