const test = require('node:test');
const assert = require('node:assert/strict');
const { selectedRoleCount } = require('../../src/assets/js/engagement-contacts.js');

test('selectedRoleCount reports checked event contact roles', () => {
    const container = {
        querySelectorAll(selector) {
            assert.equal(selector, 'input[type="checkbox"]:checked');
            return [{}, {}, {}];
        }
    };
    assert.equal(selectedRoleCount(container), 3);
    assert.equal(selectedRoleCount(null), 0);
});

const { selectedContactCount, contactSelectionSummary } = require('../../src/assets/js/engagement-contacts.js');

test('contact counts group multiple roles for one person', () => {
    const card = count => ({ querySelectorAll: () => Array(count).fill({}) });
    const container = { querySelectorAll: () => [card(3), card(0), card(2)] };
    assert.equal(selectedContactCount(container), 2);
    assert.equal(selectedContactCount(null), 0);
    assert.equal(contactSelectionSummary(2, 5), '2 contacts · 5 roles');
    assert.equal(contactSelectionSummary(1, 1), '1 contact · 1 role');
});

// Small DOM harness exercises the real asynchronous form handlers without browser dependencies.
function contactPickerHarness() {
    class Element {
        constructor(tag = 'div') {
            this.tag = tag;
            this.children = [];
            this.dataset = {};
            this.attributes = new Map();
            this.listeners = {};
            this.value = '';
            this.className = '';
        }
        append(...children) {
            children.forEach(child => {
                if (child.tag === 'fragment') this.append(...child.children);
                else { this.children.push(child); child.parent = this; }
            });
        }
        appendChild(child) { this.append(child); }
        replaceChildren(...children) { this.children = []; this.append(...children); }
        setAttribute(name, value) { this.attributes.set(name, value); }
        removeAttribute(name) { this.attributes.delete(name); }
        addEventListener(name, listener) { this.listeners[name] = listener; }
        closest(selector) { return this.tag === selector ? this : this.parent?.closest(selector); }
        focus() {}
        setCustomValidity(message) { this.validityMessage = message; }
        querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
        querySelectorAll(selector) {
            const matches = element => {
                if (selector === '.engagement-contact-card') return element.className.split(' ').includes('engagement-contact-card');
                if (selector.startsWith('[data-')) {
                    const key = selector.slice(6, -1).replace(/-([a-z])/g, (_, char) => char.toUpperCase());
                    return Object.hasOwn(element.dataset, key);
                }
                if (selector.startsWith('input[type="checkbox"]')) {
                    return element.tag === 'input' && element.type === 'checkbox'
                        && (!selector.endsWith(':checked') || element.checked);
                }
                return false;
            };
            return this.children.flatMap(child => [ ...(matches(child) ? [child] : []), ...child.querySelectorAll(selector) ]);
        }
    }
    const form = new Element('form');
    const picker = new Element();
    picker.dataset.engagementContactPicker = '';
    picker.dataset.contactOptionsUrl = 'organization_contacts.php';
    form.append(picker);
    const parts = {};
    ['engagementContactList', 'engagementAddedContactList', 'engagementNewContactList', 'newContactTemplate',
        'addNewContact', 'engagementContactStatus', 'engagementContactCount', 'retryContactLoad',
        'contactSearch', 'contactSearchButton', 'contactSearchStatus', 'contactSearchResults'].forEach(key => {
        const element = new Element();
        element.dataset[key] = '';
        parts[key] = element;
        picker.append(element);
    });
    const original = new Element('fieldset');
    original.className = 'engagement-contact-card';
    original.dataset.existingContactId = '101';
    const checkbox = new Element('input');
    checkbox.type = 'checkbox';
    checkbox.value = 'primary_host';
    checkbox.checked = true;
    original.append(checkbox);
    parts.engagementContactList.append(original);
    const organization = new Element('select');
    organization.value = '1';
    const requests = [];
    const vm = require('node:vm');
    const fs = require('node:fs');
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/engagement-contacts.js'), 'utf8'), {
        document: { querySelector: () => picker, getElementById: () => organization,
            createElement: tag => new Element(tag), createDocumentFragment: () => new Element('fragment') },
        window: { location: { href: 'http://localhost/index.php' } }, URL, AbortController,
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }))
    });
    return { form, parts, requests, change(value) { organization.value = value; return organization.listeners.change(); } };
}

test('loading and failed organization changes preserve roles and block premature submission', async () => {
    const fixture = contactPickerHarness();
    const loadingOther = fixture.change('2');
    const returning = fixture.change('1');
    let prevented = false;
    fixture.form.listeners.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true, 'Saving during a reload must not silently remove existing assignments');
    fixture.requests[0].reject(Object.assign(new Error(), { name: 'AbortError' }));
    fixture.requests[1].reject(new Error('Network unavailable'));
    await Promise.all([loadingOther, returning]);
    assert.equal(fixture.parts.retryContactLoad.hidden, false);
    const retrying = fixture.parts.retryContactLoad.listeners.click();
    fixture.requests[2].resolve({ ok: true, json: async () => ({ contacts: [{ id: 101, name: 'Original contact' }],
        roles: { primary_host: 'Primary host', travel: 'Travel' } }) });
    await retrying;
    assert.equal(fixture.parts.engagementContactList.querySelectorAll('input[type="checkbox"]:checked').length, 1,
        'Retry must restore the original role instead of caching the pending empty list');
    prevented = false;
    fixture.form.listeners.submit({ preventDefault() { prevented = true; } });
    assert.equal(prevented, false, 'Saving should resume after contacts have loaded successfully');
});
