"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");

const projectRoot = path.resolve(__dirname, "../..");
const sourceRoot = path.join(projectRoot, "src");
const cssRoot = path.join(sourceRoot, "assets/css");

function filesWithExtension(directory, extension) {
    return fs.readdirSync(directory, { withFileTypes: true }).flatMap(function (entry) {
        const entryPath = path.join(directory, entry.name);
        if (entry.isDirectory()) return filesWithExtension(entryPath, extension);
        return entry.isFile() && entry.name.endsWith(extension) ? [entryPath] : [];
    });
}

test("record and reference tables use the shared typography contract", function () {
    for (const phpFile of filesWithExtension(sourceRoot, ".php")) {
        const source = fs.readFileSync(phpFile, "utf8");
        for (const match of source.matchAll(/<table\b([^>]*)>/gi)) {
            const attributes = match[1];
            if (/\brole\s*=\s*["']presentation["']/i.test(attributes)) continue;
            if (/\bcalendar-month-table\b/.test(attributes)) continue;

            const classMatch = attributes.match(/\bclass\s*=\s*["']([^"']*)["']/i);
            assert.ok(
                classMatch && classMatch[1].split(/\s+/).includes("data-table"),
                `${path.relative(projectRoot, phpFile)} has a table outside the shared data-table contract`
            );
        }
    }
});

test("table body and header sizes are defined only by the shared contract", function () {
    const styleSource = fs.readFileSync(path.join(cssRoot, "style.css"), "utf8");
    const modernSource = fs.readFileSync(path.join(cssRoot, "modern.css"), "utf8");

    assert.match(styleSource, /--table-body-font-size:\s*var\(--font-size-base\)/);
    assert.match(styleSource, /--table-header-font-size:\s*0\.78rem/);
    assert.match(styleSource, /--table-numeric-font-size:\s*0\.9rem/);
    assert.match(modernSource, /\.data-table\s*\{[^}]*font-size:\s*var\(--table-body-font-size\)/s);
    assert.match(modernSource, /\.data-table th\s*\{[^}]*font-size:\s*var\(--table-header-font-size\)/s);

    const protectedTableClasses = [
        "audit-table",
        "calendar-subscription-table",
        "contact-table",
        "engagement-table",
        "financial-history-table",
        "manual-table",
        "mattermost-links-table",
        "organization-table",
        "standard-task-table",
        "task-table"
    ];

    for (const cssFile of filesWithExtension(cssRoot, ".css").filter(function (file) {
        return !file.endsWith(".min.css");
    })) {
        const source = fs.readFileSync(cssFile, "utf8");
        for (const match of source.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
            if (!/\bfont-size\s*:/.test(match[2])) continue;
            for (const selector of match[1].split(",").map(function (value) {
                return value.replace(/\s+/g, " ").trim();
            })) {
                const overridesTableScale = protectedTableClasses.some(function (className) {
                    return new RegExp(`\\.${className}(?:\\s+(?:th|td))?$`).test(selector);
                });
                assert.equal(
                    overridesTableScale || /^(?:table|th|td)$/.test(selector),
                    false,
                    `${path.relative(projectRoot, cssFile)} overrides shared table sizing in ${selector}`
                );
            }
        }
    }
});
