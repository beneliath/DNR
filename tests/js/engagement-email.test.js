const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function composerHarness() {
    const element = (properties = {}) => ({
        listeners: {},
        addEventListener(event, handler) { this.listeners[event] = handler; },
        ...properties,
    });
    const recipient = (role, disabled = false, checked = false) => element({
        dataset: { contactRoles: role }, disabled, checked,
    });
    const recipients = [recipient('primary_host', false, true), recipient('travel'),
        recipient('speaker'), recipient('speaker', true), recipient('primary_host', true)];
    const template = element({ value: 'travel_lodging' });
    const count = element();
    const all = element();
    const clear = element();
    const speaker = element({ dataset: { selectRecipientRole: 'speaker' } });
    const host = element({ dataset: { selectRecipientRole: 'primary_host' } });
    const subject = element();
    const body = element();
    const controls = { '[data-email-template]': template, '[data-email-subject]': subject,
        '[data-email-body]': body, '[data-recipient-count]': count,
        '[data-select-all-recipients]': all, '[data-clear-recipients]': clear };
    const form = {
        querySelector: selector => controls[selector],
        querySelectorAll: selector => selector === '[data-email-recipient]' ? recipients : [speaker, host],
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/engagement-email.js'), 'utf8'), {
        document: { querySelector: () => form, getElementById: () => ({ textContent: JSON.stringify({
            no_suggestions: { subject: 'Hello', body: 'Welcome', suggested_roles: [] },
            custom: { subject: 'Marker', body: '', suggested_roles: [] },
            travel_lodging: { subject: 'Travel details', body: 'Please confirm', suggested_roles: ['travel'] },
        }) }) },
    });
    return { recipients, template, count, all, clear, speaker, host, subject, body };
}

test('speakers are optional and shortcuts count only available recipients', () => {
    const form = composerHarness();
    assert.equal(form.recipients[2].checked, false);
    assert.equal(form.count.textContent, '1 recipient selected.');
    form.speaker.listeners.click();
    assert.equal(form.count.textContent, '2 recipients selected.');
    assert.deepEqual(form.recipients.map(item => item.checked), [true, false, true, false, false]);
    form.host.listeners.click();
    assert.equal(form.recipients[4].checked, false);
    form.all.listeners.click();
    assert.equal(form.count.textContent, '3 recipients selected.');
    form.clear.listeners.click();
    assert.equal(form.count.textContent, '0 recipients selected.');
    assert.ok(form.recipients.every(item => !item.checked));
});

test('changing templates preserves the explicit speaker choice while updating contact suggestions', () => {
    const form = composerHarness();
    form.speaker.listeners.click();
    form.template.listeners.change();
    assert.deepEqual(form.recipients.map(item => item.checked), [false, true, true, false, false]);
    assert.equal(form.count.textContent, '2 recipients selected.');
    assert.equal(form.subject.value, 'Travel details');
    assert.equal(form.body.value, 'Please confirm');
    form.recipients[2].checked = false;
    form.recipients[2].listeners.change();
    form.template.listeners.change();
    assert.equal(form.recipients[2].checked, false);
    assert.equal(form.count.textContent, '1 recipient selected.');
});


test('templates without suggestions clear contact selections and custom preserves manual recipients', () => {
    const form = composerHarness();
    form.speaker.listeners.click();
    form.template.value = 'custom';
    form.template.listeners.change();
    assert.deepEqual(form.recipients.map(item => item.checked), [true, false, true, false, false]);
    form.template.value = 'no_suggestions';
    form.template.listeners.change();
    assert.deepEqual(form.recipients.map(item => item.checked), [false, false, true, false, false]);
    assert.equal(form.count.textContent, '1 recipient selected.');
});
