const test = require('node:test');
const assert = require('node:assert/strict');
const {wireImportFileDrop} = require('../../src/assets/js/audience-import.js');

function fixture({rejectFileAssignment = false} = {}) {
    const element = () => {
        const classes = new Set();
        return {
            events: {}, hidden: false, textContent: '', disabled: false,
            classList: {add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name)},
            addEventListener(type, handler) { this.events[type] = handler; },
            dispatchEvent(event) { this.events[event.type]?.(event); }
        };
    };
    const input = element(), browse = element(), status = element(), error = element(), zone = element();
    let files = [], picked = 0;
    Object.defineProperty(input, 'files', {
        get: () => files,
        set: value => { if (rejectFileAssignment) throw new Error('FileList unavailable'); files = value; }
    });
    Object.defineProperty(input, 'value', {set: value => { if (value === '') files = []; }});
    input.setCustomValidity = message => { input.validationMessage = message; };
    input.click = () => { picked++; };
    const nodes = {'input[type="file"]': input, '[data-import-browse]': browse,
        '[data-import-file-status]': status, '[data-import-file-error]': error};
    zone.querySelector = selector => nodes[selector];
    wireImportFileDrop(zone);
    const dispatch = (type, selection = []) => {
        const event = {dataTransfer: {files: selection}, prevented: false, preventDefault() { this.prevented = true; }};
        zone.dispatchEvent(Object.assign(event, {type}));
        return event;
    };
    return {input, browse, status, error, zone, dispatch, picked: () => picked,
        choose(selection) { files = selection; input.dispatchEvent({type: 'change'}); }};
}
const file = (name = 'connections.csv', size = 1024) => ({name, size});

test('browse uses the native chooser and announces the selected file as literal text', () => {
    const f = fixture();
    f.browse.dispatchEvent({type: 'click'});
    assert.equal(f.picked(), 1);
    f.choose([file('<connections>.CSV')]);
    assert.match(f.status.textContent, /<connections>\.CSV · 1 KB — ready to continue/);
    assert.equal(f.input.validationMessage, '');
    assert.equal(f.error.hidden, true);
});

test('a drop assigns one file to the existing multipart input without opening the chooser', () => {
    const f = fixture(), files = [file('connections.XLSX', 20 * 1024 * 1024)];
    assert.equal(f.dispatch('drop', files).prevented, true);
    assert.equal(f.input.files, files);
    assert.match(f.status.textContent, /connections.XLSX · 20.0 MB/);
    assert.equal(f.picked(), 0);
});

test('bad drops clear an earlier selection and block submission until corrected', () => {
    for (const [files, message] of [
        [[file(), file('second.csv')], /one file/],
        [[file('contacts.exe')], /CSV, TXT, TSV or XLSX/],
        [[file('large.csv', 20 * 1024 * 1024 + 1)], /20 MB/],
        [[file('empty.tsv', 0)], /not empty/],
        [[], /Drop a file/]
    ]) {
        const f = fixture();
        f.choose([file('earlier.csv')]);
        f.dispatch('drop', files);
        assert.equal(f.input.files.length, 0);
        assert.match(f.input.validationMessage, message);
        assert.equal(f.error.hidden, false);
        assert.equal(f.status.textContent, 'No file selected.');
        f.choose([file('corrected.txt')]);
        assert.equal(f.input.validationMessage, '');
        assert.equal(f.error.hidden, true);
    }
});

test('native chooser validates size and format too, and clearing selection resets feedback', () => {
    const f = fixture();
    f.choose([file('large.csv', 21 * 1024 * 1024)]);
    assert.match(f.error.textContent, /20 MB/);
    f.choose([file('contacts.pdf')]);
    assert.match(f.error.textContent, /CSV, TXT, TSV or XLSX/);
    f.choose([]);
    assert.equal(f.input.validationMessage, '');
    assert.equal(f.status.textContent, 'No file selected.');
    assert.equal(f.error.hidden, true);
});

test('nested drag events keep highlighting until the file leaves the whole region', () => {
    const f = fixture();
    f.dispatch('dragenter'); f.dispatch('dragenter'); f.dispatch('dragleave');
    assert.equal(f.zone.classList.contains('is-dragging'), true);
    assert.equal(f.dispatch('dragover').dataTransfer.dropEffect, 'copy');
    f.dispatch('dragleave');
    assert.equal(f.zone.classList.contains('is-dragging'), false);
    f.input.disabled = true;
    f.dispatch('dragenter');
    assert.equal(f.zone.classList.contains('is-dragging'), false);
    assert.equal(f.dispatch('dragover').dataTransfer.dropEffect, 'none');
    f.dispatch('drop', [file()]);
    assert.equal(f.input.files.length, 0);
});

test('browsers unable to accept a dropped FileList provide the native browse fallback', () => {
    const f = fixture({rejectFileAssignment: true});
    f.dispatch('drop', [file()]);
    assert.match(f.error.textContent, /Use Browse files/);
    assert.equal(f.input.files.length, 0);
    f.browse.dispatchEvent({type: 'click'});
    assert.equal(f.picked(), 1);
    f.choose([file('fallback.csv')]);
    assert.equal(f.input.validationMessage, '');
});
