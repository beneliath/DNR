<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/database_backup_client.php';
startSecureSession();
requireAdmin();
requireTwoFactorSchema($conn);
requireAuditLogSchema($conn);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

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
        $encrypted_backup = null;
        $download_completed = false;
        $previous_ignore_user_abort = ignore_user_abort(true);
        try {
            if (strlen($backup_password) < DNR_DATABASE_BACKUP_MINIMUM_PASSWORD_BYTES
                || strlen($backup_password) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $backup_password)) {
                $error = 'The backup encryption password must contain 16–1024 characters without control characters.';
            } elseif (!hash_equals($backup_password, $backup_password_confirmation)) {
                $error = 'The backup encryption passwords do not match.';
            } else {
                releaseApplicationSessionLock();
                set_time_limit(320);
                $encrypted_backup = requestEncryptedDatabaseBackup([
                    'user_id' => $actor_id, 'auth_version' => (int) $actor['auth_version'],
                    'admin_password' => $admin_password, 'admin_code' => $admin_code,
                    'backup_password' => $backup_password,
                ], $maximum_backup_bytes);
                $filename = 'dnr-database-' . gmdate('Ymd-His') . 'Z.dnrbackup';
                $download_token = $_POST['download_token'] ?? null;
                if (is_string($download_token) && preg_match('/\A[a-f0-9]{32}\z/', $download_token)) {
                    // Acknowledge archive creation without buffering large downloads in JavaScript.
                    // This signals the download response, not a completed save on the user's disk.
                    setcookie('dnr_backup_' . $download_token, json_encode([
                        'filename' => $filename,
                        'createdAt' => applicationTimestampLabel(gmdate('Y-m-d H:i:s'), 'M j, Y g:i:s A T'),
                    ], JSON_THROW_ON_ERROR), [
                        'expires' => time() + 600,
                        'path' => '/',
                        'secure' => requestUsesHttps() || applicationRequiresHttps(),
                        'httponly' => false,
                        'samesite' => 'Strict',
                    ]);
                }
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . $encrypted_backup['size']);
                header('X-Content-Type-Options: nosniff');
                if (readfile($encrypted_backup['path']) === false) throw new RuntimeException('Unable to stream the encrypted backup.');
                $download_completed = true;
            }
        } catch (Throwable $exception) {
            applicationLog('error', 'Database backup request failed');
            $error = $exception->getMessage();
        } finally {
            if ($encrypted_backup !== null) @unlink($encrypted_backup['path']);
            ignore_user_abort((bool) $previous_ignore_user_abort);
        }
        if ($download_completed) {
            exit();
        }
    }
}
$estimated_backup_bytes = null;
try {
    $estimated_backup_bytes = databaseBackupEstimatedBytes($conn);
} catch (Throwable $exception) {
    applicationLog('warning', 'Backup size estimate unavailable');
}
$last_backup_label = 'No successful backup recorded.';
try {
    $last_backup_result = $conn->query("SELECT created_at FROM security_audit_log
        WHERE event_type = 'database_backup_created'
        ORDER BY created_at DESC, id DESC LIMIT 1");
    if (!$last_backup_result) throw new RuntimeException('Unable to read backup history.');
    $last_backup = $last_backup_result->fetch_assoc();
    if ($last_backup) {
        $last_backup_label = applicationTimestampLabel($last_backup['created_at'], 'M j, Y g:i:s A T');
    }
} catch (Throwable $exception) {
    applicationLog('warning', 'Backup history unavailable');
    $last_backup_label = 'Backup history is temporarily unavailable.';
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
  'scripts' => ['assets/js/database-backup.min.js'],
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
        <p class="error" id="database-backup-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
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
            <p class="database-backup-history">Last backup created:<br>
                <strong id="database-backup-last-created"><?php echo htmlspecialchars($last_backup_label, ENT_QUOTES, 'UTF-8'); ?></strong>
            </p>
            <?php if ($estimated_backup_bytes !== null): ?>
                <p class="<?php echo $estimated_backup_bytes >= $maximum_backup_bytes * 0.8 ? 'database-warning' : 'maintenance-note'; ?>">
                    Estimated backup size: <?php echo htmlspecialchars(databaseBackupMaximumSizeLabel($estimated_backup_bytes)); ?>
                    of <?php echo htmlspecialchars(databaseBackupMaximumSizeLabel($maximum_backup_bytes)); ?>.
                    This estimate uses database statistics; the actual export may be larger.
                    <?php if ($estimated_backup_bytes >= $maximum_backup_bytes * 0.8): ?>
                        Capacity is approaching the export limit. Arrange a database-native backup before adding more attachments.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <p>
                Creates a consistent snapshot of every DNR table, encrypts and authenticates the
                complete archive with your password, and downloads it as a <code>.dnrbackup</code> file.
            </p>
            <form method="post" action="database_maintenance.php" autocomplete="off" id="database-backup-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="backup">
                <input type="hidden" name="download_token" id="database-backup-token" value="">

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
            <p id="database-backup-status" class="database-backup-status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
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
