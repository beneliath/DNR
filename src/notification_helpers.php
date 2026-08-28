<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

const TASK_DIGEST_WEEKDAYS = 31;
const TASK_DIGEST_WEEKENDS = 96;
const TASK_DIGEST_EVERY_DAY = 127;

/**
 * @return array{overdue: int, today: int, upcoming: int, waiting: int,
 *   closeouts: int, total: int}
 */
function fetchTaskReminderCounts(
    mysqli $conn,
    int $userId,
    string $role,
    ?string $businessDate = null
): array {
    $counts = [
        'overdue' => 0,
        'today' => 0,
        'upcoming' => 0,
        'waiting' => 0,
        'closeouts' => 0,
        'total' => 0,
    ];
    if ($userId < 1) {
        return $counts;
    }

    $businessDate ??= applicationBusinessDate();
    $upcomingDays = applicationWorkflowSetting('task_upcoming_days');
    $taskStatement = $conn->prepare(
        "SELECT
            SUM(status IN ('open', 'in_progress') AND due_date < ?) AS overdue_count,
            SUM(status IN ('open', 'in_progress') AND due_date = ?) AS today_count,
            SUM(status IN ('open', 'in_progress')
                AND due_date > ?
                AND due_date <= DATE_ADD(?, INTERVAL {$upcomingDays} DAY)) AS upcoming_count,
            SUM(status = 'waiting') AS waiting_count
         FROM follow_up_tasks
         WHERE assigned_to = ?
           AND status IN ('open', 'in_progress', 'waiting')"
    );
    if (!$taskStatement) {
        throw new RuntimeException('Unable to prepare work reminder counts.');
    }
    $taskStatement->bind_param(
        'ssssi',
        $businessDate,
        $businessDate,
        $businessDate,
        $businessDate,
        $userId
    );
    $taskStatement->execute();
    $taskCounts = $taskStatement->get_result()->fetch_assoc() ?: [];
    $taskStatement->close();
    foreach (['overdue', 'today', 'upcoming', 'waiting'] as $key) {
        $counts[$key] = (int) ($taskCounts[$key . '_count'] ?? 0);
    }

    if (in_array($role, ['admin', 'editor'], true)) {
        $closeoutStatement = $conn->prepare(
            "SELECT COUNT(*) AS closeout_count
             FROM engagements engagement
             INNER JOIN organizations organization
                ON organization.id = engagement.organization_id
             LEFT JOIN engagement_financial_reports report
                ON report.engagement_id = engagement.id
             WHERE engagement.is_deleted = 0
               AND organization.is_deleted = 0
               AND engagement.lifecycle_status IN ('active', 'completed')
               AND engagement.event_end_date < ?
               AND report.engagement_id IS NULL"
        );
        if (!$closeoutStatement) {
            throw new RuntimeException('Unable to prepare closeout reminder counts.');
        }
        $closeoutStatement->bind_param('s', $businessDate);
        $closeoutStatement->execute();
        $closeoutRow = $closeoutStatement->get_result()->fetch_assoc() ?: [];
        $closeoutStatement->close();
        $counts['closeouts'] = (int) ($closeoutRow['closeout_count'] ?? 0);
    }

    $counts['total'] = $counts['overdue'] + $counts['today']
        + $counts['upcoming'] + $counts['waiting'] + $counts['closeouts'];
    return $counts;
}

function taskDigestDeliveryTimeFromInput(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    if (preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $value) !== 1) {
        throw new InvalidArgumentException('Choose a valid daily work digest delivery time.');
    }
    return $value . ':00';
}

function taskDigestDeliveryTimeInputValue(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    if (preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $value) !== 1) {
        return '07:00';
    }
    return substr($value, 0, 5);
}

function taskDigestDaysFromInput(mixed $values): int
{
    if (!is_array($values)) {
        throw new InvalidArgumentException('Choose at least one daily work digest delivery day.');
    }

    $mask = 0;
    foreach ($values as $value) {
        if (!is_string($value)
            || !in_array($value, ['1', '2', '4', '8', '16', '32', '64'], true)
        ) {
            throw new InvalidArgumentException('Choose valid daily work digest delivery days.');
        }
        $mask |= (int) $value;
    }
    if ($mask < 1) {
        throw new InvalidArgumentException('Choose at least one daily work digest delivery day.');
    }
    return $mask;
}

function taskDigestScheduleIsDue(
    string $deliveryTime,
    int $deliveryDays,
    ?DateTimeImmutable $instant = null
): bool {
    if (preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/', $deliveryTime) !== 1
        || $deliveryDays < 1
        || $deliveryDays > TASK_DIGEST_EVERY_DAY
    ) {
        return false;
    }

    $instant ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $localInstant = $instant->setTimezone(applicationTimezone());
    $dayMask = 1 << ((int) $localInstant->format('N') - 1);
    return ($deliveryDays & $dayMask) !== 0
        && $localInstant->format('H:i:s') >= $deliveryTime;
}

/**
 * @return array{
 *   counts: array{overdue: int, today: int, upcoming: int, waiting: int,
 *     closeouts: int, total: int},
 *   overdue: list<array<string, mixed>>,
 *   today: list<array<string, mixed>>,
 *   upcoming: list<array<string, mixed>>,
 *   waiting: list<array<string, mixed>>,
 *   closeouts: list<array<string, mixed>>
 * }
 */
function fetchDailyTaskDigestData(
    mysqli $conn,
    int $userId,
    string $role,
    string $businessDate
): array {
    $data = [
        'counts' => fetchTaskReminderCounts(
            $conn,
            $userId,
            $role,
            $businessDate
        ),
        'overdue' => [],
        'today' => [],
        'upcoming' => [],
        'waiting' => [],
        'closeouts' => [],
    ];
    $upcomingDays = applicationWorkflowSetting('task_upcoming_days');

    $taskStatement = $conn->prepare(
        "SELECT id, title, status, priority, due_date, waiting_on
         FROM follow_up_tasks
         WHERE assigned_to = ?
           AND (
                status = 'waiting'
                OR (
                    status IN ('open', 'in_progress')
                    AND due_date IS NOT NULL
                    AND due_date <= DATE_ADD(?, INTERVAL {$upcomingDays} DAY)
                )
           )
         ORDER BY
            FIELD(status, 'open', 'in_progress', 'waiting'),
            COALESCE(due_date, '9999-12-31'),
            FIELD(priority, 'urgent', 'high', 'normal', 'low'),
            id
         LIMIT 500"
    );
    if (!$taskStatement) {
        throw new RuntimeException('Unable to prepare daily digest tasks.');
    }
    $taskStatement->bind_param('is', $userId, $businessDate);
    $taskStatement->execute();
    $tasks = $taskStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $taskStatement->close();

    foreach ($tasks as $task) {
        if ($task['status'] === 'waiting') {
            $data['waiting'][] = $task;
            continue;
        }
        $dueDate = (string) ($task['due_date'] ?? '');
        if ($dueDate < $businessDate) {
            $data['overdue'][] = $task;
        } elseif ($dueDate === $businessDate) {
            $data['today'][] = $task;
        } else {
            $data['upcoming'][] = $task;
        }
    }

    if (in_array($role, ['admin', 'editor'], true)) {
        $closeoutStatement = $conn->prepare(
            "SELECT engagement.id, engagement.event_title,
                    engagement.event_end_date, organization.organization_name
             FROM engagements engagement
             INNER JOIN organizations organization
                ON organization.id = engagement.organization_id
             LEFT JOIN engagement_financial_reports report
                ON report.engagement_id = engagement.id
             WHERE engagement.is_deleted = 0
               AND organization.is_deleted = 0
               AND engagement.lifecycle_status IN ('active', 'completed')
               AND engagement.event_end_date < ?
               AND report.engagement_id IS NULL
             ORDER BY engagement.event_end_date, engagement.id
             LIMIT 100"
        );
        if (!$closeoutStatement) {
            throw new RuntimeException('Unable to prepare daily digest closeouts.');
        }
        $closeoutStatement->bind_param('s', $businessDate);
        $closeoutStatement->execute();
        $data['closeouts'] = $closeoutStatement->get_result()->fetch_all(MYSQLI_ASSOC);
        $closeoutStatement->close();
    }

    return $data;
}

function digestDisplayDate(string $businessDate): string
{
    try {
        return (new DateTimeImmutable($businessDate . ' 12:00:00', applicationTimezone()))
            ->format('l, F j, Y');
    } catch (Throwable $exception) {
        return $businessDate;
    }
}

/** @param list<array<string, mixed>> $items */
function appendTaskDigestSection(
    array &$lines,
    string $heading,
    array $items,
    int $total,
    bool $waiting = false
): void {
    if ($total < 1) {
        return;
    }
    $lines[] = '';
    $lines[] = strtoupper($heading) . ' (' . $total . ')';
    foreach (array_slice($items, 0, 12) as $item) {
        $priority = ucfirst((string) ($item['priority'] ?? 'normal'));
        $line = '- [' . $priority . '] ' . trim((string) ($item['title'] ?? 'Task'));
        if ($waiting) {
            $waitingOn = trim((string) ($item['waiting_on'] ?? ''));
            if ($waitingOn !== '') {
                $line .= ' — waiting on: ' . $waitingOn;
            }
        } elseif (!empty($item['due_date'])) {
            $line .= ' — due ' . $item['due_date'];
        }
        $lines[] = $line;
    }
    if ($total > count($items) || $total > 12) {
        $shown = min(12, count($items));
        $lines[] = '- … and ' . max(0, $total - $shown) . ' more';
    }
}

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $digest
 * @return array{recipient: string, subject: string, body: string}
 */
function dailyTaskDigestMessage(array $user, array $digest, string $businessDate): array
{
    $recipient = normalizeAccountEmail($user['email'] ?? '');
    $name = trim((string) ($user['first_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($user['username'] ?? 'there')) ?: 'there';
    }
    $displayDate = digestDisplayDate($businessDate);
    $brandName = applicationBrandName();
    $upcomingDays = applicationWorkflowSetting('task_upcoming_days');
    $counts = is_array($digest['counts'] ?? null) ? $digest['counts'] : [];
    $lines = [
        'Good day, ' . $name . '.',
        '',
        'Here is your ' . $brandName . ' work digest for ' . $displayDate . '.',
    ];

    appendTaskDigestSection(
        $lines,
        'Overdue',
        is_array($digest['overdue'] ?? null) ? $digest['overdue'] : [],
        (int) ($counts['overdue'] ?? 0)
    );
    appendTaskDigestSection(
        $lines,
        'Due today',
        is_array($digest['today'] ?? null) ? $digest['today'] : [],
        (int) ($counts['today'] ?? 0)
    );
    appendTaskDigestSection(
        $lines,
        'Next ' . $upcomingDays . ' days',
        is_array($digest['upcoming'] ?? null) ? $digest['upcoming'] : [],
        (int) ($counts['upcoming'] ?? 0)
    );
    appendTaskDigestSection(
        $lines,
        'Waiting',
        is_array($digest['waiting'] ?? null) ? $digest['waiting'] : [],
        (int) ($counts['waiting'] ?? 0),
        true
    );

    $closeoutTotal = (int) ($counts['closeouts'] ?? 0);
    if ($closeoutTotal > 0) {
        $lines[] = '';
        $lines[] = 'FINANCIAL CLOSEOUTS (' . $closeoutTotal . ')';
        $closeouts = is_array($digest['closeouts'] ?? null) ? $digest['closeouts'] : [];
        foreach (array_slice($closeouts, 0, 12) as $closeout) {
            $title = trim((string) ($closeout['event_title'] ?? ''));
            if ($title === '') {
                $title = (string) ($closeout['organization_name'] ?? 'Engagement');
            }
            $lines[] = '- ' . $title . ' · '
                . (string) ($closeout['organization_name'] ?? 'Organization')
                . ' — ended ' . (string) ($closeout['event_end_date'] ?? '');
        }
        if ($closeoutTotal > count($closeouts) || $closeoutTotal > 12) {
            $shown = min(12, count($closeouts));
            $lines[] = '- … and ' . max(0, $closeoutTotal - $shown) . ' more';
        }
    }

    if ((int) ($counts['total'] ?? 0) === 0) {
        $lines[] = '';
        $lines[] = 'You have no assigned reminders or financial closeouts needing attention.';
    }

    $lines[] = '';
    $lines[] = 'Open your work queue: ' . applicationPublicUrl('tasks.php', [
        'view' => 'my',
    ]);
    if ($closeoutTotal > 0) {
        $lines[] = 'Review closeouts: ' . applicationPublicUrl('dashboard.php')
            . '#financial-closeouts';
    }
    $lines[] = 'Manage this digest: ' . applicationPublicUrl('profile.php');

    return [
        'recipient' => $recipient,
        'subject' => $brandName . ' daily work digest · ' . $businessDate,
        'body' => implode("\n", $lines),
    ];
}

function queueNotificationPayload(
    mysqli $conn,
    int $userId,
    string $notificationType,
    string $digestDate,
    array $message
): bool {
    $recipient = normalizeAccountEmail($message['recipient'] ?? '');
    $json = json_encode([
        'recipient' => $recipient,
        'subject' => (string) ($message['subject'] ?? ''),
        'body' => (string) ($message['body'] ?? ''),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $ciphertext = \Dnr\Security\ApplicationKey::seal($json);
    $recipientHash = hash('sha256', $recipient, true);
    $statement = $conn->prepare(
        'INSERT IGNORE INTO notification_outbox
            (user_id, notification_type, digest_date, recipient_hash,
             payload_ciphertext)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$statement) {
        throw new RuntimeException('Unable to prepare the notification outbox.');
    }
    $statement->bind_param(
        'issss',
        $userId,
        $notificationType,
        $digestDate,
        $recipientHash,
        $ciphertext
    );
    $statement->execute();
    $inserted = $statement->affected_rows === 1;
    $statement->close();
    return $inserted;
}

function queueDueDailyTaskDigests(
    mysqli $conn,
    ?DateTimeImmutable $instant = null
): int {
    $instant ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $businessDate = applicationBusinessDate($instant);
    $userStatement = $conn->prepare(
        "SELECT user.id, user.username, user.first_name, user.email, user.role,
                user.task_digest_time, user.task_digest_days
         FROM users user
         LEFT JOIN notification_outbox outbox
            ON outbox.user_id = user.id
           AND outbox.notification_type = 'daily_task_digest'
           AND outbox.digest_date = ?
         WHERE user.account_status = 'active'
           AND user.task_digest_enabled = 1
           AND user.email IS NOT NULL
           AND user.email <> ''
           AND user.email_verified_at IS NOT NULL
           AND outbox.id IS NULL
         ORDER BY user.id"
    );
    if (!$userStatement) {
        throw new RuntimeException('Unable to prepare daily digest recipients.');
    }
    $userStatement->bind_param('s', $businessDate);
    $userStatement->execute();
    $users = $userStatement->get_result();

    $queued = 0;
    while ($user = $users->fetch_assoc()) {
        if (!taskDigestScheduleIsDue(
            (string) $user['task_digest_time'],
            (int) $user['task_digest_days'],
            $instant
        )) {
            continue;
        }
        $userId = (int) $user['id'];
        $digest = fetchDailyTaskDigestData(
            $conn,
            $userId,
            (string) $user['role'],
            $businessDate
        );
        $message = dailyTaskDigestMessage($user, $digest, $businessDate);
        if (queueNotificationPayload(
            $conn,
            $userId,
            'daily_task_digest',
            $businessDate,
            $message
        )) {
            $queued++;
        }
    }
    $userStatement->close();
    return $queued;
}

/**
 * @return array{id: int, user_id: int, notification_type: string,
 *   digest_date: string, payload_ciphertext: string}|null
 */
function claimQueuedNotificationEmail(
    mysqli $conn,
    string $businessDate,
    int $leaseSeconds = 600
): ?array {
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    $conn->begin_transaction();
    try {
        $discard = $conn->prepare(
            "UPDATE notification_outbox outbox
             INNER JOIN users user ON user.id = outbox.user_id
             SET outbox.status = 'failed', outbox.payload_ciphertext = NULL,
                 outbox.processing_started_at = NULL,
                 outbox.last_error = 'Digest is stale or its recipient is unavailable.'
             WHERE outbox.attempts < 8
               AND outbox.payload_ciphertext IS NOT NULL
               AND (
                    (outbox.status IN ('pending', 'retry')
                        AND outbox.next_attempt_at <= UTC_TIMESTAMP())
                    OR (outbox.status = 'processing'
                        AND outbox.processing_started_at <= DATE_SUB(
                            UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND
                        ))
               )
               AND (
                    outbox.digest_date < ?
                    OR user.account_status <> 'active'
                    OR user.task_digest_enabled <> 1
                    OR (user.task_digest_days & (1 << WEEKDAY(outbox.digest_date))) = 0
                    OR user.email_verified_at IS NULL
                    OR user.email IS NULL
                    OR outbox.recipient_hash <> UNHEX(SHA2(LOWER(TRIM(user.email)), 256))
               )"
        );
        if (!$discard) {
            throw new RuntimeException('Unable to prepare invalid notification cleanup.');
        }
        $discard->bind_param('s', $businessDate);
        $discard->execute();
        $discard->close();

        $statement = $conn->prepare(
            "SELECT outbox.id, outbox.user_id, outbox.notification_type,
                    outbox.digest_date, outbox.payload_ciphertext
             FROM notification_outbox outbox
             INNER JOIN users user ON user.id = outbox.user_id
             WHERE outbox.attempts < 8
               AND outbox.payload_ciphertext IS NOT NULL
               AND outbox.digest_date = ?
               AND user.account_status = 'active'
               AND user.task_digest_enabled = 1
               AND (user.task_digest_days & (1 << WEEKDAY(outbox.digest_date))) <> 0
               AND user.email_verified_at IS NOT NULL
               AND user.email IS NOT NULL
               AND outbox.recipient_hash = UNHEX(SHA2(LOWER(TRIM(user.email)), 256))
               AND (
                    (outbox.status IN ('pending', 'retry')
                        AND outbox.next_attempt_at <= UTC_TIMESTAMP())
                    OR (outbox.status = 'processing'
                        AND outbox.processing_started_at <= DATE_SUB(
                            UTC_TIMESTAMP(), INTERVAL {$leaseSeconds} SECOND
                        ))
             )
             ORDER BY outbox.next_attempt_at, outbox.id
             LIMIT 1 FOR UPDATE OF outbox SKIP LOCKED"
        );
        if (!$statement) {
            throw new RuntimeException('Unable to prepare notification claim.');
        }
        $statement->bind_param('s', $businessDate);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!$row) {
            $conn->commit();
            return null;
        }

        $id = (int) $row['id'];
        $claim = $conn->prepare(
            "UPDATE notification_outbox
             SET status = 'processing', attempts = attempts + 1,
                 processing_started_at = UTC_TIMESTAMP(), last_error = NULL
             WHERE id = ?"
        );
        if (!$claim) {
            throw new RuntimeException('Unable to prepare notification lease.');
        }
        $claim->bind_param('i', $id);
        $claim->execute();
        $claim->close();
        $conn->commit();
        return [
            'id' => $id,
            'user_id' => (int) $row['user_id'],
            'notification_type' => (string) $row['notification_type'],
            'digest_date' => (string) $row['digest_date'],
            'payload_ciphertext' => (string) $row['payload_ciphertext'],
        ];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

/** @return array{recipient: string, subject: string, body: string} */
function decryptQueuedNotificationEmail(string $ciphertext): array
{
    $decoded = json_decode(
        \Dnr\Security\ApplicationKey::open($ciphertext),
        true,
        4,
        JSON_THROW_ON_ERROR
    );
    if (!is_array($decoded)
        || !is_string($decoded['recipient'] ?? null)
        || !is_string($decoded['subject'] ?? null)
        || !is_string($decoded['body'] ?? null)
    ) {
        throw new RuntimeException('The queued notification payload is invalid.');
    }
    return [
        'recipient' => normalizeAccountEmail($decoded['recipient']),
        'subject' => $decoded['subject'],
        'body' => $decoded['body'],
    ];
}

function completeQueuedNotificationEmail(mysqli $conn, int $outboxId): void
{
    $statement = $conn->prepare(
        "UPDATE notification_outbox
         SET status = 'sent', sent_at = UTC_TIMESTAMP(),
             processing_started_at = NULL, payload_ciphertext = NULL,
             last_error = NULL
         WHERE id = ? AND status = 'processing'"
    );
    if (!$statement) {
        throw new RuntimeException('Unable to prepare notification completion.');
    }
    $statement->bind_param('i', $outboxId);
    $statement->execute();
    if ($statement->affected_rows !== 1) {
        $statement->close();
        throw new RuntimeException('The queued notification can no longer be completed.');
    }
    $statement->close();
}

function failQueuedNotificationEmail(
    mysqli $conn,
    int $outboxId,
    Throwable $exception,
    bool $permanent = false
): void {
    $error = mb_substr($exception->getMessage(), 0, 255, 'UTF-8');
    $permanentFlag = $permanent ? 1 : 0;
    $statement = $conn->prepare(
        "UPDATE notification_outbox
         SET status = IF(? OR attempts >= 8, 'failed', 'retry'),
             processing_started_at = NULL, last_error = ?,
             payload_ciphertext = IF(? OR attempts >= 8, NULL, payload_ciphertext),
             next_attempt_at = TIMESTAMPADD(
                 MINUTE, LEAST(1440, CAST(POW(2, attempts) AS UNSIGNED)),
                 UTC_TIMESTAMP()
             )
         WHERE id = ? AND status = 'processing'"
    );
    if (!$statement) {
        throw new RuntimeException('Unable to prepare notification failure handling.');
    }
    $statement->bind_param(
        'isii',
        $permanentFlag,
        $error,
        $permanentFlag,
        $outboxId
    );
    $statement->execute();
    $statement->close();
}
