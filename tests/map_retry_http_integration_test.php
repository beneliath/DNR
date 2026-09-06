<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Map retry HTTP integration tests skipped (requires a disposable database and HTTP server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/map_helpers.php';
function expectMapHttp(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Map retry HTTP test failed: ' . $message);
}
$baseUrl = rtrim((string) (getenv('DNR_TEST_BASE_URL') ?: 'http://web'), '/');
expectMapHttp(in_array(parse_url($baseUrl, PHP_URL_HOST), ['127.0.0.1', 'localhost', 'web'], true), 'Use only the disposable local HTTP server');
$cookieFile = tempnam(sys_get_temp_dir(), 'dnr-map-test-');
$request = static function (string $path, ?array $post = null) use ($baseUrl, &$cookieFile): array {
    $curl = curl_init($baseUrl . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = curl_exec($curl);
    expectMapHttp(is_string($body), 'HTTP request should complete');
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body,
        'json' => json_decode($body, true)];
};
$mapData = static function (array $response): array {
    expectMapHttp($response['status'] === 200, 'Map should render');
    preg_match('/id="engagement-map-data">(.*?)<\/script>/s', $response['body'], $matches);
    $data = json_decode($matches[1] ?? '', true);
    expectMapHttp(is_array($data), 'Map payload should be available');
    return $data;
};
$suffix = bin2hex(random_bytes(5));
$userId = $organizationId = 0;
$addressHash = '';
try {
    $username = 'map-retry-' . $suffix;
    $password = bin2hex(random_bytes(16));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')");
    $stmt->bind_param('ss', $username, $passwordHash);
    $stmt->execute();
    $userId = (int) $conn->insert_id;
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Map retry fixture {$suffix}')");
    $organizationId = (int) $conn->insert_id;
    foreach (['Needs lookup', 'Country only'] as $title) {
        $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date,
            event_type, confirmation_status, event_country) VALUES ({$organizationId}, '{$title}', '2097-01-01',
            '2097-01-02', 'conference', 'confirmed', 'US')");
        if ($title === 'Needs lookup') $engagementId = (int) $conn->insert_id;
        else $missingId = (int) $conn->insert_id;
    }
    $street = '960 Test Highway ' . $suffix;
    $stmt = $conn->prepare("UPDATE engagements SET event_address_line_1 = ?, event_city='Inverness', event_state='FL', event_zipcode='34450' WHERE id=?");
    $stmt->bind_param('si', $street, $engagementId);
    $stmt->execute();
    $address = $street . ', Inverness, FL 34450, US';
    $addressHash = engagementMapAddressHash($address);
    $stmt = $conn->prepare("INSERT INTO engagement_map_geocodes (address_hash, address_query, lookup_status, geocoded_at) VALUES (?, ?, 'not_found', UTC_TIMESTAMP())");
    $stmt->bind_param('ss', $addressHash, $address);
    $stmt->execute();
    $login = static function () use ($request, $username, $password): void {
        $form = $request('login.php');
        preg_match('/name="csrf_token"[^>]*value="([^"]*)"/', $form['body'], $match);
        $result = $request('login.php', ['csrf_token' => html_entity_decode($match[1] ?? '', ENT_QUOTES), 'username' => $username, 'password' => $password]);
        expectMapHttp($result['status'] === 302, 'Fixture user should log in');
    };
    $login();
    $path = 'map.php?date_from=2097-01-01&date_to=2097-01-02';
    $page = $request($path);
    $data = $mapData($page);
    $csrf = $data['locationLookup']['csrfToken'];
    $fields = ['csrf_token' => $csrf, 'engagement_ids' => (string) $engagementId];
    expectMapHttp(str_contains($page['body'], 'Show missing addresses') && !str_contains($page['body'], 'Find missing addresses'), 'The filter action must describe its behavior');
    $missing = $mapData($request($path . '&location=needs_address'));
    expectMapHttp(array_column($missing['events'], 'id') === [$missingId], 'A country-only record should need an address; an unresolved address should not');
    $empty = $request('map.php?date_from=2098-01-01&date_to=2098-01-02&location=needs_address');
    expectMapHttp(str_contains($empty['body'], 'No missing addresses') && str_contains($empty['body'], 'Show all locations')
        && str_contains($empty['body'], 'id="engagement-map" class="engagement-map" hidden'), 'Empty results should explain the filter and hide the world map');
    expectMapHttp($request('map_geocode.php', ['engagement_ids' => $engagementId, 'retry' => '1'])['status'] === 400, 'Retry must require CSRF');
    $normal = $request('map_geocode.php', $fields);
    expectMapHttp($normal['json']['locations'][0]['status'] === 'not_found', 'Refresh should preserve a cached miss');
    $retry = $request('map_geocode.php', $fields + ['retry' => '1']);
    expectMapHttp($retry['status'] === 202 && $retry['json']['locations'][0]['status'] === 'pending', 'Explicit retry should queue the cached miss');
    $status = $request('map_geocode_status.php', $fields);
    expectMapHttp($status['json']['locations'][0]['status'] === 'pending', 'Polling must prefer active work to the old cached miss');
    $pending = $mapData($request($path));
    expectMapHttp($pending['events'][0]['locationState'] === 'pending', 'Reloaded page should show the same pending state as the endpoint');
    $conn->query("UPDATE engagement_map_geocode_queue SET status='retry', attempts=3, next_attempt_at='2099-01-01', last_error='test outage' WHERE address_hash='{$addressHash}'");
    $request('map_geocode.php', $fields + ['retry' => '1']);
    $queue = $conn->query("SELECT status, attempts, next_attempt_at, last_error FROM engagement_map_geocode_queue WHERE address_hash='{$addressHash}'")->fetch_assoc();
    expectMapHttp($queue['status'] === 'retry' && (int) $queue['attempts'] === 3 && $queue['next_attempt_at'] === '2099-01-01 00:00:00', 'Repeated clicks must not reset an active backoff or its attempts');
    $conn->query("UPDATE engagement_map_geocode_queue SET status='failed' WHERE address_hash='{$addressHash}'");
    $request('map_geocode.php', $fields + ['retry' => '1']);
    $queue = $conn->query("SELECT status, attempts FROM engagement_map_geocode_queue WHERE address_hash='{$addressHash}'")->fetch_assoc();
    expectMapHttp($queue['status'] === 'pending' && (int) $queue['attempts'] === 0, 'Explicit retry should revive terminal service errors');
    $conn->query("UPDATE engagement_map_geocode_queue SET status='processing' WHERE address_hash='{$addressHash}'");
    completeEngagementMapGeocodeJob($conn, $addressHash, $address, ['latitude' => 28.83, 'longitude' => -82.34, 'provider' => 'geoapify',
        'confidence' => 0.99, 'match_type' => 'full_match', 'matched_address' => '960 Test Highway, Inverness, FL 34450']);
    $done = $request('map_geocode.php', $fields + ['retry' => '1']);
    expectMapHttp($done['status'] === 200 && $done['json']['locations'][0]['status'] === 'found', 'Retry must preserve already located coordinates');
    expectMapHttp((int) $conn->query("SELECT COUNT(*) n FROM engagement_map_geocode_queue WHERE address_hash='{$addressHash}'")->fetch_assoc()['n'] === 0, 'Found coordinates must not be requeued');
    expectMapHttp($done['json']['locations'][0]['provider'] === 'geoapify' && $done['json']['locations'][0]['confidence'] === 0.99,
        'Provider and match quality should survive worker storage and status polling');
    $pinPage = $request('map_pin.php?id=' . $engagementId);
    expectMapHttp($pinPage['status'] === 200 && str_contains($pinPage['body'], 'Save confirmed pin'), 'Editor should be able to set a map pin');
    $pinFields = ['csrf_token' => $csrf, 'address_hash' => $addressHash, 'latitude' => '28.84', 'longitude' => '-82.35'];
    expectMapHttp($request('map_pin.php?id=' . $engagementId, $pinFields)['status'] === 400, 'Pin confirmation must be explicit');
    expectMapHttp($request('map_pin.php?id=' . $engagementId, array_merge($pinFields, ['latitude' => '100', 'confirm_pin' => 'yes']))['status'] === 400, 'Reject invalid coordinates');
    expectMapHttp($request('map_pin.php?id=' . $engagementId, $pinFields + ['confirm_pin' => 'yes'])['status'] === 303, 'Confirmed pin should save');
    $manual = $request('map_geocode_status.php', $fields)['json']['locations'][0];
    expectMapHttp($manual['provider'] === 'manual' && $manual['latitude'] === 28.84 && $manual['longitude'] === -82.35, 'Manual pins must take priority over automatic results');
    $request('map_geocode.php', $fields + ['retry' => '1']);
    expectMapHttp((int) $conn->query("SELECT COUNT(*) n FROM engagement_map_geocode_queue WHERE address_hash='{$addressHash}'")->fetch_assoc()['n'] === 0, 'Retry must not overwrite a confirmed pin');
    // Deleting a former confirmer must retain the venue pin and not block account deletion.
    $conn->query("INSERT INTO users (username, password, role, account_status) VALUES ('pin-actor-{$suffix}', 'unused', 'editor', 'inactive')");
    $formerActorId = (int) $conn->insert_id;
    try {
        $conn->query("UPDATE engagement_map_pins SET confirmed_by={$formerActorId} WHERE engagement_id={$engagementId}");
        $conn->query("DELETE FROM users WHERE id={$formerActorId}");
        $retainedPin = $conn->query("SELECT confirmed_by FROM engagement_map_pins WHERE engagement_id={$engagementId}")->fetch_assoc();
        expectMapHttp($retainedPin !== null && $retainedPin['confirmed_by'] === null, 'Confirmed pin should survive deletion of its confirmer');
        expectMapHttp($request('map_geocode_status.php', $fields)['json']['locations'][0]['provider'] === 'manual', 'Retained pins must keep manual precedence');
    } finally {
        $conn->query("UPDATE engagement_map_pins SET confirmed_by={$userId} WHERE engagement_id={$engagementId}");
        $conn->query("DELETE FROM users WHERE id={$formerActorId}");
    }
    $conn->query("UPDATE engagements SET event_city='Other city' WHERE id={$engagementId}");
    expectMapHttp($request('map_pin.php?id=' . $engagementId, $pinFields + ['confirm_pin' => 'yes'])['status'] === 409, 'Reject a pin saved against a stale address');
    $changed = $request('map_geocode_status.php', $fields)['json']['locations'][0];
    expectMapHttp(($changed['provider'] ?? '') !== 'manual', 'An old pin must not follow an engagement to a different address');
    $conn->query("UPDATE engagements SET event_city='Inverness' WHERE id={$engagementId}");
    expectMapHttp($request('map_pin.php?id=' . $engagementId, $pinFields + ['action' => 'clear'])['status'] === 303, 'Editor can return to automatic lookup');
    expectMapHttp($request('map_geocode_status.php', $fields)['json']['locations'][0]['provider'] === 'geoapify', 'Clearing an override should restore automatic results');
    $conn->query("UPDATE engagements SET is_deleted=1 WHERE id={$engagementId}");
    expectMapHttp($request('map_geocode.php', $fields + ['retry' => '1'])['status'] === 404, 'Deleted engagements cannot be retried');
    $conn->query("UPDATE users SET role='reviewer' WHERE id={$userId}");
    unlink($cookieFile);
    $cookieFile = tempnam(sys_get_temp_dir(), 'dnr-map-viewer-');
    $login();
    $viewer = $mapData($request($path));
    expectMapHttp($request('map_geocode.php', ['csrf_token' => $viewer['locationLookup']['csrfToken'], 'engagement_ids' => $missingId, 'retry' => '1'])['status'] === 403,
        'Reviewers cannot trigger explicit retries');
    expectMapHttp($request('map_pin.php?id=' . $missingId)['status'] === 403, 'Reviewers cannot edit pins');
    echo "Map retry HTTP integration tests passed.\n";
} finally {
    if ($addressHash !== '') {
        $conn->query("DELETE FROM engagement_map_geocode_queue WHERE address_hash='{$addressHash}'");
        $conn->query("DELETE FROM engagement_map_geocodes WHERE address_hash='{$addressHash}'");
    }
    if ($organizationId) {
        $conn->query("DELETE FROM engagements WHERE organization_id={$organizationId}");
        $conn->query("DELETE FROM organizations WHERE id={$organizationId}");
    }
    if ($userId) $conn->query("DELETE FROM users WHERE id={$userId}");
    if (is_file($cookieFile)) unlink($cookieFile);
}
