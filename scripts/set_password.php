<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once '/var/www/html/config.php';
require_once __DIR__ . '/cli_input.php';

$username = trim($argv[1] ?? '');
if ($username === '') {
    fwrite(STDERR, "Usage: dnr-set-password USERNAME\n");
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

$password = readHiddenCliValue('New password: ');
$confirmation = readHiddenCliValue('Confirm new password: ');

$password_error = \Dnr\Security\PasswordPolicy::validationError($password);
if ($password_error !== null) {
    fwrite(STDERR, $password_error . "\n");
    exit(1);
}

if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$password_hash = \Dnr\Security\PasswordPolicy::hash($password);
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
