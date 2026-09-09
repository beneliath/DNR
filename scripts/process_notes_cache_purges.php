<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once '/var/www/html/config.php';
require_once '/var/www/html/notes_cache_helpers.php';
require_once '/var/www/html/worker_health_helpers.php';

$zone = configurationSecret('DNR_CLOUDFLARE_ZONE_ID');
$token = configurationSecret('DNR_CLOUDFLARE_PURGE_TOKEN');
$origin = rtrim((string) (getenv('DNR_PUBLIC_BASE_URL') ?: ''), '/');
notesCachePurgeUrl($origin, str_repeat('0', 16));
if (!preg_match('/\A[a-f0-9]{32}\z/i', $zone) || $token === '') {
    fwrite(STDERR, "Configure the Cloudflare zone ID and cache-purge token files.\n");
    exit(1);
}
if ((int) $conn->query("SELECT GET_LOCK('dnr:notes-cache-purge', 0) AS acquired")->fetch_assoc()['acquired'] !== 1) exit(0);
$loop = in_array('--loop', $argv, true);
do {
    $ok = processNotesCachePurges($conn, static function (array $urls) use ($zone, $token): void {
        purgeNotesCloudflareUrls($zone, $token, $urls);
    }, $origin);
    recordWorkerHeartbeat('notes-cache', $ok);
    if (!$ok) fwrite(STDERR, "Cloudflare purge failed; durable retry is pending.\n");
    if ($loop) sleep(5);
} while ($loop);
$conn->query("SELECT RELEASE_LOCK('dnr:notes-cache-purge')");
exit($ok ? 0 : 1);
