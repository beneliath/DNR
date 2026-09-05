<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Mail worker bootstrap tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

// Boot the actual image entrypoints while holding their singleton locks. This
// exercises dependency loading without claiming messages or contacting IMAP.
foreach ([
    'process_email_outbox.php' => 'dnr-email-outbox-worker.lock',
    'process_inbound_mail.php' => 'dnr-inbound-mail-worker.lock',
] as $script => $lockName) {
    $directory = sys_get_temp_dir() . '/dnr-mail-bootstrap-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $lock = fopen($directory . '/' . $lockName, 'c+');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Unable to reserve the isolated worker lock.');
    }
    try {
        $code = <<<'PHP'
register_shutdown_function(static function (): void {
    if (!function_exists('recordWorkerHeartbeat')) {
        fwrite(STDERR, "Worker did not load its heartbeat helper.\n");
        exit(70);
    }
    echo "Heartbeat helper loaded.\n";
});
$argv = [$argv[1]];
require $argv[0];
PHP;
        $environment = array_merge(getenv(), [
            'TMPDIR' => $directory,
            'DNR_MAIL_TRANSPORT' => 'disabled',
            'DNR_IMAP_HOST' => '127.0.0.1',
            'DNR_IMAP_PORT' => '9',
            'DNR_IMAP_USERNAME' => 'disposable-bootstrap-test',
            'DNR_IMAP_PASSWORD' => 'not-a-real-mailbox-password',
            'DNR_IMAP_PASSWORD_FILE' => '',
        ]);
        $process = proc_open([PHP_BINARY, '-r', $code, '/opt/dnr/bin/' . $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            null, $environment);
        if (!is_resource($process)) throw new RuntimeException('Unable to start worker bootstrap.');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || !str_contains($stdout, 'Heartbeat helper loaded.')
            || !str_contains($stderr, 'worker is already running.')) {
            throw new RuntimeException($script . ' bootstrap failed: ' . $stdout . $stderr);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        unlink($directory . '/' . $lockName);
        rmdir($directory);
    }
}

echo "Mail worker bootstrap integration tests passed.\n";
