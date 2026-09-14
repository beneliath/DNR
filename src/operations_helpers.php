<?php

declare(strict_types=1);

function countRecentFailedAuthentications(mysqli $conn): int
{
    $result = $conn->query(
        "SELECT COUNT(*) AS total
         FROM security_audit_log
         WHERE event_category = 'login'
           AND event_type = 'failed_login'
           AND created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR"
    );
    if (!$result) {
        throw new RuntimeException('Unable to load authentication failure metrics.');
    }
    return (int) ($result->fetch_assoc()['total'] ?? 0);
}

function inboundMailboxOperationalStates(mysqli $conn): array
{
    $result = $conn->query("SELECT state.mailbox_label, state.last_checked_at, state.last_imported_at,
        state.last_error, state.failed_at, state.consecutive_failures,
        state.scanned_uid < state.observed_uid AS catching_up,
        state.last_checked_at IS NULL OR state.last_checked_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE AS stale,
        (SELECT COUNT(*) FROM inbound_mail_reconciliation candidate
         WHERE candidate.mailbox_key = state.mailbox_key AND candidate.decision = 'pending') AS reconciliation_count
        FROM inbound_mailbox_state state ORDER BY state.mailbox_label");
    if (!$result) {
        throw new RuntimeException('Unable to load mailbox synchronization status.');
    }
    return $result->fetch_all(MYSQLI_ASSOC);
}
