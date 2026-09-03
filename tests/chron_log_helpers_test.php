<?php
require_once __DIR__ . '/../src/chron_log_helpers.php';

function expectChronLog($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Chron log helper test failed: {$message}\n");
        exit(1);
    }
}

putenv('DNR_TIMEZONE=America/Chicago');

$entries = [
    [
        'id' => 1,
        'entry_text' => 'Called venue about setup.',
        'created_at' => '2026-08-15 14:00:00',
        'created_by_username' => 'Alex Editor',
    ],
    [
        'id' => 3,
        'entry_text' => 'Confirmed room reservation.',
        'created_at' => '2026-08-16 16:00:00',
        'created_by_username' => 'Jordan Admin',
    ],
    [
        'id' => 2,
        'entry_text' => 'Sent reminder email.',
        'created_at' => '2026-08-16 16:00:00',
        'created_by_username' => 'Alex Editor',
    ],
];

$sorted = sortChronLogEntriesReverseChronological($entries);
expectChronLog(
    array_column($sorted, 'id') === [3, 2, 1],
    'entries should sort by created timestamp and ID, newest first.'
);
expectChronLog(
    chronLogTimestampDetails('2026-08-16 16:00:00')['display']
        === 'August 16, 2026 at 11:00 AM CDT',
    'UTC database timestamps should display in the configured timezone.'
);

$linked_entry = renderChronLogEntryHtml(
    "Source: https://example.test/posts/42?view=full&from=chron.\n<script>alert('unsafe')</script>"
);
expectChronLog(
    str_contains(
        $linked_entry,
        '<a href="https://example.test/posts/42?view=full&amp;from=chron" target="_blank" rel="noopener noreferrer">https://example.test/posts/42?view=full&amp;from=chron</a>.'
    ),
    'absolute HTTP(S) URLs should render as external links without absorbing sentence punctuation.'
);
expectChronLog(
    str_contains($linked_entry, "<br />\n&lt;script&gt;alert(&#039;unsafe&#039;)&lt;/script&gt;")
        && !str_contains($linked_entry, '<script>'),
    'Chron link rendering should preserve line breaks and escape non-link HTML.'
);

$parenthesized_entry = renderChronLogEntryHtml(
    'See (https://example.test/wiki/Function_(mathematics)) and http://example.test/plain. javascript:alert(1)'
);
expectChronLog(
    str_contains(
        $parenthesized_entry,
        '<a href="https://example.test/wiki/Function_(mathematics)" target="_blank" rel="noopener noreferrer">https://example.test/wiki/Function_(mathematics)</a>'
    )
        && str_contains(
            $parenthesized_entry,
            '<a href="http://example.test/plain" target="_blank" rel="noopener noreferrer">http://example.test/plain</a>.'
        )
        && substr_count($parenthesized_entry, '<a href=') === 2
        && !str_contains($parenthesized_entry, 'href="javascript:'),
    'multiple HTTP(S) URLs should link while balanced parentheses stay included and other schemes stay plain.'
);

echo "Chron log helper tests passed.\n";
