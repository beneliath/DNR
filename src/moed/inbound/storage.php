<?php

declare(strict_types=1);

/** @param mixed $value */
function inboundEmailJson($value): string
{
    $encoded = json_encode(
        $value,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode inbound email data.');
    }
    return $encoded;
}

/**
 * @param array{
 *   rfc_message_id: string, sender_name: string, sender_address: string,
 *   to_addresses: list<string>, cc_addresses: list<string>, subject: string,
 *   sent_at: ?string, received_at: string, body_text: string,
 *   attachment_names: list<string>, raw_headers: string, deduplication_hash: string,
 *   _inbox_attachments?: list<array{filename: string, data_base64: string}>
 * } $message
 * @return array{id: int, inserted: bool}
 */
function storeInboundEmailMessage(
    mysqli $conn,
    string $transport,
    string $transportKey,
    array $message,
    ?string $gatewayAddress = null,
    ?array $manualAssignment = null
): array {
    $manualReview = false;
    if (accountMailEnabled()) {
        require_once dirname(__DIR__, 2) . '/account_mail_helpers.php';
        if ($manualAssignment !== null) {
            if (!accountMailManualAssignmentIsValid($manualAssignment, $message, currentAccountKey(), accountMailSigningKey())) {
                throw new RuntimeException('The manual mail destination could not be verified.');
            }
            $manualReview = true;
        } elseif (localAccountMailRoute($message)['account_key'] !== currentAccountKey()) {
            throw new RuntimeException('Incoming mail must unambiguously identify this Account.');
        }
    } elseif ($manualAssignment !== null) {
        throw new RuntimeException('Account mail routing is not enabled.');
    }
    if (!in_array($transport, ['imap', 'webhook', 'file'], true)) {
        throw new InvalidArgumentException('Invalid inbound email transport.');
    }
    $transportKey = trim($transportKey);
    if ($transportKey === '' || strlen($transportKey) > 255) {
        throw new InvalidArgumentException('Invalid inbound email transport key.');
    }
    $gatewayAddress = normalizeInboundEmailAddress($gatewayAddress ?? inboundEmailGatewayAddress());
    if ($gatewayAddress === '') {
        throw new InvalidArgumentException('Invalid inbound email gateway address.');
    }

    $toJson = inboundEmailJson($message['to_addresses']);
    $ccJson = inboundEmailJson($message['cc_addresses']);
    $attachmentJson = inboundEmailJson($message['attachment_names']);
    // Insert directly into review so a background worker can never auto-file a manual assignment.
    $initialStatus = $manualReview ? 'review' : 'pending';
    $reviewReason = $manualReview ? 'Manually routed to this Account. Review before filing.' : null;
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO inbound_email_messages
            (transport, transport_key, deduplication_hash, rfc_message_id,
             gateway_address, sender_name, sender_address, to_addresses,
             cc_addresses, subject, sent_at, received_at, body_text,
             attachment_names, raw_headers, status, review_reason)
         VALUES (?, ?, ?, NULLIF(?, \'\'), ?, NULLIF(?, \'\'), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound email storage.');
    }
    $stmt->bind_param(
        'sssssssssssssssss',
        $transport,
        $transportKey,
        $message['deduplication_hash'],
        $message['rfc_message_id'],
        $gatewayAddress,
        $message['sender_name'],
        $message['sender_address'],
        $toJson,
        $ccJson,
        $message['subject'],
        $message['sent_at'],
        $message['received_at'],
        $message['body_text'],
        $attachmentJson,
        $message['raw_headers'],
        $initialStatus,
        $reviewReason
    );
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Unable to save the inbound email: ' . $error);
    }
    $inserted = $stmt->affected_rows === 1;
    $messageId = $inserted ? (int) $conn->insert_id : 0;
    $stmt->close();

    if (!$inserted) {
        $lookup = $conn->prepare(
            'SELECT id FROM inbound_email_messages
             WHERE (transport = ? AND transport_key = ?)
                OR deduplication_hash = ?
             ORDER BY id LIMIT 1'
        );
        if (!$lookup) {
            throw new RuntimeException('Unable to prepare inbound email duplicate lookup.');
        }
        $lookup->bind_param('sss', $transport, $transportKey, $message['deduplication_hash']);
        $lookup->execute();
        $messageId = (int) ($lookup->get_result()->fetch_assoc()['id'] ?? 0);
        $lookup->close();
        if ($messageId < 1) {
            throw new RuntimeException('The inbound email could not be stored or located.');
        }
    }

    if ($inserted && !empty($message['_inbox_attachments'])) {
        require_once dirname(__DIR__, 2).'/audience_inbox.php';
        if (audienceInboxInstalled($conn)) {
            $total = 0;
            foreach (array_slice($message['_inbox_attachments'],0,10) as $part => $file) {
                $bytes = base64_decode($file['data_base64'],true);
                if ($bytes === false || strlen($bytes)>AUDIENCE_INBOX_ATTACHMENT_BYTES || ($total+=strlen($bytes))>AUDIENCE_INBOX_ATTACHMENTS_BYTES) break;
                $conn->execute_query('INSERT IGNORE INTO marketing_inbox_inbound_parts(inbound_id,part_number,filename,content) VALUES (?,?,?,?)',[$messageId,$part,mb_substr($file['filename'],0,180),$bytes]);
            }
        }
    }
    return ['id' => $messageId, 'inserted' => $inserted];
}

function quarantineInboundEmailMessage(
    mysqli $conn,
    string $transportKey,
    Throwable $exception
): void {
    $transportKey = trim($transportKey);
    if ($transportKey === '' || strlen($transportKey) > 255) {
        throw new InvalidArgumentException('Invalid inbound quarantine transport key.');
    }
    $reason = mb_substr($exception->getMessage(), 0, 255, 'UTF-8');
    if ($reason === '') {
        $reason = 'Inbound message parsing failed.';
    }
    $stmt = $conn->prepare(
        "INSERT INTO inbound_email_quarantine
            (transport, transport_key, failure_reason)
         VALUES ('imap', ?, ?)
         ON DUPLICATE KEY UPDATE
            failure_reason = VALUES(failure_reason),
            occurrences = occurrences + 1,
            last_failed_at = CURRENT_TIMESTAMP"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound email quarantine storage.');
    }
    $stmt->bind_param('ss', $transportKey, $reason);
    $stmt->execute();
    $stmt->close();
}
