<?php
declare(strict_types=1);
require_once __DIR__ . '/persistent_file_helpers.php';

function persistentFileReferenceSql(): string
{
    return 'SELECT profile_picture_key AS storage_key FROM users WHERE profile_picture_key IS NOT NULL
        UNION SELECT profile_picture_thumbnail_key FROM users WHERE profile_picture_thumbnail_key IS NOT NULL
        UNION SELECT contact_photo_key FROM contacts WHERE contact_photo_key IS NOT NULL
        UNION SELECT contact_photo_thumbnail_key FROM contacts WHERE contact_photo_thumbnail_key IS NOT NULL
        UNION SELECT photo_key FROM speakers WHERE photo_key IS NOT NULL
        UNION SELECT photo_thumbnail_key FROM speakers WHERE photo_thumbnail_key IS NOT NULL
        UNION SELECT storage_key FROM presentation_notes WHERE storage_key IS NOT NULL
        UNION SELECT storage_key FROM presentation_slidedecks WHERE storage_key IS NOT NULL';
}

function persistentFileCapacity(mysqli $conn): array
{
    $row = $conn->query('SELECT COUNT(*) AS total_count, COALESCE(SUM(f.size), 0) AS total_bytes,
        COALESCE(SUM(IF(r.storage_key IS NOT NULL, f.size, 0)), 0) AS live_bytes,
        COALESCE(SUM(r.storage_key IS NOT NULL), 0) AS live_count
        FROM stored_files f LEFT JOIN (' . persistentFileReferenceSql() . ') r ON r.storage_key = f.storage_key')->fetch_assoc();
    return array_map('intval', $row);
}

function persistentStorageHealth(): array
{
    $root = persistentFileRoot();
    $state = json_decode((string) @file_get_contents($root . '/.integrity.json'), true);
    return is_array($state) ? $state : [];
}

function requireHealthyPersistentStorage(): void
{
    $root = persistentFileRoot();
    if (disk_free_space($root) < max(134217728, (int) getenv('DNR_STORAGE_MIN_FREE_BYTES'))) {
        throw new RuntimeException('Persistent storage is low on free space.');
    }
    if (getenv('DNR_REQUIRE_STORAGE_MONITOR') !== '1') return;
    $health = persistentStorageHealth();
    if (time() - (int) ($health['checked_at'] ?? 0) > 180 || !($health['writable'] ?? false)
        || !empty($health['errors'])) throw new RuntimeException('Persistent storage monitoring reports a failure or is stale.');
}

/** One bounded, resumable batch. Hashes file contents without loading them into memory. */
function checkPersistentStorageBatch(mysqli $conn, int $limit = 8): array
{
    $root = persistentFileRoot();
    $guard = fopen($root . '/.monitor.lock', 'c+b');
    if (!$guard || !flock($guard, LOCK_EX | LOCK_NB)) {
        if (is_resource($guard)) fclose($guard);
        throw new RuntimeException('A storage check is already running.');
    }
    try {
        lockPersistentFiles();
        $state = persistentStorageHealth();
        $cursor = (string) ($state['cursor'] ?? '');
        $errors = (array) ($state['errors'] ?? []);
        $rows = $conn->execute_query('SELECT * FROM stored_files WHERE storage_key > ? ORDER BY storage_key LIMIT ?',
            [$cursor, max(1, min(100, $limit))])->fetch_all(MYSQLI_ASSOC);
        $bytes = 0;
        foreach ($rows as $row) {
            $key = $row['storage_key'];
            try { $file = openPersistentFile($row, true); fclose($file); unset($errors[$key]); }
            catch (Throwable $exception) { $errors[$key] = 'Missing file, size mismatch, or checksum mismatch'; }
            $cursor = $key; $bytes += (int) $row['size'];
            if ($bytes >= 209715200) break;
        }
        if ($rows === []) { $cursor = ''; $state['completed_at'] = time(); }
        // Prune resolved registry entries in bounded batches too, even after widespread file loss.
        $errorOffset = count($errors) > 0 ? (int) ($state['error_offset'] ?? 0) % count($errors) : 0;
        foreach (array_slice(array_keys($errors), $errorOffset, 8) as $key) {
            if (!$conn->execute_query('SELECT 1 FROM stored_files WHERE storage_key = ?', [$key])->fetch_row()) unset($errors[$key]);
        }
        // A real write+fsync tests the worker identity and catches a read-only/full volume.
        $temporary = tempnam($root, '.health-');
        if ($temporary === false) throw new RuntimeException('Storage write probe failed.');
        $handle = fopen($temporary, 'wb');
        try {
            $state = ['checked_at' => time(), 'completed_at' => $state['completed_at'] ?? null,
                'cursor' => $cursor, 'errors' => $errors, 'error_offset' => $errorOffset + 8, 'writable' => true, 'free_bytes' => disk_free_space($root)];
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) throw new RuntimeException('Storage write probe failed.');
            fclose($handle); $handle = null;
            if (!rename($temporary, $root . '/.integrity.json')) throw new RuntimeException('Storage health publication failed.');
        } finally { if (is_resource($handle)) fclose($handle); if (is_file($temporary)) unlink($temporary); }
        return $state;
    } finally { flock($guard, LOCK_UN); fclose($guard); }
}

/** Offline operator command: exclusive lock refuses active uploads, downloads and backups. */
function prunePersistentFiles(mysqli $conn, bool $apply = false, int $retentionDays = 30, ?int $now = null): array
{
    if ($retentionDays < 30) throw new InvalidArgumentException('Retain unused files for at least 30 days.');
    $now ??= time();
    $root = persistentFileRoot();
    $lock = fopen($root . '/.lifecycle.lock', 'c+b');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        throw new RuntimeException('Storage is in use; retry cleanup during a quiet window.');
    }
    try {
        $live = array_fill_keys(array_column($conn->query(persistentFileReferenceSql())->fetch_all(MYSQLI_ASSOC), 'storage_key'), true);
        $registry = array_fill_keys(array_column($conn->query('SELECT storage_key FROM stored_files')->fetch_all(MYSQLI_ASSOC), 'storage_key'), true);
        $seen = json_decode((string) @file_get_contents($root . '/.retention.json'), true);
        $seen = is_array($seen) ? $seen : [];
        $next = []; $removed = []; $retained = 0;
        $keys = array_unique(array_merge(array_keys($registry), array_map('basename', glob($root . '/*') ?: [])));
        foreach ($keys as $key) {
            if (!preg_match('/\A[0-9a-f]{64}\z/', $key) || isset($live[$key])) continue;
            $firstSeen = (int) ($seen[$key] ?? $now);
            $next[$key] = $firstSeen; $retained++;
            if ($now - $firstSeen < $retentionDays * 86400) continue;
            $path = persistentFilePath($key);
            if ($apply) {
                // Foreign keys additionally prevent deletion if a new reference appeared.
                $conn->execute_query('DELETE FROM stored_files WHERE storage_key = ?', [$key]);
                if (is_file($path) && !unlink($path)) throw new RuntimeException('Unused file could not be removed.');
                unset($next[$key]);
            }
            $removed[] = $key;
        }
        if ($apply) {
            $temporary = $root . '/.retention-' . bin2hex(random_bytes(8));
            try {
                if (file_put_contents($temporary, json_encode($next, JSON_THROW_ON_ERROR), LOCK_EX) === false
                    || !rename($temporary, $root . '/.retention.json')) throw new RuntimeException('Retention inventory could not be saved.');
            } finally { if (is_file($temporary)) unlink($temporary); }
        }
        return ['apply' => $apply, 'retention_days' => $retentionDays, 'unreferenced_count' => $retained,
            'eligible_count' => count($removed), 'eligible_keys' => $removed];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
