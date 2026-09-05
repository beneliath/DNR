<?php

declare(strict_types=1);
if (getenv('DNR_INBOUND_PRIVILEGE_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Inbound email privileges tests skipped (requires disposable worker credentials).\n"; exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/inbound_email_helpers.php';
$identity = $conn->query('SELECT CURRENT_USER() AS identity')->fetch_assoc()['identity'];
if (!str_starts_with($identity, 'dnrmailingest@')) throw new RuntimeException('Use the real ingest worker identity');
$matches = inboundEmailBatchMatches($conn, ['nobody@example.test']);
foreach (['password', 'totp_secret_encrypted', 'auth_version', 'pending_email', 'email'] as $column) {
    try { $conn->query('SELECT ' . $column . ' FROM users LIMIT 1'); }
    catch (mysqli_sql_exception $error) { if (in_array($error->getCode(), [1142, 1143], true)) continue; throw $error; }
    throw new RuntimeException('Ingest worker can read forbidden column ' . $column);
}
$conn->query('SELECT id, username, verified_email, account_status FROM users LIMIT 1');
echo "Inbound email privileges tests passed.\n";
