<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli'
    || getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    fwrite(STDERR, "HTTP fixtures require an explicitly disposable integration database.\n");
    exit(1);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/two_factor_helpers.php';

const HTTP_FIXTURE_PASSWORD = 'HttpTestPassword!123';
const HTTP_FIXTURE_LOGIN_RECOVERY_CODE = 'ABCD-EFGH-JKLM';
const HTTP_FIXTURE_ELEVATION_RECOVERY_CODE = 'NPQR-STUV-WXYZ';

$action = $argv[1] ?? '';
$suffix = $argv[2] ?? '';
if (preg_match('/\A[a-z0-9]{6,32}\z/', $suffix) !== 1) {
    fwrite(STDERR, "Provide a 6-32 character lowercase alphanumeric fixture suffix.\n");
    exit(1);
}

$usernames = [
    'reviewer' => 'http-reviewer-' . $suffix,
    'editor' => 'http-editor-' . $suffix,
    'admin' => 'http-admin-' . $suffix,
    'target' => 'http-target-' . $suffix,
];
$organizationName = 'HTTP Test Organization ' . $suffix;

$deleteFixtures = static function () use ($conn, $usernames, $organizationName): void {
    $organizationStatement = $conn->prepare('DELETE FROM organizations WHERE organization_name = ?');
    $organizationStatement->bind_param('s', $organizationName);
    $organizationStatement->execute();
    $organizationStatement->close();

    $userStatement = $conn->prepare('DELETE FROM users WHERE username = ?');
    foreach ($usernames as $username) {
        $userStatement->bind_param('s', $username);
        $userStatement->execute();
    }
    $userStatement->close();
};

if ($action === 'cleanup') {
    $deleteFixtures();
    echo "HTTP fixtures removed.\n";
    exit(0);
}

if ($action === 'setup') {
    $deleteFixtures();
    $passwordHash = password_hash(HTTP_FIXTURE_PASSWORD, PASSWORD_DEFAULT);
    $insertUser = $conn->prepare(
        'INSERT INTO users (username, password, role, two_factor_enabled)
         VALUES (?, ?, ?, ?)'
    );
    foreach (['reviewer', 'editor', 'target'] as $fixtureRole) {
        $username = $usernames[$fixtureRole];
        $role = $fixtureRole === 'target' ? 'reviewer' : $fixtureRole;
        $twoFactorEnabled = 0;
        $insertUser->bind_param('sssi', $username, $passwordHash, $role, $twoFactorEnabled);
        $insertUser->execute();
    }
    $username = $usernames['admin'];
    $role = 'admin';
    $twoFactorEnabled = 1;
    $insertUser->bind_param('sssi', $username, $passwordHash, $role, $twoFactorEnabled);
    $insertUser->execute();
    $adminId = (int) $conn->insert_id;
    $insertUser->close();

    $secret = generateTotpSecret();
    $encryptedSecret = encryptTwoFactorSecret($secret);
    $factorStatement = $conn->prepare(
        'UPDATE users
         SET totp_secret_encrypted = ?, totp_confirmed_at = UTC_TIMESTAMP()
         WHERE id = ?'
    );
    $factorStatement->bind_param('si', $encryptedSecret, $adminId);
    $factorStatement->execute();
    $factorStatement->close();
    replaceRecoveryCodes($conn, $adminId, [
        HTTP_FIXTURE_LOGIN_RECOVERY_CODE,
        HTTP_FIXTURE_ELEVATION_RECOVERY_CODE,
    ]);

    echo "HTTP fixtures created.\n";
    exit(0);
}

if ($action === 'user-exists') {
    $fixture = $argv[3] ?? '';
    if (!isset($usernames[$fixture])) {
        fwrite(STDERR, "Unknown user fixture.\n");
        exit(1);
    }
    $statement = $conn->prepare('SELECT COUNT(*) AS total FROM users WHERE username = ?');
    $statement->bind_param('s', $usernames[$fixture]);
    $statement->execute();
    echo (int) $statement->get_result()->fetch_assoc()['total'] . "\n";
    exit(0);
}

if ($action === 'user-id') {
    $fixture = $argv[3] ?? '';
    if (!isset($usernames[$fixture])) {
        fwrite(STDERR, "Unknown user fixture.\n");
        exit(1);
    }
    $statement = $conn->prepare('SELECT id FROM users WHERE username = ?');
    $statement->bind_param('s', $usernames[$fixture]);
    $statement->execute();
    echo (int) ($statement->get_result()->fetch_assoc()['id'] ?? 0) . "\n";
    exit(0);
}

if ($action === 'organization-state') {
    $statement = $conn->prepare('SELECT is_deleted FROM organizations WHERE organization_name = ?');
    $statement->bind_param('s', $organizationName);
    $statement->execute();
    $organization = $statement->get_result()->fetch_assoc();
    echo $organization ? (string) (int) $organization['is_deleted'] . "\n" : "missing\n";
    exit(0);
}

if ($action === 'organization-id') {
    $statement = $conn->prepare('SELECT id FROM organizations WHERE organization_name = ?');
    $statement->bind_param('s', $organizationName);
    $statement->execute();
    echo (int) ($statement->get_result()->fetch_assoc()['id'] ?? 0) . "\n";
    exit(0);
}

fwrite(STDERR, "Unknown HTTP fixture action.\n");
exit(1);
