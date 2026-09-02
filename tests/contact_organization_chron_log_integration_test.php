<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Contact/organization Chron log integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/chron_log_helpers.php';

function expectEntityChronIntegration($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException("Contact/organization Chron log integration test failed: {$message}");
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$suffix = bin2hex(random_bytes(4));

$conn->begin_transaction();
try {
    $username = 'chron-owner-test-' . $suffix;
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $userStmt = $conn->prepare(
        "INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')"
    );
    $userStmt->bind_param('ss', $username, $passwordHash);
    $userStmt->execute();
    $userId = (int) $conn->insert_id;
    $userStmt->close();

    $organizationName = 'Chron Isolation Organization ' . $suffix;
    $organizationStmt = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    $organizationStmt->bind_param('s', $organizationName);
    $organizationStmt->execute();
    $organizationId = (int) $conn->insert_id;
    $organizationStmt->close();

    $contactEmail = 'chron-' . $suffix . '@example.com';
    $contactStmt = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name, contact_role, contact_email)
         VALUES (?, 'Chron', 'Contact', 'admin', ?)"
    );
    $contactStmt->bind_param('is', $organizationId, $contactEmail);
    $contactStmt->execute();
    $contactId = (int) $conn->insert_id;
    $contactStmt->close();

    $contactText = 'Contact-only communication ' . $suffix;
    $organizationText = 'Organization-only communication ' . $suffix;
    insertEntityChronLogEntry(
        $conn,
        'contact',
        $contactId,
        $contactText,
        $userId,
        $username
    );
    insertEntityChronLogEntry(
        $conn,
        'organization',
        $organizationId,
        $organizationText,
        $userId,
        $username
    );

    $contactEntries = fetchEntityChronLogEntries($conn, 'contact', $contactId);
    $organizationEntries = fetchEntityChronLogEntries($conn, 'organization', $organizationId);
    expectEntityChronIntegration(
        count($contactEntries) === 1
            && $contactEntries[0]['entry_text'] === $contactText
            && count($organizationEntries) === 1
            && $organizationEntries[0]['entry_text'] === $organizationText,
        'each owner query should return only the entry written to its own table.'
    );

    $updatedContactText = $contactText . ' updated';
    updateEntityChronLogEntries(
        $conn,
        'contact',
        $contactId,
        [(int) $contactEntries[0]['id'] => $updatedContactText],
        [(int) $contactEntries[0]['id'] => (string) $contactEntries[0]['updated_at']],
        $userId
    );
    $organizationEntries = fetchEntityChronLogEntries($conn, 'organization', $organizationId);
    expectEntityChronIntegration(
        $organizationEntries[0]['entry_text'] === $organizationText,
        'editing a contact entry should not alter the organization history.'
    );

    $updatedContactEntries = fetchEntityChronLogEntries($conn, 'contact', $contactId);
    $staleVersion = (string) $updatedContactEntries[0]['updated_at'];
    $concurrentText = $updatedContactText . ' by another editor';
    $concurrentStmt = $conn->prepare(
        'UPDATE contact_chron_entries
         SET entry_text = ?, updated_at = TIMESTAMPADD(MICROSECOND, 1, updated_at)
         WHERE id = ?'
    );
    $contactEntryId = (int) $updatedContactEntries[0]['id'];
    $concurrentStmt->bind_param('si', $concurrentText, $contactEntryId);
    $concurrentStmt->execute();
    $concurrentStmt->close();
    $staleChronRejected = false;
    try {
        updateEntityChronLogEntries(
            $conn,
            'contact',
            $contactId,
            [$contactEntryId => $updatedContactText . ' stale overwrite'],
            [$contactEntryId => $staleVersion],
            $userId
        );
    } catch (InvalidArgumentException $exception) {
        $staleChronRejected = str_contains($exception->getMessage(), 'changed after you opened');
    }
    $concurrentContactEntries = fetchEntityChronLogEntries($conn, 'contact', $contactId);
    expectEntityChronIntegration(
        $staleChronRejected && $concurrentContactEntries[0]['entry_text'] === $concurrentText,
        'a stale parent form should not overwrite a newer Chron-only edit.'
    );

    archiveEntityChronLogEntry($conn, 'contact', $contactId, $contactEntryId, $userId);
    expectEntityChronIntegration(
        countEntityChronLogEntries($conn, 'contact', $contactId) === 0
            && countEntityChronLogEntries($conn, 'contact', $contactId, 1) === 1
            && countEntityChronLogEntries($conn, 'organization', $organizationId) === 1,
        'archiving a contact entry should leave the organization history active.'
    );
    restoreEntityChronLogEntries(
        $conn,
        'contact',
        $contactId,
        [$contactEntryId],
        $userId
    );
    deleteEntityChronLogEntry($conn, 'contact', $contactId, $contactEntryId);
    expectEntityChronIntegration(
        countEntityChronLogEntries($conn, 'contact', $contactId) === 0
            && countEntityChronLogEntries($conn, 'contact', $contactId, 1) === 0
            && countEntityChronLogEntries($conn, 'organization', $organizationId) === 1,
        'permanently deleting a contact entry should remove only that entry.'
    );

    insertEntityChronLogEntry(
        $conn,
        'contact',
        $contactId,
        $contactText . ' cascade probe',
        $userId,
        $username
    );

    $deleteContactStmt = $conn->prepare('DELETE FROM contacts WHERE id = ?');
    $deleteContactStmt->bind_param('i', $contactId);
    $deleteContactStmt->execute();
    $deleteContactStmt->close();
    expectEntityChronIntegration(
        countEntityChronLogEntries($conn, 'contact', $contactId) === 0
            && countEntityChronLogEntries($conn, 'organization', $organizationId) === 1,
        'deleting a contact should cascade only its contact-owned Chron history.'
    );

    $deleteOrganizationStmt = $conn->prepare('DELETE FROM organizations WHERE id = ?');
    $deleteOrganizationStmt->bind_param('i', $organizationId);
    $deleteOrganizationStmt->execute();
    $deleteOrganizationStmt->close();
    expectEntityChronIntegration(
        countEntityChronLogEntries($conn, 'organization', $organizationId) === 0,
        'deleting an organization should cascade its independent Chron history.'
    );

    $conn->rollback();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

echo "Contact and organization Chron log integration tests passed.\n";
