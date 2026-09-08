<?php

declare(strict_types=1);

require_once __DIR__ . '/speaker_helpers.php';
require_once __DIR__ . '/presentation_asset_helpers.php';

const SHORT_LINK_TYPES = ['website' => 'Website', 'bio' => 'Bio', 'donation' => 'Donations',
    'connection' => 'Connection', 'blog' => 'Blog', 'books' => 'Books', 'notes' => 'Speaker Notes', 'custom' => 'Custom Links'];

function shortLinkLabel(array $link): string
{
    return $link['link_type'] === 'custom' ? (string) $link['custom_label'] : SHORT_LINK_TYPES[$link['link_type']];
}

/** Called inside the speaker save transaction. */
function ensureSpeakerCustomShortLinks(mysqli $conn, int $speakerId): void
{
    $stmt = $conn->prepare('SELECT id FROM presentations WHERE speaker_id = ? ORDER BY id FOR UPDATE');
    $stmt->bind_param('i', $speakerId);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $presentation) {
        ensurePresentationShortLinks($conn, (int) $presentation['id'], true);
    }
}

function shortLinkUrl(string $code): string
{
    if (!preg_match('/\A[a-f0-9]{16}\z/', $code)) throw new InvalidArgumentException('Invalid short link.');
    $base = rtrim(trim((string) (getenv('DNR_PUBLIC_BASE_URL') ?: '')), '/');
    if ($base === '' && !applicationRequiresHttps()) {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost:8080');
        if (!preg_match('/\A[a-z0-9.\-\[\]:]+\z/i', $host)) $host = 'localhost:8080';
        $base = 'http://' . $host;
    }
    $parts = parse_url($base);
    if (!filter_var($base, FILTER_VALIDATE_URL) || !is_array($parts)
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || (applicationRequiresHttps() && $parts['scheme'] !== 'https')
        || isset($parts['pass']) || isset($parts['user']) || isset($parts['query'])
        || isset($parts['fragment']) || !empty($parts['path'])) {
        throw new RuntimeException('Configure DNR_PUBLIC_BASE_URL as the public MOED origin before downloading QR codes.');
    }
    return $base . '/surls/' . $code;
}

function shortLinkTarget(string $value): string
{
    $value = trim($value);
    $parts = parse_url($value);
    if (strlen($value) > SPEAKER_URL_MAX_LENGTH || preg_match('/[\x00-\x20\x7f]/', $value)
        || !filter_var($value, FILTER_VALIDATE_URL) || !is_array($parts)
        || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
        || isset($parts['user']) || isset($parts['pass'])) {
        throw new InvalidArgumentException('Enter a valid HTTP or HTTPS destination without embedded credentials.');
    }
    // Avoid cycles through our resolver, which would inflate counts and never reach a destination.
    $origin = parse_url((string) (getenv('DNR_PUBLIC_BASE_URL') ?: ''));
    if (is_array($origin) && strtolower($parts['host'] ?? '') === strtolower($origin['host'] ?? '')
        && (str_starts_with($parts['path'] ?? '', '/surls/') || ($parts['path'] ?? '') === '/short_link.php')) {
        throw new InvalidArgumentException('Choose the final destination, not another MOED short link.');
    }
    return $value;
}

/** Called inside the presentation transaction, with the presentation row locked. */
function ensurePresentationShortLinks(mysqli $conn, int $presentationId, bool $customOnly = false): bool
{
    $stmt = $conn->prepare('SELECT p.engagement_id, p.speaker_id, s.*,
        EXISTS(SELECT 1 FROM presentation_notes n WHERE n.presentation_id = p.id
            AND n.speaker_id = p.speaker_id AND n.pdf IS NOT NULL) AS has_notes FROM presentations p
        JOIN speakers s ON s.id = p.speaker_id WHERE p.id = ?');
    $stmt->bind_param('i', $presentationId);
    $stmt->execute();
    $speaker = $stmt->get_result()->fetch_assoc();
    if (!$speaker) throw new RuntimeException('Presentation not found.');
    $existing = $conn->prepare('SELECT l.id, l.code, l.link_type, l.custom_link_key, q.link_id AS image_id FROM short_links l
        LEFT JOIN short_link_qr_images q ON q.link_id = l.id WHERE l.presentation_id = ? AND l.speaker_id = ?');
    $existing->bind_param('ii', $presentationId, $speaker['speaker_id']);
    $existing->execute();
    $types = [];
    foreach ($existing->get_result()->fetch_all(MYSQLI_ASSOC) as $link) {
        $types[$link['link_type'] . ':' . $link['custom_link_key']] = $link;
    }
    $definitions = [];
    if (!$customOnly) {
        foreach (SHORT_LINK_TYPES as $type => $label) {
            if ($type === 'custom' || ($type === 'notes' && !$speaker['has_notes'])) continue;
            $definitions[] = ['type' => $type, 'key' => '', 'label' => null,
                'url' => $type === 'notes' ? null : trim((string) ($speaker[$type . '_url'] ?? ''))];
        }
    }
    foreach (speakerCustomLinks($speaker) as $link) {
        $definitions[] = ['type' => 'custom', 'key' => $link['key'], 'label' => $link['label'], 'url' => $link['url']];
    }
    $changed = false;
    foreach ($definitions as $definition) {
        $type = $definition['type'];
        $identity = $type . ':' . $definition['key'];
        if (isset($types[$identity])) {
            // Also prepares a legacy notes link on its first PDF upload.
            if ($types[$identity]['image_id'] === null) {
                storeShortLinkQrImages($conn, (int) $types[$identity]['id'], $types[$identity]['code']);
                $changed = true;
            }
            continue;
        }
        $target = $definition['url'];
        if ($target === '') continue;
        // Existing profile validation already restricts URLs to HTTP(S).
        if ($target !== null) $target = shortLinkTarget($target);
        $insert = $conn->prepare('INSERT INTO short_links
            (code, engagement_id, presentation_id, speaker_id, link_type, target_url, custom_link_key, custom_label)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = bin2hex(random_bytes(8));
            $insert->bind_param('siiissss', $code, $speaker['engagement_id'], $presentationId, $speaker['speaker_id'],
                $type, $target, $definition['key'], $definition['label']);
            try { $insert->execute(); break; }
            catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() !== 1062 || $attempt === 4) throw $exception;
            }
        }
        storeShortLinkQrImages($conn, (int) $insert->insert_id, $code);
        $changed = true;
    }
    return $changed;
}

function fetchPresentationShortLinks(mysqli $conn, int $presentationId): array
{
    $stmt = $conn->prepare('SELECT l.*, q.encoded_url AS qr_url, q.png AS qr_png,
        s.name AS speaker_name, p.speaker_id AS current_speaker_id,
        EXISTS(SELECT 1 FROM presentation_notes n WHERE n.presentation_id = l.presentation_id
            AND n.speaker_id = l.speaker_id AND n.pdf IS NOT NULL) AS has_notes
        FROM short_links l JOIN speakers s ON s.id = l.speaker_id
        JOIN presentations p ON p.id = l.presentation_id
        LEFT JOIN short_link_qr_images q ON q.link_id = l.id
        WHERE l.presentation_id = ? ORDER BY l.speaker_id, l.id');
    $stmt->bind_param('i', $presentationId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function updateShortLink(mysqli $conn, int $id, int $version, string $target, bool $enabled): void
{
    $stmt = $conn->prepare('SELECT link_type FROM short_links WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $link = $stmt->get_result()->fetch_assoc();
    if (!$link) throw new InvalidArgumentException('Link not found.');
    $targetUrl = $link['link_type'] === 'notes' ? null : shortLinkTarget($target);
    $stmt = $conn->prepare('UPDATE short_links SET target_url = ?, is_enabled = ?, version = version + 1,
        updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND version = ?');
    $stmt->bind_param('siii', $targetUrl, $enabled, $id, $version);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) throw new InvalidArgumentException('This link changed in another session. Reload before saving.');
}

/** Reset all historical visit aggregates, keeping the presentation and its links intact. */
function resetPresentationShortLinkStats(mysqli $conn, int $presentationId, int $actorId): void
{
    if ($presentationId < 1 || $actorId < 1) {
        throw new InvalidArgumentException('Select a valid presentation.');
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT topic_title FROM presentations WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $presentationId);
        $stmt->execute();
        $presentation = $stmt->get_result()->fetch_assoc();
        if (!$presentation) {
            throw new InvalidArgumentException('Presentation not found.');
        }
        // Match the presentation-edit lock order and include disabled links and
        // links retained for previous speakers, not just the visible QR cards.
        $links = $conn->prepare('SELECT id FROM short_links WHERE presentation_id = ? ORDER BY id FOR UPDATE');
        $links->bind_param('i', $presentationId);
        $links->execute();
        $linkCount = $links->get_result()->num_rows;
        $delete = $conn->prepare('DELETE v FROM short_link_stats v
            JOIN short_links l ON l.id = v.link_id WHERE l.presentation_id = ?');
        $delete->bind_param('i', $presentationId);
        $delete->execute();
        if (!recordAuditEvent($conn, [
            'event_category' => 'database_change',
            'event_type' => 'presentation_statistics_reset',
            'actor_user_id' => $actorId,
            'entity_type' => 'presentations',
            'entity_id' => $presentationId,
            'entity_label' => mb_strcut((string) $presentation['topic_title'], 0, 255, 'UTF-8'),
            'details' => 'Reset all visit statistics for ' . $linkCount . ' links; removed '
                . $delete->affected_rows . ' hourly statistic groups.',
        ])) {
            throw new RuntimeException('Unable to audit the statistics reset.');
        }
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function applyPresentationNotesChange(mysqli $conn, int $presentationId, int $engagementId, array $change): void
{
    $stmt = $conn->prepare('SELECT speaker_id FROM presentations WHERE id = ? AND engagement_id = ? FOR UPDATE');
    $stmt->bind_param('ii', $presentationId, $engagementId);
    $stmt->execute();
    $speakerId = $stmt->get_result()->fetch_assoc()['speaker_id'] ?? null;
    if ($speakerId === null) throw new InvalidArgumentException('Presentation not found.');
    if (($change['action'] ?? '') === 'remove') {
        $stmt = $conn->prepare('UPDATE presentation_notes SET pdf = NULL, filename = NULL, size = NULL,
            sha256 = NULL, updated_at = UTC_TIMESTAMP(6) WHERE presentation_id = ? AND speaker_id = ?');
        $stmt->bind_param('ii', $presentationId, $speakerId);
    } elseif (($change['action'] ?? '') === 'replace' && is_array($change['asset'] ?? null)) {
        $asset = $change['asset'];
        $stmt = $conn->prepare('INSERT INTO presentation_notes (presentation_id, speaker_id, pdf, filename, size, sha256)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE pdf = VALUES(pdf), filename = VALUES(filename),
            size = VALUES(size), sha256 = VALUES(sha256), updated_at = UTC_TIMESTAMP(6)');
        $blob = null;
        $stmt->bind_param('iibsis', $presentationId, $speakerId, $blob, $asset['filename'], $asset['size'], $asset['sha256']);
        $stmt->send_long_data(2, $asset['data']);
    } else throw new InvalidArgumentException('Invalid notes change.');
    $stmt->execute();
}

/** Country headers are accepted only from the configured Cloudflare path. */
function shortLinkCountry(array $server): string
{
    $country = strtoupper((string) ($server['HTTP_CF_IPCOUNTRY'] ?? ''));
    $remote = (string) ($server['REMOTE_ADDR'] ?? '');
    $cloudflare = isTrustedCloudflareProxyAddress($remote);
    if (!$cloudflare && isTrustedProxyAddress($remote)) {
        foreach (array_reverse(explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))) as $hop) {
            $hop = trim($hop);
            if (isTrustedCloudflareProxyAddress($hop)) { $cloudflare = true; break; }
            if (!isTrustedProxyAddress($hop)) break;
        }
    }
    if ($cloudflare && preg_match('/\A[0-9a-f]{16,32}(?:-[a-z]{3})?\z/i', (string) ($server['HTTP_CF_RAY'] ?? ''))
        && filter_var($server['HTTP_CF_CONNECTING_IP'] ?? '', FILTER_VALIDATE_IP)
        && preg_match('/\A[A-Z]{2}\z/', $country) && $country !== 'XX') return $country;
    $database = (string) (getenv('DNR_GEOIP_DATABASE') ?: '');
    $ip = requestIpAddress($server);
    if ($database !== '' && $ip !== null) {
        try {
            $reader = new \MaxMind\Db\Reader($database);
            try { $record = $reader->get($ip); } finally { $reader->close(); }
            $country = strtoupper((string) ($record['country']['iso_code'] ?? ''));
            if (preg_match('/\A[A-Z]{2}\z/', $country)) return $country;
        } catch (Throwable $exception) {
            applicationLog('warning', 'Short-link GeoIP lookup unavailable');
        }
    }
    return 'ZZ';
}

function shortLinkVisitDimensions(array $server): ?array
{
    if (($server['REQUEST_METHOD'] ?? 'GET') !== 'GET') return null;
    if (preg_match('/prefetch|prerender/i', (string) ($server['HTTP_SEC_PURPOSE'] ?? $server['HTTP_PURPOSE'] ?? ''))) return null;
    $ua = substr((string) ($server['HTTP_USER_AGENT'] ?? ''), 0, 2048);
    $detector = new \DeviceDetector\DeviceDetector($ua);
    $detector->setYamlParser(new \DeviceDetector\Yaml\Symfony());
    $detector->discardBotInformation();
    $detector->parse();
    if ($detector->isBot()) return null;
    $referrer = strtolower((string) parse_url(substr((string) ($server['HTTP_REFERER'] ?? ''), 0, 4096), PHP_URL_HOST));
    if (!preg_match('/\A[a-z0-9.\-]{1,253}\z/', $referrer)) $referrer = '';
    return ['browser' => mb_substr((string) ($detector->getClient('name') ?: 'Other'), 0, 64),
        'os' => mb_substr((string) ($detector->getOs('name') ?: 'Other'), 0, 64),
        'country' => shortLinkCountry($server), 'referrer' => $referrer];
}

function recordShortLinkVisit(mysqli $conn, int $linkId, array $server): void
{
    try {
        $dimensions = shortLinkVisitDimensions($server);
        if ($dimensions === null) return;
        $hour = gmdate('Y-m-d H:00:00');
        $stmt = $conn->prepare('INSERT INTO short_link_stats (link_id, visit_hour, browser, os, country, referrer)
            VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE visits = visits + 1');
        $stmt->bind_param('isssss', $linkId, $hour, $dimensions['browser'], $dimensions['os'], $dimensions['country'], $dimensions['referrer']);
        $stmt->execute();
    } catch (Throwable $exception) {
        // A statistics outage must not break a distributed link.
        applicationLog('error', 'Unable to record short-link visit', ['link_id' => $linkId]);
    }
}

function shortLinkQr(string $url, string $format): string
{
    if (!in_array($format, ['png', 'svg'], true)) throw new InvalidArgumentException('Choose PNG or SVG.');
    $writer = $format === 'png' ? new \Endroid\QrCode\Writer\PngWriter() : new \Endroid\QrCode\Writer\SvgWriter();
    return (new \Endroid\QrCode\Builder\Builder(writer: $writer, data: $url,
        errorCorrectionLevel: \Endroid\QrCode\ErrorCorrectionLevel::Medium, size: 800, margin: 40))->build()->getString();
}

/** Only called while creating a link or by the explicit deployment backfill. */
function storeShortLinkQrImages(mysqli $conn, int $linkId, string $code): void
{
    $url = shortLinkUrl($code);
    $png = shortLinkQr($url, 'png');
    $svg = shortLinkQr($url, 'svg');
    $pngHash = hash('sha256', $png, true);
    $svgHash = hash('sha256', $svg, true);
    $stmt = $conn->prepare('INSERT INTO short_link_qr_images
        (link_id, encoded_url, png, svg, png_sha256, svg_sha256) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('isssss', $linkId, $url, $png, $svg, $pngHash, $svgHash);
    $stmt->execute();
}

/** Re-runnable, bounded deployment backfill; never used by page/image requests. */
function backfillShortLinkQrImages(mysqli $conn, int $batchSize = 100): int
{
    $batchSize = max(1, min(500, $batchSize));
    $lastId = 0;
    $created = 0;
    do {
        $links = $conn->query("SELECT l.id, l.code FROM short_links l
            LEFT JOIN short_link_qr_images q ON q.link_id = l.id
            WHERE l.id > $lastId AND q.link_id IS NULL
                AND (l.link_type <> 'notes' OR EXISTS(SELECT 1 FROM presentation_notes n
                    WHERE n.presentation_id = l.presentation_id AND n.speaker_id = l.speaker_id AND n.pdf IS NOT NULL))
            ORDER BY l.id LIMIT $batchSize")->fetch_all(MYSQLI_ASSOC);
        foreach ($links as $link) {
            $lastId = (int) $link['id'];
            $conn->begin_transaction();
            try {
                // Serialize backfill workers and tolerate a concurrently deleted link.
                $locked = $conn->query("SELECT code FROM short_links WHERE id = $lastId FOR UPDATE")->fetch_assoc();
                $exists = $conn->query("SELECT link_id FROM short_link_qr_images WHERE link_id = $lastId")->fetch_assoc();
                if ($locked && !$exists) {
                    storeShortLinkQrImages($conn, $lastId, $locked['code']);
                    $created++;
                }
                $conn->commit();
            } catch (Throwable $exception) {
                $conn->rollback();
                throw $exception;
            }
        }
    } while (count($links) === $batchSize);
    return $created;
}

/** Public notes are scoped to the link's original presentation AND speaker. */
function deliverPresentationNotes(mysqli $conn, int $presentationId, int $speakerId, ?int $linkId = null): void
{
    $conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    $stmt = $conn->prepare('SELECT filename, size, HEX(sha256) AS sha FROM presentation_notes
        WHERE presentation_id = ? AND speaker_id = ? AND pdf IS NOT NULL');
    $stmt->bind_param('ii', $presentationId, $speakerId);
    $stmt->execute();
    $notes = $stmt->get_result()->fetch_assoc();
    if (!$notes) { $conn->commit(); http_response_code(404); echo 'Notes are not available yet.'; return; }
    $size = (int) $notes['size'];
    try { $range = presentationAssetByteRange((string) ($_SERVER['HTTP_RANGE'] ?? ''), $size); }
    catch (OutOfRangeException $exception) {
        $conn->commit(); http_response_code(416); header('Content-Range: bytes */' . $size); return;
    }
    $remaining = $range['length'] ?? $size;
    $position = ($range['start'] ?? 0) + 1;
    $length = min(1048576, $remaining);
    $data = $conn->prepare('SELECT SUBSTRING(pdf, ?, ?) AS chunk FROM presentation_notes
        WHERE presentation_id = ? AND speaker_id = ?');
    $data->bind_param('iiii', $position, $length, $presentationId, $speakerId);
    $data->execute();
    $chunk = $data->get_result()->fetch_assoc()['chunk'] ?? null;
    if (!is_string($chunk) || strlen($chunk) !== $length) { $conn->commit(); http_response_code(503); return; }
    sendPresentationPdfViewHeaders((string) $notes['filename']);
    header('Cache-Control: private, no-store');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $remaining);
    if ($range !== null) { http_response_code(206); header('Content-Range: bytes ' . $range['start'] . '-' . $range['end'] . '/' . $size); }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { $conn->commit(); return; }
    while (true) {
        echo $chunk;
        $remaining -= $length;
        if ($remaining === 0 || connection_aborted()) break;
        $position += $length;
        $length = min(1048576, $remaining);
        $data->execute();
        $chunk = $data->get_result()->fetch_assoc()['chunk'] ?? null;
        if (!is_string($chunk) || strlen($chunk) !== $length) break;
    }
    $conn->commit();
    if ($linkId !== null && $remaining === 0 && ($range === null || $range['start'] === 0)) recordShortLinkVisit($conn, $linkId, $_SERVER);
}

/** @return array{0: string, 1: string} */
function shortLinkStatsDates(string $from, string $to): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to, new DateTimeZone('UTC'));
    if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
        || $start > $end || (int) $start->diff($end)->days > 365) {
        throw new InvalidArgumentException('Choose a valid date range of up to 366 days.');
    }
    return [$from . ' 00:00:00', $end->modify('+1 day')->format('Y-m-d 00:00:00')];
}

function shortLinkStats(mysqli $conn, string $where, string $start, string $end): array
{
    $stats = [];
    // $where is assembled only from fixed columns and validated integer IDs below.
    foreach (['day' => 'DATE(v.visit_hour)', 'browser' => 'v.browser', 'os' => 'v.os',
        'country' => 'v.country', 'referrer' => 'v.referrer'] as $dimension => $column) {
        $order = $dimension === 'day' ? 'label' : 'total DESC, label';
        // Include every country so the map does not drop visits outside the top 20.
        $limit = $dimension === 'country' ? 676 : ($dimension === 'day' ? 366 : 20);
        $stmt = $conn->prepare("SELECT $column AS label, SUM(v.visits) AS total FROM short_link_stats v
            JOIN short_links l ON l.id = v.link_id WHERE $where AND v.visit_hour >= ? AND v.visit_hour < ?
            GROUP BY $column ORDER BY $order LIMIT $limit");
        $stmt->bind_param('ss', $start, $end);
        $stmt->execute();
        $stats[$dimension] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    $stats['total'] = array_sum(array_column($stats['day'], 'total'));
    return $stats;
}

/** Build chart buckets without losing quiet dates or traffic outside the leading categories. */
function shortLinkReportData(array $stats, string $start, string $end): array
{
    $zone = new DateTimeZone('UTC');
    $first = new DateTimeImmutable($start, $zone);
    $until = new DateTimeImmutable($end, $zone);
    $monthly = (int) $first->diff($until)->days > 90;
    $days = array_column($stats['day'], 'total', 'label');
    $buckets = [];
    for ($date = $first; $date < $until; $date = $date->modify('+1 day')) {
        $key = $date->format($monthly ? 'Y-m' : 'Y-m-d');
        $buckets[$key] = ($buckets[$key] ?? 0) + (int) ($days[$date->format('Y-m-d')] ?? 0);
    }
    $report = ['total' => (int) $stats['total'], 'period' => $monthly ? 'month' : 'day', 'timeline' => []];
    foreach ($buckets as $label => $total) $report['timeline'][] = ['label' => $label, 'total' => $total];
    foreach (['referrer' => 6, 'browser' => 7, 'os' => 7, 'country' => 676] as $key => $limit) {
        $report[$key] = [];
        foreach (array_slice($stats[$key], 0, $limit) as $row) {
            $label = (string) $row['label'];
            if ($label === '') $label = $key === 'referrer' ? 'Direct / unknown' : 'Unknown';
            $report[$key][] = ['label' => $label, 'total' => (int) $row['total']];
        }
        $remaining = $report['total'] - array_sum(array_column($report[$key], 'total'));
        if ($remaining > 0 && $key !== 'country') {
            $label = ['referrer' => 'Other referrers', 'browser' => 'Other browsers', 'os' => 'Other operating systems'][$key];
            $report[$key][] = ['label' => $label, 'total' => $remaining];
        }
    }
    return $report;
}
