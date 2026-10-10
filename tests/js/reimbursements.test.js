'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('./helpers/account-context.js');
const source = fs.readFileSync(require.resolve('../../src/assets/js/reimbursements.js'), 'utf8');
test('pipeline emphasis follows sticky position, responsive offsets, and history restoration', () => {
  let position = 200, stickyTop = '12px', scrollContainerTop = 52, scrollContainerPadding = '0px';
  const classes = new Set(), frames = [], events = {};
  const scrollContainer = {getBoundingClientRect: () => ({top: scrollContainerTop}), clientTop: 0};
  const progress = {
    parentElement: scrollContainer,
    getBoundingClientRect: () => ({top: position}),
    classList: {toggle(name, active) { if (active) classes.add(name); else classes.delete(name); }}
  };
  vm.runInNewContext(source, {
    document: {
      querySelectorAll: selector => selector === '.reimbursement-progress' ? [progress] : [],
      addEventListener(name, callback, options) { events[name] = callback; assert.equal(options.capture, true); }
    },
    window: {
      getComputedStyle: element => element === progress ? {top: stickyTop} : {overflowY: 'auto', paddingTop: scrollContainerPadding},
      requestAnimationFrame(callback) { frames.push(callback); },
      addEventListener(name, callback) { events[name] = callback; }
    }
  });
  assert.equal(classes.has('is-stuck'), false);
  position = 64;
  events.scroll(); events.scroll();
  assert.equal(frames.length, 1);
  frames.shift()();
  assert.equal(classes.has('is-stuck'), true);
  position = 100;
  events.scroll(); frames.shift()();
  assert.equal(classes.has('is-stuck'), false);
  position = 88; scrollContainerPadding = '24px';
  events.scroll(); frames.shift()();
  assert.equal(classes.has('is-stuck'), true);
  scrollContainerPadding = '0px';
  position = 120; stickyTop = '68px';
  events.resize(); frames.shift()();
  assert.equal(classes.has('is-stuck'), true);
  scrollContainerTop = 0; position = 68;
  events.resize(); frames.shift()();
  assert.equal(classes.has('is-stuck'), true);
  position = 200;
  events.pageshow(); frames.shift()();
  assert.equal(classes.has('is-stuck'), false);
});

function page(storage, ids, {scope = 'range', ineligible = [], clearOnSuccess = false, checked = [], available = []} = {}) {
  const node = () => ({dataset: {}, events: {}, addEventListener(name, fn) { this.events[name] = fn; }});
  const boxes = ids.map(value => Object.assign(node(), {value, checked: checked.includes(value)}));
  const hidden = [];
  const count = {parentElement: {hidden: true}};
  const lowerCount = {parentElement: {hidden: true}};
  const total = {};
  const lowerTotal = {};
  const clear = node();
  const lowerClear = node();
  const selectAll = node();
  const lowerSelectAll = node();
  const error = {hidden: true};
  const lowerError = {hidden: true};
  const buttons = [{}, {}];
  const form = Object.assign(node(), {dataset: {selectionKey: 'user', selectionScope: scope, availableUrl: '/available'},
    querySelector(selector) { return selector === '[data-selection-count]' ? count : clear; },
    querySelectorAll(selector) {
      if (selector === '[data-selection-count]') return [count, lowerCount];
      if (selector === '[data-selection-total]') return [total, lowerTotal];
      if (selector === '[data-clear-selection]') return [clear, lowerClear];
      if (selector === '[data-select-all-available]') return [selectAll, lowerSelectAll];
      if (selector === '[data-selection-error]') return [error, lowerError];
      if (selector === 'input[name="expense_ids[]"]') return boxes;
      if (selector === '[data-off-page-selection]') return [...hidden];
      return ids.concat(ineligible).map(id => ({dataset: {selectionExpense: id}, querySelector: () => boxes.find(box => box.value === id)}));
    },
    appendChild(input) { hidden.push(input); input.remove = () => hidden.splice(hidden.indexOf(input), 1); }
  });
  const events = {};
  vm.runInNewContext(source, {sessionStorage: storage, fetch: async () => ({ok: true, json: async () => available}), window: {addEventListener(name, fn) { events[name] = fn; }}, document: {
    querySelectorAll(selector) {
      if (selector === '[data-clear-reimbursement-selection]') return clearOnSuccess ? [{dataset: {clearReimbursementSelection: 'user'}}] : [];
      if (selector === '[data-reimbursement-selection]') return [form];
      if (selector === '[data-create-reimbursement]') return buttons;
      return [];
    }, createElement: () => node()
  }});
  return {boxes, hidden, count, lowerCount, total, lowerTotal, clear, lowerClear, selectAll, lowerSelectAll, error, lowerError, buttons, events,
    select(id, value = true) { const box = boxes.find(box => box.value === id); box.checked = value; box.events.change(); }};
}
function storage() {
  const map = new Map();
  return {getItem: key => map.get(key) || null, setItem: (key, value) => map.set(key, value), removeItem: key => map.delete(key)};
}
test('selection persists across pagination and sorting; submits visible and off-page IDs', () => {
  const state = storage();
  const first = page(state, ['1', '2']);
  assert.ok(first.buttons.every(button => button.disabled));
  first.select('2');
  const second = page(state, ['3', '4']);
  assert.equal(second.count.textContent, '1 expense selected (1 on other pages)');
  assert.deepEqual(second.hidden.map(input => input.value), ['2']);
  second.select('3');
  assert.equal(second.lowerCount.textContent, second.count.textContent);
  assert.equal(second.lowerClear.hidden, false);
  const sorted = page(state, ['3', '2', '1']);
  assert.deepEqual(sorted.boxes.filter(box => box.checked).map(box => box.value), ['3', '2']);
  assert.equal(sorted.hidden.length, 0);
  sorted.lowerClear.events.click();
  assert.equal(sorted.lowerCount.textContent, '0 expenses selected');
  assert.equal(sorted.count.textContent, '0 expenses selected');
  assert.equal(sorted.clear.hidden, true);
  assert.equal(sorted.lowerClear.hidden, true);
  assert.ok(sorted.buttons.every(button => button.disabled));
  assert.ok(page(state, ['1', '2']).boxes.every(box => !box.checked));
});
test('filter changes, claimed expenses, and successful creation remove stale selections', () => {
  const state = storage();
  page(state, ['1']).select('1');
  assert.equal(page(state, [], {ineligible: ['1']}).hidden.length, 0);
  page(state, ['1']).select('1');
  assert.equal(page(state, ['2'], {scope: 'other-owner'}).hidden.length, 0);
  const original = page(state, ['1']); original.select('1');
  page(state, [], {clearOnSuccess: true});
  original.events.pageshow({persisted: true});
  assert.equal(original.boxes[0].checked, false);
  assert.equal(original.buttons[0].disabled, true);
});
test('unavailable browser storage still permits current-page selection and preserves failed POST values', () => {
  const blocked = {getItem() { throw new Error(); }, setItem() { throw new Error(); }};
  const form = page(blocked, ['1', '2'], {checked: ['1']});
  assert.equal(form.count.textContent, '1 expense selected');
  form.select('2');
  assert.equal(form.count.textContent, '2 expenses selected');
  assert.equal(form.buttons[0].disabled, false);
});
test('Select All Available includes eligible expenses on other pages and updates totals', async () => {
  const state = storage();
  const first = page(state, ['1', '2'], {available: {expenses: [
    {id: 1, amount_cents: 4009, receipt_count: 1},
    {id: 2, amount_cents: 6177, receipt_count: 0},
    {id: 3, amount_cents: 2399, receipt_count: 1}
  ]}});
  await first.selectAll.events.click();
  assert.deepEqual(first.boxes.map(box => box.checked), [true, true]);
  assert.deepEqual(first.hidden.map(input => input.value), ['3']);
  assert.equal(first.count.textContent, '3 expenses selected (1 on other pages)');
  assert.match(first.total.textContent, /Selected Total: \$125\.85 · 1 Without Receipts/);
  assert.equal(first.lowerTotal.textContent, first.total.textContent);
  assert.equal(first.lowerSelectAll.disabled, false);
  assert.equal(first.lowerClear.hidden, false);
  assert.equal(first.hidden.length, 1);
  const next = page(state, ['3']);
  assert.equal(next.boxes[0].checked, true);
  assert.equal(next.count.textContent, '3 expenses selected (2 on other pages)');
});
test('Select All Available leaves selection intact when more than 500 expenses match', async () => {
  const state = storage();
  const ui = page(state, ['1'], {available: {too_many: true, expenses: []}});
  ui.select('1');
  await ui.lowerSelectAll.events.click();
  assert.equal(ui.boxes[0].checked, true);
  assert.match(ui.error.textContent, /More than 500/);
  assert.equal(ui.error.hidden, false);
  ui.clear.events.click();
  assert.equal(ui.error.hidden, true);
});

test('Update Dates blinks only while the request dates differ from their loaded values', () => {
  const fields = [{value: '2026-07-01'}, {value: '2026-09-29'}];
  const events = {};
  let blinking = false;
  const button = {classList: {toggle(name, active) {
    assert.equal(name, 'reimbursement-apply-reminder');
    blinking = active;
  }}};
  const form = {
    querySelectorAll: () => fields,
    querySelector: () => button,
    addEventListener(name, handler) { events[name] = handler; }
  };
  vm.runInNewContext(source, {document: {querySelectorAll(selector) {
    return selector === '[data-reimbursement-filters], [data-reimbursement-date-range], [data-reimbursement-note]' ? [form] : [];
  }}});
  fields[0].value = '2026-07-02'; events.input();
  assert.equal(blinking, true);
  fields[1].value = '2026-09-30'; events.change();
  fields[0].value = '2026-07-01'; events.input();
  assert.equal(blinking, true);
  fields[1].value = '2026-09-29'; events.change();
  assert.equal(blinking, false);
});

test('Save Note blinks only while the note differs from its loaded value', () => {
  const note = {value: 'Please review this receipt.'};
  const events = {};
  let blinking = false;
  const button = {classList: {toggle(name, active) {
    assert.equal(name, 'reimbursement-apply-reminder');
    blinking = active;
  }}};
  const form = {
    querySelectorAll: () => [note],
    querySelector: () => button,
    addEventListener(name, handler) { events[name] = handler; }
  };
  vm.runInNewContext(source, {document: {querySelectorAll(selector) {
    return selector === '[data-reimbursement-filters], [data-reimbursement-date-range], [data-reimbursement-note]' ? [form] : [];
  }}});
  note.value = 'Please review this receipt and call me.'; events.input();
  assert.equal(blinking, true);
  note.value = 'Please review this receipt.'; events.input();
  assert.equal(blinking, false);
});

function receiptUpload(clipboard = null) {
  const node = () => ({events: {}, textContent: '', classList: {toggle() {}, add() {}, remove() {}}, addEventListener(name, fn) {this.events[name] = fn;}});
  const input = Object.assign(node(), {files: [{name:'existing.pdf', type:'application/pdf', size:12}]});
  const selected = node(), paste = Object.assign(node(), {setAttribute() {}}), status = node(), button = node();
  const pasteBox = {hidden:true};
  const pasteTarget = {value:'', focus(){this.focused=true;}};
  const zone = Object.assign(node(), {querySelector: selector => selector === '[data-receipt-drop-button]' ? button : status});
  const upload = Object.assign(node(), {querySelector: selector => ({'[data-receipt-drop]':zone, '[data-receipt-files]':input, '[data-receipt-selected]':selected, '[data-receipt-paste]':paste, '[data-receipt-drop-status]':status, '[data-receipt-paste-box]':pasteBox, '[data-receipt-paste-target]':pasteTarget}[selector] || null)});
  class Transfer {constructor() {this.files=[]; this.items={add: file => this.files.push(file)};}}
  class ImageFile {constructor(parts,name,options) {this.name=name;this.type=options.type;this.size=parts[0].size;}}
  vm.runInNewContext(source, {navigator:{clipboard}, DataTransfer:Transfer, File:ImageFile, document:{querySelectorAll: selector => selector === '[data-receipt-upload]' ? [upload] : []}});
  return {input,paste,upload,status,pasteBox,pasteTarget};
}
test('clipboard image button appends a receipt and preserves selected uploads', async () => {
  const ui = receiptUpload({read: async () => [{types:['image/png'],getType:async () => ({type:'image/png',size:123})}]});
  await ui.paste.events.click();
  assert.equal(ui.input.files.length,2);
  assert.match(ui.input.files[1].name,/\.png$/);
  assert.match(ui.status.textContent,/Save expense/);
});
test('keyboard paste works without clipboard API and rejects oversized images', async () => {
  const ui = receiptUpload();
  await ui.paste.events.click(); assert.match(ui.status.textContent,/Command\+V/);
  let prevented=false;
  ui.upload.events.paste({clipboardData:{files:[{type:'image/jpeg',size:234}]},preventDefault(){prevented=true;}});
  assert.equal(prevented,true); assert.equal(ui.input.files.length,2);
  ui.upload.events.paste({clipboardData:{files:[{type:'image/png',size:16*1024*1024}]},preventDefault(){}});
  assert.equal(ui.input.files.length,2); assert.match(ui.status.textContent,/15 MB/);
});

test('denied clipboard reads focus the keyboard fallback and paste accepts clipboard items', async () => {
  const ui = receiptUpload({read: async () => {throw new Error('NotAllowedError');}});
  await ui.paste.events.click();
  assert.equal(ui.pasteBox.hidden,false); assert.equal(ui.pasteTarget.focused,true);
  assert.match(ui.status.textContent,/paste box/);
  ui.upload.events.paste({target:ui.pasteTarget,clipboardData:{files:[],items:[{kind:'file',getAsFile:()=>({type:'image/png',size:10})}]},preventDefault(){}});
  assert.equal(ui.input.files.length,2); assert.match(ui.status.textContent,/Image added/);
});
test('keyboard paste during a pending browser prompt cannot add the image twice', async () => {
  let finish;
  const ui = receiptUpload({read: () => new Promise(resolve=>{finish=resolve;})});
  const pending=ui.paste.events.click();
  assert.match(ui.status.textContent,/browser’s prompt/);
  ui.upload.events.paste({target:ui.pasteTarget,clipboardData:{files:[{type:'image/png',size:10}]},preventDefault(){}});
  finish([{types:['image/png'],getType:async()=>({type:'image/png',size:10})}]);
  await pending;
  assert.equal(ui.input.files.length,2);
});

test('native picker appends to staged files and rejects aggregate overflow without losing earlier receipts', () => {
  const ui=receiptUpload();
  ui.input.files=[{name:'second.pdf',type:'application/pdf',size:100}];
  ui.input.events.change();
  assert.equal(ui.input.files.length,2);
  ui.input.files=[{name:'large.png',type:'image/png',size:15*1024*1024}];
  ui.input.events.change();
  assert.equal(ui.input.files.length,2);
  assert.match(ui.status.textContent,/15 MB total/);
});
test('picker file count limit preserves the current valid selection', () => {
  const ui=receiptUpload();
  ui.input.files=Array.from({length:20},(_,i)=>({name:`receipt-${i}.pdf`,type:'application/pdf',size:10}));
  ui.input.events.change();
  assert.equal(ui.input.files.length,1);
  assert.match(ui.status.textContent,/at most 20/);
});

function reviewFeedback(link = false, submitting = false) {
  const node = () => ({events: {}, attributes: {}, addEventListener(name, fn) { this.events[name] = fn; },
    setAttribute(name, value) { this.attributes[name] = value; }, removeAttribute(name) { delete this.attributes[name]; }});
  const form = link ? null : node();
  const button = Object.assign(node(), {form, name: 'continue_to_review', value: '1', textContent: submitting ? 'Submit Reimbursement Request' : 'Review and Submit', dataset: submitting ? {submittingLabel: 'Submitting Request…'} : {}});
  const status = {hidden: true};
  const window = node();
  vm.runInNewContext(source, {window, document: {
    querySelectorAll: selector => selector === '[data-reimbursement-progress]' ? [button] : [],
    querySelector: () => status
  }});
  const event = values => Object.assign({defaultPrevented: false, preventDefault() { this.defaultPrevented = true; }}, values);
  return {form, button, status, window, event};
}
test('review submission shows progress without dropping named submitter; blocks repeats and resets on Back', () => {
  const ui = reviewFeedback();
  ui.form.events.submit(ui.event({submitter: {name: 'save_note'}}));
  assert.equal(ui.status.hidden, true);
  ui.form.events.submit(ui.event({submitter: ui.button, defaultPrevented: true}));
  assert.equal(ui.status.hidden, true);
  ui.form.events.submit(ui.event({submitter: ui.button}));
  assert.equal(ui.status.hidden, false);
  assert.equal(ui.button.textContent, 'Preparing Review…');
  assert.equal(ui.button.attributes['aria-busy'], 'true');
  assert.ok(!ui.button.disabled);
  assert.equal(ui.button.name, 'continue_to_review');
  assert.equal(ui.button.value, '1');
  const repeated = ui.event({submitter: ui.button});
  ui.form.events.submit(repeated);
  assert.equal(repeated.defaultPrevented, true);
  ui.window.events.pageshow();
  assert.equal(ui.status.hidden, true);
  assert.equal(ui.button.textContent, 'Review and Submit');
  assert.equal(ui.button.attributes['aria-disabled'], undefined);
  const retry = ui.event({submitter: ui.button});
  ui.form.events.submit(retry);
  assert.equal(retry.defaultPrevented, false);
});
test('review link preserves modified clicks and shows feedback for ordinary navigation', () => {
  const ui = reviewFeedback(true);
  ui.button.events.click(ui.event({button: 0, ctrlKey: true}));
  assert.equal(ui.status.hidden, true);
  ui.button.events.click(ui.event({button: 0}));
  assert.equal(ui.status.hidden, false);
  const repeated = ui.event({button: 0});
  ui.button.events.click(repeated);
  assert.equal(repeated.defaultPrevented, true);
});

test('final submission shows its own busy label, blocks duplicates, and resets without bypassing size restrictions', () => {
  const ui = reviewFeedback(false, true);
  ui.button.disabled = true;
  ui.form.events.submit(ui.event({submitter: ui.button}));
  assert.equal(ui.status.hidden, true);
  ui.window.events.pageshow();
  assert.equal(ui.button.disabled, true);
  ui.button.disabled = false;
  ui.form.events.submit(ui.event({submitter: ui.button, defaultPrevented: true}));
  assert.equal(ui.status.hidden, true);
  ui.form.events.submit(ui.event({submitter: ui.button}));
  assert.equal(ui.button.textContent, 'Submitting Request…');
  assert.equal(ui.status.hidden, false);
  assert.equal(ui.form.attributes['aria-busy'], 'true');
  const repeat = ui.event({submitter: ui.button});
  ui.form.events.submit(repeat);
  assert.equal(repeat.defaultPrevented, true);
  ui.window.events.pageshow();
  assert.equal(ui.status.hidden, true);
  assert.equal(ui.form.attributes['aria-busy'], undefined);
  assert.equal(ui.button.textContent, 'Submit Reimbursement Request');
});
