<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_TEST_BASE_URL') === false) {
    echo "Record workspace integration tests skipped (requires disposable database and HTTP test server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/chron_log_helpers.php';
function expectRecordHttp(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function recordHttp(string $path, string $sessionId, ?array $post = null): array {
    $curl = curl_init(rtrim((string) getenv('DNR_TEST_BASE_URL'), '/') . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sessionId, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $response = curl_exec($curl);
    if (!is_string($response)) throw new RuntimeException('HTTP test request failed: ' . curl_error($curl));
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
    return $result;
}
function recordTestSession(array $user): array {
    session_start();
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = ['user_id' => (int) $user['id'], 'username' => $user['username'], 'role' => $user['role'],
        'auth_version' => (int) $user['auth_version'], 'auth_complete' => true, '_csrf_token' => $csrf];
    $id = session_id();
    session_write_close();
    session_id('');
    return [$id, $csrf];
}
$suffix = bin2hex(random_bytes(5));
$users = $organizations = $contacts = $engagements = $sessions = [];
try {
    foreach (['editor', 'reviewer'] as $role) {
        $username = 'record-http-' . $role . '-' . $suffix;
        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $username, $password, $role); $stmt->execute();
        $users[] = (int) $conn->insert_id;
        $row = $conn->query('SELECT * FROM users WHERE id = ' . end($users))->fetch_assoc();
        $sessions[$role] = recordTestSession($row);
    }
    [$editorSession, $csrf] = $sessions['editor'];
    $orgName = 'Relationship HTTP ' . $suffix;
    $inline = recordHttp('create_organization_inline.php', $editorSession, ['csrf_token' => $csrf, 'organization_name' => $orgName]);
    $created = json_decode($inline['body'], true);
    expectRecordHttp($inline['status'] === 200 && ($created['id'] ?? 0) > 0, 'Inline name-only organization must be persisted successfully');
    $orgId = $organizations[] = (int) $created['id'];
    expectRecordHttp($conn->query('SELECT organization_name FROM organizations WHERE id = ' . $orgId)->fetch_assoc()['organization_name'] === $orgName, 'Inline name must be stored');
    $invalidCsrf = recordHttp('create_organization_inline.php', $editorSession, ['csrf_token' => 'invalid', 'organization_name' => $orgName . ' invalid']);
    expectRecordHttp($invalidCsrf['status'] === 400, 'Organization creation requires CSRF');
    $reviewerCreate = recordHttp('create_organization_inline.php', $sessions['reviewer'][0], ['csrf_token' => $sessions['reviewer'][1], 'organization_name' => $orgName . ' reviewer']);
    expectRecordHttp($reviewerCreate['status'] === 403, 'Reviewers cannot create organizations');
    $blank = recordHttp('create_organization_inline.php', $editorSession, ['csrf_token' => $csrf, 'organization_name' => '']);
    expectRecordHttp($blank['status'] === 422, 'Name-only intake still rejects an empty name');
    $conn->query("INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_role, contact_email) VALUES ({$orgId}, 'Draft', 'Contact', 'admin', 'record-{$suffix}@example.org')");
    $contactId = $contacts[] = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status) VALUES ({$orgId}, 'Note scope {$suffix}', '2026-09-10', '2026-09-12', 'conference', 'under_review')");
    $eventId = $engagements[] = (int) $conn->insert_id;
    foreach (['organization' => $orgId, 'contact' => $contactId, 'engagement' => $eventId] as $type => $id) {
        $note = ucfirst($type) . ' note ' . $suffix;
        $path = 'view_' . $type . '.php?id=' . $id . '&return_to=' . rawurlencode($type === 'engagement' ? 'engagements.php?search=test&per_page=25' : ($type === 'contact' ? 'contacts.php?search=test' : 'organizations.php?search=test'));
        $result = recordHttp($path, $editorSession, ['csrf_token' => $csrf, 'action' => 'add_note', 'new_chron_entry' => $note, 'event_title' => 'Malicious unrelated change', 'contact_email' => 'changed@example.org', 'organization_name' => 'Unrelated change']);
        expectRecordHttp($result['status'] === 302 && str_contains($result['headers'], 'return_to=') && str_contains($result['headers'], '#chron-log'), 'Scoped note save returns to the record activity and preserves list context');
        $entries = fetchEntityChronLogEntries($conn, $type, $id);
        expectRecordHttp(count($entries) === 1 && $entries[0]['entry_text'] === $note, 'Only the selected entity receives its note');
        $get = recordHttp($path, $editorSession);
        expectRecordHttp($get['status'] === 200 && str_contains($get['body'], $note), 'Record workspace renders its saved note: ' . $type . ' status ' . $get['status']);
        expectRecordHttp(!str_contains($get['body'], 'Fatal error'), 'Record workspace must render without PHP errors');
        $reviewer = recordHttp($path, $sessions['reviewer'][0], ['csrf_token' => $sessions['reviewer'][1], 'action' => 'add_note', 'new_chron_entry' => 'Forbidden']);
        expectRecordHttp($reviewer['status'] === 403 && countEntityChronLogEntries($conn, $type, $id) === 1, 'Read-only roles cannot write a scoped note');
        $invalid = recordHttp($path, $editorSession, ['csrf_token' => 'invalid', 'action' => 'add_note', 'new_chron_entry' => 'Forbidden']);
        expectRecordHttp($invalid['status'] === 400 && countEntityChronLogEntries($conn, $type, $id) === 1, 'Scoped notes require valid CSRF');
        $empty = recordHttp($path, $editorSession, ['csrf_token' => $csrf, 'action' => 'add_note', 'new_chron_entry' => '   ']);
        expectRecordHttp($empty['status'] === 200 && countEntityChronLogEntries($conn, $type, $id) === 1, 'Empty notes redisplay validation without inserting');
    }
    expectRecordHttp($conn->query('SELECT organization_name FROM organizations WHERE id = ' . $orgId)->fetch_assoc()['organization_name'] === $orgName, 'Note save cannot mutate organization fields');
    expectRecordHttp($conn->query('SELECT contact_email FROM contacts WHERE id = ' . $contactId)->fetch_assoc()['contact_email'] === 'record-' . $suffix . '@example.org', 'Note save cannot mutate contact fields');
    expectRecordHttp($conn->query('SELECT event_title FROM engagements WHERE id = ' . $eventId)->fetch_assoc()['event_title'] === 'Note scope ' . $suffix, 'Note save cannot mutate engagement fields');
    // Verify the creation return contract consumed by the inquiry draft controls.
    $newOrg = recordHttp('add_organization.php', $editorSession, ['csrf_token' => $csrf, 'save_org' => '1', 'organization_name' => 'Form intake ' . $suffix, 'return_to' => 'edit_inquiry.php?id=999']);
    expectRecordHttp($newOrg['status'] === 302 && preg_match('/Location: edit_inquiry\.php\?id=999&created_organization_id=(\d+)/i', $newOrg['headers'], $createdMatch) === 1, 'Name-only organization form resumes the inquiry with its new ID');
    $organizations[] = (int) $createdMatch[1];
    $newContact = recordHttp('add_contact.php', $editorSession, ['csrf_token' => $csrf, 'save_contact' => '1', 'contact_first_name' => 'One', 'contact_last_name' => 'Email', 'contact_role' => 'admin', 'contact_email' => 'one-' . $suffix . '@example.org', 'organization_id' => $orgId, 'return_to' => 'add_inquiry.php']);
    expectRecordHttp($newContact['status'] === 302 && preg_match('/Location: add_inquiry\.php\?created_contact_id=(\d+)/i', $newContact['headers'], $contactMatch) === 1, 'A single email contact form resumes the inquiry with its new ID');
    $contacts[] = (int) $contactMatch[1];

    // Each filtered list has enough fixtures to traverse a real page boundary.
    $keyword = 'Recordnav' . $suffix;
    for ($n = 1; $n <= 27; $n++) {
        $conn->query("INSERT INTO organizations (organization_name) VALUES ('{$keyword} Organization {$n}')");
        $organizations[] = (int) $conn->insert_id;
        $conn->query("INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_role, contact_email) VALUES ({$orgId}, '{$keyword}', 'Contact {$n}', 'admin', 'nav-{$suffix}-{$n}@example.org')");
        $contacts[] = (int) $conn->insert_id;
        $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status) VALUES ({$orgId}, '{$keyword} Event {$n}', '2026-09-10', '2026-09-12', 'conference', 'under_review')");
        $engagements[] = (int) $conn->insert_id;
    }
    foreach (['contacts.php', 'organizations.php', 'engagements.php'] as $list) {
        $first = recordHttp($list . '?q=' . $keyword . '&per_page=20', $editorSession);
        expectRecordHttp($first['status'] === 200, 'First page renders successfully: ' . $list);
        $dom = new DOMDocument(); @$dom->loadHTML($first['body']); $xpath = new DOMXPath($dom);
        $pagers = $xpath->query('//nav[contains(@class,"numbered-pagination")]');
        expectRecordHttp($pagers->length === 2 && $dom->saveHTML($pagers->item(0)) === $dom->saveHTML($pagers->item(1)),
            'Lists have identical pagination controls above and below the records: ' . $list);
        expectRecordHttp($xpath->query('//nav[contains(@class,"pagination")]//a[@rel="prev"]')->length === 0,
            'First page has no enabled Previous link: ' . $list);
        $next = $xpath->query('//nav[contains(@class,"pagination")]//a[@rel="next"]')->item(0);
        expectRecordHttp($next instanceof DOMElement, 'Filtered list should offer Next: ' . $list);
        $secondUrl = $next->getAttribute('href');
        expectRecordHttp(str_contains($secondUrl, 'q=' . $keyword)
            && str_contains($secondUrl, 'page=2'),
            'Next must preserve the filter and page position');
        $second = recordHttp($secondUrl, $editorSession);
        expectRecordHttp($second['status'] === 200, 'Later pages render successfully: ' . $list);
        $dom = new DOMDocument(); @$dom->loadHTML($second['body']); $xpath = new DOMXPath($dom);
        $previousLinks = $xpath->query('//nav[contains(@class,"pagination")]//a[@rel="prev" or normalize-space(.)="Previous"]');
        expectRecordHttp($previousLinks->length === 2, 'Both controls offer Previous on later pages: ' . $list);
        $previous = $previousLinks->item(0);
        expectRecordHttp($previous instanceof DOMElement && !str_contains($previous->getAttribute('href'), 'cursor='), 'Previous from page two reaches filtered page one');
        $recordLink = $xpath->query('//tbody//a[starts-with(@href,"view_")]')->item(0);
        expectRecordHttp($recordLink instanceof DOMElement, 'List rows open record details');
        parse_str((string) parse_url($recordLink->getAttribute('href'), PHP_URL_QUERY), $detailQuery);
        expectRecordHttp(($detailQuery['return_to'] ?? '') === $secondUrl, 'Opening a record retains the exact filtered second page');
        if ($list === 'contacts.php') {
            expectRecordHttp($xpath->query('//nav[@aria-label="Contact pages"]//*[@aria-current="page" and normalize-space(.)="2"]')->length === 2,
                'Contacts identify the current numbered page');
            expectRecordHttp($xpath->query('//nav[@aria-label="Contact pages"]//a[@rel="next"]')->length === 0
                && $xpath->query('//tbody//a[@class="record-link"]')->length === 7,
                'The final contacts page has seven remaining records and a disabled Next control');
            $last = recordHttp($list . '?q=' . $keyword . '&per_page=20&page=999999', $editorSession);
            expectRecordHttp($last['status'] === 200 && str_contains($last['body'], 'Showing 21–27 of 27 contacts'),
                'Out-of-range page numbers clamp to the last matching page');
            foreach (['page=0', 'page=-2', 'page[]=2', 'page=invalid'] as $invalidPage) {
                $invalid = recordHttp($list . '?q=' . $keyword . '&per_page=20&' . $invalidPage, $editorSession);
                expectRecordHttp($invalid['status'] === 200 && str_contains($invalid['body'], 'Showing 1–20 of 27 contacts'),
                    'Invalid page input falls back to the first page');
            }
            $empty = recordHttp($list . '?q=NoContact' . $suffix . '&page=5', $editorSession);
            expectRecordHttp($empty['status'] === 200 && str_contains($empty['body'], 'Showing 0 of 0 contacts')
                && !str_contains($empty['body'], 'rel="next"') && !str_contains($empty['body'], 'rel="prev"'),
                'Empty searches have no enabled page navigation');
            $larger = recordHttp($list . '?q=' . $keyword . '&per_page=50&page=2', $editorSession);
            expectRecordHttp($larger['status'] === 200 && str_contains($larger['body'], 'Showing 1–27 of 27 contacts'),
                'Changing the page size keeps the request within the available pages');
        }
    }
    $organizationPageTwo = recordHttp('view_organization.php?id=' . $orgId . '&events_page=2', $editorSession);
    expectRecordHttp($organizationPageTwo['status'] === 200 && substr_count($organizationPageTwo['body'], 'aria-label="Organization engagement pages"') === 2 && str_contains($organizationPageTwo['body'], 'Showing 21–28 of 28 engagements'), 'Organization history includes a reachable second page of related engagements');

    $conn->query('UPDATE organizations SET is_deleted = 1 WHERE id = ' . $orgId);
    $archived = recordHttp('view_contact.php?id=' . $contactId, $editorSession, ['csrf_token' => $csrf, 'action' => 'add_note', 'new_chron_entry' => 'Forbidden archived organization']);
    expectRecordHttp($archived['status'] === 200 && countEntityChronLogEntries($conn, 'contact', $contactId) === 1, 'Contacts on an archived organization remain read-only');
    $conn->query('UPDATE organizations SET is_deleted = 0 WHERE id = ' . $orgId);
    foreach (['contact' => $contactId, 'organization' => $orgId, 'engagement' => $eventId] as $type => $id) {
        $table = $type === 'organization' ? 'organizations' : ($type === 'contact' ? 'contacts' : 'engagements');
        $conn->query("UPDATE {$table} SET is_deleted = 1 WHERE id = {$id}");
        $archived = recordHttp('view_' . $type . '.php?id=' . $id, $editorSession, ['csrf_token' => $csrf, 'action' => 'add_note', 'new_chron_entry' => 'Forbidden archived record']);
        expectRecordHttp($archived['status'] === 200 && countEntityChronLogEntries($conn, $type, $id) === 1, 'Archived records remain read-only');
    }
    echo "Record workspace HTTP integration tests passed.\n";
} finally {
    foreach (['engagements' => $engagements, 'contacts' => $contacts, 'organizations' => $organizations, 'users' => $users] as $table => $ids) {
        foreach ($ids as $id) $conn->query("DELETE FROM {$table} WHERE id = " . (int) $id);
    }
    foreach ($sessions as [$id]) {
        $sessionPath = rtrim((string) ini_get('session.save_path'), '/') . '/sess_' . $id;
        if (is_file($sessionPath)) unlink($sessionPath);
    }
}
