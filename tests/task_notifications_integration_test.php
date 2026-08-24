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
putenv('DNR_TASK_DIGEST_HOUR=7');

$suffix = bin2hex(random_bytes(5));
$instant = new DateTimeImmutable('2026-08-23 13:00:00', new DateTimeZone('UTC'));
$businessDate = applicationBusinessDate($instant);

$userId = 0;
$organizationId = 0;
try {
    $username = 'digest-' . $suffix;
    $email = $username . '@example.test';
    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $role = 'editor';
    $userStatement = $conn->prepare(
        "INSERT INTO users
            (username, email, email_verified_at, task_digest_enabled,
             password, role, account_status)
         VALUES (?, ?, UTC_TIMESTAMP(), 1, ?, ?, 'active')"
    );
    $userStatement->bind_param('ssss', $username, $email, $password, $role);
    $userStatement->execute();
    $userId = (int) $conn->insert_id;
    $userStatement->close();

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
        queueDueDailyTaskDigests($conn, $instant) === 1
            && queueDueDailyTaskDigests($conn, $instant) === 0,
        'one idempotent digest should be queued for an opted-in verified user each day.'
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
            && str_contains($message['body'], 'OVERDUE (1)')
            && str_contains($message['body'], 'DUE TODAY (1)')
            && str_contains($message['body'], 'NEXT 7 DAYS (1)')
            && str_contains($message['body'], 'WAITING (1)')
            && str_contains($message['body'], 'FINANCIAL CLOSEOUTS (1)'),
        'the daily digest should contain every requested reminder category.'
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
        queueDueDailyTaskDigests($conn, $nextInstant) === 1,
        'the next business day should receive its own digest record.'
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

} finally {
    if ($userId > 0) {
        $conn->query("DELETE FROM follow_up_tasks WHERE created_by = {$userId}");
    }
    if ($organizationId > 0) {
        $conn->query("DELETE FROM engagements WHERE organization_id = {$organizationId}");
        $conn->query("DELETE FROM organizations WHERE id = {$organizationId}");
    }
    if ($userId > 0) {
        $conn->query("DELETE FROM users WHERE id = {$userId}");
    }
    putenv('DNR_2FA_ENCRYPTION_KEY');
    putenv('DNR_PUBLIC_BASE_URL');
    putenv('DNR_REQUIRE_HTTPS');
    putenv('DNR_TIMEZONE');
    putenv('DNR_TASK_DIGEST_HOUR');
}

echo "Task notifications integration tests passed.\n";
