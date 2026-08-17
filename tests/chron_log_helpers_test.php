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

echo "Chron log helper tests passed.\n";
