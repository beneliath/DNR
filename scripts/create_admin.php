<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once '/var/www/html/config.php';
require_once __DIR__ . '/cli_input.php';

$username = trim($argv[1] ?? 'admin');
if ($username === '' || strlen($username) > 50) {
    fwrite(STDERR, "Username must contain between 1 and 50 characters.\n");
    exit(1);
}

$check = $conn->prepare('SELECT id FROM users WHERE username = ?');
$check->bind_param('s', $username);
$check->execute();
if ($check->get_result()->num_rows > 0) {
    fwrite(STDERR, "That username already exists.\n");
    exit(1);
}

$password = readHiddenCliValue('New administrator password: ');
$confirmation = readHiddenCliValue('Confirm administrator password: ');

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
$role = 'admin';
$stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
$stmt->bind_param('sss', $username, $password_hash, $role);

if (!$stmt->execute()) {
    fwrite(STDERR, "Unable to create the administrator.\n");
    exit(1);
}

fwrite(
    STDOUT,
    "Administrator created. The first login will require authenticator enrollment.\n"
);
