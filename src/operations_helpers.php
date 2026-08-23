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
