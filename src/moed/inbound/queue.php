<?php

declare(strict_types=1);

function rejectInboundEmailMessage(mysqli $conn, int $messageId, int $processedBy): void
{
    $stmt = $conn->prepare(
        "UPDATE inbound_email_messages
         SET status = 'rejected', processed_by = ?, processed_at = UTC_TIMESTAMP(),
             processing_started_at = NULL, review_reason = 'Rejected by reviewer'
         WHERE id = ? AND status IN ('pending', 'review', 'failed')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound message rejection.');
    }
    $stmt->bind_param('ii', $processedBy, $messageId);
    $succeeded = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$succeeded) {
        throw new InvalidArgumentException('That inbound message can no longer be rejected.');
    }
}

function purgeInboundEmailMessage(mysqli $conn, int $messageId): bool
{
    if ($messageId < 1) {
        throw new InvalidArgumentException('Select a valid inbound message.');
    }

    $stmt = $conn->prepare('DELETE FROM inbound_email_messages WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound message purge.');
    }
    $stmt->bind_param('i', $messageId);
    $succeeded = $stmt->execute();
    $purged = $succeeded && $stmt->affected_rows === 1;
    $stmt->close();

    return $purged;
}

function claimInboundEmailMessage(mysqli $conn, int $leaseSeconds = 600): ?int
{
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    $conn->begin_transaction();
    try {
        $result = $conn->query(
            "SELECT id FROM inbound_email_messages
             WHERE attempts < 8
               AND (
                    (status IN ('pending', 'failed') AND next_attempt_at <= UTC_TIMESTAMP())
                    OR (status = 'processing' AND processing_started_at <= DATE_SUB(
                        UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND
                    ))
               )
             ORDER BY next_attempt_at, id
             LIMIT 1 FOR UPDATE SKIP LOCKED"
        );
        $message = $result ? $result->fetch_assoc() : null;
        if (!$message) {
            $conn->commit();
            return null;
        }
        $messageId = (int) $message['id'];
        $stmt = $conn->prepare(
            "UPDATE inbound_email_messages
             SET status = 'processing', attempts = attempts + 1,
                 processing_started_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE id = ?"
        );
        $stmt->bind_param('i', $messageId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return $messageId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function failInboundEmailMessage(mysqli $conn, int $messageId, Throwable $exception): void
{
    $error = mb_substr($exception->getMessage(), 0, 255, 'UTF-8');
    $stmt = $conn->prepare(
        "UPDATE inbound_email_messages
         SET status = 'failed', processing_started_at = NULL, last_error = ?,
             next_attempt_at = TIMESTAMPADD(
                 MINUTE,
                 LEAST(1440, CAST(POW(2, attempts) AS UNSIGNED)),
                 UTC_TIMESTAMP()
             )
         WHERE id = ? AND status = 'processing'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound processing failure.');
    }
    $stmt->bind_param('si', $error, $messageId);
    $stmt->execute();
    $stmt->close();
}
