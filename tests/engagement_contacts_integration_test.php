<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Engagement contacts integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/engagement_contact_helpers.php';

function expectEngagementContactsIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Engagement contacts integration test failed: {$message}");
    }
}

$suffix = bin2hex(random_bytes(4));
$conn->begin_transaction();
try {
    $username = 'event-contact-test-' . $suffix;
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $userStmt = $conn->prepare(
        "INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')"
    );
    $userStmt->bind_param('ss', $username, $passwordHash);
    $userStmt->execute();
    $userId = (int) $conn->insert_id;
    $userStmt->close();

    $organizationIds = [];
    $organizationStmt = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    foreach (['Primary', 'Unrelated'] as $organizationPrefix) {
        $organizationName = $organizationPrefix . ' Event Contact Organization ' . $suffix;
        $organizationStmt->bind_param('s', $organizationName);
        $organizationStmt->execute();
        $organizationIds[] = (int) $conn->insert_id;
    }
    $organizationStmt->close();

    $contactIds = [];
    $contactStmt = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name,
             contact_role, contact_email)
         VALUES (?, ?, ?, 'admin', ?)"
    );
    foreach ([
        [$organizationIds[0], 'Avery', 'Host', 'avery-' . $suffix . '@example.test'],
        [$organizationIds[0], 'Blair', 'Coordinator', 'blair-' . $suffix . '@example.test'],
        [$organizationIds[1], 'Casey', 'Outsider', 'casey-' . $suffix . '@example.test'],
    ] as [$organizationId, $firstName, $lastName, $email]) {
        $contactStmt->bind_param('isss', $organizationId, $firstName, $lastName, $email);
        $contactStmt->execute();
        $contactIds[] = (int) $conn->insert_id;
    }
    $contactStmt->close();

    $eventTitle = 'Event Contact Test ' . $suffix;
    $eventStmt = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status, is_deleted)
         VALUES (?, ?, '2026-09-10', '2026-09-12',
                 'conference', 'under_review', 0)"
    );
    $eventStmt->bind_param('is', $organizationIds[0], $eventTitle);
    $eventStmt->execute();
    $engagementId = (int) $conn->insert_id;
    $eventStmt->close();

    $assignments = normalizeEngagementContactAssignments([
        (string) $contactIds[0] => ['primary_host', 'travel'],
        (string) $contactIds[1] => ['materials'],
    ]);
    validateEngagementContactAssignments($conn, $organizationIds[0], $assignments);
    expectEngagementContactsIntegration(
        syncEngagementContacts($conn, $engagementId, $assignments, $userId),
        'the first synchronization should write event contact roles.'
    );
    expectEngagementContactsIntegration(
        !syncEngagementContacts($conn, $engagementId, $assignments, $userId),
        'an unchanged synchronization should avoid rewriting assignments.'
    );

    $contacts = fetchEngagementContacts($conn, $engagementId);
    expectEngagementContactsIntegration(
        count($contacts) === 2
            && $contacts[0]['contact_first_name'] === 'Avery'
            && $contacts[0]['engagement_contact_roles'] === ['primary_host', 'travel']
            && $contacts[1]['engagement_contact_roles'] === ['materials'],
        'assigned contacts should be grouped with all of their ordered event roles.'
    );

    $unrelatedContactRejected = false;
    try {
        validateEngagementContactAssignments(
            $conn,
            $organizationIds[0],
            normalizeEngagementContactAssignments([
                (string) $contactIds[2] => ['billing'],
            ])
        );
    } catch (InvalidArgumentException) {
        $unrelatedContactRejected = true;
    }
    expectEngagementContactsIntegration(
        $unrelatedContactRejected,
        'a contact from another organization should be rejected.'
    );

    $replacementAssignments = normalizeEngagementContactAssignments([
        (string) $contactIds[1] => ['on_site_contact', 'billing'],
    ]);
    syncEngagementContacts($conn, $engagementId, $replacementAssignments, $userId);
    expectEngagementContactsIntegration(
        fetchEngagementContactAssignments($conn, $engagementId) === $replacementAssignments,
        'later saves should replace removed roles rather than retaining stale assignments.'
    );

    $conn->rollback();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

echo "Engagement contacts integration tests passed.\n";
