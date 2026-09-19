<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TARGET') !== 'disposable' || getenv('DNR_DESTRUCTIVE_BACKUP_TEST') !== 'isolated-restore') {
    echo "Storage maintenance tests skipped (isolated restore database required).\n"; exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/file_storage_maintenance_helpers.php';
function expectStorageMaintenance(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$directory = sys_get_temp_dir() . '/dnr-maintenance-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); putenv('DNR_FILE_STORAGE_PATH=' . $directory);
$keys = [];
foreach (['live', 'unused', 'orphan'] as $kind) {
    $data = 'storage fixture ' . $kind; $checksum = hash('sha256', $data);
    $key = persistentFileKey($checksum, $kind . '.txt', 'text/plain'); $keys[$kind] = $key;
    file_put_contents($directory . '/' . $key, $data);
    if ($kind !== 'orphan') $conn->execute_query('INSERT INTO stored_files (storage_key, filename, content_type, size, checksum) VALUES (?, ?, ?, ?, ?)',
        [$key, $kind . '.txt', 'text/plain', strlen($data), $checksum]);
}
$conn->execute_query("INSERT INTO users (username, password, role, profile_picture_key) VALUES (?, ?, 'reviewer', ?)",
    ['storage-retention-' . bin2hex(random_bytes(5)), password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $keys['live']]);
$user = (int) $conn->insert_id;
try {
    $guard = fopen($directory . '/.lifecycle.lock', 'c+b'); flock($guard, LOCK_SH);
    try { prunePersistentFiles($conn, true); throw new LogicException('Cleanup ran during a shared operation.'); }
    catch (RuntimeException $expected) {}
    flock($guard, LOCK_UN); fclose($guard);
    $now = time();
    expectStorageMaintenance(prunePersistentFiles($conn, true, 30, $now)['eligible_count'] === 0, 'Initial observation must start a grace period.');
    expectStorageMaintenance(prunePersistentFiles($conn, false, 30, $now + 29 * 86400)['eligible_count'] === 0, 'Grace period must not be shortened.');
    expectStorageMaintenance(prunePersistentFiles($conn, false, 30, $now + 31 * 86400)['eligible_count'] === 2, 'Unused and orphan files become eligible.');
    expectStorageMaintenance(is_file($directory . '/' . $keys['unused']), 'Dry run must retain files.');
    prunePersistentFiles($conn, true, 30, $now + 31 * 86400);
    expectStorageMaintenance(is_file($directory . '/' . $keys['live']) && !is_file($directory . '/' . $keys['unused']) && !is_file($directory . '/' . $keys['orphan']), 'Referenced files must survive cleanup.');
    expectStorageMaintenance($conn->execute_query('SELECT 1 FROM stored_files WHERE storage_key = ?', [$keys['unused']])->num_rows === 0, 'Registry and file deletion must agree.');
    $capacity = persistentFileCapacity($conn);
    expectStorageMaintenance($capacity['live_count'] === 1 && $capacity['total_count'] === 1, 'Capacity distinguishes live and retained bytes.');
    $path = $directory . '/' . $keys['live']; $original = file_get_contents($path);
    file_put_contents($path, str_repeat('x', strlen($original)));
    $state = checkPersistentStorageBatch($conn);
    expectStorageMaintenance(isset($state['errors'][$keys['live']]), 'Same-size corruption must be detected.');
    checkPersistentStorageBatch($conn); // wrap cursor
    file_put_contents($path, $original);
    $state = checkPersistentStorageBatch($conn);
    expectStorageMaintenance($state['errors'] === [], 'A repaired file must clear its alert only after verification.');
    checkPersistentStorageBatch($conn);
    unlink($path); $state = checkPersistentStorageBatch($conn);
    expectStorageMaintenance(isset($state['errors'][$keys['live']]), 'Missing files after startup must be detected.');
    putenv('DNR_REQUIRE_STORAGE_MONITOR=1');
    try { requireHealthyPersistentStorage(); throw new LogicException('Unhealthy storage was ready.'); }
    catch (RuntimeException $expected) {}
    echo "Storage retention, locking, capacity and integrity tests passed.\n";
} finally {
    $conn->execute_query('DELETE FROM users WHERE id = ?', [$user]);
    foreach ($keys as $key) $conn->execute_query('DELETE FROM stored_files WHERE storage_key = ?', [$key]);
    foreach (scandir($directory) as $file) if ($file !== '.' && $file !== '..') @unlink($directory . '/' . $file);
    rmdir($directory);
}
