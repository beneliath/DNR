<?php

require_once __DIR__ . '/../src/functions.php';

function expectTrue($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$_SESSION = [];

$token = generateCsrfToken();
expectTrue(strlen($token) === 64, 'CSRF token should contain 32 random bytes as hex.');
expectTrue(ctype_xdigit($token), 'CSRF token should be hexadecimal.');
expectTrue(generateCsrfToken() === $token, 'CSRF token should remain stable within a session.');
expectTrue(validateCsrfToken($token), 'The session CSRF token should validate.');
expectTrue(!validateCsrfToken(str_repeat('0', 64)), 'An incorrect CSRF token should fail.');
expectTrue(!validateCsrfToken(null), 'A missing CSRF token should fail.');

$_SESSION['role'] = 'editor';
expectTrue(hasRole(['admin', 'editor']), 'Editor should match an allowed role list.');
expectTrue(!hasRole(['admin']), 'Editor should not match the admin role.');
expectTrue(checkRole('editor'), 'Exact role checks should succeed.');
expectTrue(!checkRole(0), 'Role checks should use strict comparison.');

echo "Security helper tests passed.\n";
