<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/account_recovery_helpers.php';
if (!accountGatewayEnabled() || !accountIsPrimary()) exit(0);
if ((int) $conn->query("SELECT GET_LOCK('moed-directory-worker', 0)")->fetch_row()[0] !== 1) exit(0);
$failed = false;
try {
    processSharedPasswordRecovery($conn);
    foreach ($conn->query("SELECT * FROM platform_accounts WHERE state='ready'")->fetch_all(MYSQLI_ASSOC) as $account) {
        try { reconcileAccountDirectory($conn, $account); }
        catch (Throwable $error) { $failed = true; fwrite(STDERR, "Account directory synchronization pending.\n"); }
    }
} finally { $conn->query("SELECT RELEASE_LOCK('moed-directory-worker')"); }
exit($failed ? 1 : 0);
