<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once '/var/www/html/config.php';

function readHiddenValue($prompt) {
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

$password = readHiddenValue('New administrator password: ');
$confirmation = readHiddenValue('Confirm administrator password: ');

if (strlen($password) < 12) {
    fwrite(STDERR, "Password must contain at least 12 characters.\n");
    exit(1);
}

if (!hash_equals($password, $confirmation)) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);
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
