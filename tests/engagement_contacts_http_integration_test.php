<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Event contacts HTTP integration tests skipped (requires a disposable database and HTTP server).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/engagement_contact_helpers.php';

function expectEventContactsHttp(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException('Event contacts HTTP integration failed: ' . $message); }
}
function eventContactsHidden(string $html, string $name): string
{
    preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $match);
    expectEventContactsHttp(isset($match[1]), 'Missing form field ' . $name);
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}
$baseUrl = rtrim((string) (getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080'), '/');
expectEventContactsHttp(in_array(parse_url($baseUrl, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true),
    'Use a loopback HTTP server connected to the disposable database');
$cookieFile = tempnam(sys_get_temp_dir(), 'dnr-contact-cookie-');
$request = static function (string $path, ?array $post = null) use ($baseUrl, $cookieFile): array {
    $curl = curl_init($baseUrl . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $response = curl_exec($curl);
    expectEventContactsHttp(is_string($response), 'HTTP request failed: ' . curl_error($curl));
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
};
$suffix = bin2hex(random_bytes(6));
$userId = 0;
$organizationIds = [];
try {
    $username = 'event-http-' . $suffix;
    $password = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')");
    $stmt->bind_param('ss', $username, $hash);
    $stmt->execute();
    $userId = (int) $conn->insert_id;
    foreach (['Event', 'Original'] as $name) {
        $organizationName = $name . ' Contact HTTP ' . $suffix;
        $stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
        $stmt->bind_param('s', $organizationName);
        $stmt->execute();
        $organizationIds[] = (int) $conn->insert_id;
    }
    [$organizationId, $originalOrganizationId] = $organizationIds;
    $existingIds = [];
    foreach ([$organizationId, $originalOrganizationId, $originalOrganizationId] as $index => $orgId) {
        $email = "existing-{$index}-{$suffix}@example.test";
        $firstName = 'Existing' . $index;
        $stmt = $conn->prepare("INSERT INTO contacts (organization_id, contact_first_name, contact_last_name,
            contact_role, contact_email) VALUES (?, ?, 'Fixture', 'pastor', ?)");
        $stmt->bind_param('iss', $orgId, $firstName, $email);
        $stmt->execute();
        $existingIds[] = (int) $conn->insert_id;
    }
    $login = $request('login.php');
    $login = $request('login.php', ['csrf_token' => eventContactsHidden($login['body'], 'csrf_token'),
        'username' => $username, 'password' => $password]);
    expectEventContactsHttp($login['status'] === 302, 'Editor should authenticate');
    $form = $request('index.php');
    expectEventContactsHttp($form['status'] === 200 && str_contains($form['body'], 'Add Existing Contacts')
        && str_contains($form['body'], 'data-new-contact-template'), 'New event form should expose both contact add paths');
    $newRow = static fn(string $name): array => ['first_name' => $name, 'last_name' => 'Fixture',
        'email' => strtolower($name) . '-' . $suffix . '@example.test', 'role_title' => 'Coordinator',
        'roles' => ['on_site_contact', 'travel']];
    $post = ['csrf_token' => eventContactsHidden($form['body'], 'csrf_token'), 'save_engagement' => '1',
        'organization_id' => $organizationId, 'event_title' => 'Contact HTTP event ' . $suffix,
        'event_start_date' => '2099-09-10', 'event_end_date' => '2099-09-11', 'event_type' => 'conference',
        'confirmation_status' => 'work_in_progress', 'lifecycle_status' => 'active',
        'engagement_contacts' => [$existingIds[0] => ['primary_host'], $existingIds[1] => ['billing']],
        'engagement_added_contact_ids' => [$existingIds[1]],
        'engagement_new_contacts' => [$newRow('NewOne'), $newRow('NewTwo')]];
    $bad = $post;
    $bad['engagement_new_contacts'][1]['email'] = 'invalid-email';
    $failed = $request('index.php', $bad);
    $count = (int) $conn->query("SELECT COUNT(*) AS total FROM contacts WHERE organization_id={$organizationId}")->fetch_assoc()['total'];
    expectEventContactsHttp($failed['status'] === 200 && str_contains($failed['body'], 'invalid-email')
        && str_contains($failed['body'], 'value="NewOne"') && str_contains($failed['body'], 'Existing1 Fixture')
        && $count === 1, 'Validation errors should retain every new/existing draft and create no contact');
    expectEventContactsHttp((int) $conn->query("SELECT COUNT(*) AS total FROM contact_organizations
        WHERE contact_id={$existingIds[1]} AND organization_id={$organizationId}")->fetch_assoc()['total'] === 0,
        'A failed create must not associate a directory contact');
    $saved = $request('index.php', $post);
    expectEventContactsHttp($saved['status'] === 302, 'Create should save multiple existing and new contacts: ' . strip_tags($saved['body']));
    $engagementId = (int) $conn->query("SELECT id FROM engagements WHERE organization_id={$organizationId}")->fetch_assoc()['id'];
    $assignments = fetchEngagementContactAssignments($conn, $engagementId);
    expectEventContactsHttp(count($assignments) === 6 && count(fetchEngagementContacts($conn, $engagementId)) === 4,
        'Created event should have four contacts and all six selected roles');
    expectEventContactsHttp((int) $conn->query("SELECT COUNT(*) AS total FROM contact_organizations
        WHERE contact_id={$existingIds[1]}")->fetch_assoc()['total'] === 2,
        'Directory selection should retain the original organization while adding the event organization');
    $search = $request('organization_contacts.php?' . http_build_query(['organization_id' => $organizationId, 'q' => $suffix]));
    $payload = json_decode($search['body'], true);
    expectEventContactsHttp($search['status'] === 200 && count($payload['contacts'] ?? []) === 5,
        'Directory search should find affiliated and unrelated active contacts');

    $editPath = 'edit_engagement.php?id=' . $engagementId;
    $edit = $request($editPath);
    expectEventContactsHttp($edit['status'] === 200 && str_contains($edit['body'], 'NewOne Fixture'), 'Saved contacts should appear on edit');
    $editPost = $post;
    $editPost['csrf_token'] = eventContactsHidden($edit['body'], 'csrf_token');
    $editPost['engagement_version'] = eventContactsHidden($edit['body'], 'engagement_version');
    $editPost['engagement_contacts'] = engagementContactAssignmentMap($assignments);
    $editPost['engagement_contacts'][$existingIds[2]] = ['materials'];
    $editPost['engagement_added_contact_ids'] = [$existingIds[2]];
    $editPost['engagement_new_contacts'] = [$newRow('EditOne'), $newRow('EditTwo')];
    // A conflicting Chron entry is validated after contacts are saved, proving rollback covers all writes.
    $conn->query("INSERT INTO engagement_chron_entries (engagement_id, entry_text, created_by)
        VALUES ({$engagementId}, 'Original history', {$userId})");
    $chronId = (int) $conn->insert_id;
    $lateFailure = $editPost;
    $lateFailure['chron_entries'] = [$chronId => 'Changed history'];
    $lateFailure['chron_entry_versions'] = [$chronId => 'stale-version'];
    $rejected = $request($editPath, $lateFailure);
    $count = (int) $conn->query("SELECT COUNT(*) AS total FROM contacts WHERE organization_id={$organizationId}")->fetch_assoc()['total'];
    expectEventContactsHttp($rejected['status'] === 200 && str_contains($rejected['body'], 'Chron entry changed')
        && str_contains($rejected['body'], 'value="EditOne"') && str_contains($rejected['body'], 'Existing2 Fixture')
        && $count === 3 && fetchEngagementContactAssignments($conn, $engagementId) === $assignments,
        'Late edit validation must roll back contacts, roles and affiliations while preserving submitted drafts');
    expectEventContactsHttp((int) $conn->query("SELECT COUNT(*) AS total FROM contact_organizations
        WHERE contact_id={$existingIds[2]} AND organization_id={$organizationId}")->fetch_assoc()['total'] === 0,
        'Late edit rollback must also undo the added affiliation');
    $saved = $request($editPath, $editPost);
    expectEventContactsHttp($saved['status'] === 302, 'Edit should save two new contacts and one directory contact');
    expectEventContactsHttp(count(fetchEngagementContacts($conn, $engagementId)) === 7
        && count(fetchEngagementContactAssignments($conn, $engagementId)) === 11,
        'Edit should retain prior contacts and persist all additional roles');
    echo "Event contacts HTTP integration tests passed (multiple create/edit, draft retention, directory affiliations, late rollback).\n";
} finally {
    if ($userId > 0) { $conn->query("DELETE FROM follow_up_tasks WHERE created_by={$userId}"); }
    foreach ($organizationIds as $id) { $conn->query("DELETE FROM engagements WHERE organization_id={$id}"); }
    foreach ($organizationIds as $id) { $conn->query("DELETE FROM contacts WHERE organization_id={$id}"); }
    foreach ($organizationIds as $id) { $conn->query("DELETE FROM organizations WHERE id={$id}"); }
    if ($userId > 0) { $conn->query("DELETE FROM users WHERE id={$userId}"); }
    if (is_string($cookieFile)) { @unlink($cookieFile); }
}
