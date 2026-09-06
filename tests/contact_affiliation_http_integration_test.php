<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_TEST_BASE_URL') === false) {
    echo "Contact affiliation HTTP integration tests skipped (requires disposable database and HTTP test server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';

function expectAffiliationHttp(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function affiliationHttp(string $path, string $sessionId, ?array $post = null): array {
    $curl = curl_init(rtrim((string) getenv('DNR_TEST_BASE_URL'), '/') . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sessionId, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
}
function affiliationVersion(string $body): string {
    $dom = new DOMDocument(); @$dom->loadHTML($body); $xpath = new DOMXPath($dom);
    $field = $xpath->query('//input[@name="contact_version"]')->item(0);
    expectAffiliationHttp($field instanceof DOMElement, 'Edit form must expose the contact version');
    return $field->getAttribute('value');
}
function affiliationSnapshot(mysqli $conn, int $contactId): array {
    return $conn->query('SELECT organization_id, role_title FROM contact_organizations WHERE contact_id = '
        . $contactId . ' ORDER BY organization_id')->fetch_all(MYSQLI_ASSOC);
}
$suffix = bin2hex(random_bytes(5));
$users = $organizations = $contacts = $engagements = [];
$sessionId = '';
try {
    $username = 'affiliation-http-' . $suffix;
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')");
    $stmt->bind_param('ss', $username, $password); $stmt->execute();
    $userId = $users[] = (int) $conn->insert_id;
    $user = $conn->query('SELECT * FROM users WHERE id = ' . $userId)->fetch_assoc();
    session_start();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = ['user_id' => $userId, 'username' => $username, 'role' => 'editor',
        'auth_version' => (int) $user['auth_version'], 'auth_complete' => true, '_csrf_token' => $csrf];
    $sessionId = session_id(); session_write_close(); session_id('');
    foreach (['Church', 'Research', 'Foundation'] as $label) {
        $conn->query("INSERT INTO organizations (organization_name) VALUES ('Affiliation{$label}{$suffix}')");
        $organizations[] = (int) $conn->insert_id;
    }
    [$church, $research, $foundation] = $organizations;
    $base = ['csrf_token' => $csrf, 'save_contact' => '1', 'contact_first_name' => 'Andy',
        'contact_last_name' => 'Affiliation' . $suffix, 'contact_role' => 'pastor',
        'contact_email' => 'affiliation-' . $suffix . '@example.org', 'organization_id' => $church,
        'additional_organizations' => [
            ['organization_id' => $research, 'role_title' => 'Chairman'],
            ['organization_id' => $foundation, 'role_title' => 'Trustee'],
        ]];
    $created = affiliationHttp('add_contact.php', $sessionId, $base);
    expectAffiliationHttp($created['status'] === 302
        && preg_match('/Location: view_contact\.php\?id=(\d+)/i', $created['headers'], $match) === 1,
        'Contact creation with multiple affiliations must redirect to its saved record');
    $contactId = $contacts[] = (int) $match[1];
    $initial = affiliationSnapshot($conn, $contactId);
    expectAffiliationHttp(count($initial) === 3
        && array_column($initial, 'role_title') === ['Pastor', 'Chairman', 'Trustee'],
        'Create route must persist independent primary and additional roles');
    foreach ([$church, $research] as $orgId) {
        $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status) VALUES ({$orgId}, 'Affiliation event {$suffix}', '2026-10-10', '2026-10-11', 'conference', 'under_review')");
        $eventId = $engagements[] = (int) $conn->insert_id;
        $conn->query("INSERT INTO engagement_contacts (engagement_id, contact_id, contact_role) VALUES ({$eventId}, {$contactId}, 'primary_host')");
    }
    $edit = affiliationHttp('edit_contact.php?id=' . $contactId, $sessionId);
    expectAffiliationHttp($edit['status'] === 200 && str_contains($edit['body'], 'Chairman') && str_contains($edit['body'], 'Trustee'),
        'Edit form must reload saved affiliations and titles');
    $promoted = array_replace($base, ['contact_version' => affiliationVersion($edit['body']),
        'organization_id' => $research, 'contact_role' => 'other', 'contact_role_other' => 'Chairman',
        'additional_organizations' => [
            ['organization_id' => $church, 'role_title' => 'Pastor'],
            ['organization_id' => $foundation, 'role_title' => 'Trustee'],
        ]]);
    $updated = affiliationHttp('edit_contact.php?id=' . $contactId, $sessionId, $promoted);
    expectAffiliationHttp($updated['status'] === 302, 'Make primary equivalent submission must save');
    $saved = $conn->query('SELECT organization_id, contact_role, contact_role_other FROM contacts WHERE id = ' . $contactId)->fetch_assoc();
    expectAffiliationHttp((int) $saved['organization_id'] === $research && $saved['contact_role'] === 'other'
        && $saved['contact_role_other'] === 'Chairman' && affiliationSnapshot($conn, $contactId) === $initial,
        'Changing primary must preserve every organization and its own role');
    expectAffiliationHttp((int) $conn->query('SELECT COUNT(*) AS total FROM engagement_contacts WHERE contact_id = ' . $contactId)->fetch_assoc()['total'] === 2,
        'Changing primary while retaining affiliations must preserve both organizations’ event assignments');
    foreach ([$church => 'Pastor', $research => 'Chairman', $foundation => 'Trustee'] as $orgId => $title) {
        $view = affiliationHttp('view_organization.php?id=' . $orgId, $sessionId);
        $dom = new DOMDocument(); @$dom->loadHTML($view['body']); $xpath = new DOMXPath($dom);
        $card = $xpath->query('//div[contains(@class,"contact-card")][.//a[contains(@href,"view_contact.php?id=' . $contactId . '&")]]')->item(0);
        expectAffiliationHttp($view['status'] === 200 && $card instanceof DOMElement && str_contains($card->textContent, $title),
            'Organization detail must show the contact with the role held at that organization');
    }
    $view = affiliationHttp('view_contact.php?id=' . $contactId, $sessionId);
    expectAffiliationHttp($view['status'] === 200 && str_contains($view['body'], 'Chairman') && str_contains($view['body'], 'Trustee') && str_contains($view['body'], 'Pastor'),
        'Contact detail must show all roles after promotion');
    $edit = affiliationHttp('edit_contact.php?id=' . $contactId, $sessionId);
    $invalid = array_replace($promoted, ['contact_version' => affiliationVersion($edit['body']),
        'contact_first_name' => 'Must not persist',
        'additional_organizations' => [['organization_id' => 2147483647, 'role_title' => 'Invalid organization']]]);
    $failed = affiliationHttp('edit_contact.php?id=' . $contactId, $sessionId, $invalid);
    expectAffiliationHttp($failed['status'] === 200 && str_contains($failed['body'], 'Select only active organizations'),
        'An unavailable affiliation must redisplay validation');
    expectAffiliationHttp(affiliationSnapshot($conn, $contactId) === $initial
        && $conn->query('SELECT contact_first_name FROM contacts WHERE id = ' . $contactId)->fetch_assoc()['contact_first_name'] === 'Andy',
        'Affiliation validation failure must roll back contact fields and all relationship changes');
    $badEmail = 'invalid-affiliation-' . $suffix . '@example.org';
    $invalidCreate = array_replace($base, ['contact_email' => $badEmail,
        'additional_organizations' => [['organization_id' => 2147483647, 'role_title' => 'Invalid organization']]]);
    $failedCreate = affiliationHttp('add_contact.php', $sessionId, $invalidCreate);
    expectAffiliationHttp($failedCreate['status'] === 200
        && (int) $conn->query("SELECT COUNT(*) AS total FROM contacts WHERE contact_email = '{$badEmail}'")->fetch_assoc()['total'] === 0,
        'Creating with an unavailable affiliation must not leave a partial contact');
    $edit = affiliationHttp('edit_contact.php?id=' . $contactId, $sessionId);
    $removed = array_replace($promoted, ['contact_version' => affiliationVersion($edit['body']),
        'additional_organizations' => [['organization_id' => $church, 'role_title' => 'Senior Pastor']]]);
    expectAffiliationHttp(affiliationHttp('edit_contact.php?id=' . $contactId, $sessionId, $removed)['status'] === 302,
        'Editing additional titles and removing an affiliation must save');
    $final = affiliationSnapshot($conn, $contactId);
    expectAffiliationHttp(count($final) === 2 && array_column($final, 'role_title') === ['Senior Pastor', 'Chairman'],
        'Edit route must save additional title changes and remove only omitted affiliations');
    echo "Contact affiliation HTTP integration tests passed.\n";
} finally {
    foreach (['engagements' => $engagements, 'contacts' => $contacts, 'organizations' => $organizations, 'users' => $users] as $table => $ids) {
        foreach ($ids as $id) $conn->query("DELETE FROM {$table} WHERE id = " . (int) $id);
    }
    $sessionPath = rtrim((string) ini_get('session.save_path'), '/') . '/sess_' . $sessionId;
    if ($sessionId !== '' && is_file($sessionPath)) unlink($sessionPath);
}
