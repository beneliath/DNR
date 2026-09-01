<?php

declare(strict_types=1);

require_once __DIR__ . '/mattermost_integration_helpers.php';
require_once __DIR__ . '/engagement_email_helpers.php';

/** @return array{engagement: array<string, mixed>, presentations: list<array<string, mixed>>, contacts: list<array<string, mixed>>} */
function mattermostEmailContext(mysqli $conn, int $engagementId): array
{
    if ($engagementId < 1) {
        throw new InvalidArgumentException('A valid linked engagement is required.');
    }
    $stmt = $conn->prepare(
        'SELECT engagement.*, organization.organization_name,
                organization.is_deleted AS organization_deleted
         FROM engagements engagement
         INNER JOIN organizations organization ON organization.id = engagement.organization_id
         WHERE engagement.id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Mattermost email engagement.');
    }
    $stmt->bind_param('i', $engagementId);
    $stmt->execute();
    $engagement = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$engagement || !empty($engagement['is_deleted']) || !empty($engagement['organization_deleted'])) {
        throw new InvalidArgumentException('Email can be sent only for an active engagement and organization.');
    }

    $presentations = $conn->prepare(
        'SELECT topic_title, presentation_date, presentation_time,
                speaker_name, duration_minutes
         FROM presentations
         WHERE engagement_id = ? AND is_archived = 0
         ORDER BY presentation_date, presentation_time, id'
    );
    if (!$presentations) {
        throw new RuntimeException('Unable to prepare the Mattermost email presentation schedule.');
    }
    $presentations->bind_param('i', $engagementId);
    $presentations->execute();
    $presentationRows = $presentations->get_result()->fetch_all(MYSQLI_ASSOC);
    $presentations->close();

    return [
        'engagement' => $engagement,
        'presentations' => $presentationRows,
        'contacts' => fetchEngagementContacts($conn, $engagementId),
    ];
}

/** @param list<array<string, mixed>> $contacts */
function mattermostEmailContactPayload(array $contacts): array
{
    $payload = [];
    foreach ($contacts as $contact) {
        $email = trim((string) ($contact['contact_email'] ?? ''));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }
        $name = trim(
            trim((string) ($contact['contact_first_name'] ?? '')) . ' '
            . trim((string) ($contact['contact_last_name'] ?? ''))
        );
        $roles = array_values(array_filter(array_map(
            static fn(mixed $role): string => trim((string) $role),
            (array) ($contact['engagement_contact_roles'] ?? [])
        )));
        $payload[] = [
            'id' => (int) $contact['id'],
            'name' => $name !== '' ? $name : $email,
            'email' => strtolower($email),
            'roles' => $roles,
            'role_labels' => array_map('engagementContactRoleLabel', $roles),
        ];
    }
    return $payload;
}

/** @return array<string, mixed> */
function mattermostEmailComposerPayload(mysqli $conn, int $engagementId): array
{
    $context = mattermostEmailContext($conn, $engagementId);
    $engagement = $context['engagement'];
    $contacts = mattermostEmailContactPayload($context['contacts']);
    $templates = engagementEmailTemplates($engagement, $context['presentations']);
    $templatePayload = [];
    foreach ($templates as $key => $template) {
        $suggestedIds = [];
        foreach ($contacts as $contact) {
            if (array_intersect($template['suggested_roles'], $contact['roles'])) {
                $suggestedIds[] = (int) $contact['id'];
            }
        }
        $templatePayload[$key] = [
            'key' => $key,
            'label' => (string) $template['label'],
            'subject' => (string) $template['subject'],
            'body' => (string) $template['body'],
            'suggested_contact_ids' => $suggestedIds,
        ];
    }
    $deliveryAvailable = true;
    try {
        accountMailTransport();
    } catch (RuntimeException) {
        $deliveryAvailable = false;
    }
    return [
        'ok' => true,
        'engagement' => [
            'id' => (int) $engagement['id'],
            'title' => engagementEmailEventLabel($engagement),
            'organization_name' => (string) $engagement['organization_name'],
            'email_routing_marker' => applicationInboundMarker((int) $engagement['id']),
            'url' => mattermostPublicUrl('view_engagement.php', ['id' => (int) $engagement['id']]),
        ],
        'contacts' => $contacts,
        'templates' => $templatePayload,
        'safe_event_brief' => engagementEmailSafeEventBrief($engagement, $context['presentations']),
        'delivery_available' => $deliveryAvailable,
        'compose_url' => mattermostPublicUrl('compose_engagement_email.php', ['id' => (int) $engagement['id']]),
    ];
}

function mattermostEmailBodyWithContext(mixed $body, mixed $mattermostContext): string
{
    $body = normalizeEngagementEmailBody($body);
    if (!is_scalar($mattermostContext)) {
        throw new InvalidArgumentException('The Mattermost context is invalid.');
    }
    $mattermostContext = trim(str_replace(["\r\n", "\r"], "\n", (string) $mattermostContext));
    if ($mattermostContext === '') {
        return $body;
    }
    if (mb_strlen($mattermostContext, 'UTF-8') > 20000) {
        throw new InvalidArgumentException('The Mattermost conversation excerpt is too long.');
    }
    $combined = $body . "\n\n---\n\n" . $mattermostContext;
    if (mb_strlen($combined, 'UTF-8') > 100000) {
        throw new InvalidArgumentException('The message and Mattermost excerpt together exceed 100,000 characters.');
    }
    return $combined;
}

/** @return array<string, mixed> */
function mattermostEmailMessagePayload(mysqli $conn, int $messageId): array
{
    $message = fetchEngagementEmailMessage($conn, $messageId);
    if ($message === null) {
        throw new InvalidArgumentException('That outbound message is no longer available.');
    }
    $deliveries = [];
    foreach ($message['deliveries'] as $delivery) {
        $deliveries[] = [
            'name' => (string) $delivery['recipient_name'],
            'status' => (string) $delivery['status'],
            'last_error' => (string) ($delivery['last_error'] ?? ''),
        ];
    }
    $recipientCount = (int) $message['recipient_count'];
    $sentCount = (int) $message['sent_count'];
    $failedCount = (int) $message['failed_count'];
    return [
        'ok' => true,
        'message' => $failedCount > 0
            ? 'One or more email deliveries failed. Open the delivery record in MOED to review or retry them.'
            : ($sentCount === $recipientCount
                ? 'Email delivered and added to the MOED Chron.'
                : 'Email queued for delivery and added to the MOED Chron.'),
        'message_id' => $messageId,
        'engagement_id' => (int) $message['engagement_id'],
        'status' => engagementEmailAggregateStatus($message),
        'recipient_count' => $recipientCount,
        'sent_count' => $sentCount,
        'failed_count' => $failedCount,
        'pending_count' => max(0, $recipientCount - $sentCount - $failedCount),
        'deliveries' => $deliveries,
        'url' => mattermostPublicUrl('outbound_mail.php', ['id' => $messageId]),
    ];
}

function queueMattermostReplyNotifications(
    mysqli $conn,
    int $inboundMessageId,
    array $engagementIds,
    string $senderAddress
): void {
    $senderAddress = strtolower(trim($senderAddress));
    if ($inboundMessageId < 1 || filter_var($senderAddress, FILTER_VALIDATE_EMAIL) === false) {
        return;
    }
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO mattermost_reply_notifications
            (instance_id, mattermost_user_id, engagement_id, inbound_email_message_id)
         SELECT link.instance_id, link.mattermost_user_id, ?, ?
         FROM mattermost_user_links link
         INNER JOIN users user ON user.id = link.user_id AND user.account_status = \'active\'
         WHERE link.user_id = (
            SELECT message.created_by
            FROM engagement_email_messages message
            INNER JOIN engagement_email_deliveries delivery ON delivery.message_id = message.id
            WHERE message.engagement_id = ?
              AND message.mattermost_instance_id = link.instance_id
              AND LOWER(TRIM(delivery.recipient_email)) = ?
              AND message.created_by IS NOT NULL
            ORDER BY message.created_at DESC, message.id DESC
            LIMIT 1
         )'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Mattermost reply notification.');
    }
    foreach (array_values(array_unique(array_map('intval', $engagementIds))) as $engagementId) {
        if ($engagementId < 1) {
            continue;
        }
        $stmt->bind_param('iiis', $engagementId, $inboundMessageId, $engagementId, $senderAddress);
        $stmt->execute();
    }
    $stmt->close();
}

/** @return list<array<string, mixed>> */
function mattermostPendingReplyNotifications(mysqli $conn, string $instanceId, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $stmt = $conn->prepare(
        "SELECT notification.id, notification.mattermost_user_id,
                notification.engagement_id, message.sender_name,
                message.sender_address, message.subject, message.received_at,
                message.attachment_names, engagement.event_title,
                organization.organization_name
         FROM mattermost_reply_notifications notification
         INNER JOIN mattermost_user_links link
            ON link.instance_id = notification.instance_id
           AND link.mattermost_user_id = notification.mattermost_user_id
         INNER JOIN users user ON user.id = link.user_id AND user.account_status = 'active'
         INNER JOIN inbound_email_messages message
            ON message.id = notification.inbound_email_message_id
         INNER JOIN engagements engagement ON engagement.id = notification.engagement_id
         INNER JOIN organizations organization ON organization.id = engagement.organization_id
         WHERE notification.instance_id = ?
           AND notification.delivered_at IS NULL
           AND message.status = 'processed'
         ORDER BY notification.id
         LIMIT {$limit}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare pending Mattermost reply notifications.');
    }
    $stmt->bind_param('s', $instanceId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(static function (array $row): array {
        $attachments = json_decode((string) ($row['attachment_names'] ?? '[]'), true);
        return [
            'id' => (int) $row['id'],
            'mattermost_user_id' => (string) $row['mattermost_user_id'],
            'engagement_id' => (int) $row['engagement_id'],
            'engagement_title' => trim((string) $row['event_title']) !== ''
                ? (string) $row['event_title']
                : (string) $row['organization_name'],
            'sender_name' => (string) ($row['sender_name'] ?? ''),
            'sender_address' => (string) $row['sender_address'],
            'subject' => trim((string) $row['subject']) !== '' ? (string) $row['subject'] : '(No subject)',
            'received_at' => (string) $row['received_at'],
            'attachment_count' => is_array($attachments) ? count($attachments) : 0,
            'url' => mattermostPublicUrl('view_engagement.php', [
                'id' => (int) $row['engagement_id'],
            ]) . '#chron',
        ];
    }, $rows);
}

function acknowledgeMattermostReplyNotification(
    mysqli $conn,
    string $instanceId,
    int $notificationId
): bool {
    if ($notificationId < 1) {
        throw new InvalidArgumentException('A valid reply notification is required.');
    }
    $stmt = $conn->prepare(
        'UPDATE mattermost_reply_notifications
         SET delivered_at = COALESCE(delivered_at, UTC_TIMESTAMP(6))
         WHERE id = ? AND instance_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Mattermost reply acknowledgement.');
    }
    $stmt->bind_param('is', $notificationId, $instanceId);
    $stmt->execute();
    $acknowledged = $stmt->affected_rows === 1;
    if (!$acknowledged) {
        $check = $conn->prepare(
            'SELECT id FROM mattermost_reply_notifications
             WHERE id = ? AND instance_id = ? AND delivered_at IS NOT NULL'
        );
        if (!$check) {
            $stmt->close();
            throw new RuntimeException('Unable to confirm the Mattermost reply acknowledgement.');
        }
        $check->bind_param('is', $notificationId, $instanceId);
        $check->execute();
        $acknowledged = $check->get_result()->fetch_assoc() !== null;
        $check->close();
    }
    $stmt->close();
    return $acknowledged;
}
