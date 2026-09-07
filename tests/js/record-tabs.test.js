"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const vm = require("node:vm");

const source = fs.readFileSync(require.resolve("../../src/assets/js/page-actions.js"), "utf8");

function recordTabsFixture({ inquiry = false, hash = "", selected = 0, initialize = true } = {}) {
    const nodes = new Map();
    const windowEvents = {};
    const documentEvents = {};
    let focused = null;

    function node(id, attributes = {}) {
        const result = {
            id, attributes, hidden: false, children: [], events: {}, scrolls: 0,
            getAttribute(name) { return this.attributes[name] ?? null; },
            setAttribute(name, value) { this.attributes[name] = value; },
            addEventListener(name, callback) { this.events[name] = callback; },
            focus() { focused = this; },
            scrollIntoView() { this.scrolls += 1; },
            contains(target) { return this === target || this.children.some(child => child.contains(target)); },
            querySelector() { return null; },
            querySelectorAll() { return []; }
        };
        nodes.set(id, result);
        return result;
    }

    const panels = (inquiry ? ["inquiry-activity", "inquiry-correspondence", "inquiry-tasks"]
        : ["chron-log", "correspondence", "engagement-tasks"]).map(id => node(id));
    const tabs = panels.map((panel, index) => node(panel.id + "-tab", {
        "aria-controls": panel.id,
        "aria-selected": String(index === selected)
    }));
    const taskSection = node("follow-up-work");
    const taskForm = node("follow-up-form");
    const nestedTask = node("task details");
    panels[2].children.push(taskSection);
    taskSection.children.push(taskForm);
    taskForm.children.push(nestedTask);
    const openNote = node("open-note");
    const addNote = node("add-note");
    const noteText = node("note-text");
    addNote.querySelector = selector => selector === "textarea" ? noteText : null;
    const group = node("record-tabs");
    group.querySelectorAll = selector => selector === '[role="tab"]' ? tabs : [];
    group.querySelector = selector => {
        if (inquiry && selector === "[data-inquiry-open-note]") return openNote;
        if (inquiry && selector === ".inquiry-add-note") return addNote;
        return null;
    };
    const document = {
        readyState: "loading",
        getElementById: id => nodes.get(id) || null,
        querySelector() { return null; },
        querySelectorAll(selector) {
            const groupSelector = inquiry ? "[data-inquiry-tabs]" : "[data-record-tabs]";
            return selector.split(", ").includes(groupSelector) ? [group] : [];
        },
        addEventListener(name, callback) { (documentEvents[name] ||= []).push(callback); }
    };
    const window = {
        location: { hash },
        history: {
            state: { retained: true }, replacements: [],
            replaceState(state, title, hash) {
                this.replacements.push({ state, hash });
                window.location.hash = hash;
            }
        },
        addEventListener(name, callback) { (windowEvents[name] ||= []).push(callback); }
    };
    const fixture = {
        panels, tabs, nodes, openNote, addNote, noteText, window,
        get focused() { return focused; },
        initialize() {
            vm.runInNewContext(source, { document, window });
            documentEvents.DOMContentLoaded.forEach(callback => callback());
        },
        changeHash(value) {
            window.location.hash = value;
            (windowEvents.hashchange || []).forEach(callback => callback());
        },
        clickAnchor(href) {
            const link = node("anchor", { href });
            const event = {
                target: { closest: selector => selector === 'a[href^="#"]' ? link : null }
            };
            (documentEvents.click || []).forEach(callback => callback(event));
        },
        key(index, key) {
            const event = { key, prevented: false, preventDefault() { this.prevented = true; } };
            tabs[index].events.keydown(event);
            return event;
        }
    };
    if (initialize) fixture.initialize();
    return fixture;
}

function assertSelected(fixture, expectedIndex) {
    fixture.tabs.forEach((tab, index) => {
        assert.equal(tab.getAttribute("aria-selected"), String(index === expectedIndex));
        assert.equal(tab.tabIndex, index === expectedIndex ? 0 : -1);
        assert.equal(fixture.panels[index].hidden, index !== expectedIndex);
    });
}

test("engagement tabs progressively enhance visible panels and switch by click", function () {
    const fixture = recordTabsFixture({ initialize: false });
    assert.ok(fixture.panels.every(panel => !panel.hidden));
    fixture.initialize();
    assertSelected(fixture, 0);
    for (const index of [1, 2, 0]) {
        fixture.tabs[index].events.click();
        assertSelected(fixture, index);
    }
});

test("record tabs support arrow wrapping, Home, End, and roving keyboard focus", function () {
    const fixture = recordTabsFixture();
    for (const [from, key, to] of [
        [0, "ArrowLeft", 2], [2, "ArrowRight", 0],
        [0, "End", 2], [2, "Home", 0],
        [0, "ArrowRight", 1], [1, "ArrowLeft", 0]
    ]) {
        assert.equal(fixture.key(from, key).prevented, true);
        assertSelected(fixture, to);
        assert.equal(fixture.focused, fixture.tabs[to]);
    }
    assert.equal(fixture.key(0, "Tab").prevented, false);
    assertSelected(fixture, 0);
});

test("initial record fragments reveal their panel, including nested task anchors", function () {
    for (const [hash, selected] of [
        ["#chron-log", 0], ["#correspondence", 1], ["#engagement-tasks", 2],
        ["#follow-up-work", 2], ["#follow-up-form", 2], ["#task%20details", 2]
    ]) {
        const fixture = recordTabsFixture({ hash });
        assertSelected(fixture, selected);
        assert.equal(fixture.focused, null);
    }
});

test("later fragment changes reveal and scroll to hidden panel content without moving focus", function () {
    const fixture = recordTabsFixture();
    fixture.changeHash("#follow-up-work");
    assertSelected(fixture, 2);
    assert.equal(fixture.nodes.get("follow-up-work").scrolls, 1);
    fixture.changeHash("#correspondence");
    assertSelected(fixture, 1);
    assert.equal(fixture.panels[1].scrolls, 1);
    fixture.changeHash("#task%20details");
    assertSelected(fixture, 2);
    assert.equal(fixture.nodes.get("task details").scrolls, 1);
    assert.equal(fixture.focused, null);

    for (const unrelatedHash of ["#unrelated-section", "", "#malformed%XX"]) {
        fixture.changeHash(unrelatedHash);
        assertSelected(fixture, 2);
    }
});

test("unrelated initial fragments preserve the server-selected tab", function () {
    assertSelected(recordTabsFixture({ selected: 1, hash: "#another-section" }), 1);
    assertSelected(recordTabsFixture({ selected: 2, hash: "#invalid%XX" }), 2);
});

test("clicking the current fragment reopens its tab after another tab was selected", function () {
    const fixture = recordTabsFixture({ hash: "#follow-up-work" });
    fixture.tabs[0].events.click();
    assertSelected(fixture, 0);
    fixture.clickAnchor("#follow-up-work");
    assertSelected(fixture, 2);
    fixture.clickAnchor("#outside-the-tabs");
    assertSelected(fixture, 2);
});

test("inquiry tabs still switch and the add-note button returns to Activity", function () {
    const fixture = recordTabsFixture({ inquiry: true, hash: "#inquiry-correspondence" });
    assertSelected(fixture, 1);
    fixture.tabs[2].events.click();
    assertSelected(fixture, 2);
    fixture.openNote.events.click();
    assertSelected(fixture, 0);
    assert.equal(fixture.addNote.open, true);
    assert.equal(fixture.focused, fixture.noteText);
});

test("selected tab survives leaving for statistics and returning to the history URL", function () {
    const fixture = recordTabsFixture();
    fixture.tabs[2].events.click();
    assert.equal(fixture.window.location.hash, '#engagement-tasks');
    assert.deepEqual(fixture.window.history.replacements, [
        { state: { retained: true }, hash: '#engagement-tasks' }
    ]);
    const returned = recordTabsFixture({ hash: fixture.window.location.hash });
    assertSelected(returned, 2);
    assert.equal(fixture.panels[2].scrolls, 0);
    fixture.key(2, 'Home');
    assert.equal(fixture.window.location.hash, '#chron-log');
    // Initial deep links retain their nested target rather than replacing it.
    const nested = recordTabsFixture({ hash: '#follow-up-form' });
    assert.equal(nested.window.history.replacements.length, 0);
});
