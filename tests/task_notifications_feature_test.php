<?php

declare(strict_types=1);

function expectTaskNotificationsFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Task notifications feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $contents;
};

$migration = $read('migrations/20260823_add_task_notifications.sql');
$reminderIndexMigration = $read('migrations/20260824_optimize_task_reminders.sql');
$order = $read('migrations/order.txt');
$helpers = $read('src/notification_helpers.php');
$worker = $read('scripts/process_email_outbox.php');
$profile = $read('src/profile.php');
$header = $read('src/templates/header.php');
$tasks = $read('src/tasks.php');
$styles = $read('src/assets/css/modern.css');
$grants = $read('scripts/configure_database_privileges.sh');
$smtp = $read('docker-compose.smtp.yaml');

expectTaskNotificationsFeature(
    str_contains($migration, 'task_digest_enabled TINYINT(1) NOT NULL DEFAULT 0')
        && str_contains($migration, 'CREATE TABLE notification_outbox')
        && str_contains($migration, 'payload_ciphertext MEDIUMTEXT')
        && str_contains($migration, 'recipient_hash BINARY(32)')
        && str_contains($migration, 'uq_notification_outbox_daily_digest')
        && str_contains($migration, 'ON DELETE CASCADE')
        && str_contains($order, '20260823_add_task_notifications.sql'),
    'the schema should provide opt-in preferences and a separate idempotent encrypted notification outbox.'
);
expectTaskNotificationsFeature(
    str_contains(
        $reminderIndexMigration,
        'idx_follow_up_task_assignee_queue'
    )
        && str_contains(
            $reminderIndexMigration,
            '(assigned_to, status, due_date, priority, id)'
        )
        && str_contains($order, '20260824_optimize_task_reminders.sql')
        && str_contains($helpers, "status IN ('open', 'in_progress', 'waiting')"),
    'header reminders should ignore terminal rows and use an assignee-first covering queue index.'
);
expectTaskNotificationsFeature(
    str_contains($helpers, 'function queueDueDailyTaskDigests(')
        && str_contains($helpers, 'function claimQueuedNotificationEmail(')
        && str_contains($helpers, "payload_ciphertext = NULL")
        && str_contains($helpers, "user.task_digest_enabled <> 1")
        && str_contains($helpers, 'outbox.digest_date < ?')
        && str_contains($helpers, 'FOR UPDATE OF outbox SKIP LOCKED')
        && str_contains($helpers, 'ApplicationKey::seal')
        && str_contains($helpers, 'ApplicationKey::open'),
    'daily scheduling should be encrypted, retryable, preference-aware, and reject stale mail before delivery.'
);
expectTaskNotificationsFeature(
    str_contains($worker, 'queueDueDailyTaskDigests($conn)')
        && str_contains($worker, 'claimQueuedNotificationEmail(')
        && str_contains($worker, 'deliverAccountEmail(')
        && str_contains($smtp, 'DNR_TASK_DIGEST_HOUR:')
        && str_contains($smtp, 'DNR_NOTIFICATION_OUTBOX_BATCH_SIZE:'),
    'the existing outbound-mail service should schedule and deliver the separate digest queue.'
);
expectTaskNotificationsFeature(
    str_contains($profile, 'name="task_digest_enabled"')
        && str_contains($profile, 'Verify your email address to enable daily digests.')
        && str_contains($profile, 'task_digest_enabled = ?')
        && str_contains($header, 'nav-notification-badge')
        && str_contains($tasks, 'task-reminder-badges')
        && str_contains($tasks, "owner_filter === 'me'"),
    'verified users should control digest delivery while personal in-app reminders remain visible.'
);
expectTaskNotificationsFeature(
    str_contains($styles, '.task-reminder-badge:hover,')
        && preg_match('/\.task-reminder-badge\s*\{[^}]*text-decoration:\s*none;/s', $styles) === 1
        && str_contains($styles, '.nav-notification-badge'),
    'reminder links should avoid underlines and retain responsive hover and focus feedback.'
);
expectTaskNotificationsFeature(
    str_contains($grants, '.notification_outbox')
        && str_contains($grants, "TO '\${mail_dispatch_user}'@'%'")
        && str_contains($grants, 'task_digest_enabled')
        && !str_contains($grants, "GRANT ALL PRIVILEGES"),
    'the outbound-mail worker should receive only table- and column-scoped notification access.'
);

echo "Task notifications feature tests passed.\n";
