<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Contact organization lifecycle integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once $source . '/contact_organization_helpers.php';

function expectContactOrganizationLifecycle(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Contact organization lifecycle test failed: ' . $message);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$orgs = [];
$contactId = 0;
try {
    $suffix = bin2hex(random_bytes(4));
    foreach (['Primary', 'Secondary'] as $name) {
        $name .= ' Shared Lifecycle Test ' . $suffix;
        $stmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $orgs[] = (int) $conn->insert_id;
        $stmt->close();
    }
    $email = 'shared-lifecycle-' . $suffix . '@example.test';
    $stmt = $conn->prepare(
        "INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_role, contact_email)
         VALUES (?, 'Shared', 'Person', 'pastor', ?)"
    );
    $stmt->bind_param('is', $orgs[0], $email);
    $stmt->execute();
    $contactId = (int) $conn->insert_id;
    $stmt->close();
    $conn->begin_transaction();
    ensureContactOrganization($conn, $contactId, $orgs[1], '');
    $conn->commit();

    $dependencies = \Dnr\Service\ArchiveService::organizationActiveDependencyCounts($conn, $orgs[1]);
    expectContactOrganizationLifecycle(($dependencies['contacts'] ?? 0) === 1, 'secondary organizations must count their active contacts.');
    expectContactOrganizationLifecycle(!\Dnr\Service\ArchiveService::setArchived($conn, 'organization', $orgs[1], true), 'an active secondary contact must prevent organization archival.');

    expectContactOrganizationLifecycle(permanentlyDeleteOrganization($conn, $orgs[0]), 'an organization containing shared contacts can be deleted.');
    $person = $conn->query("SELECT organization_id, contact_role, contact_role_other FROM contacts WHERE id = {$contactId}")->fetch_assoc();
    expectContactOrganizationLifecycle($person && (int) $person['organization_id'] === $orgs[1], 'deleting a primary organization must retain its shared people under the remaining organization.');
    expectContactOrganizationLifecycle($person['contact_role'] === 'other' && $person['contact_role_other'] === 'Contact', 'an optional blank secondary title should promote to a valid primary role.');
    $remaining = fetchContactOrganizations($conn, $contactId);
    expectContactOrganizationLifecycle(count($remaining) === 1 && (int) $remaining[0]['organization_id'] === $orgs[1], 'deleted organizations should leave no stale shared links.');

    expectContactOrganizationLifecycle(\Dnr\Service\ArchiveService::setArchived($conn, 'contact', $contactId, true), 'shared contact should remain archivable.');
    expectContactOrganizationLifecycle(\Dnr\Service\ArchiveService::setArchived($conn, 'organization', $orgs[1], true), 'archived contacts should no longer block organization archival.');
    expectContactOrganizationLifecycle(!\Dnr\Service\ArchiveService::setArchived($conn, 'contact', $contactId, false), 'restoration must respect archived organization dependencies.');
    expectContactOrganizationLifecycle(\Dnr\Service\ArchiveService::setArchived($conn, 'organization', $orgs[1], false), 'remaining organization should be restorable.');
    expectContactOrganizationLifecycle(\Dnr\Service\ArchiveService::setArchived($conn, 'contact', $contactId, false), 'person should restore after their organization.');
} finally {
    $conn->rollback();
    if ($contactId > 0) {
        $conn->query("DELETE FROM contacts WHERE id = {$contactId}");
    }
    foreach ($orgs as $organizationId) {
        $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    }
}
echo "Contact organization lifecycle integration tests passed.\n";
