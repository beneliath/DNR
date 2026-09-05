<?php

declare(strict_types=1);

/** Record completed worker passes, rather than treating a live PID as progress. */
function recordWorkerHeartbeat(string $worker, bool $successful): void
{
    $path = sys_get_temp_dir() . '/dnr-' . $worker . '-heartbeat.json';
    $previous = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $state = ['checked_at' => time(), 'successful_at' => $successful ? time() : (int) ($previous['successful_at'] ?? 0)];
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false
        || !rename($temporary, $path)) {
        throw new RuntimeException('Unable to record worker progress.');
    }
}
