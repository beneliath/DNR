<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/data_maintenance_helpers.php';
$original = getenv('DNR_AI_HISTORY_RETENTION_DAYS');
try {
    foreach (['' => 90, '0' => 0, '30' => 30, '90' => 90, '36500' => 36500] as $value => $expected) {
        putenv('DNR_AI_HISTORY_RETENTION_DAYS=' . $value);
        if (aiHistoryRetentionDays() !== $expected) throw new RuntimeException('Incorrect retention setting');
    }
    putenv('DNR_AI_HISTORY_RETENTION_DAYS');
    if (aiHistoryRetentionDays() !== 90) throw new RuntimeException('Unset policy must default to 90 days');
    foreach (['-1', '29', '36501', 'forever', '90.5'] as $value) {
        putenv('DNR_AI_HISTORY_RETENTION_DAYS=' . $value);
        try { aiHistoryRetentionDays(); throw new LogicException('Invalid retention accepted'); }
        catch (RuntimeException $expected) {}
    }
    echo "Data maintenance policy tests passed.\n";
} finally {
    putenv($original === false ? 'DNR_AI_HISTORY_RETENTION_DAYS' : 'DNR_AI_HISTORY_RETENTION_DAYS=' . $original);
}
