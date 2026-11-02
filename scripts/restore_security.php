<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/database_backup_helpers.php';

$locked = false;
try {
    currentAccountProfile(); // Validate account/database pairing before any operator action.
    $action = $argv[1] ?? 'status';
    if ($action !== 'status') {
        $locked = (int) $conn->query("SELECT GET_LOCK('dnr_database_restore',0)")->fetch_row()[0] === 1;
        if (!$locked) throw new RuntimeException('Another database recovery operation is running.');
    }
    if ($action === 'prepare') {
        $state = prepareAccountRestoreSecurity();
    } elseif ($action === 'resume') {
        $generation = (string) ($argv[2] ?? '');
        if (($argv[3] ?? '') !== 'RECONCILED') throw new InvalidArgumentException('Usage: restore_security.php resume GENERATION RECONCILED');
        $state = accountRestoreSecurityState();
        if (!$state['mail_hold'] || !hash_equals($state['generation'], $generation)) throw new InvalidArgumentException('The recovery generation does not match an active mail hold.');
        // Also quarantine work queued while held, so release never implicitly sends old selections.
        $conn->begin_transaction();
        try {
            $tables = array_column(databaseBackupSchemaDescriptor($conn), 'name');
            revokeRestoredAccountCredentials($conn, $tables);
            quarantineRestoredOutboundMail($conn, $tables);
            if (!recordAuditEvent($conn, ['event_category' => 'security', 'event_type' => 'restore_mail_reconciled',
                'actor_username' => 'maintenance-cli', 'entity_type' => 'database',
                'details' => 'Operator confirmed post-snapshot provider/consent reconciliation; pending mail quarantined before release.'])) {
                throw new RuntimeException('Unable to audit recovery mail reconciliation.');
            }
            $conn->commit();
        } catch (Throwable $error) { $conn->rollback(); throw $error; }
        $state = releaseAccountRestoreMailHold($generation, 'RECONCILED');
    } elseif ($action === 'status') {
        $state = accountRestoreSecurityState();
    } else {
        throw new InvalidArgumentException('Usage: restore_security.php [status|prepare|resume GENERATION RECONCILED]');
    }
    echo json_encode(['account' => currentAccountKey()] + $state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($locked) $conn->query("SELECT RELEASE_LOCK('dnr_database_restore')");
}
