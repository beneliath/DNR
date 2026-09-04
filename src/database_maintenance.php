<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/database_backup_helpers.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);
requireAuditLogSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

function databaseMaintenanceAuthenticationAccepted(mysqli $conn, array $actor, $password, $code, $action) {
    $actor_id = (int) $actor['id'];
    $failure_event = 'database_backup_auth_failed';

    if (!\Dnr\Security\PasswordPolicy::verify($password, $actor['password'])) {
        if (empty($actor['login_is_locked'])) {
            recordAuthenticationFailure($conn, $actor_id, 'password');
        }
        logSecurityEvent($conn, $failure_event, $actor_id, $actor_id);
        return false;
    }
    if (empty($actor['two_factor_enabled']) || !empty($actor['two_factor_is_locked'])) {
        return false;
    }

    resetAuthenticationFailures($conn, $actor_id, 'password');
    $normalized_code = trim((string) $code);
    $is_totp_code = preg_match('/^[0-9]{6}$/', $normalized_code) === 1;
    $factor_verified = $is_totp_code
        ? verifyAndConsumeTotp($conn, $actor, $normalized_code)
        : consumeRecoveryCode($conn, $actor_id, $normalized_code);

    if (!$factor_verified) {
        recordAuthenticationFailure($conn, $actor_id, 'two_factor');
        logSecurityEvent($conn, $failure_event, $actor_id, $actor_id);
        return false;
    }

    resetAuthenticationFailures($conn, $actor_id, 'two_factor');
    return true;
}

function acquireDatabaseBackupOperationLock(mysqli $conn): bool {
    $result = $conn->query("SELECT GET_LOCK('dnr_database_backup_export', 0) AS lock_acquired");
    if (!$result) {
        throw new RuntimeException('Unable to check the database backup operation lock.');
    }
    return (int) ($result->fetch_assoc()['lock_acquired'] ?? 0) === 1;
}

function releaseDatabaseBackupOperationLock(mysqli $conn): void {
    if (!$conn->query("DO RELEASE_LOCK('dnr_database_backup_export')")) {
        applicationLog('warning', 'Unable to release the database backup operation lock');
    }
}

$maximum_backup_bytes = databaseBackupMaximumBytes();
$actor_id = (int) $_SESSION['user_id'];
$actor = fetchAuthenticationUserById($conn, $actor_id);
if (!$actor) {
    http_response_code(403);
    exit('Forbidden.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $admin_password = is_string($_POST['admin_password'] ?? null)
        ? $_POST['admin_password']
        : '';
    $admin_code = is_string($_POST['admin_code'] ?? null)
        ? $_POST['admin_code']
        : '';
    $backup_password = is_string($_POST['backup_password'] ?? null)
        ? $_POST['backup_password']
        : '';

    if ($action !== 'backup') {
        http_response_code(400);
        $error = 'Select a valid database maintenance action.';
    } else {
        $backup_password_confirmation = is_string($_POST['backup_password_confirmation'] ?? null)
            ? $_POST['backup_password_confirmation']
            : '';
        $backup = null;
        $backup_connection = null;
        $encrypted_backup = null;
        $backup_lock_acquired = false;
        $download_completed = false;
        $previous_ignore_user_abort = null;
        $backup_started_at = microtime(true);
        try {
            if (strlen($backup_password) < DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES) {
                $error = 'The backup encryption password must contain at least '
                    . DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES . ' characters.';
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $backup_password) === 1) {
                $error = 'The backup encryption password cannot contain control characters.';
            } elseif (!hash_equals($backup_password, $backup_password_confirmation)) {
                $error = 'The backup encryption passwords do not match.';
            } elseif (!($backup_lock_acquired = acquireDatabaseBackupOperationLock($conn))) {
                $error = 'Another database backup is already in progress. Try again after it finishes.';
            } elseif (!databaseMaintenanceAuthenticationAccepted(
                $conn,
                $actor,
                $admin_password,
                $admin_code,
                'backup'
            )) {
                $error = 'Your administrator password or authentication code was not accepted.';
            } else {
                // The authenticated backup can take minutes on a large dataset.
                // Persist the consumed factor and let this user's other requests proceed.
                releaseApplicationSessionLock();
                set_time_limit(300);
                $backup_connection = databaseBackupConnection();
                $backup = createDatabaseBackup(
                    $backup_connection,
                    APP_VERSION,
                    $maximum_backup_bytes
                );
                $backup_connection->close();
                $backup_connection = null;
                $encrypted_backup = encryptDatabaseBackup(
                    $backup['path'],
                    $backup_password,
                    $maximum_backup_bytes
                );
                if (is_file($backup['path']) && !@unlink($backup['path'])) {
                    throw new RuntimeException(
                        'Unable to remove the plaintext database backup after encryption.'
                    );
                }
                $backup = null;

                $audit_recorded = recordAuditEvent($conn, [
                    'event_category' => 'security',
                    'event_type' => 'database_backup_created',
                    'actor_user_id' => $actor_id,
                    'target_user_id' => $actor_id,
                    'entity_type' => 'database',
                    'entity_label' => 'DNR database',
                    'details' => sprintf(
                        'Encrypted backup: %d bytes in %d ms',
                        (int) $encrypted_backup['size'],
                        (int) round((microtime(true) - $backup_started_at) * 1000)
                    ),
                ]);
                if (!$audit_recorded) {
                    throw new RuntimeException('Unable to record the database backup audit event.');
                }
                $filename = 'dnr-database-' . gmdate('Ymd-His') . 'Z.dnrbackup';

                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . $encrypted_backup['size']);
                header('X-Content-Type-Options: nosniff');
                $previous_ignore_user_abort = ignore_user_abort(true);
                if (readfile($encrypted_backup['path']) === false) {
                    throw new RuntimeException('Unable to stream the encrypted database backup.');
                }
                $download_completed = true;
            }
        } catch (Throwable $exception) {
            applicationLog('error', 'Database backup failed', ['error' => $exception->getMessage()]);
            $error = 'The database backup could not be created. No database data was changed.';
        } finally {
            if ($backup_connection instanceof mysqli) {
                $backup_connection->close();
            }
            if (is_array($backup) && isset($backup['path'])) {
                @unlink($backup['path']);
            }
            if (is_array($encrypted_backup) && isset($encrypted_backup['path'])) {
                @unlink($encrypted_backup['path']);
            }
            if ($backup_lock_acquired) {
                releaseDatabaseBackupOperationLock($conn);
            }
            if ($previous_ignore_user_abort !== null) {
                ignore_user_abort((bool) $previous_ignore_user_abort);
            }
        }
        if ($download_completed) {
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Database Backup'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/database_maintenance.min.css',
  ),
)); ?>
<body class="database-maintenance-body">
<?php include 'templates/header.php'; ?>
<main class="container database-maintenance-page">
    <div class="page-heading database-maintenance-heading">
        <div>
            <h1>Database Backup</h1>
            <p class="page-intro">Download a password-encrypted snapshot. Restore access is isolated from the web application.</p>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <p class="database-warning">
        Backups are encrypted with the password you choose, but should still be kept private. They
        contain user accounts, password hashes, encrypted authenticator secrets, recovery-code hashes,
        contacts, and all engagement data. A lost backup password cannot be recovered.
        The separate DNR two-factor encryption key is not included and must also be backed up securely.
    </p>

    <div class="database-maintenance-grid">
        <section class="database-maintenance-card">
            <h2>Export Backup</h2>
            <p>
                Creates a consistent snapshot of every DNR table, encrypts and authenticates the
                complete archive with your password, and downloads it as a <code>.dnrbackup</code> file.
            </p>
            <form method="post" action="database_maintenance.php" autocomplete="off">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="backup">

                <label for="backup_admin_password">Your Administrator Password</label>
                <input type="password" name="admin_password" id="backup_admin_password" autocomplete="current-password" maxlength="72" required>

                <label for="backup_admin_code">Fresh Authenticator Code or Recovery Code</label>
                <input type="text" name="admin_code" id="backup_admin_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>

                <label for="backup_password">New Backup Encryption Password</label>
                <input type="password" name="backup_password" id="backup_password" autocomplete="new-password" minlength="<?php echo DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES; ?>" required>

                <label for="backup_password_confirmation">Confirm Backup Encryption Password</label>
                <input type="password" name="backup_password_confirmation" id="backup_password_confirmation" autocomplete="new-password" minlength="<?php echo DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES; ?>" required>

                <button type="submit" class="button-add">Encrypt and Download Backup</button>
            </form>
            <p class="maintenance-note">
                Encryption uses Argon2id and XChaCha20-Poly1305. Maximum backup data size:
                <?php echo htmlspecialchars(databaseBackupMaximumSizeLabel($maximum_backup_bytes)); ?>.
            </p>
        </section>

        <section class="database-maintenance-card">
            <h2>Restore Procedure</h2>
            <p>Restore is intentionally unavailable to the web process. From the deployment host:</p>
            <ol>
                <li>Confirm that the matching DNR release, Docker Compose, the original 2FA encryption key, all database secret files, the encrypted backup, and its exact password are available.</li>
                <li>Place the backup in <code>backups/</code>, create <code>secrets/backup_password</code> with the exact password and no trailing newline, then set both files to mode <code>600</code>.</li>
                <li>Create a separate root-level SQL safety dump of the current database.</li>
                <li>Stop <code>web</code> and <code>geocoder</code> so no request can write during restoration.</li>
                <li>Run <code>docker compose --profile maintenance run --rm --no-deps maintenance /backups/FILE.dnrbackup RESTORE</code>.</li>
                <li>Run the migration command and database privilege configuration command, start <code>web</code> and <code>geocoder</code>, and wait for the web health check to pass.</li>
                <li>Sign in with an account contained in the restored backup, verify a representative record and the <code>database_restored</code> audit event, then securely remove the temporary backup-password file.</li>
            </ol>
            <p class="maintenance-note">The authoritative copy-and-paste procedure—including prerequisites, a clean disaster-recovery database, verification, failure cleanup, and SQL safety-dump rollback—is in README.md under “Exact database restore runbook.” Follow every numbered step; do not skip the safety dump, service stop, or session invalidation.</p>
        </section>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
