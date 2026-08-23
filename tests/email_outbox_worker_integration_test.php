<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Email outbox worker integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';
require_once $source_directory . '/email_helpers.php';

function expectEmailOutboxWorker(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Email outbox worker integration test failed: {$message}\n");
        exit(1);
    }
}

$suffix = bin2hex(random_bytes(8));
$username = 'outbox-worker-' . $suffix;
$email = $username . '@example.test';
$password = password_hash('OutboxWorkerIntegration!123', PASSWORD_DEFAULT);
$role = 'reviewer';
$insert_user = $conn->prepare(
    "INSERT INTO users
        (username, email, email_verified_at, password, role, account_status, auth_version)
     VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, 'active', 2)"
);
$insert_user->bind_param('ssss', $username, $email, $password, $role);
$insert_user->execute();
$user_id = (int) $conn->insert_id;
$insert_user->close();

try {
    $token_ids = [];
    $insert_token = $conn->prepare(
        "INSERT INTO user_email_tokens
            (user_id, purpose, email, auth_version, token_hash, expires_at)
         VALUES (?, 'recovery', ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))"
    );
    $insert_outbox = $conn->prepare(
        "INSERT INTO email_outbox
            (token_id, user_id, purpose, payload_ciphertext, next_attempt_at)
         VALUES (?, ?, 'recovery', 'integration-ciphertext', UTC_TIMESTAMP())"
    );
    foreach ([1, 2, 2] as $auth_version) {
        $token_hash = random_bytes(32);
        $insert_token->bind_param('isis', $user_id, $email, $auth_version, $token_hash);
        $insert_token->execute();
        $token_id = (int) $conn->insert_id;
        $token_ids[] = $token_id;
        $insert_outbox->bind_param('ii', $token_id, $user_id);
        $insert_outbox->execute();
    }
    $insert_token->close();
    $insert_outbox->close();

    [$stale_id, $previous_id, $replacement_id] = $token_ids;
    $first = claimQueuedAccountEmail($conn);
    expectEmailOutboxWorker(
        $first !== null && $first['token_id'] === $previous_id,
        'the worker should discard a stale auth-version message and claim the oldest valid link.'
    );
    $conn->begin_transaction();
    completeQueuedAccountEmail($conn, $previous_id, $user_id, 'recovery');
    $conn->commit();

    $after_first = $conn->query(
        "SELECT id, consumed_at FROM user_email_tokens
         WHERE id IN ({$previous_id}, {$replacement_id}) ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);
    expectEmailOutboxWorker(
        count($after_first) === 2
            && $after_first[0]['consumed_at'] === null
            && $after_first[1]['consumed_at'] === null,
        'completing an older delivery should not consume its newer replacement.'
    );

    $second = claimQueuedAccountEmail($conn);
    expectEmailOutboxWorker(
        $second !== null && $second['token_id'] === $replacement_id,
        'the replacement should remain claimable after the earlier delivery completes.'
    );
    $conn->begin_transaction();
    completeQueuedAccountEmail($conn, $replacement_id, $user_id, 'recovery');
    $conn->commit();

    $final_tokens = $conn->query(
        "SELECT id, consumed_at FROM user_email_tokens
         WHERE id IN ({$previous_id}, {$replacement_id}) ORDER BY id"
    )->fetch_all(MYSQLI_ASSOC);
    $stale_outbox = $conn->query(
        "SELECT status, payload_ciphertext FROM email_outbox WHERE token_id = {$stale_id}"
    )->fetch_assoc();
    expectEmailOutboxWorker(
        $final_tokens[0]['consumed_at'] !== null
            && $final_tokens[1]['consumed_at'] === null
            && ($stale_outbox['status'] ?? null) === 'failed'
            && array_key_exists('payload_ciphertext', $stale_outbox)
            && $stale_outbox['payload_ciphertext'] === null,
        'replacement delivery should consume only older links and erase stale queued payloads.'
    );
} finally {
    try {
        $conn->rollback();
    } catch (Throwable $exception) {
    }
    $cleanup = $conn->prepare('DELETE FROM users WHERE id = ?');
    $cleanup->bind_param('i', $user_id);
    $cleanup->execute();
    $cleanup->close();
}

echo "Email outbox worker integration tests passed.\n";
