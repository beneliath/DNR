<?php
if (PHP_SAPI !== 'cli') exit(1);
$worker = $argv[1] ?? '';
if (!in_array($worker, ['geocoder', 'mail-ingest', 'mail-dispatch'], true)) exit(64);
$path = sys_get_temp_dir() . '/dnr-' . $worker . '-heartbeat.json';
$state = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
$maximumAge = max(60, min(3600, (int) (getenv('DNR_WORKER_HEALTH_MAX_AGE') ?: 600)));
if (!is_array($state) || (int) ($state['successful_at'] ?? 0) < time() - $maximumAge) {
    fwrite(STDERR, "Worker has not completed a successful pass recently.\n");
    exit(1);
}
echo "Worker progress is current.\n";
