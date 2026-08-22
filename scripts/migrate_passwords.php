<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$config_path = is_file('/var/www/html/config.php')
    ? '/var/www/html/config.php'
    : dirname(__DIR__) . '/src/config.php';
require_once $config_path;

if (!$conn->begin_transaction()) {
    fwrite(STDERR, "Unable to start the password migration.\n");
    exit(1);
}

$scanned = 0;
$updated = 0;

try {
    $users = $conn->query('SELECT id, password FROM users FOR UPDATE');
    if (!$users) {
        throw new RuntimeException('Unable to inspect user passwords: ' . $conn->error);
    }

    $update = $conn->prepare(
        'UPDATE users
         SET password = ?, auth_version = auth_version + 1, must_change_password = 1
         WHERE id = ?'
    );
    if (!$update) {
        throw new RuntimeException('Unable to prepare password migration: ' . $conn->error);
    }

    while ($user = $users->fetch_assoc()) {
        $scanned++;
        $password = (string) $user['password'];
        $password_info = password_get_info($password);
        $password_algorithm = $password_info['algo'] ?? null;
        if ($password_algorithm !== null && $password_algorithm !== 0) {
            continue;
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        if ($password_hash === false) {
            throw new RuntimeException('Unable to hash a legacy password.');
        }
        $user_id = (int) $user['id'];
        $update->bind_param('si', $password_hash, $user_id);
        if (!$update->execute() || $update->affected_rows !== 1) {
            throw new RuntimeException('Unable to update a legacy password: ' . $update->error);
        }
        $updated++;
    }

    $update->close();
    $users->free();
    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit the password migration: ' . $conn->error);
    }
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, "Password migration failed: {$exception->getMessage()}\n");
    exit(1);
}

fwrite(
    STDOUT,
    "Password migration complete. Scanned {$scanned} users; migrated {$updated} legacy passwords.\n"
);
