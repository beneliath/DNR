<?php
declare(strict_types=1);

final class SmtpUncertainDeliveryException extends RuntimeException {}

function emailDeliveryTable(string $table): string
{
    if (!in_array($table, ['email_outbox', 'notification_outbox', 'engagement_email_deliveries'], true)) {
        throw new InvalidArgumentException('Invalid delivery queue.');
    }
    return $table;
}

/** Call inside the claim transaction, while its row lock is held. */
function stampEmailDeliveryClaim(mysqli $conn, string $table, int $id): array
{
    $table = emailDeliveryTable($table);
    $token = bin2hex(random_bytes(16));
    $messageId = '<' . bin2hex(random_bytes(24)) . '@dnr.invalid>';
    $conn->execute_query("UPDATE {$table} SET claim_token = ?, delivery_started_at = NULL,
        smtp_message_id = COALESCE(smtp_message_id, ?) WHERE id = ?", [$token, $messageId, $id]);
    $stored = $conn->execute_query("SELECT smtp_message_id FROM {$table} WHERE id = ?", [$id])->fetch_assoc();
    return ['claim_token' => $token, 'smtp_message_id' => (string) $stored['smtp_message_id']];
}

function startEmailDelivery(mysqli $conn, string $table, int $id, string $token): void
{
    $table = emailDeliveryTable($table);
    $conn->execute_query("UPDATE {$table} SET delivery_started_at = UTC_TIMESTAMP()
        WHERE id = ? AND claim_token = ? AND status = 'processing' AND delivery_started_at IS NULL
        AND processing_started_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)", [$id, $token]);
    if ($conn->affected_rows !== 1) throw new RuntimeException('The delivery claim expired before sending.');
}

function markEmailDeliveryUncertain(mysqli $conn, string $table, int $id, ?string $token): void
{
    $table = emailDeliveryTable($table);
    $conn->execute_query("UPDATE {$table} SET status = 'delivery_uncertain', processing_started_at = NULL,
        last_error = 'Delivery may have succeeded. Check provider records before manually resending.'
        WHERE id = ? AND claim_token <=> ? AND status = 'processing'", [$id, $token]);
}

function expireUncertainEmailDeliveries(mysqli $conn, string $table, int $leaseSeconds): void
{
    $table = emailDeliveryTable($table);
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    $conn->query("UPDATE {$table} SET status = 'delivery_uncertain', processing_started_at = NULL,
        last_error = 'Worker stopped during delivery. Check provider records before manually resending.'
        WHERE status = 'processing' AND delivery_started_at IS NOT NULL
        AND processing_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND)");
}
