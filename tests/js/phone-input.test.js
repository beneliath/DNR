"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const {
    formatNationalInput,
    localDigits,
    normalizedCountryCode
} = require("../../src/assets/js/phone-input.js");

function phoneInput(value, countryValue) {
    const countryInput = {
        validityMessage: "",
        value: countryValue,
        setCustomValidity(message) {
            this.validityMessage = message;
        }
    };
    const group = {
        querySelector(selector) {
            return selector === "[data-phone-country-code]" ? countryInput : null;
        }
    };
    const input = {
        validityMessage: "",
        value,
        closest() {
            return group;
        },
        setCustomValidity(message) {
            this.validityMessage = message;
        }
    };
    return { countryInput, input };
}

test("normalizedCountryCode accepts compact international dialing prefixes", function () {
    assert.equal(normalizedCountryCode(" +1 "), "+1");
    assert.equal(normalizedCountryCode("+(44)"), "+44");
    assert.equal(normalizedCountryCode("+972"), "+972");
    assert.equal(normalizedCountryCode("1"), null);
    assert.equal(normalizedCountryCode("+012"), null);
    assert.equal(normalizedCountryCode("+1234"), null);
});

test("localDigits removes punctuation and a matching explicit country prefix", function () {
    assert.equal(localDigits("+1 (312) 555-0199", "+1"), "3125550199");
    assert.equal(localDigits("312-555-0199", "+1"), "3125550199");
    assert.equal(localDigits("+44 20 7946 0958", "+44"), "2079460958");
});

test("formatNationalInput formats valid North American numbers", function () {
    const fields = phoneInput("+1 312 555 0199", "+1");

    assert.equal(formatNationalInput(fields.input), true);
    assert.equal(fields.input.value, "(312) 555-0199");
    assert.equal(fields.input.validityMessage, "");
    assert.equal(fields.countryInput.validityMessage, "");
});

test("formatNationalInput reports invalid country codes and national lengths", function () {
    const invalidCountry = phoneInput("3125550199", "1");
    assert.equal(formatNationalInput(invalidCountry.input), false);
    assert.match(invalidCountry.countryInput.validityMessage, /country code/);

    const invalidNumber = phoneInput("31255", "+1");
    assert.equal(formatNationalInput(invalidNumber.input), false);
    assert.match(invalidNumber.input.validityMessage, /valid telephone number/);
});

test("phone picker supports selected focus, arrow/typeahead navigation and single-Tab exit", function () {
    const fs = require('node:fs');
    const vm = require('node:vm');
    const events = {};
    let focused = null;
    const trigger = {
        attrs: {}, dataset: { phoneCountryLabel: 'Phone country code' },
        setAttribute(k, v) { this.attrs[k] = v; }, focus() { focused = this; }, closest() { return picker; }
    };
    const options = ['Argentina', 'Israel', 'United States / Canada'].map((countryName, index) => ({
        dataset: { countryName }, index, focus() { focused = this; }, scrollIntoView() {},
        closest(selector) { return selector === '[data-phone-country-option]' ? this : picker; }
    }));
    const menu = {
        hidden: true, id: '', setAttribute() {},
        querySelector() { return options[2]; }, querySelectorAll() { return options; }
    };
    const picker = {
        contains(target) { return target === trigger || options.includes(target); },
        querySelector(selector) { return selector === '[data-phone-country-toggle]' ? trigger : menu; }
    };
    const doc = {
        addEventListener(name, fn) { (events[name] ||= []).push(fn); },
        querySelectorAll() { return [picker]; }, getElementById(id) { return id === menu.id ? menu : null; }
    };
    vm.runInNewContext(fs.readFileSync(require.resolve('../../src/assets/js/phone-input.js'), 'utf8'), { document: doc });
    function key(target, key) {
        const event = { target, key, preventDefault() {} };
        events.keydown.forEach(fn => fn(event));
    }
    key(trigger, 'ArrowDown');
    assert.equal(menu.hidden, false);
    assert.equal(focused, options[2]);
    key(options[2], 'Home');
    assert.equal(focused, options[0]);
    key(options[0], 'i');
    assert.equal(focused, options[1]);
    key(options[1], 'Tab');
    assert.equal(menu.hidden, true);
    assert.equal(focused, trigger, 'restores trigger so native Tab advances past the whole picker');
    key(trigger, 'ArrowDown');
    key(options[2], 'Escape');
    assert.equal(focused, trigger);
    assert.equal(menu.hidden, true);
});
