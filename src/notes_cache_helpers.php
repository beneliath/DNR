<?php

declare(strict_types=1);

/** Only the canonical public URL without a query string can enter the CDN. */
function notesEdgeCacheTtl(string $code, array $server): int
{
    if (!preg_match('/\A[a-f0-9]{16}\z/', $code)
        || ($server['REQUEST_URI'] ?? '') !== '/surls/' . $code . '/speaker-notes.pdf'
        || !in_array($server['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) return 0;
    return max(0, min(300, (int) (getenv('DNR_NOTES_EDGE_CACHE_TTL') ?: 0)));
}

function notesCachePurgeUrl(string $origin, string $code): string
{
    if (!preg_match('/\Ahttps:\/\/[a-z0-9.-]+(?::[0-9]+)?\z/i', $origin)
        || !preg_match('/\A[a-f0-9]{16}\z/', $code)) {
        throw new InvalidArgumentException('Configure a canonical HTTPS origin and valid notes code.');
    }
    return $origin . '/surls/' . $code . '/speaker-notes.pdf';
}

/** Network errors are deliberately generic so credentials never enter logs. */
function purgeNotesCloudflareUrls(string $zone, string $token, array $urls): void
{
    if (!preg_match('/\A[a-f0-9]{32}\z/i', $zone) || $token === '' || preg_match('/[\r\n]/', $token)
        || count($urls) < 1 || count($urls) > 30) {
        throw new RuntimeException('Invalid Cloudflare purge configuration.');
    }
    $curl = curl_init('https://api.cloudflare.com/client/v4/zones/' . $zone . '/purge_cache');
    $response = '';
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['files' => array_values($urls)], JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 1048576) return 0;
            $response .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $body = json_decode($response, true);
    if ($success === false || $status !== 200 || !is_array($body) || ($body['success'] ?? false) !== true) {
        throw new RuntimeException('Cloudflare purge failed (HTTP ' . $status . ').');
    }
}

/** Caller holds a connection-scoped worker lock; HTTP never holds row locks. */
function processNotesCachePurges(mysqli $conn, callable $purge, string $origin): bool
{
    $jobs = $conn->query('SELECT code, generation, attempts, pass FROM notes_cache_purge_queue
        WHERE next_attempt_at <= UTC_TIMESTAMP(6) ORDER BY next_attempt_at, code LIMIT 30')->fetch_all(MYSQLI_ASSOC);
    if (!$jobs) return notesCachePurgeQueueHealthy($conn);
    try {
        $urls = array_map(static fn(array $job): string => notesCachePurgeUrl($origin, $job['code']), $jobs);
        $purge($urls);
    } catch (Throwable $exception) {
        // Do not log API response bodies, bearer URLs, or credentials.
        foreach ($jobs as $job) {
            $delay = min(300, 5 * (2 ** min(6, (int) $job['attempts'])));
            $stmt = $conn->prepare('UPDATE notes_cache_purge_queue SET attempts = LEAST(attempts + 1, 1000),
                last_error = ?, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)
                WHERE code = ? AND generation = ?');
            $error = 'Purge failed; retry scheduled';
            $stmt->bind_param('sisi', $error, $delay, $job['code'], $job['generation']);
            $stmt->execute();
        }
        return false;
    }
    foreach ($jobs as $job) {
        if ((int) $job['pass'] === 0) {
            // A second purge catches an old response already in flight when
            // the edit committed. Pending jobs suppress new origin cache fills.
            $stmt = $conn->prepare('UPDATE notes_cache_purge_queue SET pass = 1, attempts = 0,
                last_error = NULL, next_attempt_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 90 SECOND)
                WHERE code = ? AND generation = ?');
        } else {
            $stmt = $conn->prepare('DELETE FROM notes_cache_purge_queue WHERE code = ? AND generation = ?');
        }
        $stmt->bind_param('si', $job['code'], $job['generation']);
        $stmt->execute();
    }
    return notesCachePurgeQueueHealthy($conn);
}

/** Backoff does not mean recovery: keep the healthcheck failed until retry succeeds. */
function notesCachePurgeQueueHealthy(mysqli $conn): bool
{
    return $conn->query('SELECT 1 FROM notes_cache_purge_queue WHERE last_error IS NOT NULL LIMIT 1')->fetch_row() === null;
}
