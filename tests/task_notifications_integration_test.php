<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Task notifications integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/email_helpers.php';
require_once $sourceDirectory . '/notification_helpers.php';

function expectTaskNotificationIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Task notification integration test failed: {$message}");
    }
}

putenv('DNR_2FA_ENCRYPTION_KEY=' . base64_encode(str_repeat('N', 32)));
putenv('DNR_PUBLIC_BASE_URL=https://moed.example.test');
putenv('DNR_REQUIRE_HTTPS=1');
putenv('DNR_TIMEZONE=America/Chicago');

$suffix = bin2hex(random_bytes(5));
$instant = new DateTimeImmutable('2026-08-23 13:00:00', new DateTimeZone('UTC'));
$businessDate = applicationBusinessDate($instant);

$userId = 0;
$organizationId = 0;
$bulkUsernamePattern = '';
try {
    $username = 'digest-' . $suffix;
    $email = $username . '@example.test';
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $role = 'editor';
    $userStatement = $conn->prepare(
        "INSERT INTO users
            (username, email, email_verified_at, password, role, account_status)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, 'active')"
    );
    $userStatement->bind_param('ssss', $username, $email, $password, $role);
    $userStatement->execute();
    $userId = (int) $conn->insert_id;
    $userStatement->close();
    $defaultPreferences = $conn->query(
        "SELECT task_digest_enabled, task_digest_time, task_digest_days
         FROM users WHERE id = {$userId}"
    )->fetch_assoc();
    expectTaskNotificationIntegration(
        (int) $defaultPreferences['task_digest_enabled'] === 1
            && (string) $defaultPreferences['task_digest_time'] === '07:00:00'
            && (int) $defaultPreferences['task_digest_days'] === TASK_DIGEST_WEEKDAYS,
        'a new user should default to an enabled weekday digest at 7am.'
    );
    $conn->query(
        "UPDATE users
         SET task_digest_time = '08:00:00', task_digest_days = 96
         WHERE id = {$userId}"
    );

    $organizationName = 'Digest Organization ' . $suffix;
    $organizationStatement = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    $organizationStatement->bind_param('s', $organizationName);
    $organizationStatement->execute();
    $organizationId = (int) $conn->insert_id;
    $organizationStatement->close();

    $eventTitle = 'Digest Closeout ' . $suffix;
    $eventStatement = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status, lifecycle_status, is_deleted)
         VALUES (?, ?, '2026-08-18', '2026-08-20', 'conference',
                 'confirmed', 'completed', 0)"
    );
    $eventStatement->bind_param('is', $organizationId, $eventTitle);
    $eventStatement->execute();
    $eventStatement->close();

    $taskStatement = $conn->prepare(
        "INSERT INTO follow_up_tasks
            (title, status, priority, due_date, waiting_on, subject_type,
             assigned_to, created_by)
         VALUES (?, ?, ?, ?, ?, 'general', ?, ?)"
    );
    foreach ([
        ['Digest overdue', 'open', 'urgent', '2026-08-22', null],
        ['Digest today', 'in_progress', 'high', '2026-08-23', null],
        ['Digest upcoming', 'open', 'normal', '2026-08-27', null],
        ['Digest waiting', 'waiting', 'normal', null, 'venue confirmation'],
        ['Digest far future', 'open', 'normal', '2027-08-23', null],
        ['Digest undated', 'open', 'normal', null, null],
    ] as [$title, $status, $priority, $dueDate, $waitingOn]) {
        $taskStatement->bind_param(
            'sssssii',
            $title,
            $status,
            $priority,
            $dueDate,
            $waitingOn,
            $userId,
            $userId
        );
        $taskStatement->execute();
    }
    $taskStatement->close();

    expectTaskNotificationIntegration(
        queueDueDailyTaskDigests($conn, $instant->modify('-1 minute')) === 0,
        'a digest should remain pending until the user-selected local delivery time.'
    );
    expectTaskNotificationIntegration(
        queueDueDailyTaskDigests($conn, $instant) === 1
            && queueDueDailyTaskDigests($conn, $instant) === 0,
        'one idempotent digest should be queued on a selected day after its delivery time.'
    );

    $queued = $conn->query(
        "SELECT id, status, payload_ciphertext
         FROM notification_outbox
         WHERE user_id = {$userId} AND digest_date = '{$businessDate}'"
    )->fetch_assoc();
    expectTaskNotificationIntegration(
        $queued !== null
            && $queued['status'] === 'pending'
            && !str_contains((string) $queued['payload_ciphertext'], 'Digest overdue'),
        'the separate notification outbox should retain only encrypted pending content.'
    );

    $claimed = claimQueuedNotificationEmail($conn, $businessDate);
    expectTaskNotificationIntegration(
        $claimed !== null && $claimed['user_id'] === $userId,
        'a valid same-day digest should be claimable once.'
    );
    $message = decryptQueuedNotificationEmail((string) $claimed['payload_ciphertext']);
    expectTaskNotificationIntegration(
        $message['recipient'] === $email
            && str_contains($message['body'], 'DASHBOARD SUMMARY')
            && str_contains($message['body'], '- My Active Work: 6')
            && str_contains($message['body'], '- My Overdue Work: 1')
            && str_contains($message['body'], '- Due Today: 1')
            && str_contains($message['body'], 'UPCOMING ENGAGEMENTS (')
            && str_contains($message['body'], 'MY WORK (6)')
            && str_contains($message['body'], 'EVENT READINESS (')
            && str_contains($message['body'], 'FINANCIAL CLOSEOUTS (')
            && str_contains($message['body'], $eventTitle)
            && str_contains($message['body'], 'Digest far future')
            && str_contains($message['body'], 'Digest undated')
            && is_string($message['html_body'])
            && str_starts_with($message['html_body'], '<!doctype html>')
            && str_contains($message['html_body'], 'name="color-scheme" content="light only"')
            && str_contains($message['html_body'], '6 active tasks, 1 overdue, and 1 due today.')
            && str_contains($message['html_body'], 'Upcoming Engagements')
            && str_contains($message['html_body'], 'My Work')
            && str_contains($message['html_body'], 'Needs Attention')
            && str_contains($message['html_body'], 'Digest overdue')
            && str_contains($message['html_body'], 'Digest far future')
            && str_contains($message['html_body'], 'Digest undated')
            && str_contains($message['html_body'], 'bgcolor="#ffe8ee"')
            && str_contains($message['html_body'], 'bgcolor="#d92d20"')
            && str_contains($message['html_body'], 'bgcolor="#e4f2ff"')
            && str_contains($message['html_body'], 'bgcolor="#2563eb"')
            && str_contains($message['html_body'], 'edit_task.php?id='),
        'the daily digest should retain its text fallback and an encrypted linked Dashboard HTML alternative.'
    );
    completeQueuedNotificationEmail($conn, (int) $claimed['id']);
    $completed = $conn->query(
        "SELECT status, payload_ciphertext FROM notification_outbox
         WHERE id = {$claimed['id']}"
    )->fetch_assoc();
    expectTaskNotificationIntegration(
        $completed['status'] === 'sent' && $completed['payload_ciphertext'] === null,
        'successful delivery should erase the encrypted payload.'
    );

    $nextInstant = $instant->modify('+1 day');
    $nextDate = applicationBusinessDate($nextInstant);
    expectTaskNotificationIntegration(
        queueDueDailyTaskDigests($conn, $nextInstant) === 0,
        'an unselected weekday should not receive a digest.'
    );
    $conn->query("UPDATE users SET task_digest_days = 31 WHERE id = {$userId}");
    expectTaskNotificationIntegration(
        queueDueDailyTaskDigests($conn, $nextInstant) === 1,
        'a newly selected weekday should receive its own digest record.'
    );
    $conn->query("UPDATE users SET task_digest_enabled = 0 WHERE id = {$userId}");
    expectTaskNotificationIntegration(
        claimQueuedNotificationEmail($conn, $nextDate) === null,
        'disabling the preference should invalidate queued undelivered mail.'
    );
    $disabled = $conn->query(
        "SELECT status, payload_ciphertext FROM notification_outbox
         WHERE user_id = {$userId} AND digest_date = '{$nextDate}'"
    )->fetch_assoc();
    expectTaskNotificationIntegration(
        $disabled['status'] === 'failed' && $disabled['payload_ciphertext'] === null,
        'invalidated digest content should be erased before it can be sent.'
    );

    $bulkPrefix = 'digest-bulk-' . $suffix . '-';
    $bulkUsernamePattern = $bulkPrefix . '%';
    $bulkRole = 'reviewer';
    $bulkPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $bulkUserStatement = $conn->prepare(
        "INSERT INTO users
            (username, email, email_verified_at, password, role, account_status,
             task_digest_enabled, task_digest_time, task_digest_days)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, 'active', 1, '00:00:00', 127)"
    );
    $bulkValidUsers = 105;
    for ($index = 0; $index <= $bulkValidUsers; $index++) {
        $bulkUsername = $bulkPrefix . $index;
        $bulkEmail = $index === 0
            ? 'not-an-email-' . $suffix
            : $bulkUsername . '@example.test';
        $bulkUserStatement->bind_param(
            'ssss',
            $bulkUsername,
            $bulkEmail,
            $bulkPassword,
            $bulkRole
        );
        $bulkUserStatement->execute();
    }
    $bulkUserStatement->close();

    $bulkQueued = queueDueDailyTaskDigests($conn, $instant);
    $bulkOutboxStatement = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM notification_outbox outbox
         INNER JOIN users user ON user.id = outbox.user_id
         WHERE user.username LIKE ?
           AND outbox.notification_type = 'daily_task_digest'
           AND outbox.digest_date = ?
           AND outbox.status = 'pending'"
    );
    $bulkOutboxStatement->bind_param('ss', $bulkUsernamePattern, $businessDate);
    $bulkOutboxStatement->execute();
    $bulkOutboxTotal = (int) $bulkOutboxStatement->get_result()->fetch_assoc()['total'];
    $bulkOutboxStatement->close();
    expectTaskNotificationIntegration(
        $bulkQueued === $bulkValidUsers && $bulkOutboxTotal === $bulkValidUsers,
        'one scheduling pass should drain more than 100 recipients while a malformed early recipient cannot starve later user IDs.'
    );

    $starvationPrefix = $bulkPrefix . 'starvation-';
    $starvationPattern = $starvationPrefix . '%';
    for ($index = 0; $index < 4; $index++) {
        $bulkUsername = $starvationPrefix . $index;
        $bulkEmail = $index < 3
            ? 'invalid-starvation-' . $suffix . '-' . $index
            : $bulkUsername . '@example.test';
        $bulkUserStatement = $conn->prepare(
            "INSERT INTO users
                (username, email, email_verified_at, password, role, account_status,
                 task_digest_enabled, task_digest_time, task_digest_days)
             VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, 'active', 1, '00:00:00', 127)"
        );
        $bulkUserStatement->bind_param(
            'ssss',
            $bulkUsername,
            $bulkEmail,
            $bulkPassword,
            $bulkRole
        );
        $bulkUserStatement->execute();
        $bulkUserStatement->close();
    }
    $firstBoundedPass = queueDueDailyTaskDigests($conn, $instant, 2, 2, 20.0);
    $secondBoundedPass = queueDueDailyTaskDigests($conn, $instant, 2, 2, 20.0);
    $starvationState = $conn->prepare(
        "SELECT
            SUM(outbox.status = 'failed') AS failed_total,
            SUM(outbox.status = 'pending') AS pending_total
         FROM notification_outbox outbox
         INNER JOIN users user ON user.id = outbox.user_id
         WHERE user.username LIKE ? AND outbox.digest_date = ?"
    );
    $starvationState->bind_param('ss', $starvationPattern, $businessDate);
    $starvationState->execute();
    $starvationTotals = $starvationState->get_result()->fetch_assoc();
    $starvationState->close();
    expectTaskNotificationIntegration(
        $firstBoundedPass === 0
            && $secondBoundedPass === 1
            && (int) $starvationTotals['failed_total'] === 3
            && (int) $starvationTotals['pending_total'] === 1,
        'per-day failure markers should let later user IDs progress across bounded scheduling calls.'
    );

} finally {
    if ($userId > 0) {
        $conn->query("DELETE FROM follow_up_tasks WHERE created_by = {$userId}");
    }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM engagements WHERE organization_id = {$organizationId}");
        $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    }
    if ($bulkUsernamePattern !== '') {
        $bulkCleanup = $conn->prepare('DELETE FROM users WHERE username LIKE ?');
        $bulkCleanup->bind_param('s', $bulkUsernamePattern);
        $bulkCleanup->execute();
        $bulkCleanup->close();
    }
    if ($userId > 0) {
        $conn->query("DELETE FROM users WHERE id = {$userId}");
    }
    putenv('DNR_2FA_ENCRYPTION_KEY');
    putenv('DNR_PUBLIC_BASE_URL');
    putenv('DNR_REQUIRE_HTTPS');
    putenv('DNR_TIMEZONE');
}

echo "Task notifications integration tests passed.\n";
