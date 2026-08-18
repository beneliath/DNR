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
    $failure_event = $action === 'restore'
        ? 'database_restore_auth_failed'
        : 'database_backup_auth_failed';

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

function databaseBackupUploadError($error_code, $maximum_bytes) {
    if ((int) $error_code === UPLOAD_ERR_NO_FILE) {
        return 'Select a DNR backup file to restore.';
    }
    if (in_array((int) $error_code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return 'The selected backup exceeds the ' . databaseBackupMaximumSizeLabel($maximum_bytes) . ' limit.';
    }
    return 'The backup upload did not complete. Please try again.';
}

$maximum_backup_bytes = databaseBackupMaximumBytes();
$maximum_encrypted_backup_bytes = databaseBackupMaximumEncryptedBytes($maximum_backup_bytes);
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

    if (!in_array($action, ['backup', 'restore'], true)) {
        http_response_code(400);
        $error = 'Select a valid database maintenance action.';
    } elseif ($action === 'backup') {
        $backup_password_confirmation = is_string($_POST['backup_password_confirmation'] ?? null)
            ? $_POST['backup_password_confirmation']
            : '';
        $backup = null;
        $encrypted_backup = null;
        try {
            if (strlen($backup_password) < 12) {
                $error = 'The backup encryption password must contain at least 12 characters.';
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
    } else {
        $upload = $_FILES['backup_file'] ?? null;
        $confirmation = is_string($_POST['restore_confirmation'] ?? null)
            ? trim($_POST['restore_confirmation'])
            : '';

        if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = databaseBackupUploadError(
                $upload['error'] ?? UPLOAD_ERR_NO_FILE,
                $maximum_encrypted_backup_bytes
            );
        } elseif ($confirmation !== 'RESTORE DATABASE') {
            $error = 'Type RESTORE DATABASE exactly to confirm the restore.';
        } elseif ((int) ($upload['size'] ?? 0) < 1
            || (int) $upload['size'] > $maximum_encrypted_backup_bytes
        ) {
            $error = 'Select a non-empty DNR backup no larger than '
                . databaseBackupMaximumSizeLabel($maximum_encrypted_backup_bytes) . '.';
        } elseif ($backup_password === '') {
            $error = 'Enter the backup encryption password.';
        } elseif (!is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            $error = 'The backup upload could not be verified.';
        } else {
            $decrypted_backup = null;
            try {
                set_time_limit(300);
                $decrypted_backup = decryptDatabaseBackup(
                    $upload['tmp_name'],
                    $backup_password,
                    $maximum_backup_bytes
                );
                $current_schema = databaseBackupSchemaDescriptor($conn);
                inspectDatabaseBackup(
                    $decrypted_backup['path'],
                    $current_schema,
                    $maximum_backup_bytes
                );

                if (!databaseMaintenanceAuthenticationAccepted(
                    $conn,
                    $actor,
                    $admin_password,
                    $admin_code,
                    'restore'
                )) {
                    $error = 'Your administrator password or authentication code was not accepted.';
                } else {
                    restoreDatabaseBackup(
                        $conn,
                        $decrypted_backup['path'],
                        $current_schema,
                        ['id' => $actor_id, 'username' => $actor['username']],
                        $maximum_backup_bytes
                    );
                    @unlink($decrypted_backup['path']);
                    $decrypted_backup = null;

                    session_unset();
                    session_regenerate_id(true);
                    header('Location: login.php?database_restored=1');
                    exit();
                }
                if (is_array($decrypted_backup) && isset($decrypted_backup['path'])) {
                    @unlink($decrypted_backup['path']);
                    $decrypted_backup = null;
                }
            } catch (Throwable $exception) {
                if (is_array($decrypted_backup) && isset($decrypted_backup['path'])) {
                    @unlink($decrypted_backup['path']);
                }
                error_log('Database restore error: ' . $exception->getMessage());
                $error = $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'The database restore failed and was rolled back.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Backup and Restore - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <style>
        .database-maintenance-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
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
        .restore-card { border-color: #b91c1c; }
        .restore-button { background: var(--button-delete-color, #b91c1c); }
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
            <h1>Database Backup and Restore</h1>
            <p class="page-intro">Download a password-encrypted data backup or restore one into the current DNR schema.</p>
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

        <section class="database-maintenance-card restore-card">
            <h2>Import and Restore</h2>
            <p>
                Replaces all current data with a backup created from the exact same database schema.
                The restore is transactional: a failure rolls back the entire operation.
            </p>
            <p><strong>After a successful restore, every signed-in session is invalidated.</strong></p>

            <form method="post" action="database_maintenance.php" enctype="multipart/form-data" autocomplete="off">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int) $maximum_encrypted_backup_bytes; ?>">

                <label for="backup_file">Encrypted DNR backup file</label>
                <input type="file" name="backup_file" id="backup_file" accept=".dnrbackup,application/octet-stream" required>

                <label for="restore_backup_password">Backup encryption password</label>
                <input type="password" name="backup_password" id="restore_backup_password" autocomplete="off" required>

                <label for="restore_confirmation">Type <strong>RESTORE DATABASE</strong></label>
                <input type="text" name="restore_confirmation" id="restore_confirmation" autocomplete="off" required>

                <label for="restore_admin_password">Your administrator password</label>
                <input type="password" name="admin_password" id="restore_admin_password" autocomplete="current-password" required>

                <label for="restore_admin_code">Fresh authenticator code or recovery code</label>
                <input type="text" name="admin_code" id="restore_admin_code" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required>

                <button type="submit" class="danger-button restore-button">Restore database</button>
            </form>
        </section>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
