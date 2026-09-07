"use strict";
const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

function photoFixture(label) {
    function node() {
        return { events: {}, addEventListener(name, callback) { this.events[name] = callback; } };
    }
    const input = Object.assign(node(), {
        dataset: { maxBytes: "5242880", ...(label ? { photoLabel: label } : {}) },
        setCustomValidity(message) { this.error = message; }, reportValidity() {}, value: ""
    });
    const preview = Object.assign(node(), { src: "original.png", alt: "Original photo",
        getAttribute(name) { return this[name]; } });
    const status = {};
    const remove = Object.assign(node(), { checked: true });
    const nodes = new Map([
        ["[data-contact-photo-input]", input], ["[data-contact-photo-preview]", preview],
        ["[data-contact-photo-preview-status]", status], ["[data-remove-contact-photo]", remove]
    ]);
    class FileReader {
        addEventListener(name, callback) { this[name] = callback; }
        readAsDataURL() { this.result = "data:image/png;base64,fixture"; this.load(); }
    }
    vm.runInNewContext(fs.readFileSync(require.resolve("../../src/assets/js/contact-photo.js"), "utf8"), {
        document: { querySelector: selector => nodes.get(selector) }, FileReader
    });
    return { input, preview, status, remove };
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
