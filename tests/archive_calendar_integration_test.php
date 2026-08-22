<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Archive/calendar integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';
require_once $source_directory . '/functions.php';

function expectArchiveCalendarIntegration($condition, $message): void
{
    if (!$condition) {
        throw new RuntimeException("Archive/calendar integration test failed: {$message}");
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$_SESSION['role'] = 'admin';
$suffix = bin2hex(random_bytes(4));

$revision_before = (int) $conn->query(
    'SELECT revision FROM calendar_feed_revision WHERE id = 1'
)->fetch_assoc()['revision'];

$organization_name = 'Archive Integration ' . $suffix;
$organization_stmt = $conn->prepare(
    'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
);
$organization_stmt->bind_param('s', $organization_name);
$organization_stmt->execute();
$organization_id = $conn->insert_id;
$organization_stmt->close();

$revision_after_insert = (int) $conn->query(
    'SELECT revision FROM calendar_feed_revision WHERE id = 1'
)->fetch_assoc()['revision'];
expectArchiveCalendarIntegration(
    $revision_after_insert > $revision_before,
    'calendar revision should advance after an organization insert.'
);

$contact_stmt = $conn->prepare(
    "INSERT INTO contacts
        (organization_id, contact_first_name, contact_last_name, contact_role,
         contact_email, is_deleted)
     VALUES (?, 'Archive', 'Dependency', 'admin', 'archive-dependency@example.test', 0)"
);
$contact_stmt->bind_param('i', $organization_id);
$contact_stmt->execute();
$contact_id = $conn->insert_id;
$contact_stmt->close();

expectArchiveCalendarIntegration(
    archiveEntity($conn, 'organization', $organization_id) === false,
    'an organization with an active child should not be archived.'
);
expectArchiveCalendarIntegration(
    archiveEntity($conn, 'contact', $contact_id) === true
        && archiveEntity($conn, 'contact', $contact_id) === false,
    'an archive transition should affect exactly one active row and reject a repeated transition.'
);
expectArchiveCalendarIntegration(
    archiveEntity($conn, 'organization', $organization_id) === true,
    'the organization should archive after all active dependencies are archived.'
);
expectArchiveCalendarIntegration(
    restoreEntity($conn, 'contact', $contact_id) === false,
    'a child should not restore while its parent organization is archived.'
);
expectArchiveCalendarIntegration(
    restoreEntity($conn, 'organization', $organization_id) === true
        && restoreEntity($conn, 'contact', $contact_id) === true,
    'restoring the parent should permit the child to be restored.'
);

$revision_after_transitions = (int) $conn->query(
    'SELECT revision FROM calendar_feed_revision WHERE id = 1'
)->fetch_assoc()['revision'];
expectArchiveCalendarIntegration(
    $revision_after_transitions >= $revision_after_insert + 2,
    'calendar revision should advance monotonically across archive and restore updates.'
);

$delete_contact_stmt = $conn->prepare('DELETE FROM contacts WHERE id = ?');
$delete_contact_stmt->bind_param('i', $contact_id);
$delete_contact_stmt->execute();
$delete_contact_stmt->close();
$delete_organization_stmt = $conn->prepare('DELETE FROM organizations WHERE id = ?');
$delete_organization_stmt->bind_param('i', $organization_id);
$delete_organization_stmt->execute();
$delete_organization_stmt->close();

echo "Archive/calendar integration tests passed.\n";
