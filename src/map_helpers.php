<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/github_version_helpers.php';

function engagementMapStatuses()
{
    return [
        'work_in_progress' => 'Work in progress',
        'under_review' => 'Under review',
        'confirmed' => 'Confirmed',
    ];
}

function engagementMapLifecycles()
{
    return [
        'active' => 'Active',
        'postponed' => 'Postponed',
        'canceled' => 'Canceled',
        'completed' => 'Completed',
    ];
}

function engagementMapDateAfterMonths($date, $months)
{
    $date = trim((string) $date);
    $months = (int) $months;
    if (!validIsoDate($date) || $months < 1) {
        throw new InvalidArgumentException('A valid date and positive month offset are required.');
    }

    $source = new DateTimeImmutable($date . ' 12:00:00', applicationTimezone());
    $target_month = $source
        ->modify('first day of this month')
        ->modify('+' . $months . ' months');
    $target_day = min((int) $source->format('j'), (int) $target_month->format('t'));

    return $target_month->setDate(
        (int) $target_month->format('Y'),
        (int) $target_month->format('n'),
        $target_day
    )->format('Y-m-d');
}

function normalizeEngagementMapFilters(array $query, ?DateTimeImmutable $instant = null)
{
    $scalar = static function ($value) {
        return is_scalar($value) ? trim((string) $value) : '';
    };

    $status = $scalar($query['status'] ?? '');
    if (!array_key_exists($status, engagementMapStatuses())) {
        $status = '';
    }

    $lifecycle = array_key_exists('lifecycle', $query)
        ? $scalar($query['lifecycle'])
        : 'active';
    if ($lifecycle !== '' && !array_key_exists($lifecycle, engagementMapLifecycles())) {
        $lifecycle = 'active';
    }

    $date_from = $scalar($query['date_from'] ?? '');
    $date_to = $scalar($query['date_to'] ?? '');
    $errors = [];

    if ($date_from !== '' && !validIsoDate($date_from)) {
        $errors[] = 'Choose a valid start date.';
        $date_from = '';
    }
    if ($date_to !== '' && !validIsoDate($date_to)) {
        $errors[] = 'Choose a valid end date.';
        $date_to = '';
    }
    $default_date_from = applicationBusinessDate($instant);
    $default_date_to = engagementMapDateAfterMonths($default_date_from, 3);
    if ($date_from === '') {
        $date_from = $default_date_from;
    }
    if ($date_to === '') {
        $date_to = engagementMapDateAfterMonths($date_from, 3);
    }
    if ($date_to < $date_from) {
        $errors[] = 'The end of the date window cannot precede its start.';
        $date_from = $default_date_from;
        $date_to = $default_date_to;
    }

    return [
        'status' => $status,
        'lifecycle' => $lifecycle,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'errors' => $errors,
    ];
}

function engagementMapAddress(array $engagement)
{
    $parts = [];
    foreach (['event_address_line_1', 'event_address_line_2'] as $field) {
        $value = trim((string) ($engagement[$field] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $city_line = trim(implode(', ', array_filter([
        trim((string) ($engagement['event_city'] ?? '')),
        trim((string) ($engagement['event_state'] ?? '')),
    ], static function ($value) {
        return $value !== '';
    })));
    $zipcode = trim((string) ($engagement['event_zipcode'] ?? ''));
    if ($zipcode !== '') {
        $city_line = trim($city_line . ' ' . $zipcode);
    }
    if ($city_line !== '') {
        $parts[] = $city_line;
    }

    $country = trim((string) ($engagement['event_country'] ?? ''));
    if ($country !== '') {
        $parts[] = $country;
    }

    return implode(', ', $parts);
}

function engagementMapAddressHash($address)
{
    $normalized = preg_replace('/\s+/u', ' ', trim((string) $address));
    return hash('sha256', strtolower($normalized));
}

/**
 * Normalize the engagement identifiers accepted by the batched map endpoints.
 *
 * @return list<int>
 */
function normalizeEngagementMapIds($value, int $maximum): array
{
    $values = is_array($value) ? $value : explode(',', (string) $value);
    $ids = [];
    foreach ($values as $candidate) {
        if (!is_scalar($candidate)
            || preg_match('/\A[1-9][0-9]{0,9}\z/', trim((string) $candidate)) !== 1
        ) {
            throw new InvalidArgumentException('Choose valid engagement locations.');
        }
        $ids[(int) $candidate] = true;
        if (count($ids) > $maximum) {
            throw new LengthException('Too many engagement locations were requested.');
        }
    }

    return array_keys($ids);
}

/**
 * Add new addresses to the queue without changing the state of existing work.
 * An explicit retry may revive failed work, but normal map refreshes must not
 * reset attempts, processing leases, errors, or retry schedules.
 *
 * @param list<string> $addresses
 */
function queueEngagementMapAddresses(mysqli $conn, array $addresses, bool $retry_failed = false): bool
{
    $unique_addresses = [];
    foreach ($addresses as $address) {
        $address = trim((string) $address);
        if ($address !== '') {
            $unique_addresses[engagementMapAddressHash($address)] = $address;
        }
    }
    if ($unique_addresses === []) {
        return true;
    }

    $rows = implode(', ', array_fill(
        0,
        count($unique_addresses),
        "(?, ?, 'pending', 0, UTC_TIMESTAMP())"
    ));
    $duplicate_update = 'address_query = IF(address_query <> VALUES(address_query), '
        . 'VALUES(address_query), address_query)';
    if ($retry_failed) {
        $duplicate_update .= ",
            attempts = IF(status = 'failed', 0, attempts),
            next_attempt_at = IF(status = 'failed', UTC_TIMESTAMP(), next_attempt_at),
            processing_started_at = IF(status = 'failed', NULL, processing_started_at),
            last_error = IF(status = 'failed', NULL, last_error),
            status = IF(status = 'failed', 'pending', status)";
    }
    $stmt = $conn->prepare(
        "INSERT INTO engagement_map_geocode_queue
            (address_hash, address_query, status, attempts, next_attempt_at)
         VALUES {$rows}
         ON DUPLICATE KEY UPDATE {$duplicate_update}"
    );
    if (!$stmt) {
        return false;
    }

    $values = [];
    foreach ($unique_addresses as $address_hash => $address) {
        $values[] = $address_hash;
        $values[] = $address;
    }
    $bind = [str_repeat('s', count($values))];
    foreach ($values as &$value) {
        $bind[] = &$value;
    }
    unset($value);
    $stmt->bind_param(...$bind);
    $queued = $stmt->execute();
    $stmt->close();

    return $queued;
}

function queueEngagementMapAddress(mysqli $conn, $address, bool $retry_failed = false)
{
    return queueEngagementMapAddresses($conn, [(string) $address], $retry_failed);
}

/**
 * Load location and queue state for a bounded set of engagement identifiers.
 * The returned address is internal endpoint data and should not be serialized.
 *
 * @param list<int> $engagement_ids
 * @return list<array{id: int, status: string, latitude?: float, longitude?: float, address: string}>
 */
function engagementMapLocationStatuses(mysqli $conn, array $engagement_ids): array
{
    if ($engagement_ids === []) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($engagement_ids), '?'));
    $stmt = $conn->prepare(
        "SELECT id, event_address_line_1, event_address_line_2, event_city,
                event_state, event_zipcode, event_country
         FROM engagements
         WHERE is_deleted = 0 AND id IN ({$placeholders})"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare engagement map locations.');
    }
    $ids = $engagement_ids;
    $bind = [str_repeat('i', count($ids))];
    foreach ($ids as &$id) {
        $bind[] = &$id;
    }
    unset($id);
    $stmt->bind_param(...$bind);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load engagement map locations.');
    }
    $engagements = [];
    $hashes = [];
    $result = $stmt->get_result();
    while ($engagement = $result->fetch_assoc()) {
        $address = engagementMapAddress($engagement);
        $hash = $address === '' ? '' : engagementMapAddressHash($address);
        $engagements[(int) $engagement['id']] = [
            'address' => $address,
            'hash' => $hash,
        ];
        if ($hash !== '') {
            $hashes[$hash] = true;
        }
    }
    $stmt->close();

    $geocodes = [];
    $queue_states = [];
    if ($hashes !== []) {
        $hash_values = array_keys($hashes);
        $hash_placeholders = implode(', ', array_fill(0, count($hash_values), '?'));
        $state_stmt = $conn->prepare(
            "SELECT requested.address_hash, requested.latitude, requested.longitude,
                    requested.lookup_status, q.status AS queue_status
             FROM (
                SELECT address_hash, latitude, longitude, lookup_status
                FROM engagement_map_geocodes
                WHERE address_hash IN ({$hash_placeholders})
             ) requested
             LEFT JOIN engagement_map_geocode_queue q
               ON q.address_hash = requested.address_hash
             UNION ALL
             SELECT q.address_hash, NULL, NULL, NULL, q.status
             FROM engagement_map_geocode_queue q
             WHERE q.address_hash IN ({$hash_placeholders})
               AND NOT EXISTS (
                    SELECT 1 FROM engagement_map_geocodes g
                    WHERE g.address_hash = q.address_hash
               )"
        );
        if (!$state_stmt) {
            throw new RuntimeException('Unable to prepare engagement map status.');
        }
        $state_hashes = array_merge($hash_values, $hash_values);
        $state_bind = [str_repeat('s', count($state_hashes))];
        foreach ($state_hashes as &$state_hash) {
            $state_bind[] = &$state_hash;
        }
        unset($state_hash);
        $state_stmt->bind_param(...$state_bind);
        if (!$state_stmt->execute()) {
            $state_stmt->close();
            throw new RuntimeException('Unable to load engagement map status.');
        }
        $state_result = $state_stmt->get_result();
        while ($state = $state_result->fetch_assoc()) {
            $hash = (string) $state['address_hash'];
            if ($state['lookup_status'] !== null) {
                $geocodes[$hash] = $state;
            }
            if ($state['queue_status'] !== null) {
                $queue_states[$hash] = (string) $state['queue_status'];
            }
        }
        $state_stmt->close();
    }

    $locations = [];
    foreach ($engagement_ids as $engagement_id) {
        if (!isset($engagements[$engagement_id])) {
            continue;
        }
        $address = $engagements[$engagement_id]['address'];
        $hash = $engagements[$engagement_id]['hash'];
        $location = [
            'id' => $engagement_id,
            'status' => $address === '' ? 'no_address' : 'unqueued',
            'address' => $address,
        ];
        $geocode = $hash === '' ? null : ($geocodes[$hash] ?? null);
        if (is_array($geocode) && $geocode['lookup_status'] === 'not_found') {
            $location['status'] = 'not_found';
        } elseif (is_array($geocode)
            && $geocode['lookup_status'] === 'found'
            && engagementMapCoordinatesAreValid($geocode['latitude'], $geocode['longitude'])
        ) {
            $location['status'] = 'found';
            $location['latitude'] = (float) $geocode['latitude'];
            $location['longitude'] = (float) $geocode['longitude'];
        } elseif ($hash !== '' && isset($queue_states[$hash])) {
            $location['status'] = $queue_states[$hash] === 'failed' ? 'failed' : 'pending';
        }
        $locations[] = $location;
    }

    return $locations;
}

/**
 * Store a completed lookup and acknowledge its queue item as one atomic change.
 *
 * @param array{latitude: float, longitude: float}|null $coordinates
 */
function completeEngagementMapGeocodeJob(
    mysqli $conn,
    string $address_hash,
    string $address_query,
    ?array $coordinates
): void {
    $latitude = $coordinates['latitude'] ?? null;
    $longitude = $coordinates['longitude'] ?? null;
    $lookup_status = $coordinates === null ? 'not_found' : 'found';
    if ($coordinates !== null && !engagementMapCoordinatesAreValid($latitude, $longitude)) {
        throw new InvalidArgumentException('A completed geocoding job must contain valid coordinates.');
    }
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start geocoding result storage: ' . $conn->error);
    }

    try {
        $save = $conn->prepare(
            'INSERT INTO engagement_map_geocodes
                (address_hash, address_query, latitude, longitude, lookup_status, geocoded_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                address_query = VALUES(address_query), latitude = VALUES(latitude),
                longitude = VALUES(longitude), lookup_status = VALUES(lookup_status),
                geocoded_at = VALUES(geocoded_at)'
        );
        if (!$save) {
            throw new RuntimeException('Unable to prepare geocoding result storage: ' . $conn->error);
        }
        $save->bind_param(
            'ssdds',
            $address_hash,
            $address_query,
            $latitude,
            $longitude,
            $lookup_status
        );
        if (!$save->execute()) {
            $save_error = $save->error;
            $save->close();
            throw new RuntimeException('Unable to store a geocoding result: ' . $save_error);
        }
        $save->close();

        $delete = $conn->prepare(
            "DELETE FROM engagement_map_geocode_queue
             WHERE address_hash = ? AND status = 'processing'"
        );
        if (!$delete) {
            throw new RuntimeException('Unable to prepare geocoding-job completion: ' . $conn->error);
        }
        $delete->bind_param('s', $address_hash);
        if (!$delete->execute() || $delete->affected_rows !== 1) {
            $delete_error = $delete->error;
            $delete->close();
            throw new RuntimeException('Unable to complete the geocoding job: ' . $delete_error);
        }
        $delete->close();

        if (!$conn->commit()) {
            throw new RuntimeException('Unable to commit the geocoding result: ' . $conn->error);
        }
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function geocoderAddressMatchesCidr($address, $cidr)
{
    [$network, $prefix] = array_pad(explode('/', (string) $cidr, 2), 2, null);
    $address_binary = @inet_pton((string) $address);
    $network_binary = @inet_pton((string) $network);
    if (!is_string($address_binary)
        || !is_string($network_binary)
        || strlen($address_binary) !== strlen($network_binary)
        || !is_numeric($prefix)
    ) {
        return false;
    }

    $prefix = (int) $prefix;
    $maximum_prefix = strlen($address_binary) * 8;
    if ($prefix < 0 || $prefix > $maximum_prefix) {
        return false;
    }

    $whole_bytes = intdiv($prefix, 8);
    if ($whole_bytes > 0
        && !hash_equals(
            substr($address_binary, 0, $whole_bytes),
            substr($network_binary, 0, $whole_bytes)
        )
    ) {
        return false;
    }
    $remaining_bits = $prefix % 8;
    if ($remaining_bits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remaining_bits)) & 0xff;
    return (ord($address_binary[$whole_bytes]) & $mask)
        === (ord($network_binary[$whole_bytes]) & $mask);
}

function geocoderAddressIsPublic($address)
{
    if (filter_var(
        $address,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) === false) {
        return false;
    }

    // PHP's reserved/private flags do not cover every IANA special-use range.
    // Explicitly exclude shared address space and IPv6 translation/tunnelling
    // prefixes that could otherwise encode a private or loopback destination.
    static $additional_non_public_networks = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2002::/16',
        '3fff::/20',
        '5f00::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];
    foreach ($additional_non_public_networks as $network) {
        if (geocoderAddressMatchesCidr($address, $network)) {
            return false;
        }
    }

    return true;
}

/** @return list<string> */
function geocoderHostPublicAddresses($host)
{
    $host = trim((string) $host, '[]');
    $addresses = filter_var($host, FILTER_VALIDATE_IP)
        ? [$host]
        : array_values(array_unique(array_filter(array_merge(
            gethostbynamel($host) ?: [],
            array_map(
                static fn($record) => $record['ipv6'] ?? null,
                dns_get_record($host, DNS_AAAA) ?: []
            )
        ))));
    if ($addresses === []) {
        return [];
    }
    foreach ($addresses as $address) {
        if (!geocoderAddressIsPublic($address)) {
            return [];
        }
    }
    return $addresses;
}

function geocoderHostIsPublic($host)
{
    return geocoderHostPublicAddresses($host) !== [];
}

/** @return array{url: string, host: string, port: int, addresses: list<string>} */
function validatedGeocoderEndpoint()
{
    $url = trim((string) (getenv('DNR_GEOCODER_BASE_URL') ?: 'https://nominatim.openstreetmap.org/search'));
    $host = trim(strtolower((string) parse_url($url, PHP_URL_HOST)), '[]');
    $allowed_hosts = array_filter(array_map(
        static fn($value) => trim(strtolower(trim($value)), '[]'),
        explode(',', (string) (getenv('DNR_GEOCODER_ALLOWED_HOSTS') ?: 'nominatim.openstreetmap.org'))
    ));
    $port = parse_url($url, PHP_URL_PORT) ?: 443;
    if (!filter_var($url, FILTER_VALIDATE_URL)
        || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
        || parse_url($url, PHP_URL_USER) !== null
        || parse_url($url, PHP_URL_PASS) !== null
        || parse_url($url, PHP_URL_FRAGMENT) !== null
        || !in_array($host, $allowed_hosts, true)
    ) {
        throw new RuntimeException('The configured geocoder endpoint is not an allowed public HTTPS host.');
    }
    $addresses = geocoderHostPublicAddresses($host);
    if ($addresses === []) {
        throw new RuntimeException('The configured geocoder endpoint is not an allowed public HTTPS host.');
    }
    return ['url' => $url, 'host' => $host, 'port' => $port, 'addresses' => $addresses];
}

function validatedGeocoderBaseUrl()
{
    return validatedGeocoderEndpoint()['url'];
}

function geocoderAddressesMatch($left, $right)
{
    $left_binary = @inet_pton((string) $left);
    $right_binary = @inet_pton((string) $right);
    return is_string($left_binary)
        && is_string($right_binary)
        && hash_equals($left_binary, $right_binary);
}

function geocodeEngagementMapAddress($address)
{
    $endpoint = validatedGeocoderEndpoint();
    $base_url = $endpoint['url'];
    $application_version = defined('APP_VERSION') ? APP_VERSION : 'dev';
    $separator = strpos($base_url, '?') === false ? '?' : '&';
    $url = $base_url . $separator . http_build_query([
        'format' => 'jsonv2',
        'limit' => 1,
        'q' => $address,
    ], '', '&', PHP_QUERY_RFC3986);
    $user_agent = preg_replace('/[\r\n]+/', ' ', trim((string) (getenv('DNR_GEOCODER_USER_AGENT')
        ?: applicationBrandName() . '/' . $application_version . ' (' . githubRepositoryUrl() . ')')));
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The geocoder HTTP client is unavailable.');
    }

    // Resolve once, validate every answer, and pin the request to one validated
    // address. This closes the DNS check/use window without weakening TLS SNI
    // or certificate-name verification for the configured hostname.
    $pinned_address = $endpoint['addresses'][0];
    $curl_address = str_contains($pinned_address, ':') ? '[' . $pinned_address . ']' : $pinned_address;
    $body = '';
    $body_too_large = false;
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('The geocoder HTTP client could not be initialized.');
    }
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => $user_agent,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RESOLVE => [
            $endpoint['host'] . ':' . $endpoint['port'] . ':' . $curl_address,
        ],
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$body_too_large): int {
            if (strlen($body) + strlen($chunk) > 262144) {
                $body_too_large = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    $completed = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $connected_address = (string) curl_getinfo($curl, CURLINFO_PRIMARY_IP);
    curl_close($curl);
    if ($completed === false
        || $body_too_large
        || $status < 200
        || $status >= 300
        || !geocoderAddressesMatch($connected_address, $pinned_address)
    ) {
        throw new RuntimeException('The geocoder did not return a successful response.');
    }
    return parseEngagementMapGeocoderResponse($body);
}

function engagementMapCoordinatesAreValid($latitude, $longitude)
{
    return is_numeric($latitude)
        && is_numeric($longitude)
        && (float) $latitude >= -90
        && (float) $latitude <= 90
        && (float) $longitude >= -180
        && (float) $longitude <= 180;
}

function parseEngagementMapGeocoderResponse($response)
{
    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('The location service returned an invalid response.');
    }
    if ($decoded === []) {
        return null;
    }

    $first = $decoded[0] ?? null;
    if (!is_array($first)
        || !engagementMapCoordinatesAreValid($first['lat'] ?? null, $first['lon'] ?? null)
    ) {
        throw new RuntimeException('The location service returned invalid coordinates.');
    }

    return [
        'latitude' => (float) $first['lat'],
        'longitude' => (float) $first['lon'],
    ];
}

function engagementMapDateLabel($start_date, $end_date)
{
    $start_timestamp = strtotime((string) $start_date);
    $end_timestamp = strtotime((string) $end_date);
    if (!$start_timestamp || !$end_timestamp) {
        return trim((string) $start_date . ' – ' . (string) $end_date);
    }
    $start = date('M j, Y', $start_timestamp);
    if ($start_date === $end_date) {
        return $start;
    }
    return $start . ' – ' . date('M j, Y', $end_timestamp);
}
