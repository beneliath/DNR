<?php

declare(strict_types=1);

/** Fetch a complete, bounded queue page with literal-text search and stable ordering. */
function fetchInboundMailQueue(mysqli $conn, string $statusFilter, string $queueSearch, string $queueSort, int $queuePage): array
{
    if (!in_array($statusFilter, ['review', 'pending', 'processing', 'failed', 'processed', 'rejected', 'all'], true)) {
        throw new InvalidArgumentException('Choose a valid inbox status.');
    }
    $queuePage = max(1, $queuePage);
    $queueSearch = mb_substr(trim($queueSearch), 0, 200);
    $queueClauses = [];
    $queueParameters = [];
    if ($statusFilter !== 'all') {
        $queueClauses[] = 'status = ?';
        $queueParameters[] = $statusFilter;
    }
    if ($queueSearch !== '') {
        // LOCATE treats %, _ and backslashes as literal text, not SQL wildcards.
        $queueClauses[] = '(LOCATE(?, subject) > 0 OR LOCATE(?, sender_address) > 0 OR LOCATE(?, sender_name) > 0 OR LOCATE(?, body_text) > 0)';
        array_push($queueParameters, $queueSearch, $queueSearch, $queueSearch, $queueSearch);
    }
    $queueWhere = $queueClauses === [] ? '' : ' WHERE ' . implode(' AND ', $queueClauses);
    $queueCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM inbound_email_messages' . $queueWhere);
    if ($queueParameters !== []) {
        $queueCountStmt->bind_param(str_repeat('s', count($queueParameters)), ...$queueParameters);
    }
    $queueCountStmt->execute();
    $queueTotal = (int) $queueCountStmt->get_result()->fetch_assoc()['total'];
    $queueCountStmt->close();
    $queuePageSize = 25;
    $queuePages = max(1, (int) ceil($queueTotal / $queuePageSize));
    $queuePage = min($queuePage, $queuePages);
    $queueOffset = ($queuePage - 1) * $queuePageSize;
    $queueDirection = $queueSort === 'oldest' ? 'ASC' : 'DESC';
    $messageStmt = $conn->prepare(
        'SELECT id, sender_name, sender_address, subject, status, review_reason, received_at, processed_at
         FROM inbound_email_messages' . $queueWhere .
        ' ORDER BY received_at ' . $queueDirection . ', id ' . $queueDirection . ' LIMIT ? OFFSET ?'
    );
    $messageParameters = array_merge($queueParameters, [$queuePageSize, $queueOffset]);
    $messageStmt->bind_param(str_repeat('s', count($queueParameters)) . 'ii', ...$messageParameters);
    $messageStmt->execute();
    $messages = $messageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $messageStmt->close();

    return ['messages' => $messages, 'total' => $queueTotal, 'page' => $queuePage,
            'pages' => $queuePages, 'offset' => $queueOffset, 'page_size' => $queuePageSize];
}
