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
$scheduleMigration = $read('migrations/20260828_customize_task_digest_schedule.sql');
$defaultMigration = $read('migrations/20260901_default_daily_work_digest.sql');
$reminderIndexMigration = $read('migrations/20260824_optimize_task_reminders.sql');
$order = $read('migrations/order.txt');
$helpers = $read('src/notification_helpers.php');
$worker = $read('scripts/process_email_outbox.php');
$profile = $read('src/profile.php');
$editUser = $read('src/edit_user.php');
$header = $read('src/templates/header.php');
$tasks = $read('src/tasks.php');
$styles = $read('src/assets/css/modern.css');
$profileScript = $read('src/assets/js/profile.js');
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
    str_contains($scheduleMigration, "task_digest_time TIME NOT NULL DEFAULT '07:00:00'")
        && str_contains($scheduleMigration, 'task_digest_days TINYINT UNSIGNED NOT NULL DEFAULT 127')
        && str_contains($scheduleMigration, 'CHECK (task_digest_days BETWEEN 1 AND 127)')
        && str_contains($order, '20260828_customize_task_digest_schedule.sql'),
    'a forward migration should persist a bounded per-user delivery time and weekday mask.'
);
expectTaskNotificationsFeature(
    str_contains($defaultMigration, 'task_digest_enabled TINYINT(1) NOT NULL DEFAULT 1')
        && str_contains($defaultMigration, "task_digest_time TIME NOT NULL DEFAULT '07:00:00'")
        && str_contains($defaultMigration, 'task_digest_days TINYINT UNSIGNED NOT NULL DEFAULT 31')
        && !str_contains($defaultMigration, 'UPDATE users')
        && str_contains($order, '20260901_default_daily_work_digest.sql'),
    'new users should default to an enabled weekday digest at 7am without overwriting existing preferences.'
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
        && str_contains($helpers, 'function taskDigestScheduleIsDue(')
        && str_contains($helpers, 'user.task_digest_time, user.task_digest_days')
        && str_contains($helpers, 'user.id > ?')
        && str_contains($helpers, '$maximumRecipients')
        && str_contains($helpers, "'Unable to schedule daily work digest for user'")
        && str_contains($helpers, 'recordDailyTaskDigestSchedulingFailure(')
        && str_contains($helpers, "NULL, 'failed', ?")
        && str_contains($helpers, 'user.task_digest_days & (1 << WEEKDAY(outbox.digest_date))')
        && str_contains($helpers, 'outbox.digest_date < ?')
        && str_contains($helpers, 'FOR UPDATE OF outbox SKIP LOCKED')
        && str_contains($helpers, 'ApplicationKey::seal')
        && str_contains($helpers, 'ApplicationKey::open'),
    'daily scheduling should be encrypted, retryable, preference-aware, and reject stale mail before delivery.'
);
expectTaskNotificationsFeature(
    str_contains($worker, 'queueDueDailyTaskDigests($conn)')
        && str_contains($worker, 'claimQueuedNotificationEmail(')
        && str_contains($worker, 'deliverApplicationEmailWithSession(')
        && str_contains($worker, 'maintainQueuedNotificationEmail(')
        && str_contains($worker, 'claimQueuedNotificationEmail($conn, $businessDate, 600, false)')
        && str_contains($smtp, 'DNR_NOTIFICATION_OUTBOX_BATCH_SIZE:'),
    'the outbound-mail service should schedule the digest queue, sweep leases once, and reuse its SMTP session.'
);
expectTaskNotificationsFeature(
    str_contains($profile, 'name="task_digest_enabled"')
        && str_contains($profile, 'name="task_digest_time"')
        && str_contains($profile, 'name="task_digest_days[]"')
        && str_contains($profile, 'data-task-digest-days="31"')
        && str_contains($profile, 'data-task-digest-days="96"')
        && str_contains($profile, '$user[\'task_digest_days\'] ?? TASK_DIGEST_WEEKDAYS')
        && str_contains($profileScript, 'updateDigestDayControls')
        && str_contains($profile, 'Verify your email address to enable daily digests.')
        && str_contains($profile, 'task_digest_enabled = ?')
        && str_contains($profile, 'task_digest_time = ?, task_digest_days = ?')
        && str_contains($header, 'nav-notification-badge')
        && str_contains($tasks, 'task-reminder-badges')
        && str_contains($tasks, "owner_filter === 'me'"),
    'verified users should control digest delivery while personal in-app reminders remain visible.'
);
expectTaskNotificationsFeature(
    str_contains($editUser, "include 'notification_helpers.php'")
        && str_contains($editUser, 'task_digest_enabled, task_digest_time, task_digest_days')
        && str_contains($editUser, 'name="task_digest_enabled"')
        && str_contains($editUser, 'name="task_digest_time"')
        && str_contains($editUser, 'name="task_digest_days[]"')
        && str_contains($editUser, 'taskDigestDeliveryTimeFromInput(')
        && str_contains($editUser, 'taskDigestDaysFromInput('),
    'administrators should be able to validate and update every user’s daily digest settings.'
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
        && str_contains($grants, 'task_digest_time, task_digest_days')
        && !str_contains($grants, "GRANT ALL PRIVILEGES"),
    'the outbound-mail worker should receive only table- and column-scoped notification access.'
);

echo "Task notifications feature tests passed.\n";
