<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Operational retention integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';

function expectOperationalRetentionIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Operational retention integration test failed: ' . $message);
    }
}

$suffix = bin2hex(random_bytes(8));
$organizationId = 0;
$engagementId = 0;
$pendingMessageId = 0;
$deliveredMessageId = 0;

try {
    $organizationName = 'Retention Organization ' . $suffix;
    $organization = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    $organization->bind_param('s', $organizationName);
    $organization->execute();
    $organizationId = (int) $conn->insert_id;
    $organization->close();

    $eventTitle = 'Retention Engagement ' . $suffix;
    $engagement = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status, lifecycle_status, is_deleted)
         VALUES (?, ?, '2026-09-10', '2026-09-10', 'conference',
                 'confirmed', 'active', 0)"
    );
    $engagement->bind_param('is', $organizationId, $eventTitle);
    $engagement->execute();
    $engagementId = (int) $conn->insert_id;
    $engagement->close();

    $insertMessage = $conn->prepare(
        "INSERT INTO inbound_email_messages
            (transport, transport_key, deduplication_hash, gateway_address,
             sender_address, to_addresses, cc_addresses, subject, received_at,
             body_text, attachment_names, raw_headers, status, processed_at)
         VALUES ('file', ?, ?, 'gateway@example.test', 'sender@example.test',
                 JSON_ARRAY(), JSON_ARRAY(), ?, '2020-01-01 00:00:00', '',
                 JSON_ARRAY(), '', 'processed', '2020-01-01 00:00:00')"
    );
    foreach (['pending', 'delivered'] as $kind) {
        $transportKey = 'retention-' . $kind . '-' . $suffix;
        $deduplicationHash = random_bytes(32);
        $subject = 'Retention ' . $kind . ' ' . $suffix;
        $insertMessage->bind_param('sss', $transportKey, $deduplicationHash, $subject);
        $insertMessage->execute();
        if ($kind === 'pending') {
            $pendingMessageId = (int) $conn->insert_id;
        } else {
            $deliveredMessageId = (int) $conn->insert_id;
        }
    }
    $insertMessage->close();

    $insertNotification = $conn->prepare(
        "INSERT INTO mattermost_reply_notifications
            (instance_id, mattermost_user_id, engagement_id,
             inbound_email_message_id, delivered_at)
         VALUES ('retention-test', ?, ?, ?, ?)"
    );
    $mattermostUser = 'pending-' . $suffix;
    $deliveredAt = null;
    $insertNotification->bind_param(
        'siis',
        $mattermostUser,
        $engagementId,
        $pendingMessageId,
        $deliveredAt
    );
    $insertNotification->execute();

    $mattermostUser = 'delivered-' . $suffix;
    $deliveredAt = '2020-01-02 00:00:00';
    $insertNotification->bind_param(
        'siis',
        $mattermostUser,
        $engagementId,
        $deliveredMessageId,
        $deliveredAt
    );
    $insertNotification->execute();
    $insertNotification->close();

    $retentionDays = 30;
    $maximumRows = 10000;
    $prune = $conn->prepare('CALL prune_operational_mail_history(?, ?)');
    $prune->bind_param('ii', $retentionDays, $maximumRows);
    $prune->execute();
    $result = $prune->get_result();
    $outcome = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    while ($prune->more_results() && $prune->next_result()) {
        $extraResult = $prune->get_result();
        if ($extraResult) {
            $extraResult->free();
        }
    }
    $prune->close();

    $remainingMessages = $conn->query(
        "SELECT id FROM inbound_email_messages
         WHERE id IN ({$pendingMessageId}, {$deliveredMessageId}) ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);
    $remainingNotifications = $conn->query(
        "SELECT inbound_email_message_id, delivered_at
         FROM mattermost_reply_notifications
         WHERE inbound_email_message_id IN ({$pendingMessageId}, {$deliveredMessageId})"
    )->fetch_all(MYSQLI_ASSOC);

    expectOperationalRetentionIntegration(
        is_array($outcome) && (int) ($outcome['inbound_message_count'] ?? 0) >= 1,
        'the maintenance procedure should report pruning an eligible delivered message.'
    );
    expectOperationalRetentionIntegration(
        count($remainingMessages) === 1
            && (int) $remainingMessages[0]['id'] === $pendingMessageId,
        'an inbound message with an undelivered Mattermost reply must survive pruning.'
    );
    expectOperationalRetentionIntegration(
        count($remainingNotifications) === 1
            && (int) $remainingNotifications[0]['inbound_email_message_id'] === $pendingMessageId
            && $remainingNotifications[0]['delivered_at'] === null,
        'the pending Mattermost reply must remain queued while delivered history may cascade.'
    );
} finally {
    while ($conn->more_results() && $conn->next_result()) {
        $extraResult = $conn->store_result();
        if ($extraResult) {
            $extraResult->free();
        }
    }
    if ($pendingMessageId > 0 || $deliveredMessageId > 0) {
        $ids = implode(',', array_filter([$pendingMessageId, $deliveredMessageId]));
        $conn->query("DELETE FROM mattermost_reply_notifications WHERE inbound_email_message_id IN ({$ids})");
        $conn->query("DELETE FROM inbound_email_messages WHERE id IN ({$ids})");
    }
    if ($engagementId > 0) {
        $conn->query("DELETE FROM engagements WHERE id = {$engagementId}");
    }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    }
}

echo "Operational retention integration tests passed.\n";
