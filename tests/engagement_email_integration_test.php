<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Engagement email integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(str_repeat('E', 32)));
putenv('DNR_INBOUND_ROUTING_KEY=' . base64_encode(str_repeat('R', 32)));
putenv('DNR_MAIL_TRANSPORT=smtp');
putenv('DNR_INBOUND_ADDRESS=replies@example.test');
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/chron_log_helpers.php';
require_once $sourceDirectory . '/mattermost_email_helpers.php';

function expectEngagementEmailIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Engagement email integration test failed: {$message}");
    }
}

$suffix = bin2hex(random_bytes(5));
$userId = 0;
$organizationId = 0;
$engagementId = 0;
$contactId = 0;
$secondaryContactId = 0;
try {
    $username = 'engagement-email-' . $suffix;
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $user = $conn->prepare(
        "INSERT INTO users (username, password, role, account_status)
         VALUES (?, ?, 'editor', 'active')"
    );
    $user->bind_param('ss', $username, $password);
    $user->execute();
    $userId = (int) $conn->insert_id;
    $user->close();

    $organizationName = 'Engagement Email Organization ' . $suffix;
    $organization = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    $organization->bind_param('s', $organizationName);
    $organization->execute();
    $organizationId = (int) $conn->insert_id;
    $organization->close();

    $email = 'host-' . $suffix . '@example.test';
    $firstName = 'Avery';
    $lastName = 'Host';
    $contact = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name,
             contact_role, contact_email, is_deleted)
         VALUES (?, ?, ?, 'admin', ?, 0)"
    );
    $contact->bind_param('isss', $organizationId, $firstName, $lastName, $email);
    $contact->execute();
    $contactId = (int) $conn->insert_id;
    $contact->close();

    $eventTitle = 'Engagement Email Test ' . $suffix;
    $engagement = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_description,
             event_start_date, event_end_date, event_type,
             confirmation_status, lifecycle_status, is_deleted)
         VALUES (?, ?, 'Public description', '2026-10-10', '2026-10-12',
                 'conference', 'confirmed', 'active', 0)"
    );
    $engagement->bind_param('is', $organizationId, $eventTitle);
    $engagement->execute();
    $engagementId = (int) $conn->insert_id;
    $engagement->close();

    $assignment = $conn->prepare(
        "INSERT INTO engagement_contacts
            (engagement_id, contact_id, contact_role, created_by)
         VALUES (?, ?, 'primary_host', ?)"
    );
    $assignment->bind_param('iii', $engagementId, $contactId, $userId);
    $assignment->execute();
    $assignment->close();

    $secondaryEmail = 'coordinator-' . $suffix . '@example.test';
    $secondaryFirstName = 'Blair';
    $secondaryLastName = 'Coordinator';
    $secondaryContact = $conn->prepare(
        "INSERT INTO contacts
            (organization_id, contact_first_name, contact_last_name,
             contact_role, contact_email, is_deleted)
         VALUES (?, ?, ?, 'admin', ?, 0)"
    );
    $secondaryContact->bind_param(
        'isss',
        $organizationId,
        $secondaryFirstName,
        $secondaryLastName,
        $secondaryEmail
    );
    $secondaryContact->execute();
    $secondaryContactId = (int) $conn->insert_id;
    $secondaryContact->close();

    $secondaryAssignment = $conn->prepare(
        "INSERT INTO engagement_contacts
            (engagement_id, contact_id, contact_role, created_by)
         VALUES (?, ?, 'travel', ?)"
    );
    $secondaryAssignment->bind_param('iii', $engagementId, $secondaryContactId, $userId);
    $secondaryAssignment->execute();
    $secondaryAssignment->close();

    $engagementRecord = [
        'id' => $engagementId,
        'organization_id' => $organizationId,
        'organization_name' => $organizationName,
        'event_title' => $eventTitle,
        'event_description' => 'Public description',
        'event_start_date' => '2026-10-10',
        'event_end_date' => '2026-10-12',
        'engagement_notes' => 'PRIVATE NOTE',
        'other_compensation' => 'PRIVATE COMPENSATION',
    ];
    $messageId = queueEngagementEmail(
        $conn,
        $engagementRecord,
        [],
        [$contactId],
        'booking_confirmation',
        'Confirmation',
        'Hello, this is the public message.',
        true,
        $userId,
        $username
    );

    $delivery = $conn->query(
        "SELECT id, status, payload_ciphertext
         FROM engagement_email_deliveries WHERE message_id = {$messageId}"
    )->fetch_assoc();
    expectEngagementEmailIntegration(
        $delivery !== null
            && $delivery['status'] === 'pending'
            && !str_contains((string) $delivery['payload_ciphertext'], 'public message'),
        'queueing should create one encrypted pending delivery.'
    );
    foreach (['engagement_chron_entries', 'contact_chron_entries', 'organization_chron_entries'] as $table) {
        $count = (int) $conn->query(
            "SELECT COUNT(*) AS total FROM {$table}
             WHERE outbound_email_message_id = {$messageId}"
        )->fetch_assoc()['total'];
        expectEngagementEmailIntegration($count === 1, "{$table} should receive one linked Chron entry.");
    }

    $claimed = claimQueuedEngagementEmail($conn);
    expectEngagementEmailIntegration(
        $claimed !== null && $claimed['message_id'] === $messageId && $claimed['attempts'] === 1,
        'the worker should claim the queued recipient exactly once.'
    );
    $decrypted = decryptQueuedEngagementEmail($claimed['payload_ciphertext']);
    expectEngagementEmailIntegration(
        $decrypted['recipient'] === $email
            && $decrypted['reply_to'] === 'replies@example.test'
            && str_contains($decrypted['subject'], applicationInboundMarker($engagementId))
            && str_contains($decrypted['body'], 'EVENT BRIEF')
            && !str_contains($decrypted['body'], 'PRIVATE NOTE')
            && !str_contains($decrypted['body'], 'PRIVATE COMPENSATION'),
        'the delivery should preserve its routing marker and only the share-safe event brief.'
    );

    failQueuedEngagementEmail(
        $conn,
        (int) $claimed['id'],
        (int) $claimed['attempts'],
        new DomainException('Permanent test rejection.'),
        true
    );
    expectEngagementEmailIntegration(
        retryFailedEngagementEmailDeliveries($conn, $messageId) === 1,
        'an editor should be able to reconstruct and re-queue a terminal failed payload.'
    );
    $retried = claimQueuedEngagementEmail($conn);
    expectEngagementEmailIntegration(
        $retried !== null && $retried['message_id'] === $messageId,
        'the manually retried delivery should become claimable again.'
    );
    completeQueuedEngagementEmail($conn, (int) $retried['id']);
    $completed = $conn->query(
        "SELECT status, payload_ciphertext FROM engagement_email_deliveries
         WHERE id = {$retried['id']}"
    )->fetch_assoc();
    expectEngagementEmailIntegration(
        $completed['status'] === 'sent' && $completed['payload_ciphertext'] === null,
        'successful delivery should erase its encrypted SMTP payload.'
    );

    $mattermostBody = mattermostEmailBodyWithContext(
        'Reviewed email body.',
        "MATTERMOST POST\nAuthor: @alex\nMessage: Approved."
    );
    $mattermostPostId = substr(str_repeat($suffix, 3), 0, 26);
    $mattermostMessageId = queueEngagementEmail(
        $conn,
        $engagementRecord,
        [],
        [$contactId, $secondaryContactId],
        'custom',
        'Mattermost follow-up',
        $mattermostBody,
        false,
        $userId,
        $username,
        'primary',
        'send-email:' . $suffix,
        $mattermostPostId
    );
    $replayedMessageId = queueEngagementEmail(
        $conn,
        $engagementRecord,
        [],
        [$contactId],
        'custom',
        'Changed retry subject',
        'Changed retry body',
        false,
        $userId,
        $username,
        'primary',
        'send-email:' . $suffix
    );
    $mattermostDelivery = $conn->query(
        "SELECT COUNT(*) AS total FROM engagement_email_deliveries
         WHERE message_id = {$mattermostMessageId}"
    )->fetch_assoc();
    $storedMattermostMessage = $conn->query(
        "SELECT subject, body_text, mattermost_instance_id, mattermost_idempotency_key,
                mattermost_post_id
         FROM engagement_email_messages WHERE id = {$mattermostMessageId}"
    )->fetch_assoc();
    expectEngagementEmailIntegration(
        $replayedMessageId === $mattermostMessageId
            && (int) $mattermostDelivery['total'] === 2
            && $storedMattermostMessage['subject'] !== 'Changed retry subject'
            && str_contains((string) $storedMattermostMessage['body_text'], 'MATTERMOST POST')
            && $storedMattermostMessage['mattermost_instance_id'] === 'primary'
            && $storedMattermostMessage['mattermost_idempotency_key'] === 'send-email:' . $suffix
            && $storedMattermostMessage['mattermost_post_id'] === $mattermostPostId,
        'a retried Mattermost send should return the original message without creating another delivery.'
    );

    $reactionRows = $conn->query(
        "SELECT id, reaction_name, source_key FROM mattermost_post_reaction_notifications
         WHERE outbound_email_message_id = {$mattermostMessageId}"
    )->fetch_all(MYSQLI_ASSOC);
    $pendingReactions = array_values(array_filter(
        mattermostPendingPostReactionNotifications($conn, 'primary'),
        static fn(array $notification): bool =>
            (int) $notification['outbound_email_message_id'] === $mattermostMessageId
    ));
    expectEngagementEmailIntegration(
        count($reactionRows) === 1
            && $reactionRows[0]['reaction_name'] === 'email'
            && $reactionRows[0]['source_key'] === 'send-email:' . $suffix
            && $pendingReactions === [],
        'Mattermost post metadata should create one durable notification intent that stays hidden before delivery.'
    );

    $firstMattermostDelivery = claimQueuedEngagementEmail($conn);
    expectEngagementEmailIntegration(
        $firstMattermostDelivery !== null
            && (int) $firstMattermostDelivery['message_id'] === $mattermostMessageId,
        'the first Mattermost-context recipient should be claimable.'
    );
    completeQueuedEngagementEmail($conn, (int) $firstMattermostDelivery['id']);
    $pendingReactions = array_values(array_filter(
        mattermostPendingPostReactionNotifications($conn, 'primary'),
        static fn(array $notification): bool =>
            (int) $notification['outbound_email_message_id'] === $mattermostMessageId
    ));
    expectEngagementEmailIntegration(
        count($pendingReactions) === 1
            && $pendingReactions[0]['mattermost_post_id'] === $mattermostPostId
            && $pendingReactions[0]['reaction_name'] === 'email',
        'the first successful recipient delivery should make the Mattermost reaction notification pending.'
    );

    $reactionNotificationId = (int) $pendingReactions[0]['id'];
    expectEngagementEmailIntegration(
        !deferMattermostPostReactionNotification(
            $conn,
            'another-instance',
            $reactionNotificationId,
            'Wrong instance must not change this row.'
        )
            && deferMattermostPostReactionNotification(
                $conn,
                'primary',
                $reactionNotificationId,
                'The source post is temporarily unavailable.'
            ),
        'a failed reaction should be deferred only by its authorized Mattermost instance.'
    );
    $deferredReaction = $conn->query(
        "SELECT attempt_count, next_attempt_at > UTC_TIMESTAMP(6) AS deferred, last_error
         FROM mattermost_post_reaction_notifications
         WHERE id = {$reactionNotificationId}"
    )->fetch_assoc();
    $pendingWhileDeferred = array_values(array_filter(
        mattermostPendingPostReactionNotifications($conn, 'primary'),
        static fn(array $notification): bool =>
            (int) $notification['id'] === $reactionNotificationId
    ));
    expectEngagementEmailIntegration(
        (int) $deferredReaction['attempt_count'] === 1
            && (int) $deferredReaction['deferred'] === 1
            && str_contains((string) $deferredReaction['last_error'], 'temporarily unavailable')
            && $pendingWhileDeferred === [],
        'a failed reaction should back off so it cannot occupy and starve the pending window.'
    );
    $conn->query(
        "UPDATE mattermost_post_reaction_notifications
         SET next_attempt_at = UTC_TIMESTAMP(6) WHERE id = {$reactionNotificationId}"
    );

    $secondMattermostDelivery = claimQueuedEngagementEmail($conn);
    expectEngagementEmailIntegration(
        $secondMattermostDelivery !== null
            && (int) $secondMattermostDelivery['message_id'] === $mattermostMessageId,
        'the second Mattermost-context recipient should remain independently claimable.'
    );
    completeQueuedEngagementEmail($conn, (int) $secondMattermostDelivery['id']);
    $pendingAfterSecondDelivery = array_values(array_filter(
        mattermostPendingPostReactionNotifications($conn, 'primary'),
        static fn(array $notification): bool =>
            (int) $notification['outbound_email_message_id'] === $mattermostMessageId
    ));
    expectEngagementEmailIntegration(
        count($pendingAfterSecondDelivery) === 1
            && !acknowledgeMattermostPostReactionNotification(
                $conn,
                'another-instance',
                $reactionNotificationId
            )
            && acknowledgeMattermostPostReactionNotification(
                $conn,
                'primary',
                $reactionNotificationId
            )
            && acknowledgeMattermostPostReactionNotification(
                $conn,
                'primary',
                $reactionNotificationId
            ),
        'later successes should not duplicate the reaction and acknowledgement should be instance-scoped and idempotent.'
    );
    $pendingAfterAcknowledgement = array_values(array_filter(
        mattermostPendingPostReactionNotifications($conn, 'primary'),
        static fn(array $notification): bool =>
            (int) $notification['outbound_email_message_id'] === $mattermostMessageId
    ));
    expectEngagementEmailIntegration(
        $pendingAfterAcknowledgement === [],
        'an acknowledged Mattermost post reaction should leave the pending service queue.'
    );

    $chronPostId = substr(str_repeat('c' . $suffix, 3), 0, 26);
    $chronSourceKey = 'save-chron:' . $suffix;
    $conn->begin_transaction();
    insertEntityChronLogEntry(
        $conn,
        'engagement',
        $engagementId,
        'Mattermost Chron receipt integration test.',
        $userId,
        $username
    );
    $chronEntryId = (int) $conn->insert_id;
    queueMattermostPostReactionNotification(
        $conn,
        'primary',
        $chronSourceKey,
        $chronPostId,
        'memo',
        null,
        $chronEntryId
    );
    $conn->commit();
    $pendingChronReactions = array_values(array_filter(
        mattermostPendingPostReactionNotifications($conn, 'primary'),
        static fn(array $notification): bool =>
            $notification['mattermost_post_id'] === $chronPostId
    ));
    expectEngagementEmailIntegration(
        count($pendingChronReactions) === 1
            && $pendingChronReactions[0]['reaction_name'] === 'memo'
            && (int) $pendingChronReactions[0]['outbound_email_message_id'] === 0
            && acknowledgeMattermostPostReactionNotification(
                $conn,
                'primary',
                (int) $pendingChronReactions[0]['id']
            ),
        'a committed Chron entry should expose a durable memo reaction that can be acknowledged.'
    );
} finally {
    if ($engagementId > 0) {
        $conn->query("DELETE FROM engagements WHERE id = {$engagementId}");
    }
    if ($contactId > 0) {
        $conn->query("DELETE FROM contacts WHERE id = {$contactId}");
    }
    if ($secondaryContactId > 0) {
        $conn->query("DELETE FROM contacts WHERE id = {$secondaryContactId}");
    }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    }
    if ($userId > 0) {
        $conn->query("DELETE FROM users WHERE id = {$userId}");
    }
    putenv('DNR_2FA_ENCRYPTION_KEY');
    putenv('DNR_MAIL_TRANSPORT');
    putenv('DNR_INBOUND_ADDRESS');
}

echo "Engagement email integration tests passed.\n";
