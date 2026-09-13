<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Mandatory 2FA HTTP tests skipped (disposable deployment required).\n"; exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/two_factor_helpers.php';
require_once $source . '/email_helpers.php';
require_once $source . '/database_backup_client.php';
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) throw new RuntimeException('Loopback test server required.');
function mfaExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function mfaCsrf(array $response): string {
    preg_match('/name="csrf_token" value="([^"]+)"/', $response['body'], $match);
    return html_entity_decode($match[1] ?? '', ENT_QUOTES);
}
function mfaLocation(array $response, string $destination): bool {
    return $response['status'] === 302 && preg_match('/Location: ' . preg_quote($destination, '/') . '\r?\n/i', $response['headers']) === 1;
}
function mfaClient(string $base, string $cookie = ''): Closure {
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
    if ($cookie !== '') curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    return static function (string $path, ?array $post = null) use ($curl, $base): array {
        curl_setopt($curl, CURLOPT_URL, $base . '/' . $path);
        curl_setopt($curl, CURLOPT_POST, $post !== null);
        if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        $response = curl_exec($curl);
        if (!is_string($response)) throw new RuntimeException(curl_error($curl));
        $size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($response, 0, $size), 'body' => substr($response, $size)];
    };
}
$ids = []; $sessions = []; $clients = []; $recovery = [];
$password = 'Mfa integration password 2026!';
try {
    foreach (['admin', 'editor', 'reviewer'] as $role) {
        $username = 'mfa-http-' . $role . '-' . bin2hex(random_bytes(5));
        $hash = \Dnr\Security\PasswordPolicy::hash($password);
        $stmt = $conn->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)');
        $stmt->bind_param('sss', $username, $hash, $role); $stmt->execute();
        $id = $ids[$role] = (int) $conn->insert_id;
        // A pre-rollout password-only session must no longer grant access.
        session_id(''); startSecureSession();
        $_SESSION = ['user_id' => $id, 'username' => $username, 'role' => $role, 'authenticated_role' => $role,
            'auth_version' => 1, 'auth_complete' => true];
        $cookie = session_name() . '=' . session_id(); $sessions[] = session_id(); session_write_close();
        $old = mfaClient($base, $cookie);
        mfaExpect(mfaLocation($old('dashboard.php'), 'login.php'), "$role old password-only session is rejected");
        $client = $clients[$role] = mfaClient($base);
        $form = $client('login.php');
        $login = $client('login.php', ['csrf_token' => mfaCsrf($form), 'username' => $username, 'password' => $password]);
        mfaExpect(mfaLocation($login, 'setup_2fa.php'), "$role must enroll on next password login");
        mfaExpect(mfaLocation($client('dashboard.php'), 'login.php'), "$role cannot bypass enrollment");
        $setup = $client('setup_2fa.php');
        mfaExpect($setup['status'] === 200 && str_contains($setup['body'], 'required for every account'), "$role receives enrollment instructions");
        preg_match('/<code class="manual-secret">([^<]+)<\/code>/', $setup['body'], $match);
        $secret = $match[1] ?? ''; mfaExpect($secret !== '', 'An enrollment key is provided');
        $csrf = mfaCsrf($setup);
        $invalid = $client('setup_2fa.php', ['csrf_token' => $csrf, 'action' => 'confirm', 'authentication_code' => 'invalid']);
        mfaExpect($invalid['status'] === 200 && empty(fetchAuthenticationUserById($conn, $id)['two_factor_enabled']), 'Invalid codes never enable 2FA');
        $code = createTotp($secret, $username)->now();
        $confirm = $client('setup_2fa.php', ['csrf_token' => $csrf, 'action' => 'confirm', 'authentication_code' => $code]);
        mfaExpect(mfaLocation($confirm, 'two_factor_recovery_codes.php'), "$role can complete enrollment");
        $codes = $client('two_factor_recovery_codes.php');
        preg_match_all('/<code>([^<]+)<\/code>/', $codes['body'], $matches);
        $recovery[$role] = $matches[1];
        mfaExpect(count($recovery[$role]) === 10 && $client('dashboard.php')['status'] === 200, "$role receives recovery codes and gains access");
        $settings = $client('two_factor_settings.php');
        mfaExpect(!str_contains($settings['body'], 'Disable Two-Factor'), 'No role can disable 2FA');
        $client('two_factor_settings.php', ['csrf_token' => mfaCsrf($settings), 'action' => 'disable', 'password' => $password, 'current_code' => $code]);
        mfaExpect(!empty(fetchAuthenticationUserById($conn, $id)['two_factor_enabled']), 'Forged disable is rejected');
        $client('logout.php', ['csrf_token' => mfaCsrf($settings)]);
        $form = $client('login.php');
        mfaExpect(mfaLocation($client('login.php', ['csrf_token' => mfaCsrf($form), 'username' => $username, 'password' => $password]), 'verify_2fa.php'), 'Enrolled accounts get verification');
        $verify = $client('verify_2fa.php');
        $verifyCsrf = mfaCsrf($verify);
        $replayed = $client('verify_2fa.php', ['csrf_token' => $verifyCsrf, 'authentication_code' => $code]);
        mfaExpect($replayed['status'] === 200, 'Enrollment code cannot be replayed for login');
        mfaExpect(mfaLocation($client('verify_2fa.php', ['csrf_token' => $verifyCsrf, 'authentication_code' => array_shift($recovery[$role])]), 'dashboard.php'), 'Recovery code completes mandatory verification');
    }
    // The web worker has no backup secret, yet admin downloads still work.
    mfaExpect(configurationSecret('MYSQL_BACKUP_PASSWORD') === '', 'Web worker must not have the backup credential');
    $admin = $clients['admin']; $page = $admin('database_maintenance.php');
    $payload = ['action' => 'backup', 'csrf_token' => mfaCsrf($page), 'admin_password' => $password,
        'admin_code' => array_shift($recovery['admin']), 'backup_password' => 'Integration archive password!',
        'backup_password_confirmation' => 'Integration archive password!', 'download_token' => bin2hex(random_bytes(16))];
    $bad = $admin('database_maintenance.php', array_replace($payload, ['admin_password' => 'wrong']));
    mfaExpect(!str_starts_with($bad['body'], DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC), 'Wrong password cannot export');
    mfaExpect(!str_contains($bad['headers'], 'dnr_backup_'), 'Failed exports must not acknowledge a successful download');
    $download = $admin('database_maintenance.php', $payload);
    mfaExpect($download['status'] === 200 && str_starts_with($download['body'], DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC), 'Admin receives an encrypted download from the isolated exporter');
    preg_match('/Set-Cookie: dnr_backup_' . $payload['download_token'] . '=([^;]+);/i', $download['headers'], $acknowledgement);
    $receipt = json_decode(urldecode($acknowledgement[1] ?? ''), true);
    mfaExpect(is_array($receipt) && str_contains($download['headers'], 'filename="' . $receipt['filename'] . '"')
        && !empty($receipt['createdAt']), 'Successful exports acknowledge this request with the actual filename and timestamp');
    $history = $admin('database_maintenance.php');
    mfaExpect(str_contains($history['body'], 'id="database-backup-last-created"')
        && !str_contains($history['body'], 'No successful backup recorded.'), 'Backup creation remains visible after reloading the page');
    $path = tempnam(sys_get_temp_dir(), 'dnr-export-test-');
    try {
        file_put_contents($path, $download['body']);
        $plain = decryptDatabaseBackup($path, $payload['backup_password'], 16777216);
        try {
            // The export includes tables hidden from the everyday web identity.
            $handle = fopen($plain['path'], 'rb');
            $header = json_decode(fgets($handle), true, 512, JSON_THROW_ON_ERROR); fclose($handle);
            $inspection = inspectDatabaseBackup($plain['path'], $header['tables'], 16777216);
            mfaExpect($inspection['row_count'] > 0, 'Export decrypts and passes archive integrity/schema validation');
        } finally { unlink($plain['path']); }
    } finally { unlink($path); }
    $replay = $admin('database_maintenance.php', $payload);
    mfaExpect(!str_starts_with($replay['body'], DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC), 'Backup recovery code is single-use');
    mfaExpect(!str_contains($replay['headers'], 'dnr_backup_'), 'A replayed code cannot produce another success acknowledgement');
    $editor = fetchAuthenticationUserById($conn, $ids['editor']);
    try {
        $illegal = requestEncryptedDatabaseBackup(['user_id' => $ids['editor'], 'auth_version' => $editor['auth_version'],
            'admin_password' => $password, 'admin_code' => array_shift($recovery['editor']), 'backup_password' => $payload['backup_password']], 16777216);
        unlink($illegal['path']); throw new LogicException('Exporter accepted non-admin credentials');
    } catch (RuntimeException $expected) {}
    // Administrative factor reset invalidates the session and requires enrollment again.
    disableTwoFactorForUser($conn, $ids['reviewer']);
    mfaExpect(mfaLocation($clients['reviewer']('dashboard.php'), 'login.php'), 'Admin reset revokes old access');
    $reviewer = fetchAuthenticationUserById($conn, $ids['reviewer']);
    $form = $clients['reviewer']('login.php');
    mfaExpect(mfaLocation($clients['reviewer']('login.php', ['csrf_token' => mfaCsrf($form), 'username' => $reviewer['username'], 'password' => $password]), 'setup_2fa.php'), 'Admin-reset account can enroll on next login');
    // Invitation activation also has no password-only path.
    $name = 'mfa-invited-' . bin2hex(random_bytes(4)); $email = $name . '@example.test';
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, account_status) VALUES (?, ?, ?, 'reviewer', 'invited')");
    $stmt->bind_param('sss', $name, $email, $hash); $stmt->execute(); $ids['invited'] = (int) $conn->insert_id;
    $issued = issueUserEmailToken($conn, $ids['invited'], 'invitation', $email);
    $token = $issued['token'];
    $invite = mfaClient($base); $form = $invite('accept_invitation.php?token=' . urlencode($token));
    $accepted = $invite('accept_invitation.php', ['csrf_token' => mfaCsrf($form), 'token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    mfaExpect(mfaLocation($accepted, 'setup_2fa.php') && mfaLocation($invite('dashboard.php'), 'login.php'), 'Accepted invitations require enrollment before access');
} finally {
    setDatabaseAuditContext($conn);
    foreach ($ids as $id) $conn->query('DELETE FROM users WHERE id=' . (int) $id);
    foreach ($sessions as $session) { session_id($session); startSecureSession(); session_destroy(); }
}
echo "Mandatory 2FA and isolated backup HTTP tests passed.\n";
