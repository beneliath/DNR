<?php

declare(strict_types=1);

require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/database_backup_helpers.php';

/** Runs only in the isolated exporter, using current database authorization. */
function createAuthenticatedDatabaseExport(mysqli $conn, array $request, string $application_version): array {
    $maximum = databaseBackupMaximumBytes();
    $backup = $encrypted = null;
    $backupConnection = null;
    $locked = false;
    $started = microtime(true);
    foreach (['admin_password', 'admin_code', 'backup_password'] as $field) {
        if (!is_string($request[$field] ?? null)) throw new InvalidArgumentException('Invalid backup request.');
    }
    $password = $request['backup_password'];
    if (strlen($password) < DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES || strlen($password) > 1024
        || preg_match('/[\x00-\x1F\x7F]/', $password)) {
        throw new InvalidArgumentException('The backup encryption password must contain 16–1024 characters without control characters.');
    }
    try {
        $locked = (int) $conn->query("SELECT GET_LOCK('dnr_database_backup_export', 0)")->fetch_row()[0] === 1;
        if (!$locked) throw new RuntimeException('Another database backup is already in progress.', 409);
        $actor = fetchAuthenticationUserById($conn, (int) ($request['user_id'] ?? 0));
        if (!$actor || $actor['account_status'] !== 'active' || $actor['role'] !== 'admin'
            || (int) $actor['auth_version'] !== (int) ($request['auth_version'] ?? 0)
            || empty($actor['two_factor_enabled']) || !empty($actor['login_is_locked'])
            || !empty($actor['two_factor_is_locked']) || !empty($actor['must_change_password'])) {
            throw new RuntimeException('Your administrator password or authentication code was not accepted.', 403);
        }
        $id = (int) $actor['id'];
        setDatabaseAuditContext($conn, $id, (string) $actor['username']);
        if (!\Dnr\Security\PasswordPolicy::verify($request['admin_password'], $actor['password'])) {
            recordAuthenticationFailure($conn, $id, 'password');
            logSecurityEvent($conn, 'database_backup_auth_failed', $id, $id);
            throw new RuntimeException('Your administrator password or authentication code was not accepted.', 403);
        }
        $code = trim($request['admin_code']);
        $verified = preg_match('/^[0-9]{6}$/', $code)
            ? verifyAndConsumeTotp($conn, $actor, $code)
            : consumeRecoveryCode($conn, $id, $code);
        if (!$verified) {
            recordAuthenticationFailure($conn, $id, 'two_factor');
            logSecurityEvent($conn, 'database_backup_auth_failed', $id, $id);
            throw new RuntimeException('Your administrator password or authentication code was not accepted.', 403);
        }
        resetAuthenticationFailures($conn, $id, 'password');
        resetAuthenticationFailures($conn, $id, 'two_factor');
        // No full-schema connection exists until fresh password AND 2FA succeed.
        $backupConnection = databaseBackupConnection();
        $backup = createDatabaseBackup($backupConnection, $application_version, $maximum);
        $backupConnection->close();
        $backupConnection = null;
        $encrypted = encryptDatabaseBackup($backup['path'], $password, $maximum);
        if (!unlink($backup['path'])) throw new RuntimeException('Unable to remove the plaintext backup.');
        $backup = null;
        $current = fetchAuthenticationUserById($conn, $id);
        if (!$current || $current['account_status'] !== 'active' || $current['role'] !== 'admin'
            || (int) $current['auth_version'] !== (int) $actor['auth_version']) {
            throw new RuntimeException('Administrator access changed during the export.', 403);
        }
        if (!recordAuditEvent($conn, [
            'event_category' => 'security', 'event_type' => 'database_backup_created',
            'actor_user_id' => $id, 'target_user_id' => $id, 'entity_type' => 'database',
            'entity_label' => 'DNR database', 'details' => sprintf('Encrypted backup: %d bytes in %d ms',
                $encrypted['size'], (int) round((microtime(true) - $started) * 1000)),
        ])) throw new RuntimeException('Unable to record the backup audit event.');
        $result = $encrypted;
        $encrypted = null;
        return $result;
    } finally {
        if ($backupConnection instanceof mysqli) $backupConnection->close();
        foreach ([$backup, $encrypted] as $temporary) {
            if (is_array($temporary)) @unlink($temporary['path']);
        }
        if ($locked) $conn->query("DO RELEASE_LOCK('dnr_database_backup_export')");
    }
}
