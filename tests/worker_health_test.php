<?php

// Exercise the same persisted heartbeat and CLI probe used by Compose.
$directory = sys_get_temp_dir() . '/dnr-worker-health-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$helper = var_export(__DIR__ . '/../src/worker_health_helpers.php', true);
$probe = var_export(__DIR__ . '/../scripts/check_worker_health.php', true);
$path = $directory . '/dnr-geocoder-heartbeat.json';
$run = static function (string $code) use ($directory): int {
    $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, array_merge(getenv(), ['TMPDIR' => $directory]));
    if (!is_resource($process)) throw new RuntimeException('Could not start heartbeat probe');
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); fclose($pipes[1]);
    stream_get_contents($pipes[2]); fclose($pipes[2]);
    return proc_close($process);
};
$check = '$argv = ["probe", "geocoder"]; require ' . $probe . ';';
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
try {
    $assert($run($check) === 1, 'A new process without progress must not be healthy');
    $assert($run('require ' . $helper . '; recordWorkerHeartbeat("geocoder", true);') === 0, 'Recording progress failed');
    $assert($run($check) === 0, 'A successful pass should make the worker healthy');
    $stale = time() - 7200;
    file_put_contents($path, json_encode(['successful_at' => $stale, 'checked_at' => $stale]));
    $assert($run('require ' . $helper . '; recordWorkerHeartbeat("geocoder", false);') === 0, 'Recording a failed pass failed');
    $state = json_decode(file_get_contents($path), true);
    $assert($state['successful_at'] === $stale && $state['checked_at'] > $stale, 'A failed pass must not refresh successful progress');
    $assert($run($check) === 1, 'A running but failing worker must become unhealthy');
    file_put_contents($path, 'invalid');
    $assert($run($check) === 1, 'A corrupt heartbeat must fail closed');
    echo "Worker heartbeat tests passed.\n";
} finally {
    @unlink($path); @unlink($path . '.tmp'); rmdir($directory);
}
