'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('./helpers/account-context.js');
const source = fs.readFileSync(require.resolve('../../src/assets/js/database-backup.js'), 'utf8');

function fixture({ crypto = true } = {}) {
    const button = { disabled: false, textContent: 'Encrypt and Download Backup' };
    const status = { hidden: true, dataset: {}, textContent: '', scrollIntoView(options) { this.scrollOptions = options; } };
    const message = { textContent: '' };
    const stage = { textContent: '' };
    const elapsed = { hidden: false, textContent: '' };
    const progressAttributes = {};
    const progress = {
        hidden: false,
        setAttribute(name, value) { progressAttributes[name] = value; },
        removeAttribute(name) { delete progressAttributes[name]; }
    };
    const token = { value: '' };
    const lastCreated = { textContent: 'Previous backup' };
    const fields = ['admin password', '123456', 'archive password', 'archive password'].map(value => ({ value, readOnly: false }));
    const attributes = {};
    const events = {};
    const timers = new Map();
    const cookies = new Map();
    let now = 0;
    let nextToken = 0;
    let nextTimer = 0;
    let errorRemoved = false;
    const form = {
        querySelector: () => button, querySelectorAll: () => fields,
        setAttribute: (name, value) => { attributes[name] = value; },
        removeAttribute: name => { delete attributes[name]; },
        addEventListener: (name, callback) => { events[name] = callback; }
    };
    const elements = {
        'database-backup-form': form, 'database-backup-status': status,
        'database-backup-token': token, 'database-backup-last-created': lastCreated,
        'database-backup-message': message, 'database-backup-stage': stage,
        'database-backup-progress': progress, 'database-backup-elapsed': elapsed,
        'database-backup-error': { remove() { errorRemoved = true; } }
    };
    const document = {
        getElementById: id => elements[id],
        get cookie() { return Array.from(cookies, ([name, value]) => name + '=' + value).join('; '); },
        set cookie(value) {
            const [name, content] = value.split(';')[0].split('=');
            if (value.includes('Max-Age=0')) cookies.delete(name);
            else cookies.set(name, content);
        }
    };
    const window = {
        crypto: crypto ? { getRandomValues: bytes => bytes.fill(++nextToken) } : undefined,
        setInterval(callback) { timers.set(++nextTimer, callback); return nextTimer; },
        clearInterval: id => timers.delete(id),
        addEventListener: (name, callback) => { events[name] = callback; }
    };
    vm.runInNewContext(source, { document, window, Date: { now: () => now } });
    const receipt = { filename: 'dnr-database-20260913-190000Z.dnrbackup', createdAt: 'Sep 13, 2026 2:00:00 PM CDT' };
    return {
        button, status, message, stage, progress, progressAttributes, elapsed,
        token, lastCreated, fields, attributes, events, timers, cookies, receipt,
        errorRemoved: () => errorRemoved,
        submit(defaultPrevented = false) {
            const event = { defaultPrevented, preventDefault() { this.defaultPrevented = true; } };
            events.submit?.(event);
            return event;
        },
        acknowledge(value = receipt, requestToken = token.value) {
            cookies.set('dnr_backup_' + requestToken, encodeURIComponent(JSON.stringify(value)));
        },
        tick(milliseconds = 500) {
            now += milliseconds;
            for (const callback of timers.values()) callback();
        }
    };
}

test('native submission shows progress, preserves posted credentials, and blocks duplicate exports', () => {
    const f = fixture();
    assert.equal(f.submit().defaultPrevented, false);
    assert.match(f.token.value, /^[a-f0-9]{32}$/);
    assert.equal(f.status.dataset.state, 'pending');
    assert.equal(f.status.hidden, false);
    assert.equal(f.status.scrollOptions.block, 'nearest');
    assert.equal(f.stage.textContent, 'Creating your backup');
    assert.equal(f.progress.hidden, false);
    assert.equal(f.progressAttributes['aria-valuenow'], undefined);
    assert.equal(f.elapsed.textContent, '0:00 elapsed');
    assert.equal(f.attributes['aria-busy'], 'true');
    assert.equal(f.button.disabled, true);
    assert.ok(f.fields.every(field => field.readOnly && field.value));
    assert.equal(f.errorRemoved(), true);
    assert.equal(f.submit().defaultPrevented, true);
    assert.equal(f.timers.size, 1);
    f.tick();
    assert.equal(f.status.dataset.state, 'pending');
    assert.equal(f.lastCreated.textContent, 'Previous backup');
});

test('only the current download acknowledgement confirms success and clears credentials', () => {
    const f = fixture();
    f.submit();
    f.acknowledge(f.receipt, 'another-tab');
    f.tick();
    assert.equal(f.status.dataset.state, 'pending');
    f.tick(900000);
    assert.equal(f.status.dataset.state, 'pending');
    f.acknowledge();
    f.tick();
    assert.equal(f.status.dataset.state, 'success');
    assert.match(f.message.textContent, /Backup created successfully\. Download started:/);
    assert.ok(f.message.textContent.includes(f.receipt.filename));
    assert.match(f.message.textContent, /confirm the file finished saving/);
    assert.equal(f.stage.textContent, 'Backup ready');
    assert.equal(f.progressAttributes['aria-valuenow'], '100');
    assert.equal(f.elapsed.hidden, true);
    assert.equal(f.lastCreated.textContent, f.receipt.createdAt);
    assert.ok(f.fields.every(field => !field.readOnly && field.value === ''));
    assert.equal(f.button.disabled, false);
    assert.equal(f.button.textContent, 'Encrypt and Download Backup');
    assert.equal(f.attributes['aria-busy'], undefined);
    assert.equal(f.timers.size, 0);
    assert.equal(f.cookies.has('dnr_backup_' + f.token.value), false);
    assert.equal(f.cookies.has('dnr_backup_another-tab'), true);
});

test('malformed receipts never report success', () => {
    for (const receipt of [null, {}, { filename: '<script>', createdAt: 'today' }, { filename: 'dnr-database-20260913-190000Z.dnrbackup' }]) {
        const f = fixture();
        f.submit();
        f.acknowledge(receipt);
        f.tick();
        assert.equal(f.status.dataset.state, 'pending');
    }
    const f = fixture();
    f.submit();
    f.cookies.set('dnr_backup_' + f.token.value, '%invalid');
    f.tick();
    assert.equal(f.status.dataset.state, 'pending');
});

test('an unconfirmed response times out without claiming failure or changing backup history', () => {
    const f = fixture();
    f.submit();
    f.tick(360000);
    assert.equal(f.status.dataset.state, 'pending');
    f.tick(599999);
    assert.equal(f.button.disabled, true);
    f.tick(1);
    assert.equal(f.status.dataset.state, 'warning');
    assert.match(f.message.textContent, /confirmation was not received/);
    assert.match(f.message.textContent, /fresh authenticator or recovery code/);
    assert.equal(f.progress.hidden, true);
    assert.equal(f.progressAttributes['aria-valuenow'], undefined);
    assert.equal(f.lastCreated.textContent, 'Previous backup');
    assert.equal(f.button.disabled, false);
    assert.equal(f.timers.size, 0);
});

test('retries use a new token so a late response cannot confirm the wrong export', () => {
    const f = fixture();
    f.submit();
    const firstToken = f.token.value;
    f.tick(960000);
    f.submit();
    assert.notEqual(f.token.value, firstToken);
    assert.equal(f.elapsed.textContent, '0:00 elapsed');
    assert.equal(f.progress.hidden, false);
    assert.equal(f.progressAttributes['aria-valuenow'], undefined);
    f.acknowledge(f.receipt, firstToken);
    f.tick();
    assert.equal(f.status.dataset.state, 'pending');
    f.acknowledge();
    f.tick();
    assert.equal(f.status.dataset.state, 'success');
});

test('returning through the back-forward cache restores usable controls without claiming success', () => {
    const f = fixture();
    f.submit();
    f.events.pageshow({ persisted: true });
    assert.equal(f.status.dataset.state, 'warning');
    assert.equal(f.progress.hidden, true);
    assert.equal(f.button.disabled, false);
    assert.equal(f.timers.size, 0);
});

test('elapsed time remains accurate across a slow backup without inventing a completion percentage', () => {
    const f = fixture();
    f.submit();
    f.tick(50000);
    assert.equal(f.elapsed.textContent, '0:50 elapsed');
    assert.equal(f.stage.textContent, 'Creating your backup');
    assert.equal(f.progressAttributes['aria-valuenow'], undefined);
    assert.equal(f.status.dataset.state, 'pending');
    f.tick(15000);
    assert.equal(f.elapsed.textContent, '1:05 elapsed');
    f.acknowledge();
    f.tick();
    assert.equal(f.progressAttributes['aria-valuenow'], '100');
    assert.equal(f.timers.size, 0);
});

test('unsupported browsers and previously canceled submissions keep the native form untouched', () => {
    const native = fixture({ crypto: false });
    assert.equal(native.events.submit, undefined);
    assert.equal(native.button.disabled, false);
    assert.equal(native.token.value, '');
    const canceled = fixture();
    canceled.submit(true);
    assert.equal(canceled.status.hidden, true);
    assert.equal(canceled.button.disabled, false);
    assert.equal(canceled.token.value, '');
});
