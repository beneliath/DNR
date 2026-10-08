<?php
declare(strict_types=1);
// Explicit local-preview fixtures, never enabled by a web route or on HTTPS.
if (PHP_SAPI !== 'cli' || getenv('DNR_ACCOUNT_PREVIEW_FIXTURE') !== '1') exit(1);
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/two_factor_helpers.php';
require_once '/var/www/html/calendar_helpers.php';
if (applicationRequiresHttps() || !accountsEnabled()
    || !preg_match('#\Ahttp://localhost:[0-9]+(?:/a/[a-z][a-z0-9-]{2,63})?\z#', (string) getenv('DNR_PUBLIC_BASE_URL'))) {
    throw new RuntimeException('An explicitly selected localhost Account preview is required.');
}
$nonce = $argv[1] ?? '';
if (!preg_match('/\A[a-f0-9]{10}\z/D', $nonce)) throw new RuntimeException('Invalid fixture nonce.');
$result = ['nonce' => $nonce, 'account_key' => currentAccountKey(), 'marker' => 'AccountTest-' . $nonce, 'users' => []];
foreach (accountIsPrimary() ? ['superadmin', 'admin', 'editor', 'reviewer'] : ['admin', 'editor', 'reviewer'] as $role) {
    $username = 'isolation-' . $nonce . '-' . $role;
    $password = 'Preview!' . bin2hex(random_bytes(16));
    $conn->execute_query('INSERT INTO users (username, password, role, is_superadmin, first_name, last_name, account_status)
        VALUES (?, ?, ?, ?, ?, ?, \'active\')', [$username, password_hash($password, PASSWORD_DEFAULT),
        $role === 'superadmin' ? 'admin' : $role, $role === 'superadmin' ? 1 : 0, 'Isolation', 'Fixture']);
    $id = (int) $conn->insert_id;
    $version = (int) $conn->execute_query('SELECT auth_version FROM users WHERE id = ?', [$id])->fetch_row()[0];
    $codes = enableTwoFactorForUser($conn, $id, generateTotpSecret(), 0, $version);
    $conn->begin_transaction();
    registerAccountLogin($conn, $id, $username);
    $conn->commit();
    flushAccountDirectory();
    $result['users'][$role] = compact('id', 'username', 'password', 'codes');
}
$conn->execute_query('INSERT INTO organizations (organization_name, notes) VALUES (?, ?)', [$result['marker'], $result['marker'] . '-private']);
$result['organization_id'] = (int) $conn->insert_id;
$conn->execute_query('INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date) VALUES (?, ?, CURDATE(), CURDATE())',
    [$result['organization_id'], $result['marker'] . '-event']);
$result['engagement_id'] = (int) $conn->insert_id;
$conn->execute_query('INSERT INTO speakers (name, email, phone) VALUES (?, ?, ?)', [$result['marker'], 'fixture@example.invalid', '+12025550123']);
$result['speaker_id'] = (int) $conn->insert_id;
$conn->execute_query('INSERT INTO presentations (engagement_id, topic_title, speaker_id, presentation_date) VALUES (?, ?, ?, CURDATE())',
    [$result['engagement_id'], $result['marker'] . '-presentation', $result['speaker_id']]);
$result['presentation_id'] = (int) $conn->insert_id;
$pdf = "%PDF-1.4\n% " . $result['marker'] . "\n%%EOF";
$conn->execute_query('INSERT INTO presentation_notes (presentation_id, speaker_id, pdf, filename, size, sha256)
    VALUES (?, ?, ?, ?, ?, ?)', [$result['presentation_id'], $result['speaker_id'], $pdf, 'fixture.pdf', strlen($pdf), hash('sha256', $pdf, true)]);
$result['short_code'] = bin2hex(random_bytes(8));
$conn->execute_query("INSERT INTO short_links (code, engagement_id, presentation_id, speaker_id, link_type, is_enabled)
    VALUES (?, ?, ?, ?, 'notes', 1)", [$result['short_code'], $result['engagement_id'], $result['presentation_id'], $result['speaker_id']]);
$result['calendar'] = createCalendarSubscription($conn, $result['users']['reviewer']['id'], 'Isolation fixture');
echo json_encode($result, JSON_THROW_ON_ERROR);
