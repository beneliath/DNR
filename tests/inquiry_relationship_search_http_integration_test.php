<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Inquiry relationship search HTTP tests skipped (disposable server required).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
putenv('DNR_PUBLIC_BASE_URL=https://crm.example.test');
require_once $source . '/bootstrap.php';
require_once $source . '/inquiry_relationship_helpers.php';
require_once $source . '/mattermost_integration_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Loopback server required.');
function expectInquiryLookup(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$request = static function (string $path, string $cookie = '', ?array $post = null) use ($base): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
        CURLOPT_COOKIE => $cookie, CURLOPT_USERAGENT => 'Inquiry lookup regression']);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = curl_exec($curl);
    if (!is_string($body)) throw new RuntimeException(curl_error($curl));
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => $body];
};
$organizations = [];
$userIds = [];
$inquiryId = 0;
try {
    $suffix = 'lookup' . bin2hex(random_bytes(5));
    for ($i = 0; $i < 32; $i++) {
        $name = sprintf('Host %s %02d', $suffix, $i);
        $stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $organizations[] = (int) $conn->insert_id;
    }
    $lastOrg = $organizations[31];
    $firstOrg = $organizations[0];
    $conn->query("INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_email) VALUES ($firstOrg, 'Taylor', '$suffix', '$suffix@example.test')");
    $contactId = (int) $conn->insert_id;
    $conn->query("INSERT INTO contact_organizations (contact_id, organization_id) VALUES ($contactId, $lastOrg)");
    $results = searchInquiryRelationships($conn, 'organization', $suffix, null, $lastOrg);
    expectInquiryLookup(count($results['results']) === 25 && $results['has_more'], 'Organization searches must be bounded');
    expectInquiryLookup((int) $results['selected']['id'] === $lastOrg
        && count(inquiryRelationshipFormOptions($results)) === 26, 'Saved selections beyond the page must remain available');
    $results = searchInquiryRelationships($conn, 'contact', $suffix, $lastOrg, $contactId);
    expectInquiryLookup(count($results['results']) === 1 && (int) $results['selected']['id'] === $contactId,
        'Secondary affiliated contacts must appear in organization-filtered searches');
    $results = searchInquiryRelationships($conn, 'contact', $suffix, $organizations[1], $contactId);
    expectInquiryLookup($results['results'] === [] && $results['selected'] === null, 'Unrelated contacts must not appear');
    expectInquiryLookup(searchInquiryRelationships($conn, 'organization', 'x\' OR 1=1 --')['results'] === [], 'SQL punctuation is search data');

    $conn->query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type)
        VALUES ($firstOrg,'Annual Summit $suffix','2026-10-01','2026-10-02','conference')");
    $eventId = (int) $conn->insert_id;
    $matches = mattermostSearchEngagements($conn, $suffix);
    expectInquiryLookup(count($matches) === 1 && (int) $matches[0]['id'] === $eventId, 'Title and organization matches must be deduplicated');
    expectInquiryLookup(count(mattermostSearchEngagements($conn, 'An')) === 1, 'Two-character title prefixes must still work');
    expectInquiryLookup(count(mattermostSearchEngagements($conn, 'Summ')) === 1, 'Interior word prefixes must use title fulltext search');
    $conn->query("UPDATE engagements SET is_deleted=1 WHERE id=$eventId");
    expectInquiryLookup(mattermostSearchEngagements($conn, $suffix) === [], 'Archived events must stay out of search');

    $csrf = bin2hex(random_bytes(32));
    foreach (['editor', 'reviewer'] as $role) {
        $name = $role . '-' . $suffix;
        $password = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username,password,role) VALUES (?,?,?)');
        $stmt->bind_param('sss', $name, $password, $role);
        $stmt->execute();
        $userIds[$role] = (int) $conn->insert_id;
        startSecureSession();
        $_SESSION = ['user_id' => $userIds[$role], 'username' => $name, 'role' => $role, 'authenticated_role' => $role,
            'auth_version' => 1, 'auth_complete' => true, '_csrf_token' => $csrf];
        completeIntegrationTestMfaSession();
        $cookie = session_name() . '=' . session_id();
        session_write_close();
        $path = 'inquiry_relationship_search.php?' . http_build_query(['kind' => 'organization', 'q' => $suffix, 'selected_id' => $lastOrg]);
        expectInquiryLookup($request($path)['status'] === 302, 'Search requires authentication');
        $response = $request($path, $cookie);
        expectInquiryLookup($response['status'] === ($role === 'editor' ? 200 : 403), 'Only inquiry managers may search');
        if ($role === 'editor') {
            expectInquiryLookup(count(json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['results']) === 25, 'HTTP response must remain bounded');
            expectInquiryLookup($request('inquiry_relationship_search.php?kind=invalid', $cookie)['status'] === 400, 'Invalid kinds are rejected');
            expectInquiryLookup($request($path, $cookie, [])['status'] === 405, 'Search is read-only');
            $form = $request('add_inquiry.php', $cookie);
            expectInquiryLookup($form['status'] === 200 && !str_contains($form['body'], sprintf('Host %s 31', $suffix)), 'Initial form must not embed the full directory');
            $conn->query("INSERT INTO booking_inquiries (title,organization_id,primary_contact_id,owner_user_id,created_by)
                VALUES ('Lookup draft',$lastOrg,$contactId,{$userIds[$role]},{$userIds[$role]})");
            $inquiryId = (int) $conn->insert_id;
            $version = $conn->query("SELECT updated_at FROM booking_inquiries WHERE id=$inquiryId")->fetch_assoc()['updated_at'];
            $conn->query("UPDATE booking_inquiries SET title='Concurrent edit', updated_at=DATE_ADD(updated_at, INTERVAL 1 SECOND) WHERE id=$inquiryId");
            $form = $request('edit_inquiry.php?id=' . $inquiryId, $cookie, ['id' => $inquiryId, 'csrf_token' => $csrf,
                'search_inquiry_relationships' => '1', 'title' => 'Unsaved draft', 'inquiry_version' => $version,
                'organization_id' => $lastOrg, 'primary_contact_id' => $contactId, 'organization_search' => $suffix]);
            expectInquiryLookup($form['status'] === 200 && str_contains($form['body'], 'value="Unsaved draft"')
                && str_contains($form['body'], 'name="inquiry_version" value="' . $version . '"')
                && str_contains($form['body'], sprintf('Host %s 31', $suffix)), 'No-JS searching preserves drafts, saved selections and the original edit version');
        }
        startSecureSession();
        $_SESSION = [];
        session_destroy();
    }
} finally {
    if ($inquiryId > 0) $conn->query("DELETE FROM booking_inquiries WHERE id=$inquiryId");
    if ($organizations !== []) {
        $ids = implode(',', $organizations);
        $conn->query("DELETE FROM engagements WHERE organization_id IN ($ids)");
        $conn->query("DELETE FROM contacts WHERE organization_id IN ($ids)");
        $conn->query("DELETE FROM organizations WHERE id IN ($ids)");
    }
    foreach ($userIds as $id) $conn->query("DELETE FROM users WHERE id=$id");
}
echo "Inquiry relationship search and Mattermost search integration tests passed.\n";
