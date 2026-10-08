"use strict";
const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

function photoFixture(label, clipboard) {
    function node() {
        return {
            events: {}, addEventListener(name, callback) { this.events[name] = callback; },
            dispatchEvent(event) { this.events[event.type]?.(event); },
            setAttribute(name, value) { this[name] = value; }, focus() { this.focused = true; }
        };
    }
    const input = Object.assign(node(), {
        dataset: { maxBytes: "5242880", ...(label ? { photoLabel: label } : {}) },
        setCustomValidity(message) { this.error = message; }, reportValidity() {}, value: ""
    });
    const preview = Object.assign(node(), { src: "original.png", alt: "Original photo",
        getAttribute(name) { return this[name]; } });
    const status = {};
    const remove = Object.assign(node(), { checked: true });
    const field = node();
    input.closest = () => field;
    const pasteButton = node();
    const pasteBox = { hidden: true };
    const pasteTarget = node();
    const nodes = new Map([
        ["[data-contact-photo-input]", input], ["[data-contact-photo-preview]", preview],
        ["[data-contact-photo-preview-status]", status], ["[data-remove-contact-photo]", remove]
    ]);
    if (label !== 'speaker') {
        nodes.set('[data-contact-photo-paste]', pasteButton);
        nodes.set('[data-contact-photo-paste-box]', pasteBox);
        nodes.set('[data-contact-photo-paste-target]', pasteTarget);
    }
    class FileReader {
        addEventListener(name, callback) { this[name] = callback; }
        readAsDataURL() { this.result = "data:image/png;base64,fixture"; this.load(); }
    }
    class File {
        constructor(parts, name, options) {
            this.name = name;
            this.type = options.type;
            this.size = parts.reduce((size, part) => size + part.size, 0);
        }
    }
    class DataTransfer {
        files = [];
        items = { add: file => this.files.push(file) };
    }
    vm.runInNewContext(fs.readFileSync(require.resolve("../../src/assets/js/contact-photo.js"), "utf8"), {
        document: { querySelector: selector => nodes.get(selector) }, FileReader, File, DataTransfer,
        navigator: { clipboard }, Event: class { constructor(type) { this.type = type; } }
    });
    function paste(files, options = {}) {
        const event = {
            target: pasteTarget, clipboardData: { files },
            preventDefault() { this.prevented = true; }, ...options
        };
        field.events.paste(event);
        return event;
    }
    return { input, preview, status, remove, pasteButton, pasteBox, pasteTarget, paste };
}

for (const label of [undefined, "speaker"]) {
    const expectedLabel = label || "contact";
    test(expectedLabel + " photo selection previews immediately and clears removal", () => {
        const f = photoFixture(label);
        f.input.files = [{ type: "image/png", size: 100 }];
        f.input.events.change();
        assert.equal(f.preview.src, "data:image/png;base64,fixture");
        assert.equal(f.preview.alt, "Preview of selected " + expectedLabel + " photo");
        assert.equal(f.remove.checked, false);
        assert.match(f.status.textContent, /Save changes to apply this photo/);
    });
}

test("speaker photo validation and removal preserve the current saved photo", () => {
    const f = photoFixture("speaker");
    f.input.files = [{ type: "image/png", size: 5242881 }];
    f.input.events.change();
    assert.equal(f.input.error, "Speaker photos must be 5 MB or smaller.");
    assert.equal(f.preview.src, "original.png");
    f.input.files = [{ type: "text/html", size: 100 }];
    f.input.events.change();
    assert.match(f.input.error, /JPEG, PNG, or WebP speaker photo/);
    f.remove.checked = true;
    f.remove.events.change();
    assert.equal(f.input.value, "");
    assert.equal(f.input.error, "");
    assert.match(f.status.textContent, /removed when you save/);
});

test('keyboard paste stages one named image in the normal upload input and previews it', () => {
    const f = photoFixture();
    assert.equal(f.paste([{ type: 'image/jpeg', size: 123 }]).prevented, true);
    assert.equal(f.input.files.length, 1);
    assert.match(f.input.files[0].name, /^contact-photo-\d+\.jpg$/);
    assert.equal(f.input.files[0].size, 123);
    assert.equal(f.preview.src, 'data:image/png;base64,fixture');
    assert.equal(f.remove.checked, false);
    assert.match(f.status.textContent, /Save changes to apply/);
});

test('clipboard item fallback works and unrelated text paste is left alone', () => {
    const f = photoFixture();
    const image = { type: 'image/webp', size: 200 };
    f.paste([], { clipboardData: { items: [{ kind: 'file', getAsFile: () => image }] } });
    assert.equal(f.input.files[0].type, 'image/webp');
    assert.equal(f.paste([], { target: f.input }).prevented, undefined);
    assert.equal(f.paste([]).prevented, true);
    assert.match(f.status.textContent, /Copy the image itself/);
});

test('invalid and oversized clipboard images leave the selected file and preview intact', () => {
    const f = photoFixture();
    const originalSelection = [{ type: 'image/png', size: 100 }];
    f.input.files = originalSelection;
    f.input.events.change();
    for (const image of [{ type: 'image/svg+xml', size: 100 }, { type: 'image/png', size: 5242881 }]) {
        f.paste([image]);
        assert.equal(f.input.files, originalSelection);
        assert.equal(f.preview.src, 'data:image/png;base64,fixture');
        assert.equal(f.input.error, '');
    }
    assert.match(f.status.textContent, /5 MB or smaller/);
});

test('Paste Image reads the clipboard only on click and stages its supported image', async () => {
    let reads = 0;
    const f = photoFixture(undefined, { async read() {
        ++reads;
        return [{ types: ['text/html', 'image/png'], async getType(type) { return { type, size: 101 }; } }];
    } });
    assert.equal(reads, 0);
    await f.pasteButton.events.click();
    assert.equal(reads, 1);
    assert.equal(f.pasteBox.hidden, false);
    assert.equal(f.pasteButton['aria-expanded'], 'true');
    assert.equal(f.pasteTarget.focused, true);
    assert.equal(f.input.files[0].type, 'image/png');
    assert.match(f.status.textContent, /Save changes to apply/);
});

test('unavailable or denied clipboard access keeps keyboard paste available', async () => {
    for (const clipboard of [undefined, { async read() { throw new Error('denied'); } }]) {
        const f = photoFixture(undefined, clipboard);
        await f.pasteButton.events.click();
        assert.equal(f.pasteTarget.focused, true);
        assert.match(f.status.textContent, /Command\+V or Ctrl\+V/);
        f.paste([{ type: 'image/png', size: 100 }]);
        assert.equal(f.input.files.length, 1);
    }
});

test('a late clipboard read cannot replace a newer upload, keyboard paste, or removal', async () => {
    for (const action of ['upload', 'paste', 'remove']) {
        let finishRead;
        const f = photoFixture(undefined, { read: () => new Promise(resolve => { finishRead = resolve; }) });
        const pending = f.pasteButton.events.click();
        if (action === 'upload') {
            f.input.files = [{ name: 'chosen.webp', type: 'image/webp', size: 50 }];
            f.input.events.change();
        } else if (action === 'paste') {
            f.paste([{ type: 'image/jpeg', size: 50 }]);
        } else {
            f.remove.checked = true;
            f.remove.events.change();
        }
        const selection = f.input.files;
        const message = f.status.textContent;
        finishRead([{ types: ['image/png'], getType: async () => ({ type: 'image/png', size: 100 }) }]);
        await pending;
        assert.equal(f.input.files, selection);
        assert.equal(f.status.textContent, message);
    }
});

test('a late clipboard blob cannot replace a newer keyboard paste', async () => {
    let finishBlob;
    let blobRequested;
    const requested = new Promise(resolve => { blobRequested = resolve; });
    const f = photoFixture(undefined, { async read() {
        return [{ types: ['image/png'], getType: () => new Promise(resolve => {
            finishBlob = resolve;
            blobRequested();
        }) }];
    } });
    const pending = f.pasteButton.events.click();
    await requested;
    f.paste([{ type: 'image/jpeg', size: 50 }]);
    const selection = f.input.files;
    finishBlob({ type: 'image/png', size: 100 });
    await pending;
    assert.equal(f.input.files, selection);
});
