<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/application_runtime.php';

function expectApplicationClock(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Application clock test failed: {$message}\n");
        exit(1);
    }
}

$original_timezone = getenv('DNR_TIMEZONE');

putenv('DNR_TIMEZONE=America/Chicago');
$near_midnight_utc = new DateTimeImmutable('2026-08-23 01:30:00', new DateTimeZone('UTC'));
expectApplicationClock(
    applicationTimezoneName() === 'America/Chicago'
        && applicationBusinessDate($near_midnight_utc) === '2026-08-22'
        && applicationBusinessDateOffset(-2, $near_midnight_utc) === '2026-08-20'
        && applicationBusinessDateOffset(7, $near_midnight_utc) === '2026-08-29',
    'business dates and rolling windows should follow the configured timezone around UTC midnight.'
);
expectApplicationClock(
    applicationTimestampLabel('2026-08-23 01:30:00') === '2026-08-22 20:30',
    'database UTC timestamps should display in the configured timezone.'
);

putenv('DNR_TIMEZONE=not/a-timezone');
expectApplicationClock(
    applicationTimezoneName() === 'UTC'
        && applicationBusinessDate($near_midnight_utc) === '2026-08-23',
    'an invalid timezone should fail closed to UTC.'
);

if ($original_timezone === false) {
    putenv('DNR_TIMEZONE');
} else {
    putenv('DNR_TIMEZONE=' . $original_timezone);
}

echo "Application clock tests passed.\n";
