<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This maintenance command is available only from the CLI.\n");
    exit(1);
}

$retentionArgument = $argv[1] ?? '';
$confirmation = $argv[2] ?? '';
if (in_array($retentionArgument, ['-h', '--help'], true)) {
    fwrite(STDOUT, "Usage: prune_audit_log.php DAYS_TO_KEEP [PRUNE]\n");
    fwrite(STDOUT, "Omit PRUNE to preview the UTC cutoff and number of entries that would be deleted.\n");
    exit(0);
}
if (count($argv) > 3
    || preg_match('/\A[1-9][0-9]{0,4}\z/', $retentionArgument) !== 1
    || (int) $retentionArgument > 36500
) {
    fwrite(STDERR, "DAYS_TO_KEEP must be a whole number from 1 through 36500.\n");
    fwrite(STDERR, "Usage: prune_audit_log.php DAYS_TO_KEEP [PRUNE]\n");
    exit(64);
}
if ($confirmation !== '' && $confirmation !== 'PRUNE') {
    fwrite(STDERR, "Audit log not changed. Pass the literal confirmation word PRUNE, or omit it for a preview.\n");
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

$pruneLockAcquired = false;
$transactionStarted = false;
$exitCode = 0;
try {
    $lockResult = $conn->query("SELECT GET_LOCK('dnr_audit_log_prune', 0) AS acquired");
    $pruneLockAcquired = (int) ($lockResult->fetch_assoc()['acquired'] ?? 0) === 1;
    if (!$pruneLockAcquired) {
        throw new RuntimeException('Another audit-log prune is already running.');
    }

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
        $conn->begin_transaction();
        $transactionStarted = true;

        $deleteStatement = $conn->prepare('DELETE FROM security_audit_log WHERE created_at < ?');
        $deleteStatement->bind_param('s', $cutoff);
        $deleteStatement->execute();
        $deletedCount = $deleteStatement->affected_rows;
        $deleteStatement->close();

        $details = sprintf(
            'Maintenance command permanently deleted %d entries older than %s UTC; retained %d days',
            $deletedCount,
            $cutoff,
            $retentionDays
        );
        $eventStatement = $conn->prepare(
            "INSERT INTO security_audit_log (
                actor_username, event_category, event_type, entity_type,
                entity_label, details, ip_address
             ) VALUES (
                'maintenance-cli', 'security', 'audit_log_pruned', 'audit_log',
                'DNR audit log', ?, 'local-maintenance'
             )"
        );
        $eventStatement->bind_param('s', $details);
        $eventStatement->execute();
        $eventStatement->close();

        $conn->commit();
        $transactionStarted = false;
        fwrite(STDOUT, sprintf(
            "Pruned %d audit %s older than %s UTC; retained the most recent %d days.\n",
            $deletedCount,
            $deletedCount === 1 ? 'entry' : 'entries',
            $cutoff,
            $retentionDays
        ));
    }
} catch (Throwable $exception) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    fwrite(STDERR, "Audit-log prune failed: " . $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($pruneLockAcquired) {
        $conn->query("SELECT RELEASE_LOCK('dnr_audit_log_prune')");
    }
}
exit($exitCode);
