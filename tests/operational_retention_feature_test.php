<?php

declare(strict_types=1);

function expectOperationalRetention(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Operational retention feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = (string) file_get_contents(
    $root . '/migrations/20260902_add_mail_history_retention.sql'
);
$order = (string) file_get_contents($root . '/migrations/order.txt');
$command = (string) file_get_contents($root . '/scripts/prune_audit_log.php');
$wrapper = (string) file_get_contents($root . '/scripts/prune_mail_history.sh');
$grants = (string) file_get_contents($root . '/scripts/configure_database_privileges.sh');

expectOperationalRetention(
    str_contains($migration, 'CREATE PROCEDURE prune_operational_mail_history')
        && str_contains($migration, 'SQL SECURITY DEFINER')
        && str_contains($migration, "status IN ('sent', 'failed')")
        && str_contains($migration, "status IN ('processed', 'rejected', 'failed')")
        && str_contains($migration, 'mattermost_reply_notifications')
        && str_contains($migration, 'delivered_at IS NULL')
        && !str_contains($migration, "status IN ('pending'")
        && substr_count($migration, 'LIMIT p_max_rows_per_table') === 3
        && str_contains($migration, 'p_max_rows_per_table > 10000')
        && str_contains($order, '20260902_add_mail_history_retention.sql'),
    'the migration should provide indexed, terminal-state-only, bounded pruning.'
);
expectOperationalRetention(
    str_contains($command, "\$mailHistoryMode")
        && str_contains($command, 'CALL prune_operational_mail_history(?, ?)')
        && str_contains($command, 'undelivered Mattermost reply records are never eligible.')
        && str_contains($command, 'mattermost_reply_notifications')
        && str_contains($wrapper, '--mail-history')
        && str_contains($wrapper, '[PRUNE]'),
    'the maintenance command should preview by default and require explicit confirmation.'
);
expectOperationalRetention(
    substr_count(
        $grants,
        'GRANT EXECUTE ON PROCEDURE \`${MYSQL_DATABASE}\`.prune_operational_mail_history'
    ) === 1
        && str_contains(
            $grants,
            "prune_operational_mail_history TO '\${maintenance_user}'@'%';"
        ),
    'only the isolated maintenance identity should receive mail-history routine execution.'
);

echo "Operational retention feature tests passed.\n";
