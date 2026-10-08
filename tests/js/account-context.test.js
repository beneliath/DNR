'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { storageKey } = require('../../src/assets/js/account-context.js');
const source = fs.readFileSync(require.resolve('../../src/assets/js/account-context.js'), 'utf8');

test('browser storage is scoped to the actual Account path', () => {
    assert.equal(storageKey('/a/primary-account/profile.php', 'draft'), 'dnr.account.primary-account:draft');
    assert.equal(storageKey('/a/member-account', 'draft'), 'dnr.account.member-account:draft');
    assert.equal(storageKey('/profile.php', 'draft'), 'draft');
    assert.equal(storageKey('/a/INVALID/profile.php', 'draft'), 'draft');
    const values = new Map([['draft', 'legacy'], ['dnr.account.primary-account:draft', 'primary private data']]);
    const storage = { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) };
    const window = { location: { pathname: '/a/member-account/profile.php' }, localStorage: storage, sessionStorage: storage };
    vm.runInNewContext(source, { window });
    assert.equal(window.DnrAccountContext.session.getItem('draft'), null);
    window.DnrAccountContext.session.setItem('draft', 'member data');
    assert.equal(values.get('dnr.account.primary-account:draft'), 'primary private data');
    assert.equal(values.get('draft'), 'legacy');
    assert.throws(() => { window.DnrAccountContext = {}; }, TypeError);
    assert.throws(() => { window.DnrAccountContext.local.getItem = () => 'changed'; }, TypeError);
    window.location.pathname = '/a/primary-account/profile.php';
    assert.equal(window.DnrAccountContext.session.getItem('draft'), 'member data', 'An existing document cannot change its captured namespace');
    window.DnrAccountContext.session.removeItem('draft');
    assert.equal(values.has('dnr.account.member-account:draft'), false);
});
