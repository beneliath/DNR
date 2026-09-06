'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../../src/assets/js/profile.js'), 'utf8');

function fixture({ enabled = true, checkedDays = [], response } = {}) {
    function control(extra = {}) { return { disabled: false, events: {}, attrs: {},
        addEventListener(k, fn) { this.events[k] = fn; }, setAttribute(k, v) { this.attrs[k] = v; }, removeAttribute(k) { delete this.attrs[k]; },
        setCustomValidity(v) { this.validationMessage = v; }, ...extra }; }
    const days = [1, 2, 4, 8, 16, 32, 64].map(value => control({ value, checked: checkedDays.includes(value) }));
    const toggle = control({ checked: enabled });
    const time = control({ value: '08:00' });
    const paused = control();
    const schedule = control({ querySelectorAll(selector) {
        if (selector === 'input[name="task_digest_days[]"]') return days;
        if (selector === '[data-task-digest-days]') return [];
        return [time, ...days];
    } });
    const resendForm = control({ action: 'profile.php' }), resendButton = control(), status = control();
    const draft = { name: 'Unsaved name', phone: '3125550100', file: { name: 'new-photo.png', size: 1000 } };
    const sent = [];
    const fetch = async (url, options) => { sent.push({ url, options }); return response || { ok: true, redirected: false, headers: { get() { return 'application/json'; } }, json: async () => ({ ok: true, message: 'Verification queued' }) }; };
    const doc = {
        querySelector(selector) { return ({ '[data-task-digest-schedule]': schedule, '[data-task-digest-enabled]': toggle, '[data-task-digest-paused]': paused, '[data-resend-verification]': resendButton, '[data-verification-status]': status }[selector] || null); },
        getElementById() { return resendForm; }
    };
    class FormData { constructor(form) { this.form = form; } }
    vm.runInNewContext(source, { document: doc, window: { fetch }, fetch, FormData });
    return { days, toggle, time, schedule, paused, resendForm, resendButton, status, draft, sent,
        async resend() { let prevented = false; await resendForm.events.submit({ preventDefault() { prevented = true; } }); return prevented; }
    };
}

test('turning digest off clears day validation and disables schedule; enabling restores validation', () => {
    const f = fixture();
    assert.match(f.days[0].validationMessage, /Choose at least one/);
    f.toggle.checked = false;
    f.toggle.events.change();
    assert.equal(f.days[0].validationMessage, '');
    assert.equal(f.time.disabled, true);
    assert.equal(f.schedule.hidden, true);
    f.toggle.checked = true;
    f.toggle.events.change();
    assert.equal(f.time.disabled, false);
    assert.match(f.days[0].validationMessage, /Choose at least one/);
    f.days[0].checked = true;
    f.days[0].events.change();
    assert.equal(f.days[0].validationMessage, '');
});

test('resend posts only its own form and preserves profile fields and selected file', async () => {
    const f = fixture({ enabled: false });
    const file = f.draft.file;
    assert.equal(await f.resend(), true);
    assert.equal(f.sent[0].options.body.form, f.resendForm);
    assert.equal(f.sent[0].options.headers.Accept, 'application/json');
    assert.equal(f.draft.name, 'Unsaved name');
    assert.equal(f.draft.file, file);
    assert.equal(f.status.textContent, 'Verification queued');
    assert.equal(f.resendButton.disabled, false);
});

test('expired-session response keeps draft in place and offers retry', async () => {
    const f = fixture({ response: { redirected: true } });
    const file = f.draft.file;
    await f.resend();
    assert.match(f.status.textContent, /session may have expired/);
    assert.equal(f.draft.file, file);
    assert.equal(f.resendButton.disabled, false);
});
