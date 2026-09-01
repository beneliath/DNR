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
putenv('DNR_MAIL_TRANSPORT=smtp');
putenv('DNR_INBOUND_ADDRESS=replies@example.test');
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
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
    $mattermostMessageId = queueEngagementEmail(
        $conn,
        $engagementRecord,
        [],
        [$contactId],
        'custom',
        'Mattermost follow-up',
        $mattermostBody,
        false,
        $userId,
        $username,
        'primary',
        'send-email:' . $suffix
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
        "SELECT subject, body_text, mattermost_instance_id, mattermost_idempotency_key
         FROM engagement_email_messages WHERE id = {$mattermostMessageId}"
    )->fetch_assoc();
    expectEngagementEmailIntegration(
        $replayedMessageId === $mattermostMessageId
            && (int) $mattermostDelivery['total'] === 1
            && $storedMattermostMessage['subject'] !== 'Changed retry subject'
            && str_contains((string) $storedMattermostMessage['body_text'], 'MATTERMOST POST')
            && $storedMattermostMessage['mattermost_instance_id'] === 'primary'
            && $storedMattermostMessage['mattermost_idempotency_key'] === 'send-email:' . $suffix,
        'a retried Mattermost send should return the original message without creating another delivery.'
    );
} finally {
    if ($engagementId > 0) {
        $conn->query("DELETE FROM engagements WHERE id = {$engagementId}");
    }
    if ($contactId > 0) {
        $conn->query("DELETE FROM contacts WHERE id = {$contactId}");
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
