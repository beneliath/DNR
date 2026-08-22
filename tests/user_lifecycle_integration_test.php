<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "User lifecycle integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';
require_once $source_directory . '/functions.php';
require_once $source_directory . '/user_lifecycle_helpers.php';

function expectLifecycleIntegration($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "User lifecycle integration test failed: {$message}\n");
        exit(1);
    }
}

$suffix = bin2hex(random_bytes(6));
$actor_username = 'lifecycle-admin-' . $suffix;
$target_username = 'lifecycle-user-' . $suffix;
$password = password_hash('LifecycleIntegration!123', PASSWORD_DEFAULT);
$actor_role = 'admin';
$target_role = 'reviewer';
$actor_email = 'admin-' . $suffix . '@example.test';
$target_email = 'user-' . $suffix . '@example.test';

$insert = $conn->prepare(
    'INSERT INTO users
        (username, email, email_verified_at, password, role, account_status)
     VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, \'active\')'
);
$insert->bind_param('ssss', $actor_username, $actor_email, $password, $actor_role);
$insert->execute();
$actor_id = (int) $conn->insert_id;
$insert->bind_param('ssss', $target_username, $target_email, $password, $target_role);
$insert->execute();
$target_id = (int) $conn->insert_id;
$insert->close();

try {
    $calendar_token = random_bytes(32);
    $calendar = $conn->prepare(
        'INSERT INTO calendar_subscriptions (user_id, label, token_hash)
         VALUES (?, \'Lifecycle integration\', ?)'
    );
    $calendar->bind_param('is', $target_id, $calendar_token);
    $calendar->execute();
    $calendar->close();

    $task = $conn->prepare(
        'INSERT INTO follow_up_tasks
            (title, subject_type, assigned_to, created_by)
         VALUES (?, \'general\', ?, ?)'
    );
    $task_title = 'Lifecycle task ' . $suffix;
    $task->bind_param('sii', $task_title, $target_id, $actor_id);
    $task->execute();
    $task_id = (int) $conn->insert_id;
    $task->close();

    $stale_token = issueUserEmailToken($conn, $target_id, 'recovery', $target_email);
    $conn->query("UPDATE users SET auth_version = auth_version + 1 WHERE id = {$target_id}");
    expectLifecycleIntegration(
        findUserEmailToken($conn, 'recovery', $stale_token['token']) === null,
        'an authentication-version change should invalidate an outstanding email token.'
    );
    issueUserEmailToken($conn, $target_id, 'recovery', $target_email);
    $before = $conn->query("SELECT auth_version FROM users WHERE id = {$target_id}")->fetch_assoc();
    $result = deactivateUserAccount($conn, $target_id, $actor_id);

    $after = $conn->query(
        "SELECT account_status, auth_version FROM users WHERE id = {$target_id}"
    )->fetch_assoc();
    $calendar_after = $conn->query(
        "SELECT revoked_at FROM calendar_subscriptions WHERE user_id = {$target_id}"
    )->fetch_assoc();
    $task_after = $conn->query(
        "SELECT assigned_to FROM follow_up_tasks WHERE id = {$task_id}"
    )->fetch_assoc();
    $token_after = $conn->query(
        "SELECT consumed_at FROM user_email_tokens WHERE user_id = {$target_id} ORDER BY id DESC LIMIT 1"
    )->fetch_assoc();
    $audit_after = $conn->query(
        "SELECT COUNT(*) AS total FROM security_audit_log
         WHERE event_type = 'user_deactivated' AND target_user_id = {$target_id}"
    )->fetch_assoc();

    expectLifecycleIntegration(
        $after['account_status'] === 'inactive'
            && (int) $after['auth_version'] === (int) $before['auth_version'] + 1,
        'deactivation should mark the account inactive and invalidate sessions.'
    );
    expectLifecycleIntegration(
        $result['calendar_tokens'] === 1
            && $calendar_after['revoked_at'] !== null
            && $result['task_assignments'] === 1
            && $task_after['assigned_to'] === null
            && $token_after['consumed_at'] !== null,
        'deactivation should revoke calendar, task, and outstanding email access.'
    );
    expectLifecycleIntegration(
        (int) $audit_after['total'] === 1,
        'deactivation should retain the account and create a semantic audit record.'
    );

    activateUserAccount($conn, $target_id, $actor_id);
    $reactivated = $conn->query(
        "SELECT account_status FROM users WHERE id = {$target_id}"
    )->fetch_assoc();
    expectLifecycleIntegration(
        $reactivated['account_status'] === 'active',
        'an administrator should be able to reactivate an inactive account.'
    );
} finally {
    $delete_actor = $conn->prepare('DELETE FROM users WHERE id = ?');
    $delete_actor->bind_param('i', $actor_id);
    $delete_actor->execute();
    $delete_actor->close();
    $delete_target = $conn->prepare('DELETE FROM users WHERE id = ?');
    $delete_target->bind_param('i', $target_id);
    $delete_target->execute();
    $delete_target->close();
}

echo "User lifecycle integration tests passed.\n";
