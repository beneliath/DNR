'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const { sendChunks, CHUNK_BYTES } = require('../../src/assets/js/presentation-upload.js');
function fakeFile(size) { return { size, slice: (start, end) => ({ start, size: end - start }) }; }
test('331 MB transfer uses bounded requests and reports completion', async () => {
    const file = fakeFile(331 * 1024 * 1024);
    const state = { offset: 0 };
    const requests = [];
    const progress = [];
    await sendChunks(file, state, async (chunk, offset, report) => {
        requests.push(chunk);
        assert.equal(chunk.start, offset);
        assert.ok(chunk.size <= CHUNK_BYTES);
        report(chunk.size);
        return { offset: offset + chunk.size };
    }, value => progress.push(value));
    assert.equal(requests.length, 34);
    assert.equal(state.offset, file.size);
    assert.equal(progress.at(-1), file.size);
});
test('lost responses retry the same chunk without advancing twice', async () => {
    const state = { offset: 0 };
    const offsets = [];
    await sendChunks(fakeFile(CHUNK_BYTES + 1), state, async (chunk, offset) => {
        offsets.push(offset);
        if (offsets.length === 1) throw Object.assign(new Error('network'), { retryable: true });
        return { offset: offset + chunk.size };
    }, () => {});
    assert.deepEqual(offsets, [0, 0, CHUNK_BYTES]);
});
test('failure preserves acknowledged offset for another Save Changes attempt', async () => {
    const state = { offset: 0 };
    const file = fakeFile(CHUNK_BYTES + 1);
    await assert.rejects(sendChunks(file, state, async (chunk, offset) => {
        if (offset) throw new Error('expired session');
        return { offset: CHUNK_BYTES };
    }, () => {}), /expired session/);
    assert.equal(state.offset, CHUNK_BYTES);
    await sendChunks(file, state, async (chunk, offset) => {
        assert.equal(offset, CHUNK_BYTES);
        return { offset: offset + chunk.size };
    }, () => {});
    assert.equal(state.offset, file.size);
});
test('invalid acknowledgements cannot mark an upload complete', async () => {
    await assert.rejects(sendChunks(fakeFile(1), { offset: 0 }, async () => ({ offset: 2 }), () => {}), /confirmed/);
});

const fs = require('node:fs');
const vm = require('node:vm');
function uploadPage() {
    const listeners = {};
    const requests = [];
    function element(tag) {
        return { tag, children: [], attributes: {}, hidden: false,
            addEventListener() {},
            setAttribute(key, value) { this.attributes[key] = value; },
            removeAttribute(key) { delete this.attributes[key]; },
            append(...items) { this.children.push(...items); },
            appendChild(item) { this.children.push(item); },
            remove() { this.removed = true; }
        };
    }
    const csrf = { name: 'csrf_token', value: 'csrf', disabled: false };
    const file = fakeFile(12 * 1024 * 1024); file.name = 'talk.pptx';
    const input = { name: 'presentations[7][ppt_slidedeck]', type: 'file', files: [file], disabled: false };
    const button = { name: 'save_engagement', value: '1', disabled: false };
    const form = element('form');
    form.dataset = { chunkEngagement: '2' };
    form.elements = [csrf, input, button];
    form.elements.namedItem = name => form.elements.find(item => item.name === name);
    function submit(submitter = button) {
        const event = { target: form, submitter, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
        const completion = listeners.submit(event);
        return { event, completion };
    }
    let saved = null;
    form.requestSubmit = submitter => {
        const { event } = submit(submitter);
        if (!event.defaultPrevented) saved = {
            submitter, filesExcluded: input.disabled,
            tokens: form.children.filter(item => !item.removed).map(item => [item.name, item.value]),
            csrfIncluded: !csrf.disabled
        };
    };
    const body = element('body');
    const document = {
        addEventListener(name, callback) { listeners[name] = callback; },
        querySelector() { return form; },
        createElement: element, body
    };
    class Data { constructor() { this.fields = {}; } append(key, value) { this.fields[key] = value; } }
    class Xhr {
        constructor() { this.upload = {}; }
        open() {}
        send(data) {
            const fields = data.fields;
            requests.push(fields);
            this.status = 200;
            this.responseText = JSON.stringify(fields.action === 'start' ? { token: 'a'.repeat(64) }
                : { offset: fields.offset + fields.chunk.size });
            this.upload.onprogress?.({ loaded: fields.chunk?.size || 0 });
            this.onload();
        }
    }
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/presentation-upload.js'), 'utf8'), {
        document, window: { addEventListener() {} }, FormData: Data, XMLHttpRequest: Xhr, setTimeout
    });
    listeners.DOMContentLoaded();
    return { submit, form, input, button, requests, body, saved: () => saved, listeners };
}
test('Save Changes uploads files, displays progress, then posts tokens without original bytes', async () => {
    const page = uploadPage();
    await page.submit().completion;
    assert.deepEqual(page.requests.map(item => item.action), ['start', 'chunk', 'chunk']);
    assert.ok(page.requests.every(item => item.csrf_token === 'csrf'));
    assert.equal(page.saved().filesExcluded, true);
    assert.equal(page.saved().csrfIncluded, true);
    assert.equal(page.saved().submitter, page.button);
    assert.deepEqual(page.saved().tokens, [['presentations[7][ppt_upload_token]', 'a'.repeat(64)]]);
    assert.equal(page.body.children[0].hidden, false);
    assert.match(page.body.children[0].children[0].textContent, /Saving changes/);
    assert.equal(page.button.disabled, true);
    assert.equal(page.submit().event.defaultPrevented, true, 'Duplicate save is blocked');
});
test('existing validation failure never starts an upload', async () => {
    const page = uploadPage();
    await page.listeners.submit({ target: page.form, defaultPrevented: true });
    assert.equal(page.requests.length, 0);
    assert.equal(page.saved(), null);
});
test('a canceled final submission restores controls and retains completed chunks for retry', async () => {
    const page = uploadPage();
    page.form.requestSubmit = () => {};
    await page.submit().completion;
    assert.equal(page.button.disabled, false);
    assert.equal(page.input.disabled, false);
    assert.match(page.body.children[0].children[0].textContent, /Check the form/);
    await page.submit().completion;
    assert.equal(page.requests.length, 3, 'Already uploaded file is reused');
});

test('PDFs use the same transfer UI and a separate typed upload reference', async () => {
    const page = uploadPage();
    page.input.name = 'presentations[7][speaker_notes]';
    page.input.files[0].name = 'notes.pdf';
    await page.submit().completion;
    assert.equal(page.requests[0].asset_key, 'speaker_notes');
    assert.equal(page.saved().filesExcluded, true);
    assert.deepEqual(page.saved().tokens, [['presentations[7][pdf_upload_token]', 'a'.repeat(64)]]);
    assert.match(page.body.children[0].children[0].textContent, /Saving changes/);
});
test('oversized PDFs are rejected before starting a transfer', async () => {
    const page = uploadPage();
    page.input.name = 'presentations[7][speaker_notes]';
    page.input.files[0].name = 'notes.pdf';
    page.input.files[0].size = 101 * 1024 * 1024;
    await page.submit().completion;
    assert.equal(page.requests.length, 0);
    assert.match(page.body.children[0].children[0].textContent, /100 MB/);
    assert.equal(page.button.disabled, false);
});
