<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/functions.php';
putenv('DNR_REQUIRE_HTTPS=0');

if (($argv[1] ?? '') === 'worker') {
    session_save_path($argv[2]);
    session_id($argv[3]);
    startSecureSession();
    $_SESSION['visits'] = (int) ($_SESSION['visits'] ?? 0) + 1;
    $result = ['id' => session_id(), 'authenticated' => isLoggedIn(), 'csrf' => $_SESSION['_csrf_token'] ?? null];
    session_write_close();
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}

function expectSessionRotation(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Session rotation test failed: ' . $message);
}

ob_start();
$directory = sys_get_temp_dir() . '/dnr-session-rotation-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
session_save_path($directory);
try {
    session_start();
    $oldId = session_id();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = ['user_id' => 123, 'auth_version' => 1, 'auth_complete' => true,
        '_csrf_token' => $csrf, '_session_started_at' => time() - 1000,
        '_session_last_seen_at' => time(), '_session_rotated_at' => time() - 1000];
    session_write_close();

    // Every process starts with the browser's same old cookie. Native file
    // locks must converge them onto one successor without losing any writes.
    $workers = [];
    for ($i = 0; $i < 8; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $directory, $oldId],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expectSessionRotation(is_resource($process), 'worker should start');
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $successor = null;
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        expectSessionRotation(proc_close($process) === 0 && $errors === '', 'worker should succeed: ' . $errors);
        $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $successor ??= $result['id'];
        expectSessionRotation($result['authenticated'] && $result['csrf'] === $csrf, 'overlapping requests retain authentication and CSRF');
        expectSessionRotation($result['id'] === $successor && $successor !== $oldId, 'all requests use one new ID');
    }
    session_id($successor); session_start();
    expectSessionRotation($_SESSION['visits'] === 8, 'concurrent writes must be serialized on the successor');
    session_write_close();
    session_id($oldId); session_start();
    expectSessionRotation(array_keys($_SESSION) === ['_session_transition_at', '_session_successor_id'], 'old record contains only transition metadata');
    session_write_close();

    // An expired alias cannot extend the lifetime of a current session.
    session_id($oldId); session_start();
    $_SESSION['_session_transition_at'] = time() - 31;
    session_write_close();
    session_id($oldId); startSecureSession();
    expectSessionRotation(!isLoggedIn(), 'an expired alias is anonymous');
    session_destroy();
    session_id($successor); session_start();
    expectSessionRotation(isLoggedIn(), 'rejecting an old alias does not alter the current session');

    // Rotate again, then exercise the same invalidation used by elevation/login.
    rotateApplicationSession(time());
    $current = session_id();
    session_regenerate_id(true);
    $elevated = session_id();
    $_SESSION['_admin_elevated_at'] = time();
    session_write_close();
    session_id($successor); startSecureSession();
    expectSessionRotation(!isLoggedIn() && !isset($_SESSION['_admin_elevated_at']), 'old aliases cannot recover a privilege-changing session');
    session_destroy();
    session_id($elevated); session_start();
    expectSessionRotation(isLoggedIn(), 'elevated successor remains intact');
    rotateApplicationSession(time());
    $logoutSession = session_id();
    session_unset(); session_destroy();
    session_id($elevated); startSecureSession();
    expectSessionRotation(!isLoggedIn() && session_id() !== $logoutSession, 'logout cannot be undone through an alias');
    session_destroy();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach (glob($directory . '/*') ?: [] as $path) unlink($path);
    rmdir($directory);
    ob_end_clean();
}
echo "Session rotation tests passed (concurrent requests, expiry, privilege changes and logout).\n";
