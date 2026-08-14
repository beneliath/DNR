<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once '/var/www/html/config.php';

function readHiddenPassword($prompt) {
    fwrite(STDOUT, $prompt);
    $can_hide = DIRECTORY_SEPARATOR === '/'
        && function_exists('shell_exec')
        && function_exists('stream_isatty')
        && stream_isatty(STDIN);

    if ($can_hide) {
        shell_exec('stty -echo');
    }

    $value = fgets(STDIN);

    if ($can_hide) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    return is_string($value) ? rtrim($value, "\r\n") : '';
}

$username = trim($argv[1] ?? '');
if ($username === '') {
    fwrite(STDERR, "Usage: php /opt/dnr/bin/set_password.php USERNAME\n");
    exit(1);
}

$check = $conn->prepare('SELECT id FROM users WHERE username = ?');
$check->bind_param('s', $username);
$check->execute();
$user = $check->get_result()->fetch_assoc();
if (!$user) {
    fwrite(STDERR, "User not found.\n");
    exit(1);
}

$password = readHiddenPassword('New password: ');
$confirmation = readHiddenPassword('Confirm new password: ');

if (strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
    exit(1);
}

if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);
$user_id = (int) $user['id'];
$stmt = $conn->prepare(
    'UPDATE users
     SET password = ?,
         auth_version = auth_version + 1,
         must_change_password = 1,
         login_failed_attempts = 0,
         login_locked_until = NULL
     WHERE id = ?'
);
$stmt->bind_param('si', $password_hash, $user_id);

if (!$stmt->execute()) {
    fwrite(STDERR, "Unable to change the password.\n");
    exit(1);
}

fwrite(STDOUT, "Temporary password set. Existing sessions were invalidated, and the user must change it after login.\n");
