<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Contact organizations integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once $source . '/contact_organization_helpers.php';
require_once $source . '/engagement_contact_helpers.php';

function expectContactOrganizationsIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Contact organizations integration test failed: ' . $message);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(4));
    $orgs = [];
    foreach (['Church', 'Research center', 'Unrelated'] as $name) {
        $name .= ' Affiliation Test ' . $suffix;
        $stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $orgs[] = (int) $conn->insert_id;
        $stmt->close();
    }
    $email = 'affiliation-' . $suffix . '@example.test';
    $stmt = $conn->prepare(
        "INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_role, contact_email)
         VALUES (?, 'Andy', 'Woods', 'pastor', ?)"
    );
    $stmt->bind_param('is', $orgs[0], $email);
    $stmt->execute();
    $contactId = (int) $conn->insert_id;
    $stmt->close();
    $primary = fetchContactOrganizations($conn, $contactId);
    expectContactOrganizationsIntegration(count($primary) === 1 && $primary[0]['role_title'] === 'Pastor', 'legacy contact inserts must create their primary affiliation.');
    $conn->query("UPDATE contacts SET updated_at = '2026-01-01 00:00:00' WHERE id = {$contactId}");
    expectContactOrganizationsIntegration(ensureContactOrganization($conn, $contactId, $orgs[1], 'Chairman'), 'a contact should gain a second organization.');
    $contactUpdated = $conn->query("SELECT updated_at FROM contacts WHERE id = {$contactId}")->fetch_assoc()['updated_at'];
    expectContactOrganizationsIntegration($contactUpdated !== '2026-01-01 00:00:00.000000', 'affiliations added by event saves must invalidate stale contact edit forms.');
    expectContactOrganizationsIntegration(!ensureContactOrganization($conn, $contactId, $orgs[1], 'Ignored'), 'additive saves must preserve existing titles.');
    $affiliations = fetchContactOrganizationsForContacts($conn, [$contactId]);
    expectContactOrganizationsIntegration(count($affiliations[$contactId]) === 2 && $affiliations[$contactId][1]['role_title'] === 'Chairman', 'one contact should have independent roles across both organizations.');
    expectContactOrganizationsIntegration(contactBelongsToOrganization($conn, $contactId, $orgs[1]), 'secondary membership should validate.');
    expectContactOrganizationsIntegration(!contactBelongsToOrganization($conn, $contactId, $orgs[2]), 'unrelated organizations should not validate.');

    $events = [];
    foreach ($orgs as $organizationId) {
        $stmt = $conn->prepare(
            "INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status)
             VALUES (?, 'Affiliation test', '2026-10-01', '2026-10-01', 'conference', 'under_review')"
        );
        $stmt->bind_param('i', $organizationId);
        $stmt->execute();
        $events[] = (int) $conn->insert_id;
        $stmt->close();
    }
    foreach ([$events[0], $events[1]] as $eventId) {
        $stmt = $conn->prepare("INSERT INTO engagement_contacts (engagement_id, contact_id, contact_role) VALUES (?, ?, 'primary_host')");
        $stmt->bind_param('ii', $eventId, $contactId);
        $stmt->execute();
        $stmt->close();
        expectContactOrganizationsIntegration(count(fetchEngagementContacts($conn, $eventId)) === 1, 'events at both affiliated organizations should include the same person.');
    }
    $rejected = false;
    try {
        $conn->query("INSERT INTO engagement_contacts (engagement_id, contact_id, contact_role) VALUES ({$events[2]}, {$contactId}, 'billing')");
    } catch (mysqli_sql_exception) {
        $rejected = true;
    }
    expectContactOrganizationsIntegration($rejected, 'database guards must reject unrelated event assignments.');

    // Changing only the primary designation must not remove valid event work.
    $conn->query("UPDATE contacts SET organization_id = {$orgs[1]}, contact_role = 'other', contact_role_other = 'Chairman' WHERE id = {$contactId}");
    syncContactOrganizations($conn, $contactId, $orgs[1], 'Chairman', [
        ['organization_id' => $orgs[0], 'role_title' => 'Pastor'],
    ]);
    expectContactOrganizationsIntegration(count(fetchEngagementContactAssignments($conn, $events[0])) === 1, 'changing primary organization should preserve retained organization assignments.');
    expectContactOrganizationsIntegration(count(fetchEngagementContactAssignments($conn, $events[1])) === 1, 'secondary event assignments should survive becoming primary.');

    $conn->query("UPDATE engagements SET updated_at = '2026-01-01 00:00:00' WHERE id = {$events[0]}");
    syncContactOrganizations($conn, $contactId, $orgs[1], 'Chairman', []);
    expectContactOrganizationsIntegration(fetchEngagementContactAssignments($conn, $events[0]) === [], 'removing an affiliation should prune its event assignments.');
    expectContactOrganizationsIntegration(count(fetchEngagementContactAssignments($conn, $events[1])) === 1, 'removing another affiliation must retain valid event assignments.');
    $updated = $conn->query("SELECT updated_at FROM engagements WHERE id = {$events[0]}")->fetch_assoc()['updated_at'];
    expectContactOrganizationsIntegration($updated !== '2026-01-01 00:00:00.000000', 'pruning assignments should invalidate stale event edit forms.');
    expectContactOrganizationsIntegration(!syncContactOrganizations($conn, $contactId, $orgs[1], 'Chairman', []), 'unchanged affiliations should avoid writes.');

    $conn->query("UPDATE engagements SET organization_id = {$orgs[2]} WHERE id = {$events[1]}");
    expectContactOrganizationsIntegration(fetchEngagementContactAssignments($conn, $events[1]) === [], 'changing event organization should prune incompatible contacts.');

    $conn->query("UPDATE organizations SET is_deleted = 1 WHERE id = {$orgs[0]}");
    $rejected = false;
    try {
        syncContactOrganizations($conn, $contactId, $orgs[1], 'Chairman', [['organization_id' => $orgs[0], 'role_title' => 'Pastor']]);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    expectContactOrganizationsIntegration($rejected, 'new affiliations cannot use archived organizations.');
    $conn->query("UPDATE organizations SET is_deleted = 0 WHERE id = {$orgs[0]}");
    ensureContactOrganization($conn, $contactId, $orgs[0], 'Pastor');
    $conn->query("UPDATE organizations SET is_deleted = 1 WHERE id = {$orgs[0]}");
    expectContactOrganizationsIntegration(!syncContactOrganizations($conn, $contactId, $orgs[1], 'Chairman', [['organization_id' => $orgs[0], 'role_title' => 'Pastor']]), 'existing archived affiliations can remain unchanged.');

    $conn->rollback();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}
echo "Contact organizations integration tests passed.\n";
