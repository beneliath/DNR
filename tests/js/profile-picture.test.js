'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/profile.js'), 'utf8');

function fixture(clipboard) {
    function node(extra = {}) {
        return {
            events: {}, addEventListener(name, callback) { this.events[name] = callback; },
            dispatchEvent(event) { this.events[event.type]?.(event); },
            setAttribute(name, value) { this[name] = value; }, focus() { this.focused = true; },
            ...extra
        };
    }
    const input = node({ dataset: { maxBytes: '5242880' }, files: [], value: '',
        setCustomValidity(message) { this.validationMessage = message; }, reportValidity() {} });
    const preview = node({ src: 'original.png', alt: 'Current profile picture',
        getAttribute(name) { return this[name]; } });
    const status = { hidden: true };
    const remove = node({ checked: true });
    const field = node(), pasteButton = node(), pasteBox = { hidden: true }, pasteTarget = node();
    const nodes = {
        '[data-profile-picture-input]': input, '[data-profile-picture-preview]': preview,
        '[data-profile-picture-preview-status]': status, '[data-remove-profile-picture]': remove,
        '[data-profile-picture-field]': field, '[data-profile-picture-paste]': pasteButton,
        '[data-profile-picture-paste-box]': pasteBox, '[data-profile-picture-paste-target]': pasteTarget
    };
    class FileReader {
        addEventListener(name, callback) { this[name] = callback; }
        readAsDataURL(file) { this.result = 'data:' + file.type + ';base64,fixture'; this.load(); }
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
    vm.runInNewContext(source, {
        document: { querySelector: selector => nodes[selector] || null, getElementById: () => null },
        FileReader, File, DataTransfer, navigator: { clipboard },
        Event: class { constructor(type) { this.type = type; } }
    });
    function paste(files, extra = {}) {
        const event = { target: pasteTarget, clipboardData: { files },
            preventDefault() { this.prevented = true; }, ...extra };
        field.events.paste(event);
        return event;
    }
    return { input, preview, status, remove, pasteButton, pasteBox, pasteTarget, paste };
}

test('keyboard paste stages supported profile pictures for normal form upload and immediate preview', () => {
    for (const [type, extension] of [['image/jpeg', 'jpg'], ['image/png', 'png'], ['image/webp', 'webp']]) {
        const f = fixture();
        assert.equal(f.paste([{ type, size: 123 }]).prevented, true);
        assert.equal(f.input.files.length, 1);
        assert.match(f.input.files[0].name, new RegExp('^profile-picture-\\d+\\.' + extension + '$'));
        assert.equal(f.input.files[0].type, type);
        assert.equal(f.input.files[0].size, 123);
        assert.equal(f.preview.src, 'data:' + type + ';base64,fixture');
        assert.equal(f.preview.alt, 'Preview of selected profile picture');
        assert.equal(f.remove.checked, false);
        assert.equal(f.input.validationMessage, '');
        assert.match(f.status.textContent, /Save changes to apply this picture/);
    }
});

test('clipboard items are accepted and text outside the picture paste box is left alone', () => {
    const f = fixture();
    f.paste([], { clipboardData: { items: [{ kind: 'file', getAsFile: () => ({ type: 'image/webp', size: 200 }) }] } });
    assert.equal(f.input.files[0].type, 'image/webp');
    const selected = f.input.files;
    assert.equal(f.paste([], { target: f.input }).prevented, undefined);
    assert.equal(f.paste([]).prevented, true);
    assert.match(f.status.textContent, /Copy the image itself/);
    assert.equal(f.input.files, selected);
});

test('invalid and oversized pasted pictures preserve a valid selection and preview', () => {
    const f = fixture();
    f.paste([{ type: 'image/png', size: 5242880 }]);
    const selected = f.input.files, preview = f.preview.src;
    for (const [image, error] of [
        [{ type: 'image/svg+xml', size: 100 }, /JPEG, PNG, or WebP/],
        [{ type: 'image/png', size: 5242881 }, /5 MB or smaller/]
    ]) {
        f.paste([image]);
        assert.equal(f.input.files, selected);
        assert.equal(f.preview.src, preview);
        assert.equal(f.input.validationMessage, '');
        assert.match(f.status.textContent, error);
    }
});

test('Paste Image reads only on click and reveals the accessible keyboard fallback', async () => {
    let reads = 0;
    const f = fixture({ async read() {
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

test('missing or denied clipboard access leaves keyboard paste working', async () => {
    for (const clipboard of [undefined, { async read() { throw new Error('denied'); } }]) {
        const f = fixture(clipboard);
        await f.pasteButton.events.click();
        assert.equal(f.pasteBox.hidden, false);
        assert.equal(f.pasteTarget.focused, true);
        assert.match(f.status.textContent, /Command\+V or Ctrl\+V/);
        f.paste([{ type: 'image/png', size: 100 }]);
        assert.equal(f.input.files.length, 1);
    }
});

test('late clipboard reads cannot replace a newer upload, keyboard paste, or removal', async () => {
    for (const action of ['upload', 'paste', 'remove']) {
        let finishRead;
        const f = fixture({ read: () => new Promise(resolve => { finishRead = resolve; }) });
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
        const selected = f.input.files, message = f.status.textContent;
        finishRead([{ types: ['image/png'], getType: async () => ({ type: 'image/png', size: 100 }) }]);
        await pending;
        assert.equal(f.input.files, selected);
        assert.equal(f.status.textContent, message);
    }
});

test('a late clipboard blob cannot overwrite a newer keyboard paste', async () => {
    let finishBlob, blobRequested;
    const requested = new Promise(resolve => { blobRequested = resolve; });
    const f = fixture({ async read() {
        return [{ types: ['image/png'], getType: () => new Promise(resolve => {
            finishBlob = resolve;
            blobRequested();
        }) }];
    } });
    const pending = f.pasteButton.events.click();
    await requested;
    f.paste([{ type: 'image/jpeg', size: 50 }]);
    const selected = f.input.files;
    finishBlob({ type: 'image/png', size: 100 });
    await pending;
    assert.equal(f.input.files, selected);
});
