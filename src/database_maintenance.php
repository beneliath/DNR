<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
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

    if (!empty($actor['login_is_locked']) || !password_verify((string) $password, $actor['password'])) {
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
        $encrypted_backup = null;
        try {
            if (strlen($backup_password) < 12) {
                $error = 'The backup encryption password must contain at least 12 characters.';
            } elseif (preg_match('/[\x00-\x1F\x7F]/', $backup_password) === 1) {
                $error = 'The backup encryption password cannot contain control characters.';
            } elseif (!hash_equals($backup_password, $backup_password_confirmation)) {
                $error = 'The backup encryption passwords do not match.';
            } elseif (!databaseMaintenanceAuthenticationAccepted(
                $conn,
                $actor,
                $admin_password,
                $admin_code,
                'backup'
            )) {
                $error = 'Your administrator password or authentication code was not accepted.';
            } else {
                $backup = createDatabaseBackup($conn, APP_VERSION, $maximum_backup_bytes);
                $encrypted_backup = encryptDatabaseBackup(
                    $backup['path'],
                    $backup_password,
                    $maximum_backup_bytes
                );
                @unlink($backup['path']);
                $backup = null;

                $audit_recorded = recordAuditEvent($conn, [
                    'event_category' => 'security',
                    'event_type' => 'database_backup_created',
                    'actor_user_id' => $actor_id,
                    'target_user_id' => $actor_id,
                    'entity_type' => 'database',
                    'entity_label' => 'DNR database',
                    'details' => 'Administrator created a password-encrypted database backup',
                ]);
                if (!$audit_recorded) {
                    throw new RuntimeException('Unable to record the database backup audit event.');
                }
                $filename = 'dnr-database-' . gmdate('Ymd-His') . 'Z.dnrbackup';

                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . $encrypted_backup['size']);
                header('X-Content-Type-Options: nosniff');
                readfile($encrypted_backup['path']);
                @unlink($encrypted_backup['path']);
                exit();
            }
        } catch (Throwable $exception) {
            if (is_array($backup) && isset($backup['path'])) {
                @unlink($backup['path']);
            }
            if (is_array($encrypted_backup) && isset($encrypted_backup['path'])) {
                @unlink($encrypted_backup['path']);
            }
            error_log('Database backup error: ' . $exception->getMessage());
            $error = 'The database backup could not be created. No database data was changed.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Backup - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <style>
        .database-maintenance-grid {
            display: grid;
            grid-template-columns: minmax(0, 720px);
            gap: 20px;
            align-items: start;
        }
        .database-maintenance-card {
            border: 1px solid var(--border-color, #ddd);
            border-radius: 8px;
            padding: 20px;
            background: var(--surface-color, #fff);
        }
        .database-maintenance-card h2 { margin-top: 0; }
        .database-maintenance-card form { display: grid; gap: 10px; }
        .database-maintenance-card input { box-sizing: border-box; width: 100%; }
        .database-warning {
            border-left: 4px solid #d97706;
            padding: 10px 12px;
            background: rgba(217, 119, 6, 0.12);
        }
        .maintenance-note { font-size: var(--font-size-small); color: var(--text-muted, #666); }
        @media (max-width: 760px) {
            .database-maintenance-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<main class="container">
    <div class="page-heading">
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

                <label for="backup_admin_password">Your administrator password</label>
                <input type="password" name="admin_password" id="backup_admin_password" autocomplete="current-password" required>

                <label for="backup_admin_code">Fresh authenticator code or recovery code</label>
                <input type="text" name="admin_code" id="backup_admin_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>

                <label for="backup_password">New backup encryption password</label>
                <input type="password" name="backup_password" id="backup_password" autocomplete="new-password" minlength="12" required>

                <label for="backup_password_confirmation">Confirm backup encryption password</label>
                <input type="password" name="backup_password_confirmation" id="backup_password_confirmation" autocomplete="new-password" minlength="12" required>

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
