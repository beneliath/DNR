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
const HTTP_FIXTURE_DEACTIVATION_RECOVERY_CODE = 'BCDF-GHJK-LMNP';
const HTTP_FIXTURE_DELETION_RECOVERY_CODE = 'QRST-VWXY-ZABC';

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
$contactFirstName = 'HTTP-' . $suffix;
$engagementTitle = 'HTTP Test Engagement ' . $suffix;
$updatedEngagementTitle = $engagementTitle . ' Updated';

$deleteFixtures = static function () use ($conn, $usernames, $organizationName): void {
    $taskStatement = $conn->prepare(
        'DELETE task FROM follow_up_tasks task
         INNER JOIN users user ON user.id = task.created_by
         WHERE user.username = ?'
    );
    foreach ($usernames as $username) {
        $taskStatement->bind_param('s', $username);
        $taskStatement->execute();
    }
    $taskStatement->close();

    $engagementStatement = $conn->prepare(
        'DELETE engagement FROM engagements engagement
         INNER JOIN organizations organization ON organization.id = engagement.organization_id
         WHERE organization.organization_name = ?'
    );
    $engagementStatement->bind_param('s', $organizationName);
    $engagementStatement->execute();
    $engagementStatement->close();

    $contactStatement = $conn->prepare(
        'DELETE contact FROM contacts contact
         INNER JOIN organizations organization ON organization.id = contact.organization_id
         WHERE organization.organization_name = ?'
    );
    $contactStatement->bind_param('s', $organizationName);
    $contactStatement->execute();
    $contactStatement->close();

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
        'INSERT INTO users
            (username, email, email_verified_at, password, role, two_factor_enabled)
         VALUES (?, ?, UTC_TIMESTAMP(), ?, ?, ?)'
    );
    foreach (['reviewer', 'editor', 'target'] as $fixtureRole) {
        $username = $usernames[$fixtureRole];
        $email = $username . '@example.test';
        $role = $fixtureRole === 'target' ? 'reviewer' : $fixtureRole;
        $twoFactorEnabled = 0;
        $insertUser->bind_param(
            'ssssi',
            $username,
            $email,
            $passwordHash,
            $role,
            $twoFactorEnabled
        );
        $insertUser->execute();
    }
    $username = $usernames['admin'];
    $email = $username . '@example.test';
    $role = 'admin';
    $twoFactorEnabled = 1;
    $insertUser->bind_param(
        'ssssi',
        $username,
        $email,
        $passwordHash,
        $role,
        $twoFactorEnabled
    );
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
        HTTP_FIXTURE_DEACTIVATION_RECOVERY_CODE,
        HTTP_FIXTURE_DELETION_RECOVERY_CODE,
    ]);

    echo "HTTP fixtures created.\n";
    exit(0);
}

if ($action === 'user-exists'
    || $action === 'user-status'
    || $action === 'digest-enabled'
) {
    $fixture = $argv[3] ?? '';
    if (!isset($usernames[$fixture])) {
        fwrite(STDERR, "Unknown user fixture.\n");
        exit(1);
    }
    $statement = $conn->prepare(
        'SELECT account_status, task_digest_enabled FROM users WHERE username = ?'
    );
    $statement->bind_param('s', $usernames[$fixture]);
    $statement->execute();
    $user = $statement->get_result()->fetch_assoc();
    if ($action === 'user-exists') {
        echo $user ? "1\n" : "0\n";
    } elseif ($action === 'digest-enabled') {
        echo $user ? (string) (int) $user['task_digest_enabled'] . "\n" : "missing\n";
    } else {
        echo $user ? (string) $user['account_status'] . "\n" : "missing\n";
    }
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

if ($action === 'mark-email-unverified') {
    $fixture = $argv[3] ?? '';
    if (!isset($usernames[$fixture])) {
        fwrite(STDERR, "Unknown user fixture.\n");
        exit(1);
    }
    $statement = $conn->prepare(
        'UPDATE users
         SET email_verified_at = NULL, task_digest_enabled = 0
         WHERE username = ?'
    );
    $statement->bind_param('s', $usernames[$fixture]);
    $statement->execute();
    $statement->close();
    echo "Fixture email marked unverified.\n";
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

if ($action === 'contact-id' || $action === 'contact-state') {
    $statement = $conn->prepare(
        'SELECT contact.id, contact.is_deleted
         FROM contacts contact
         INNER JOIN organizations organization ON organization.id = contact.organization_id
         WHERE organization.organization_name = ? AND contact.contact_first_name = ?'
    );
    $statement->bind_param('ss', $organizationName, $contactFirstName);
    $statement->execute();
    $contact = $statement->get_result()->fetch_assoc();
    $statement->close();
    echo $action === 'contact-id'
        ? (int) ($contact['id'] ?? 0) . "\n"
        : ($contact ? (string) (int) $contact['is_deleted'] . "\n" : "missing\n");
    exit(0);
}

if ($action === 'engagement-id'
    || $action === 'engagement-state'
    || $action === 'engagement-title'
) {
    $statement = $conn->prepare(
        'SELECT engagement.id, engagement.is_deleted, engagement.event_title
         FROM engagements engagement
         INNER JOIN organizations organization ON organization.id = engagement.organization_id
         WHERE organization.organization_name = ?
           AND engagement.event_title IN (?, ?)
         ORDER BY engagement.id DESC
         LIMIT 1'
    );
    $statement->bind_param('sss', $organizationName, $engagementTitle, $updatedEngagementTitle);
    $statement->execute();
    $engagement = $statement->get_result()->fetch_assoc();
    $statement->close();
    if ($action === 'engagement-id') {
        echo (int) ($engagement['id'] ?? 0) . "\n";
    } elseif ($action === 'engagement-state') {
        echo $engagement ? (string) (int) $engagement['is_deleted'] . "\n" : "missing\n";
    } else {
        echo $engagement ? (string) $engagement['event_title'] . "\n" : "missing\n";
    }
    exit(0);
}

fwrite(STDERR, "Unknown HTTP fixture action.\n");
exit(1);
