<?php

declare(strict_types=1);

putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R', 32)));
putenv('DNR_INBOUND_REQUIRE_AUTHENTICATED_FROM=1');
putenv('DNR_INBOUND_TRUSTED_AUTH_SERVERS=mx.integration.test');

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

putenv('DNR_INBOUND_ADDRESS=dnr@example.org');
$suffix = bin2hex(random_bytes(5));
$userEmail = 'staff-' . $suffix . '@example.net';
$contactEmail = 'contact-' . $suffix . '@example.org';
$organizationName = 'Inbound Mail Organization ' . $suffix;
$username = 'mail-test-' . $suffix;
$createdIds = [
    'messages' => [],
    'contacts' => [],
    'engagements' => [],
    'organizations' => [],
    'users' => [],
];

$rawMessage = static function (
    string $from,
    string $to,
    string $messageId,
    string $subject,
    ?string $body = null,
    bool $authenticated = true
): string {
    $headers = [
        'From: ' . $from,
        'To: ' . $to,
        'Cc: DNR <dnr@example.org>',
        'Subject: ' . $subject,
        'Date: Sun, 23 Aug 2026 10:00:00 -0500',
        'Message-ID: <' . $messageId . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if ($authenticated) {
        $fromAddress = $from;
        if (preg_match('/<([^>]+)>/', $from, $match) === 1) {
            $fromAddress = $match[1];
        }
        $fromDomain = strtolower((string) substr(strrchr($fromAddress, '@') ?: '', 1));
        array_splice($headers, 1, 0, [
            'Authentication-Results: mx.integration.test; dmarc=pass header.from=' . $fromDomain,
        ]);
    }
    $headers[] = '';
    $headers[] = $body ?? 'Integration test body for ' . $messageId;

    return implode("\r\n", $headers);
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

    $engagementTitle = 'Inbound Mail Engagement ' . $suffix;
    $eventStartDate = '2026-09-10';
    $eventEndDate = '2026-09-12';
    $engagementStmt = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status, lifecycle_status, is_deleted)
         VALUES (?, ?, ?, ?, 'conference', 'confirmed', 'active', 0)"
    );
    $engagementStmt->bind_param(
        'isss',
        $organizationId,
        $engagementTitle,
        $eventStartDate,
        $eventEndDate
    );
    $engagementStmt->execute();
    $engagementId = (int) $conn->insert_id;
    $createdIds['engagements'][] = $engagementId;
    $engagementStmt->close();

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

    $unrelatedOrganizationName = 'Unrelated Inbound Organization ' . $suffix;
    $unrelatedOrganizationEmail = 'unrelated-' . $suffix . '@example.org';
    $organizationStmt = $conn->prepare(
        'INSERT INTO organizations (organization_name, email, is_deleted) VALUES (?, ?, 0)'
    );
    $organizationStmt->bind_param(
        'ss',
        $unrelatedOrganizationName,
        $unrelatedOrganizationEmail
    );
    $organizationStmt->execute();
    $unrelatedOrganizationId = (int) $conn->insert_id;
    $createdIds['organizations'][] = $unrelatedOrganizationId;
    $organizationStmt->close();

    $unrelatedEngagementTitle = 'Searchable Unrelated Engagement ' . $suffix;
    $engagementStmt = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status, lifecycle_status, is_deleted)
         VALUES (?, ?, ?, ?, 'conference', 'confirmed', 'active', 0)"
    );
    $engagementStmt->bind_param(
        'isss',
        $unrelatedOrganizationId,
        $unrelatedEngagementTitle,
        $eventStartDate,
        $eventEndDate
    );
    $engagementStmt->execute();
    $unrelatedEngagementId = (int) $conn->insert_id;
    $createdIds['engagements'][] = $unrelatedEngagementId;
    $engagementStmt->close();

    $engagementMarker = applicationInboundMarker($engagementId);
    $unrelatedEngagementMarker = applicationInboundMarker($unrelatedEngagementId);

    $searchedEngagements = searchInboundEmailEngagements(
        $conn,
        $unrelatedEngagementMarker,
        1
    );
    expectInboundIntegration(
        count($searchedEngagements) === 1
            && $searchedEngagements[0]['id'] === $unrelatedEngagementId
            && $searchedEngagements[0]['organization_id'] === $unrelatedOrganizationId,
        'Engagement review search should resolve an exact marker without relying on a global option slice.'
    );

    setDatabaseAuditContext($conn, null, 'Email Gateway');
    $unauthenticated = parseInboundEmail($rawMessage(
        'Staff <' . $userEmail . '>',
        'Inbound Contact <' . $contactEmail . '>',
        'unauthenticated-' . $suffix . '@example.net',
        'Unauthenticated routing test ' . $engagementMarker,
        null,
        false
    ), '2026-08-23T15:00:00Z');
    $unauthenticatedStored = storeInboundEmailMessage(
        $conn,
        'file',
        'unauthenticated-' . $suffix,
        $unauthenticated
    );
    $createdIds['messages'][] = $unauthenticatedStored['id'];
    expectInboundIntegration(
        processInboundEmailMessage($conn, $unauthenticatedStored['id']) === 'review',
        'mail without trusted DMARC results should remain in manual review.'
    );

    $outgoing = parseInboundEmail($rawMessage(
        'Staff <' . $userEmail . '>',
        'Inbound Contact <' . $contactEmail . '>',
        'outgoing-' . $suffix . '@example.net',
        'Outgoing routing test ' . $engagementMarker
    ), '2026-08-23T15:01:00Z');
    $stored = storeInboundEmailMessage($conn, 'file', 'outgoing-' . $suffix, $outgoing);
    $createdIds['messages'][] = $stored['id'];
    expectInboundIntegration($stored['inserted'], 'a new source email should be stored.');
    expectInboundIntegration(
        processInboundEmailMessage($conn, $stored['id']) === 'processed',
        'authenticated mail from a verified active user to a unique Contact should route automatically.'
    );

    $contactChron = $conn->prepare(
        'SELECT id, inbound_email_message_id, created_by,
                created_by_username_snapshot, entry_text
         FROM contact_chron_entries
         WHERE contact_id = ? AND inbound_email_message_id = ?'
    );
    $contactChron->bind_param('ii', $contactId, $stored['id']);
    $contactChron->execute();
    $contactEntry = $contactChron->get_result()->fetch_assoc();
    $contactChron->close();
    $organizationChron = $conn->prepare(
        'SELECT id, inbound_email_message_id, entry_text
         FROM organization_chron_entries
         WHERE organization_id = ? AND inbound_email_message_id = ?'
    );
    $organizationChron->bind_param('ii', $organizationId, $stored['id']);
    $organizationChron->execute();
    $organizationEntry = $organizationChron->get_result()->fetch_assoc();
    $organizationChron->close();
    $engagementChron = $conn->prepare(
        'SELECT id, inbound_email_message_id, entry_text
         FROM engagement_chron_entries
         WHERE engagement_id = ? AND inbound_email_message_id = ?'
    );
    $engagementChron->bind_param('ii', $engagementId, $stored['id']);
    $engagementChron->execute();
    $engagementEntry = $engagementChron->get_result()->fetch_assoc();
    $engagementChron->close();
    expectInboundIntegration(
        $contactEntry
            && $contactEntry['created_by'] === null
            && $contactEntry['created_by_username_snapshot'] === 'Email Gateway'
            && str_contains((string) $contactEntry['entry_text'], 'Outgoing routing test')
            && $organizationEntry
            && str_contains((string) $organizationEntry['entry_text'], 'Outgoing routing test')
            && $engagementEntry
            && str_contains((string) $engagementEntry['entry_text'], $engagementMarker),
        'one Contact, Organization, and signed Engagement entry should use gateway attribution.'
    );

    $duplicate = storeInboundEmailMessage(
        $conn,
        'file',
        'outgoing-retry-' . $suffix,
        parseInboundEmail(str_replace('Integration test body', 'Changed retry body', $rawMessage(
            'Staff <' . $userEmail . '>',
            'Inbound Contact <' . $contactEmail . '>',
            'outgoing-' . $suffix . '@example.net',
            'Outgoing routing test ' . $engagementMarker
        )))
    );
    expectInboundIntegration(
        !$duplicate['inserted'] && $duplicate['id'] === $stored['id'],
        'delivery retries with the same RFC Message-ID should resolve to the original source.'
    );

    expectInboundIntegration(
        purgeInboundEmailMessage($conn, $stored['id']),
        'an administrator should be able to purge a retained inbound source record.'
    );
    $purgedMessage = $conn->prepare(
        'SELECT COUNT(*) AS total FROM inbound_email_messages WHERE id = ?'
    );
    $purgedMessage->bind_param('i', $stored['id']);
    $purgedMessage->execute();
    $purgedMessageTotal = (int) $purgedMessage->get_result()->fetch_assoc()['total'];
    $purgedMessage->close();

    $preservedContactChron = $conn->prepare(
        'SELECT inbound_email_message_id, entry_text FROM contact_chron_entries WHERE id = ?'
    );
    $contactEntryId = (int) $contactEntry['id'];
    $preservedContactChron->bind_param('i', $contactEntryId);
    $preservedContactChron->execute();
    $preservedContactEntry = $preservedContactChron->get_result()->fetch_assoc();
    $preservedContactChron->close();

    $preservedOrganizationChron = $conn->prepare(
        'SELECT inbound_email_message_id, entry_text FROM organization_chron_entries WHERE id = ?'
    );
    $organizationEntryId = (int) $organizationEntry['id'];
    $preservedOrganizationChron->bind_param('i', $organizationEntryId);
    $preservedOrganizationChron->execute();
    $preservedOrganizationEntry = $preservedOrganizationChron->get_result()->fetch_assoc();
    $preservedOrganizationChron->close();

    $preservedEngagementChron = $conn->prepare(
        'SELECT inbound_email_message_id, entry_text FROM engagement_chron_entries WHERE id = ?'
    );
    $engagementEntryId = (int) $engagementEntry['id'];
    $preservedEngagementChron->bind_param('i', $engagementEntryId);
    $preservedEngagementChron->execute();
    $preservedEngagementEntry = $preservedEngagementChron->get_result()->fetch_assoc();
    $preservedEngagementChron->close();

    expectInboundIntegration(
        $purgedMessageTotal === 0
            && $preservedContactEntry
            && $preservedContactEntry['inbound_email_message_id'] === null
            && str_contains((string) $preservedContactEntry['entry_text'], 'Outgoing routing test')
            && $preservedOrganizationEntry
            && $preservedOrganizationEntry['inbound_email_message_id'] === null
            && str_contains(
                (string) $preservedOrganizationEntry['entry_text'],
                'Outgoing routing test'
            )
            && $preservedEngagementEntry
            && $preservedEngagementEntry['inbound_email_message_id'] === null
            && str_contains(
                (string) $preservedEngagementEntry['entry_text'],
                $engagementMarker
            ),
        'purging a source should clear every foreign-key link without deleting or changing any Chron entry.'
    );

    $mattermostUserId = 'mm-' . $suffix;
    $mattermostUsername = 'mm-' . $suffix;
    $mattermostLink = $conn->prepare(
        'INSERT INTO mattermost_user_links
            (instance_id, mattermost_user_id, mattermost_username, user_id)
         VALUES (\'primary\', ?, ?, ?)'
    );
    $mattermostLink->bind_param('ssi', $mattermostUserId, $mattermostUsername, $userId);
    $mattermostLink->execute();
    $mattermostLink->close();
    $outboundSubject = 'Previous message ' . $engagementMarker;
    $outboundBody = 'Previous outbound message';
    $outboundMessage = $conn->prepare(
        "INSERT INTO engagement_email_messages
            (engagement_id, organization_id, template_key, subject, body_text,
             created_by, created_by_username_snapshot,
             mattermost_instance_id, mattermost_idempotency_key)
         VALUES (?, ?, 'custom', ?, ?, ?, ?, 'primary', ?)"
    );
    $mattermostKey = 'send-email:' . $suffix;
    $outboundMessage->bind_param(
        'iississ',
        $engagementId,
        $organizationId,
        $outboundSubject,
        $outboundBody,
        $userId,
        $username,
        $mattermostKey
    );
    $outboundMessage->execute();
    $outboundMessageId = (int) $conn->insert_id;
    $outboundMessage->close();
    $recipientName = 'Inbound Contact';
    $recipientRoles = '["primary_host"]';
    $outboundDelivery = $conn->prepare(
        "INSERT INTO engagement_email_deliveries
            (message_id, contact_id, recipient_name, recipient_email,
             recipient_roles_json, status, sent_at)
         VALUES (?, ?, ?, ?, ?, 'sent', UTC_TIMESTAMP())"
    );
    $outboundDelivery->bind_param(
        'iisss',
        $outboundMessageId,
        $contactId,
        $recipientName,
        $contactEmail,
        $recipientRoles
    );
    $outboundDelivery->execute();
    $outboundDelivery->close();

    $reply = parseInboundEmail($rawMessage(
        'Inbound Contact <' . $contactEmail . '>',
        'Staff <' . $userEmail . '>',
        'reply-' . $suffix . '@example.org',
        'Contact reply routing test ' . $engagementMarker
    ));
    $replyStored = storeInboundEmailMessage($conn, 'file', 'reply-' . $suffix, $reply);
    $createdIds['messages'][] = $replyStored['id'];
    expectInboundIntegration(
        processInboundEmailMessage($conn, $replyStored['id']) === 'processed',
        'a reply should route from the uniquely matched Contact in From.'
    );
    $replyNotifications = mattermostPendingReplyNotifications($conn, 'primary');
    $matchingReplyNotifications = array_values(array_filter(
        $replyNotifications,
        static fn(array $notification): bool =>
            (int) $notification['engagement_id'] === $engagementId
            && $notification['mattermost_user_id'] === $mattermostUserId
    ));
    expectInboundIntegration(
        count($matchingReplyNotifications) === 1
            && $matchingReplyNotifications[0]['sender_address'] === $contactEmail
            && !array_key_exists('body_text', $matchingReplyNotifications[0])
            && acknowledgeMattermostReplyNotification(
                $conn,
                'primary',
                (int) $matchingReplyNotifications[0]['id']
            ),
        'a routed reply should create one private notification for the latest linked sender without exposing its body.'
    );

    $crossOrganization = parseInboundEmail($rawMessage(
        'Inbound Contact <' . $contactEmail . '>',
        'Staff <' . $userEmail . '>',
        'cross-organization-' . $suffix . '@example.org',
        'Unrelated body marker routing',
        'Route this message to ' . $unrelatedEngagementMarker . '.'
    ));
    $crossOrganizationRouting = routeInboundEmailMessage($conn, $crossOrganization);
    expectInboundIntegration(
        $crossOrganizationRouting['automatic']
            && $crossOrganizationRouting['authoritative_engagement']
            && in_array(
                $unrelatedEngagementMarker
                    . ' belongs to an Organization not identified by the message participants.',
                $crossOrganizationRouting['reasons'],
                true
            ),
        'a valid body marker should authoritatively select its active Engagement.'
    );
    $crossOrganizationStored = storeInboundEmailMessage(
        $conn,
        'file',
        'cross-organization-' . $suffix,
        $crossOrganization
    );
    $createdIds['messages'][] = $crossOrganizationStored['id'];
    expectInboundIntegration(
        processInboundEmailMessage($conn, $crossOrganizationStored['id']) === 'processed',
        'an authoritative Engagement marker should route automatically despite participant mismatch.'
    );
    $crossOrganizationEngagementChron = $conn->prepare(
        'SELECT COUNT(*) AS total FROM engagement_chron_entries
         WHERE engagement_id = ? AND inbound_email_message_id = ?'
    );
    $crossOrganizationEngagementChron->bind_param(
        'ii',
        $unrelatedEngagementId,
        $crossOrganizationStored['id']
    );
    $crossOrganizationEngagementChron->execute();
    $crossOrganizationEngagementTotal = (int) $crossOrganizationEngagementChron
        ->get_result()->fetch_assoc()['total'];
    $crossOrganizationEngagementChron->close();
    $crossOrganizationContactChron = $conn->prepare(
        'SELECT COUNT(*) AS total FROM contact_chron_entries
         WHERE contact_id = ? AND inbound_email_message_id = ?'
    );
    $crossOrganizationContactChron->bind_param(
        'ii',
        $contactId,
        $crossOrganizationStored['id']
    );
    $crossOrganizationContactChron->execute();
    $crossOrganizationContactTotal = (int) $crossOrganizationContactChron
        ->get_result()->fetch_assoc()['total'];
    $crossOrganizationContactChron->close();
    expectInboundIntegration(
        $crossOrganizationEngagementTotal === 1 && $crossOrganizationContactTotal === 0,
        'participant mismatches should write only to the authoritatively marked Engagement.'
    );

    $unknownSigned = parseInboundEmail($rawMessage(
        'Unknown Sender <unknown-' . $suffix . '@example.org>',
        'Staff <' . $userEmail . '>',
        'unknown-signed-' . $suffix . '@example.org',
        'Unknown sender ' . $engagementMarker
    ));
    $unknownSignedStored = storeInboundEmailMessage(
        $conn,
        'file',
        'unknown-signed-' . $suffix,
        $unknownSigned
    );
    $createdIds['messages'][] = $unknownSignedStored['id'];
    expectInboundIntegration(
        processInboundEmailMessage($conn, $unknownSignedStored['id']) === 'review',
        'a signed marker must not authorize automatic writes for an unknown sender.'
    );

    $standaloneEmail = 'standalone-' . $suffix . '@example.org';
    $standaloneContactStmt = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name,
             contact_role, contact_email, is_deleted)
         VALUES (NULL, 'Standalone', 'Contact', 'admin', ?, 0)"
    );
    $standaloneContactStmt->bind_param('s', $standaloneEmail);
    $standaloneContactStmt->execute();
    $standaloneContactId = (int) $conn->insert_id;
    $createdIds['contacts'][] = $standaloneContactId;
    $standaloneContactStmt->close();
    $standaloneMatches = inboundEmailContactMatches($conn, $standaloneEmail);
    expectInboundIntegration(
        count($standaloneMatches) === 1
            && $standaloneMatches[0]['id'] === $standaloneContactId
            && $standaloneMatches[0]['organization_id'] === null,
        'a standalone Contact should remain an inbound-routing candidate without an Organization.'
    );
    $standaloneMessage = parseInboundEmail($rawMessage(
        'Standalone Contact <' . $standaloneEmail . '>',
        'Staff <' . $userEmail . '>',
        'standalone-' . $suffix . '@example.org',
        'Standalone Contact routing test'
    ));
    $standaloneStored = storeInboundEmailMessage(
        $conn,
        'file',
        'standalone-' . $suffix,
        $standaloneMessage
    );
    $createdIds['messages'][] = $standaloneStored['id'];
    expectInboundIntegration(
        processInboundEmailMessage($conn, $standaloneStored['id']) === 'review'
            && processInboundEmailMessage(
                $conn,
                $standaloneStored['id'],
                [$standaloneContactId],
                [],
                $userId,
                []
            ) === 'processed',
        'manual review should be able to route mail to a standalone Contact.'
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
        'ambiguous-' . $suffix . '@example.net',
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
            $userId,
            [$engagementId]
        ) === 'processed',
        'a reviewer should be able to approve matched targets and select an Engagement manually.'
    );

    $manualEngagementChron = $conn->prepare(
        'SELECT COUNT(*) AS total FROM engagement_chron_entries
         WHERE engagement_id = ? AND inbound_email_message_id = ?'
    );
    $manualEngagementChron->bind_param('ii', $engagementId, $ambiguousStored['id']);
    $manualEngagementChron->execute();
    $manualEngagementTotal = (int) $manualEngagementChron->get_result()->fetch_assoc()['total'];
    $manualEngagementChron->close();

    $statusStmt = $conn->prepare(
        'SELECT status, processed_by FROM inbound_email_messages WHERE id = ?'
    );
    $statusStmt->bind_param('i', $ambiguousStored['id']);
    $statusStmt->execute();
    $reviewed = $statusStmt->get_result()->fetch_assoc();
    $statusStmt->close();
    expectInboundIntegration(
        $reviewed['status'] === 'processed'
            && (int) $reviewed['processed_by'] === $userId
            && $manualEngagementTotal === 1,
        'manual approval should record the reviewer, selected Engagement, and final state.'
    );

    $terminalStateRejected = false;
    try {
        processInboundEmailMessage(
            $conn,
            $ambiguousStored['id'],
            [$contactId],
            [$organizationId],
            $userId,
            [$unrelatedEngagementId]
        );
    } catch (InvalidArgumentException $exception) {
        $terminalStateRejected = str_contains($exception->getMessage(), 'already been processed');
    }
    $unexpectedEngagementChron = $conn->prepare(
        'SELECT COUNT(*) AS total FROM engagement_chron_entries
         WHERE engagement_id = ? AND inbound_email_message_id = ?'
    );
    $unexpectedEngagementChron->bind_param(
        'ii',
        $unrelatedEngagementId,
        $ambiguousStored['id']
    );
    $unexpectedEngagementChron->execute();
    $unexpectedEngagementTotal = (int) $unexpectedEngagementChron
        ->get_result()->fetch_assoc()['total'];
    $unexpectedEngagementChron->close();
    expectInboundIntegration(
        $terminalStateRejected && $unexpectedEngagementTotal === 0,
        'processed inbound messages should be terminal and reject crafted reprocessing attempts.'
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
    foreach (array_reverse($createdIds['engagements']) as $id) {
        $stmt = $conn->prepare('DELETE FROM engagements WHERE id = ?');
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
