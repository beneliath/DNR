const test = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {join} = require('node:path');
const {Script} = require('node:vm');

// These assets are loaded by renderPageHead as ordinary browser scripts.
// Node's module-aware source check permits imports that the browser rejects here.
for (const name of ['audience-ai.min.js', 'audience.min.js', 'audience-import.min.js', 'audience-designer.min.js', 'audience-collection.min.js', 'audience-flows.min.js']) {
    test(`${name} parses as a classic browser script`, () => {
        const source = readFileSync(join(__dirname, '../../src/assets/js', name), 'utf8');
        let parseError;
        try { new Script(source, {filename: name}); }
        catch (error) { parseError = error.message; }
        assert.equal(parseError, undefined);
    });
}
