<?php
declare(strict_types=1);
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/persistent_file_helpers.php';

function maintainQueuedReimbursementEmail(mysqli $conn, int $leaseSeconds = 600): void
{
    $conn->query('UPDATE reimbursement_submissions SET snapshot_ciphertext=NULL WHERE snapshot_ciphertext IS NOT NULL AND created_at <= UTC_TIMESTAMP() - INTERVAL 365 DAY');
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    expireUncertainEmailDeliveries($conn, 'reimbursement_email_deliveries', $leaseSeconds);
    if ($conn->query(
        "UPDATE reimbursement_email_deliveries
         SET status = 'failed', processing_started_at = NULL,
             payload_ciphertext = NULL,
             last_error = 'Delivery stopped after the final attempt.'
         WHERE status = 'processing'
           AND processing_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND)
           AND attempts >= 8"
    ) === false) {
        throw new RuntimeException('Unable to expire terminal reimbursement-email leases.');
    }
    if ($conn->query(
        "UPDATE reimbursement_email_deliveries
         SET status = 'retry', processing_started_at = NULL,
             next_attempt_at = UTC_TIMESTAMP(),
             last_error = 'Delivery lease expired before completion.'
         WHERE status = 'processing'
           AND processing_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND)
           AND attempts < 8"
    ) === false) {
        throw new RuntimeException('Unable to recover expired reimbursement-email leases.');
    }
}

/** @return array{claim_token: string, smtp_message_id: string, id: int, attachment_key: string, payload_ciphertext: string, attempts: int}|null */
function claimQueuedReimbursementEmail(
    mysqli $conn,
    int $leaseSeconds = 600,
    bool $performMaintenance = true
): ?array {
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    if ($performMaintenance) {
        maintainQueuedReimbursementEmail($conn, $leaseSeconds);
    }
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "SELECT id, attachment_key, payload_ciphertext, attempts
             FROM reimbursement_email_deliveries
             WHERE status IN ('pending', 'retry')
               AND next_attempt_at <= UTC_TIMESTAMP()
               AND attempts < 8
               AND payload_ciphertext IS NOT NULL
             ORDER BY next_attempt_at, id
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the reimbursement email claim.');
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            $conn->commit();
            return null;
        }
        $deliveryId = (int) $row['id'];
        $claim = $conn->prepare(
            "UPDATE reimbursement_email_deliveries
             SET status = 'processing', processing_started_at = UTC_TIMESTAMP(),
                 attempts = attempts + 1, last_error = NULL
             WHERE id = ? AND status IN ('pending', 'retry')"
        );
        if (!$claim) {
            throw new RuntimeException('Unable to prepare the reimbursement email claim update.');
        }
        $claim->bind_param('i', $deliveryId);
        $claim->execute();
        if ($claim->affected_rows !== 1) {
            $claim->close();
            throw new RuntimeException('The reimbursement email could not be claimed.');
        }
        $claim->close();
        $deliveryClaim = stampEmailDeliveryClaim($conn, 'reimbursement_email_deliveries', $deliveryId);
        $conn->commit();
        return [
            ...$deliveryClaim,
            'id' => $deliveryId,
            'attachment_key' => (string) $row['attachment_key'],
            'payload_ciphertext' => (string) $row['payload_ciphertext'],
            'attempts' => (int) $row['attempts'] + 1,
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return array{recipient: string, subject: string, body: string, reply_to: string, visible_recipients: array{to: list<string>, cc: list<string>}|null} */
function decryptQueuedReimbursementEmail(string $ciphertext): array
{
    $json = \Dnr\Security\ApplicationKey::open($ciphertext);
    $message = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($message)) {
        throw new RuntimeException('The queued reimbursement email payload is invalid.');
    }
    return [
        'recipient' => normalizeAccountEmail($message['recipient'] ?? ''),
        'subject' => trim((string) ($message['subject'] ?? '')),
        'body' => (string) ($message['body'] ?? ''),
        'html_body' => (string) ($message['html_body'] ?? ''),
        'filename' => (string) ($message['filename'] ?? ''),
        'individual_attachments' => reimbursementEmailIndividualManifest($message['individual_attachments'] ?? []),
        'inline_images' => emailInlineImages($message['inline_images'] ?? []),
        'reply_to' => reimbursementEmailNormalizeOptionalAddress($message['reply_to'] ?? ''),
        'visible_recipients' => smtpNormalizeVisibleRecipients($message['visible_recipients'] ?? null),
    ];
}

function reimbursementEmailIndividualManifest(mixed $value): array
{
    if (!is_array($value)) throw new DomainException('The reimbursement attachment list is invalid.');
    $manifest = []; $seen = [];
    foreach ($value as $index => $entry) {
        if (!is_array($entry)) throw new DomainException('The reimbursement attachment list is invalid.');
        $path = $entry['archive_path'] ?? null;
        $filename = $entry['filename'] ?? null;
        $type = $entry['content_type'] ?? null;
        if (!is_string($path) || !is_string($filename) || !is_string($type)
            || !preg_match('/\A[A-Za-z0-9._-]{1,200}\z/D', $filename)
            || ($index === 0 && ($path !== $filename || $type !== 'application/pdf'))
            || ($index !== 0 && ($path !== 'receipts/' . $filename
                || !in_array($type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)))
            || isset($seen[$path])) {
            throw new DomainException('The reimbursement attachment list is invalid.');
        }
        $seen[$path] = true;
        $manifest[] = ['archive_path' => $path, 'filename' => $filename, 'content_type' => $type];
    }
    return $manifest;
}

function reimbursementEmailNormalizeOptionalAddress(mixed $address): string
{
    if (!is_scalar($address) || trim((string) $address) === '') {
        return '';
    }
    return normalizeAccountEmail($address);
}

function completeQueuedReimbursementEmail(mysqli $conn, int $deliveryId, ?string $claimToken = null): void
{
    $stmt = $conn->prepare(
        "UPDATE reimbursement_email_deliveries
         SET status = 'sent', sent_at = UTC_TIMESTAMP(),
             processing_started_at = NULL, payload_ciphertext = NULL,
             last_error = NULL
         WHERE id = ? AND claim_token <=> ? AND status IN ('pending', 'processing', 'retry')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare reimbursement email completion.');
    }
    $stmt->bind_param('is', $deliveryId, $claimToken);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException('The reimbursement email can no longer be completed.');
    }
    $stmt->close();
}

function failQueuedReimbursementEmail(
    mysqli $conn,
    int $deliveryId,
    int $attempts,
    Throwable $exception,
    bool $permanent = false,
    ?string $claimToken = null
): void {
    if ($exception instanceof SmtpUncertainDeliveryException) {
        markEmailDeliveryUncertain($conn, 'reimbursement_email_deliveries', $deliveryId, $claimToken);
        return;
    }
    $terminal = $permanent || $attempts >= 8;
    $status = $terminal ? 'failed' : 'retry';
    $delay = min(3600, 15 * (2 ** max(0, min(7, $attempts - 1))));
    $error = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? 'Delivery failed.');
    $error = mb_substr($error !== '' ? $error : 'Delivery failed.', 0, 255, 'UTF-8');
    $stmt = $conn->prepare(
        "UPDATE reimbursement_email_deliveries
         SET status = ?, processing_started_at = NULL, delivery_started_at = NULL,
             next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
             payload_ciphertext = IF(? = 1, NULL, payload_ciphertext),
             last_error = ?
         WHERE id = ? AND claim_token <=> ? AND status IN ('pending', 'processing', 'retry')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare reimbursement email failure handling.');
    }
    $terminalValue = $terminal ? 1 : 0;
    $stmt->bind_param('siisis', $status, $delay, $terminalValue, $error, $deliveryId, $claimToken);
    $stmt->execute();
    $stmt->close();
}


function reimbursementEmailAttachment(mysqli $conn, array $queued, array $message): array
{
    $metadata = persistentFileMetadata($conn, $queued['attachment_key']);
    if ((int) $metadata['size'] > 15 * 1024 * 1024) throw new DomainException('The reimbursement attachment exceeds 15 MB.');
    // A long-lived worker must release its shared storage lock after reading.
    $lockPath = persistentFileRoot() . '/.lifecycle.lock';
    if (is_link($lockPath)) throw new RuntimeException('Invalid file lifecycle lock.');
    $guard = fopen($lockPath, 'rb');
    if (!$guard) throw new RuntimeException('Persistent storage is unavailable.');
    try {
        if (!flock($guard, LOCK_SH)) throw new RuntimeException('Persistent storage is busy.');
        $data = file_get_contents(persistentFilePath($queued['attachment_key']), false, null, 0, 15 * 1024 * 1024 + 1);
        if (!is_string($data) || strlen($data) !== (int) $metadata['size']
            || !hash_equals($metadata['checksum'], hash('sha256', $data))) {
            throw new DomainException('The reimbursement attachment failed its integrity check.');
        }
    } finally { flock($guard, LOCK_UN); fclose($guard); }
    return ['filename' => $message['filename'], 'content_type' => 'application/zip', 'data' => $data];
}

function reimbursementEmailAttachments(mysqli $conn, array $queued, array $message): array
{
    $zipAttachment = reimbursementEmailAttachment($conn, $queued, $message);
    return reimbursementAttachmentsFromVerifiedZip($zipAttachment, $message['individual_attachments'] ?? []);
}

function reimbursementAttachmentsFromVerifiedZip(array $zipAttachment, array $manifest): array
{
    if ($manifest === []) return [$zipAttachment]; // Queued before individual attachments were introduced.
    $manifest = reimbursementEmailIndividualManifest($manifest);
    $expectedReport = preg_replace('/\.zip\z/', '.pdf', $zipAttachment['filename']);
    if ($expectedReport === $zipAttachment['filename'] || $manifest[0]['filename'] !== $expectedReport) {
        throw new DomainException('The reimbursement report attachment is invalid.');
    }
    $path = tempnam(sys_get_temp_dir(), 'dnr-email-zip-');
    if ($path === false) throw new RuntimeException('Unable to inspect the reimbursement package.');
    $archive = new ZipArchive();
    $opened = false;
    try {
        if (file_put_contents($path, $zipAttachment['data']) !== strlen($zipAttachment['data'])) {
            throw new DomainException('The reimbursement package could not be staged.');
        }
        if ($archive->open($path) !== true) throw new DomainException('The reimbursement package could not be opened.');
        $opened = true;
        if ($archive->numFiles !== count($manifest)) throw new DomainException('The reimbursement package contents changed.');
        $total = strlen($zipAttachment['data']);
        foreach ($manifest as $entry) {
            $stat = $archive->statName($entry['archive_path']);
            if (!$stat || !isset($stat['size'])) throw new DomainException('A reimbursement attachment is missing.');
            $total += (int) $stat['size'];
            if ($total > 15 * 1024 * 1024) throw new DomainException('Email attachments exceed 15 MB.');
        }
        $attachments = [$zipAttachment];
        foreach ($manifest as $entry) {
            $data = $archive->getFromName($entry['archive_path']);
            $stat = $archive->statName($entry['archive_path']);
            if (!is_string($data) || strlen($data) !== (int) $stat['size']) {
                throw new DomainException('A reimbursement attachment could not be read.');
            }
            $attachments[] = ['filename' => $entry['filename'], 'content_type' => $entry['content_type'], 'data' => $data];
        }
        return $attachments;
    } finally {
        if ($opened) $archive->close();
        unlink($path);
    }
}
