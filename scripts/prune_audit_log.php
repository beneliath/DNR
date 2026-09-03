<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This maintenance command is available only from the CLI.\n");
    exit(1);
}

$mailHistoryMode = ($argv[1] ?? '') === '--mail-history';
$argumentOffset = $mailHistoryMode ? 1 : 0;
$retentionArgument = $argv[1 + $argumentOffset] ?? '';
$confirmation = $argv[2 + $argumentOffset] ?? '';
if (in_array($retentionArgument, ['-h', '--help'], true)) {
    fwrite(STDOUT, "Usage: prune_audit_log.php DAYS_TO_KEEP [PRUNE]\n");
    fwrite(STDOUT, "       prune_audit_log.php --mail-history DAYS_TO_KEEP [PRUNE]\n");
    fwrite(STDOUT, "Omit PRUNE to preview the UTC cutoff and number of eligible rows.\n");
    exit(0);
}
if (count($argv) > 3 + $argumentOffset
    || preg_match('/\A[1-9][0-9]{0,4}\z/', $retentionArgument) !== 1
    || (int) $retentionArgument > 36500
    || ($mailHistoryMode && (int) $retentionArgument < 30)
) {
    $minimumDays = $mailHistoryMode ? 30 : 1;
    fwrite(STDERR, "DAYS_TO_KEEP must be a whole number from {$minimumDays} through 36500.\n");
    fwrite(STDERR, "Use --mail-history before DAYS_TO_KEEP for operational mail retention.\n");
    exit(64);
}
if ($confirmation !== '' && $confirmation !== 'PRUNE') {
    fwrite(STDERR, "Data not changed. Pass the literal confirmation word PRUNE, or omit it for a preview.\n");
    exit(64);
}

$retentionDays = (int) $retentionArgument;
$cutoff = (new DateTimeImmutable('today', new DateTimeZone('UTC')))
    ->sub(new DateInterval("P{$retentionDays}D"))
    ->format('Y-m-d H:i:s');

define('DNR_DATABASE_FAILURES_THROW', true);
$sourceDirectory = trim((string) (getenv('DNR_APPLICATION_SOURCE_DIR') ?: ''));
if ($sourceDirectory === '') {
    $sourceDirectory = is_file('/var/www/html/config.php')
        ? '/var/www/html'
        : dirname(__DIR__) . '/src';
}
require_once $sourceDirectory . '/config.php';

if ($mailHistoryMode) {
    $eligibleCounts = [];
    $previewQueries = [
        'account-email outbox rows' =>
            "SELECT COUNT(*) AS row_count FROM email_outbox
             WHERE status IN ('sent', 'failed') AND updated_at < ?",
        'notification outbox rows' =>
            "SELECT COUNT(*) AS row_count FROM notification_outbox
             WHERE status IN ('sent', 'failed') AND updated_at < ?",
        'inbound source messages' =>
            "SELECT COUNT(*) AS row_count FROM inbound_email_messages
             WHERE status IN ('processed', 'rejected', 'failed') AND received_at < ?
               AND NOT EXISTS (
                   SELECT 1 FROM mattermost_reply_notifications
                   WHERE inbound_email_message_id = inbound_email_messages.id
                     AND delivered_at IS NULL
               )",
    ];
    try {
        foreach ($previewQueries as $label => $sql) {
            $statement = $conn->prepare($sql);
            if (!$statement) {
                throw new RuntimeException('Unable to prepare the mail-history preview.');
            }
            $statement->bind_param('s', $cutoff);
            $statement->execute();
            $eligibleCounts[$label] = (int) (
                $statement->get_result()->fetch_assoc()['row_count'] ?? 0
            );
            $statement->close();
        }

        if ($confirmation === '') {
            fwrite(STDOUT, "Preview of terminal mail history older than {$cutoff} UTC:\n");
            foreach ($eligibleCounts as $label => $count) {
                fwrite(STDOUT, sprintf("  %d %s\n", $count, $label));
            }
            fwrite(STDOUT, "Pending, retrying, processing, review, and undelivered Mattermost reply records are never eligible.\n");
            fwrite(STDOUT, "Repeat with PRUNE to delete at most 10000 rows from each category.\n");
            exit(0);
        }

        $maximumRowsPerTable = 10000;
        $statement = $conn->prepare('CALL prune_operational_mail_history(?, ?)');
        if (!$statement) {
            throw new RuntimeException('Unable to prepare the mail-history prune.');
        }
        $statement->bind_param('ii', $retentionDays, $maximumRowsPerTable);
        $statement->execute();
        $result = $statement->get_result();
        $outcome = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        while ($statement->more_results() && $statement->next_result()) {
            $extraResult = $statement->get_result();
            if ($extraResult) {
                $extraResult->free();
            }
        }
        $statement->close();
        if (!is_array($outcome)) {
            throw new RuntimeException('The mail-history prune returned no result.');
        }
        fwrite(STDOUT, sprintf(
            "Pruned %d account-email outbox rows, %d notification rows, and %d inbound source messages older than %s UTC.\n",
            (int) ($outcome['email_outbox_count'] ?? 0),
            (int) ($outcome['notification_outbox_count'] ?? 0),
            (int) ($outcome['inbound_message_count'] ?? 0),
            (string) ($outcome['cutoff_utc'] ?? $cutoff)
        ));
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, "Mail-history prune failed: {$exception->getMessage()}\n");
        exit(1);
    }
}

$exitCode = 0;
try {
    $countStatement = $conn->prepare(
        'SELECT COUNT(*) AS entry_count FROM security_audit_log WHERE created_at < ?'
    );
    $countStatement->bind_param('s', $cutoff);
    $countStatement->execute();
    $entryCount = (int) ($countStatement->get_result()->fetch_assoc()['entry_count'] ?? 0);
    $countStatement->close();

    if ($confirmation === '') {
        fwrite(STDOUT, sprintf(
            "Preview: %d audit %s older than %s UTC would be permanently deleted; the most recent %d days would be kept.\n",
            $entryCount,
            $entryCount === 1 ? 'entry' : 'entries',
            $cutoff,
            $retentionDays
        ));
        fwrite(STDOUT, "Run the same command with PRUNE after the retention period and count have been reviewed.\n");
    } elseif ($entryCount === 0) {
        fwrite(STDOUT, "Nothing to prune. No audit entries are older than {$cutoff} UTC.\n");
    } else {
        $actorId = null;
        $actorUsername = 'maintenance-cli';
        $requestIp = 'local-maintenance';
        $pruneStatement = $conn->prepare(
            'CALL prune_security_audit_log(?, ?, ?, ?)'
        );
        if (!$pruneStatement) {
            throw new RuntimeException('Unable to prepare the bounded audit-log prune.');
        }
        $pruneStatement->bind_param(
            'iiss',
            $retentionDays,
            $actorId,
            $actorUsername,
            $requestIp
        );
        $pruneStatement->execute();
        $result = $pruneStatement->get_result();
        $outcome = $result ? $result->fetch_assoc() : null;
        if ($result) {
            $result->free();
        }
        while ($pruneStatement->more_results() && $pruneStatement->next_result()) {
            $extraResult = $pruneStatement->get_result();
            if ($extraResult) {
                $extraResult->free();
            }
        }
        $pruneStatement->close();
        if (!is_array($outcome)) {
            throw new RuntimeException('The bounded audit-log prune returned no result.');
        }
        $deletedCount = (int) ($outcome['deleted_count'] ?? 0);
        $moreEntries = !empty($outcome['more_entries']);
        fwrite(STDOUT, sprintf(
            "Pruned %d audit %s older than %s UTC in bounded batches; retained the most recent %d days.%s\n",
            $deletedCount,
            $deletedCount === 1 ? 'entry' : 'entries',
            $cutoff,
            $retentionDays,
            $moreEntries ? ' More expired entries remain; run the reviewed command again.' : ''
        ));
    }
} catch (Throwable $exception) {
    fwrite(STDERR, "Audit-log prune failed: " . $exception->getMessage() . "\n");
    $exitCode = 1;
}
exit($exitCode);
