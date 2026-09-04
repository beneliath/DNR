<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/dashboard_helpers.php';
require_once __DIR__ . '/follow_up_task_helpers.php';
require_once __DIR__ . '/daily_digest_email.php';

const TASK_DIGEST_WEEKDAYS = 31;
const TASK_DIGEST_WEEKENDS = 96;
const TASK_DIGEST_EVERY_DAY = 127;

/**
 * @return array{active: int, dashboard_overdue: int, dashboard_today: int,
 *   overdue: int, today: int, upcoming: int, waiting: int, closeouts: int,
 *   total: int}
 */
function fetchTaskReminderCounts(
    mysqli $conn,
    int $userId,
    string $role,
    ?string $businessDate = null,
    ?int $sharedCloseoutCount = null
): array {
    $counts = [
        'active' => 0,
        'dashboard_overdue' => 0,
        'dashboard_today' => 0,
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
            COUNT(*) AS active_count,
            SUM(due_date < ?) AS dashboard_overdue_count,
            SUM(due_date = ?) AS dashboard_today_count,
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
        'ssssssi',
        $businessDate,
        $businessDate,
        $businessDate,
        $businessDate,
        $businessDate,
        $businessDate,
        $userId
    );
    $taskStatement->execute();
    $taskCounts = $taskStatement->get_result()->fetch_assoc() ?: [];
    $taskStatement->close();
    $counts['active'] = (int) ($taskCounts['active_count'] ?? 0);
    $counts['dashboard_overdue'] = (int) ($taskCounts['dashboard_overdue_count'] ?? 0);
    $counts['dashboard_today'] = (int) ($taskCounts['dashboard_today_count'] ?? 0);
    foreach (['overdue', 'today', 'upcoming', 'waiting'] as $key) {
        $counts[$key] = (int) ($taskCounts[$key . '_count'] ?? 0);
    }

    if (in_array($role, ['admin', 'editor'], true)) {
        if ($sharedCloseoutCount !== null) {
            $counts['closeouts'] = max(0, $sharedCloseoutCount);
        } else {
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
    }

    $counts['total'] = $counts['overdue'] + $counts['today']
        + $counts['upcoming'] + $counts['waiting'] + $counts['closeouts'];
    return $counts;
}

/** @return array{count: int, items: list<array<string, mixed>>} */
function fetchDailyTaskDigestCloseouts(mysqli $conn, string $businessDate): array
{
    $items = fetchDashboardFinancialCloseouts($conn, $businessDate);
    return [
        'count' => $items === [] ? 0 : (int) ($items[0]['dashboard_total'] ?? 0),
        'items' => $items,
    ];
}

/**
 * The schedule, readiness, closeout, and inbound-review data is identical for
 * all recipients on a business day. Fetch it once per scheduler pass so a rich
 * digest has the same bounded-query behavior as the Dashboard itself.
 *
 * @return array{
 *   upcoming_count: int,
 *   upcoming_engagements: list<array<string, mixed>>,
 *   readiness_items: list<array<string, mixed>>,
 *   financial_closeout_count: int,
 *   financial_closeouts: list<array<string, mixed>>,
 *   inbound_review_count: int
 * }
 */
function fetchDailyTaskDigestSharedDashboardData(
    mysqli $conn,
    string $businessDate
): array {
    $dashboardUpcomingDays = applicationWorkflowSetting('dashboard_upcoming_days');
    try {
        $windowEnd = (new DateTimeImmutable(
            $businessDate . ' 12:00:00',
            applicationTimezone()
        ))->modify('+' . $dashboardUpcomingDays . ' days')->format('Y-m-d');
    } catch (Throwable $exception) {
        throw new InvalidArgumentException('Choose a valid digest business date.', 0, $exception);
    }

    $upcoming = fetchDashboardUpcomingEngagements(
        $conn,
        $businessDate,
        $windowEnd
    );
    $displayedUpcoming = array_slice($upcoming, 0, 8);
    $readiness = [];
    foreach ($upcoming as $engagement) {
        $issues = dashboardEngagementReadinessIssues($engagement);
        if ($issues === []) {
            continue;
        }
        $engagement['readiness_issues'] = $issues;
        $readiness[] = $engagement;
        if (count($readiness) === 8) {
            break;
        }
    }

    $closeouts = fetchDailyTaskDigestCloseouts($conn, $businessDate);
    return [
        'upcoming_count' => $upcoming === []
            ? 0
            : (int) ($upcoming[0]['dashboard_total'] ?? 0),
        'upcoming_engagements' => $displayedUpcoming,
        'readiness_items' => $readiness,
        'financial_closeout_count' => (int) $closeouts['count'],
        'financial_closeouts' => $closeouts['items'],
        'inbound_review_count' => fetchDashboardInboundReviewCount($conn),
    ];
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
 *   counts: array{active: int, dashboard_overdue: int, dashboard_today: int,
 *     overdue: int, today: int, upcoming: int, waiting: int, closeouts: int,
 *     total: int},
 *   overdue: list<array<string, mixed>>,
 *   today: list<array<string, mixed>>,
 *   upcoming: list<array<string, mixed>>,
 *   waiting: list<array<string, mixed>>,
 *   undated: list<array<string, mixed>>,
 *   future: list<array<string, mixed>>,
 *   closeouts: list<array<string, mixed>>,
 *   dashboard: array<string, mixed>
 * }
 */
function fetchDailyTaskDigestData(
    mysqli $conn,
    int $userId,
    string $role,
    string $businessDate,
    ?array $sharedDashboard = null
): array {
    $canReviewCloseouts = in_array($role, ['admin', 'editor'], true);
    $sharedDashboard ??= fetchDailyTaskDigestSharedDashboardData($conn, $businessDate);
    $counts = fetchTaskReminderCounts(
        $conn,
        $userId,
        $role,
        $businessDate,
        $canReviewCloseouts
            ? (int) ($sharedDashboard['financial_closeout_count'] ?? 0)
            : null
    );
    $data = [
        'counts' => $counts,
        'overdue' => [],
        'today' => [],
        'upcoming' => [],
        'waiting' => [],
        'undated' => [],
        'future' => [],
        'closeouts' => [],
        'dashboard' => [],
    ];
    $upcomingDays = applicationWorkflowSetting('task_upcoming_days');

    $taskStatement = $conn->prepare(
        "SELECT id, title, status, priority, due_date, waiting_on,
                subject_type, engagement_id, organization_id, contact_id, inquiry_id,
                engagement_label, organization_label, contact_label, inquiry_label,
                digest_section
         FROM (
            SELECT categorized.*,
                   ROW_NUMBER() OVER (
                       PARTITION BY digest_section
                       ORDER BY COALESCE(due_date, '9999-12-31'),
                                FIELD(priority, 'urgent', 'high', 'normal', 'low'), id
                   ) AS digest_rank
            FROM (
                SELECT task.id, task.title, task.status, task.priority,
                       task.due_date, task.waiting_on, task.subject_type,
                       task.engagement_id, task.organization_id, task.contact_id,
                       task.inquiry_id,
                       COALESCE(NULLIF(TRIM(engagement.event_title), ''),
                                engagement_organization.organization_name)
                           AS engagement_label,
                       organization.organization_name AS organization_label,
                       CONCAT(contact.contact_last_name, ', ', contact.contact_first_name)
                           AS contact_label,
                       inquiry.title AS inquiry_label,
                       CASE
                           WHEN task.status = 'waiting' THEN 'waiting'
                           WHEN task.due_date IS NULL THEN 'undated'
                           WHEN task.due_date < ? THEN 'overdue'
                           WHEN task.due_date = ? THEN 'today'
                           WHEN task.due_date <= DATE_ADD(
                               ?, INTERVAL {$upcomingDays} DAY
                           ) THEN 'upcoming'
                           ELSE 'future'
                       END AS digest_section
                FROM follow_up_tasks task
                LEFT JOIN engagements engagement
                  ON engagement.id = task.engagement_id
                LEFT JOIN organizations engagement_organization
                  ON engagement_organization.id = engagement.organization_id
                LEFT JOIN organizations organization
                  ON organization.id = task.organization_id
                LEFT JOIN contacts contact
                  ON contact.id = task.contact_id
                LEFT JOIN booking_inquiries inquiry
                  ON inquiry.id = task.inquiry_id
                WHERE task.assigned_to = ?
                  AND task.status IN ('open', 'in_progress', 'waiting')
            ) categorized
         ) ranked
         WHERE digest_rank <= 12
         ORDER BY
            FIELD(
                digest_section,
                'overdue', 'today', 'upcoming', 'waiting', 'undated', 'future'
            ),
            COALESCE(due_date, '9999-12-31'),
            FIELD(priority, 'urgent', 'high', 'normal', 'low'),
            id"
    );
    if (!$taskStatement) {
        throw new RuntimeException('Unable to prepare daily digest tasks.');
    }
    $taskStatement->bind_param(
        'sssi',
        $businessDate,
        $businessDate,
        $businessDate,
        $userId
    );
    $taskStatement->execute();
    $tasks = $taskStatement->get_result()->fetch_all(MYSQLI_ASSOC);
    $taskStatement->close();

    $dashboardTasks = [];
    foreach ($tasks as $task) {
        $section = (string) $task['digest_section'];
        unset($task['digest_section']);
        $data[$section][] = $task;
        $dashboardTasks[] = $task;
    }

    if ($canReviewCloseouts) {
        $data['closeouts'] = is_array($sharedDashboard['financial_closeouts'] ?? null)
            ? $sharedDashboard['financial_closeouts']
            : [];
    }

    $priorityOrder = ['urgent' => 0, 'high' => 1, 'normal' => 2, 'low' => 3];
    usort($dashboardTasks, static function (array $left, array $right) use ($priorityOrder): int {
        $leftDate = trim((string) ($left['due_date'] ?? '')) ?: '9999-12-31';
        $rightDate = trim((string) ($right['due_date'] ?? '')) ?: '9999-12-31';
        $dateOrder = $leftDate <=> $rightDate;
        if ($dateOrder !== 0) {
            return $dateOrder;
        }
        $priority = ($priorityOrder[(string) ($left['priority'] ?? '')] ?? 4)
            <=> ($priorityOrder[(string) ($right['priority'] ?? '')] ?? 4);
        return $priority !== 0
            ? $priority
            : ((int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0));
    });
    $data['dashboard'] = array_merge($sharedDashboard, [
        'task_summary' => [
            'active' => $counts['active'],
            'overdue' => $counts['dashboard_overdue'],
            'today' => $counts['dashboard_today'],
        ],
        'my_tasks' => array_slice($dashboardTasks, 0, 8),
    ]);

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

/**
 * @param array<string, mixed> $user
 * @param array<string, mixed> $digest
 * @return array{recipient: string, subject: string, body: string, html_body: string}
 */
function dailyTaskDigestMessage(array $user, array $digest, string $businessDate): array
{
    $recipient = normalizeAccountEmail($user['email'] ?? '');
    $brandName = applicationBrandName();

    return [
        'recipient' => $recipient,
        'subject' => $brandName . ' daily work digest · ' . $businessDate,
        'body' => renderDailyTaskDigestText($user, $digest, $businessDate),
        'html_body' => renderDailyTaskDigestHtml($user, $digest, $businessDate),
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
        'html_body' => is_string($message['html_body'] ?? null)
            ? $message['html_body']
            : null,
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

function recordDailyTaskDigestSchedulingFailure(
    mysqli $conn,
    int $userId,
    string $digestDate,
    string $email,
    Throwable $exception
): void {
    $recipientHash = hash('sha256', strtolower(trim($email)), true);
    $error = mb_substr('Scheduling failed: ' . $exception->getMessage(), 0, 255, 'UTF-8');
    $statement = $conn->prepare(
        "INSERT IGNORE INTO notification_outbox
            (user_id, notification_type, digest_date, recipient_hash,
             payload_ciphertext, status, last_error)
         VALUES (?, 'daily_task_digest', ?, ?, NULL, 'failed', ?)"
    );
    if (!$statement) {
        throw new RuntimeException('Unable to prepare a daily digest scheduling failure.');
    }
    $statement->bind_param('isss', $userId, $digestDate, $recipientHash, $error);
    $statement->execute();
    $statement->close();
}

function queueDueDailyTaskDigests(
    mysqli $conn,
    ?DateTimeImmutable $instant = null,
    int $pageSize = 100,
    int $maximumRecipients = 5000,
    float $maximumSeconds = 20.0
): int {
    $instant ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $businessDate = applicationBusinessDate($instant);
    $localInstant = $instant->setTimezone(applicationTimezone());
    $deliveryCutoff = $localInstant->format('H:i:s');
    $deliveryDayMask = 1 << ((int) $localInstant->format('N') - 1);
    $pageSize = max(1, min(500, $pageSize));
    $maximumRecipients = max($pageSize, min(50000, $maximumRecipients));
    $maximumSeconds = max(1.0, min(60.0, $maximumSeconds));
    $deadline = hrtime(true) + (int) round($maximumSeconds * 1_000_000_000);
    $userStatement = $conn->prepare(
        "SELECT user.id, user.username, user.first_name, user.email, user.role,
                user.task_digest_time, user.task_digest_days
         FROM users user
         LEFT JOIN notification_outbox outbox
            ON outbox.user_id = user.id
           AND outbox.notification_type = 'daily_task_digest'
           AND outbox.digest_date = ?
         WHERE user.account_status = 'active'
           AND user.id > ?
           AND user.task_digest_enabled = 1
           AND user.email IS NOT NULL
           AND user.email <> ''
           AND user.email_verified_at IS NOT NULL
           AND user.task_digest_time <= ?
           AND (user.task_digest_days & ?) <> 0
           AND outbox.id IS NULL
         ORDER BY user.id
         LIMIT ?"
    );
    if (!$userStatement) {
        throw new RuntimeException('Unable to prepare daily digest recipients.');
    }

    $queued = 0;
    $scanned = 0;
    $afterUserId = 0;
    $sharedDashboard = null;
    try {
        while ($scanned < $maximumRecipients && hrtime(true) < $deadline) {
            $pageLimit = min($pageSize, $maximumRecipients - $scanned);
            $userStatement->bind_param(
                'sisii',
                $businessDate,
                $afterUserId,
                $deliveryCutoff,
                $deliveryDayMask,
                $pageLimit
            );
            $userStatement->execute();
            $users = $userStatement->get_result()->fetch_all(MYSQLI_ASSOC);
            if ($users === []) {
                break;
            }

            foreach ($users as $user) {
                if (hrtime(true) >= $deadline) {
                    break 2;
                }
                $userId = (int) $user['id'];
                $afterUserId = $userId;
                $scanned++;
                try {
                    $sharedDashboard ??= fetchDailyTaskDigestSharedDashboardData(
                        $conn,
                        $businessDate
                    );
                    $digest = fetchDailyTaskDigestData(
                        $conn,
                        $userId,
                        (string) $user['role'],
                        $businessDate,
                        $sharedDashboard
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
                } catch (Throwable $exception) {
                    applicationLog('error', 'Unable to schedule daily work digest for user', [
                        'user_id' => $userId,
                        'error' => $exception->getMessage(),
                    ]);
                    try {
                        // A per-day terminal marker keeps one malformed account
                        // from consuming every bounded scan while trying again
                        // automatically on the next selected digest date.
                        recordDailyTaskDigestSchedulingFailure(
                            $conn,
                            $userId,
                            $businessDate,
                            (string) ($user['email'] ?? ''),
                            $exception
                        );
                    } catch (Throwable $recordException) {
                        applicationLog('error', 'Unable to record daily digest scheduling failure', [
                            'user_id' => $userId,
                            'error' => $recordException->getMessage(),
                        ]);
                    }
                }
            }

            if (count($users) < $pageLimit) {
                break;
            }
        }
    } finally {
        $userStatement->close();
    }
    return $queued;
}

function maintainQueuedNotificationEmail(
    mysqli $conn,
    string $businessDate,
    int $leaseSeconds = 600
): void {
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
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
}

/**
 * @return array{id: int, user_id: int, notification_type: string,
 *   digest_date: string, payload_ciphertext: string}|null
 */
function claimQueuedNotificationEmail(
    mysqli $conn,
    string $businessDate,
    int $leaseSeconds = 600,
    bool $performMaintenance = true
): ?array {
    $leaseSeconds = max(60, min(3600, $leaseSeconds));
    if ($performMaintenance) {
        maintainQueuedNotificationEmail($conn, $businessDate, $leaseSeconds);
    }
    $conn->begin_transaction();
    try {
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

/** @return array{recipient: string, subject: string, body: string, html_body: ?string} */
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
        || (isset($decoded['html_body']) && !is_string($decoded['html_body']))
    ) {
        throw new RuntimeException('The queued notification payload is invalid.');
    }
    return [
        'recipient' => normalizeAccountEmail($decoded['recipient']),
        'subject' => $decoded['subject'],
        'body' => $decoded['body'],
        'html_body' => isset($decoded['html_body']) ? $decoded['html_body'] : null,
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
