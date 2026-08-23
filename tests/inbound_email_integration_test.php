<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Inbound email integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/inbound_email_helpers.php';

function expectInboundIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Inbound email integration test failed: {$message}");
    }
}

putenv('DNR_INBOUND_ADDRESS=moed@beneliath.com');
$suffix = bin2hex(random_bytes(5));
$userEmail = 'staff-' . $suffix . '@beneliath.com';
$contactEmail = 'contact-' . $suffix . '@example.org';
$organizationName = 'Inbound Mail Organization ' . $suffix;
$username = 'mail-test-' . $suffix;
$createdIds = [
    'messages' => [],
    'contacts' => [],
    'organizations' => [],
    'users' => [],
];

$rawMessage = static function (
    string $from,
    string $to,
    string $messageId,
    string $subject
): string {
    return implode("\r\n", [
        'From: ' . $from,
        'To: ' . $to,
        'Cc: MOED <moed@beneliath.com>',
        'Subject: ' . $subject,
        'Date: Sun, 23 Aug 2026 10:00:00 -0500',
        'Message-ID: <' . $messageId . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'Integration test body for ' . $messageId,
    ]);
};

try {
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $userStmt = $conn->prepare(
        "INSERT INTO users
            (username, email, email_verified_at, password, role, account_status)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, 'editor', 'active')"
    );
    $userStmt->bind_param('sss', $username, $userEmail, $passwordHash);
    $userStmt->execute();
    $userId = (int) $conn->insert_id;
    $createdIds['users'][] = $userId;
    $userStmt->close();

    $organizationStmt = $conn->prepare(
        'INSERT INTO organizations (organization_name, email, is_deleted) VALUES (?, ?, 0)'
    );
    $organizationEmail = 'office-' . $suffix . '@example.org';
    $organizationStmt->bind_param('ss', $organizationName, $organizationEmail);
    $organizationStmt->execute();
    $organizationId = (int) $conn->insert_id;
    $createdIds['organizations'][] = $organizationId;
    $organizationStmt->close();

    $contactStmt = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name,
             contact_role, contact_email, is_deleted)
         VALUES (?, 'Inbound', 'Contact', 'admin', ?, 0)"
    );
    $contactStmt->bind_param('is', $organizationId, $contactEmail);
    $contactStmt->execute();
    $contactId = (int) $conn->insert_id;
    $createdIds['contacts'][] = $contactId;
    $contactStmt->close();

    setDatabaseAuditContext($conn, null, 'Email Gateway');
    $outgoing = parseInboundEmail($rawMessage(
        'Staff <' . $userEmail . '>',
        'Inbound Contact <' . $contactEmail . '>',
        'outgoing-' . $suffix . '@beneliath.com',
        'Outgoing routing test'
    ), '2026-08-23T15:01:00Z');
    $stored = storeInboundEmailMessage($conn, 'file', 'outgoing-' . $suffix, $outgoing);
    $createdIds['messages'][] = $stored['id'];
    expectInboundIntegration($stored['inserted'], 'a new source email should be stored.');
    expectInboundIntegration(
        processInboundEmailMessage($conn, $stored['id']) === 'processed',
        'mail from a verified active user to a unique Contact should route automatically.'
    );

    $contactChron = $conn->prepare(
        'SELECT created_by, created_by_username_snapshot, entry_text
         FROM contact_chron_entries
         WHERE contact_id = ? AND inbound_email_message_id = ?'
    );
    $contactChron->bind_param('ii', $contactId, $stored['id']);
    $contactChron->execute();
    $contactEntry = $contactChron->get_result()->fetch_assoc();
    $contactChron->close();
    $organizationChron = $conn->prepare(
        'SELECT COUNT(*) AS total FROM organization_chron_entries
         WHERE organization_id = ? AND inbound_email_message_id = ?'
    );
    $organizationChron->bind_param('ii', $organizationId, $stored['id']);
    $organizationChron->execute();
    $organizationTotal = (int) $organizationChron->get_result()->fetch_assoc()['total'];
    $organizationChron->close();
    expectInboundIntegration(
        $contactEntry
            && (int) $contactEntry['created_by'] === $userId
            && $contactEntry['created_by_username_snapshot'] === $username
            && str_contains((string) $contactEntry['entry_text'], 'Outgoing routing test')
            && $organizationTotal === 1,
        'one Contact entry and one deduplicated Organization entry should retain sender attribution.'
    );

    $duplicate = storeInboundEmailMessage(
        $conn,
        'file',
        'outgoing-retry-' . $suffix,
        parseInboundEmail(str_replace('Integration test body', 'Changed retry body', $rawMessage(
            'Staff <' . $userEmail . '>',
            'Inbound Contact <' . $contactEmail . '>',
            'outgoing-' . $suffix . '@beneliath.com',
            'Outgoing routing test'
        )))
    );
    expectInboundIntegration(
        !$duplicate['inserted'] && $duplicate['id'] === $stored['id'],
        'delivery retries with the same RFC Message-ID should resolve to the original source.'
    );

    $reply = parseInboundEmail($rawMessage(
        'Inbound Contact <' . $contactEmail . '>',
        'Staff <' . $userEmail . '>',
        'reply-' . $suffix . '@example.org',
        'Contact reply routing test'
    ));
    $replyStored = storeInboundEmailMessage($conn, 'file', 'reply-' . $suffix, $reply);
    $createdIds['messages'][] = $replyStored['id'];
    expectInboundIntegration(
        processInboundEmailMessage($conn, $replyStored['id']) === 'processed',
        'a reply should route from the uniquely matched Contact in From.'
    );

    $duplicateContactStmt = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name,
             contact_role, contact_email, is_deleted)
         VALUES (?, 'Shared', 'Mailbox', 'admin', ?, 0)"
    );
    $duplicateContactStmt->bind_param('is', $organizationId, $contactEmail);
    $duplicateContactStmt->execute();
    $duplicateContactId = (int) $conn->insert_id;
    $createdIds['contacts'][] = $duplicateContactId;
    $duplicateContactStmt->close();

    $ambiguous = parseInboundEmail($rawMessage(
        'Staff <' . $userEmail . '>',
        'Shared address <' . $contactEmail . '>',
        'ambiguous-' . $suffix . '@beneliath.com',
        'Ambiguous routing test'
    ));
    $ambiguousStored = storeInboundEmailMessage(
        $conn,
        'file',
        'ambiguous-' . $suffix,
        $ambiguous
    );
    $createdIds['messages'][] = $ambiguousStored['id'];
    expectInboundIntegration(
        processInboundEmailMessage($conn, $ambiguousStored['id']) === 'review',
        'a shared Contact address should wait for review.'
    );
    expectInboundIntegration(
        processInboundEmailMessage(
            $conn,
            $ambiguousStored['id'],
            [$contactId],
            [$organizationId],
            $userId
        ) === 'processed',
        'a reviewer should be able to approve selected matched targets.'
    );

    $statusStmt = $conn->prepare(
        'SELECT status, processed_by FROM inbound_email_messages WHERE id = ?'
    );
    $statusStmt->bind_param('i', $ambiguousStored['id']);
    $statusStmt->execute();
    $reviewed = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();
    expectInboundIntegration(
        $reviewed['status'] === 'processed' && (int) $reviewed['processed_by'] === $userId,
        'manual approval should record the reviewer and final state.'
    );
} finally {
    foreach (array_reverse($createdIds['contacts']) as $id) {
        $stmt = $conn->prepare('DELETE FROM contacts WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
    foreach (array_reverse($createdIds['messages']) as $id) {
        $stmt = $conn->prepare('DELETE FROM inbound_email_messages WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
    foreach (array_reverse($createdIds['organizations']) as $id) {
        $stmt = $conn->prepare('DELETE FROM organizations WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
    foreach (array_reverse($createdIds['users']) as $id) {
        $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }
}

echo "Inbound email integration tests passed.\n";
