<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Admin user profile HTTP tests skipped (disposable server required).\n";
    exit;
}

$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/persistent_file_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';

$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1:8080', '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Loopback server required.');
}

function expectAdminProfile(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$request = static function (string $path, ?array $post = null, string $cookie = '') use ($base): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15]);
    if ($cookie !== '') curl_setopt($curl, CURLOPT_COOKIE, $cookie);
    if ($post !== null) {
        $multipart = isset($post['profile_picture']) && $post['profile_picture'] instanceof CURLFile;
        curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $raw = curl_exec($curl);
    if (!is_string($raw)) throw new RuntimeException(curl_error($curl));
    $headerLength = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'headers' => substr($raw, 0, $headerLength), 'body' => substr($raw, $headerLength)];
};
$users = [];
$sessions = [];
$picturePath = tempnam(sys_get_temp_dir(), 'admin-profile-picture-');
$invalidPath = tempnam(sys_get_temp_dir(), 'admin-profile-invalid-');
file_put_contents($picturePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
file_put_contents($invalidPath, 'This is not a picture.');
$fetch = static fn(int $id): array => $conn->execute_query('SELECT * FROM users WHERE id = ?', [$id])->fetch_assoc();

try {
    foreach (['admin', 'editor', 'reviewer', 'inactive', 'invited'] as $kind) {
        $username = 'admin-profile-' . $kind . '-' . bin2hex(random_bytes(4));
        $role = in_array($kind, ['inactive', 'invited'], true) ? 'reviewer' : $kind;
        $conn->execute_query(
            "INSERT INTO users (username, password, role, account_status, first_name, last_name,
                email, email_verified_at, task_digest_time, task_digest_days)
             VALUES (?, ?, ?, ?, 'Original', 'Name', ?, UTC_TIMESTAMP(), '16:45:00', 21)",
            [$username, password_hash('DisposableProfileFixture!123', PASSWORD_DEFAULT), $role,
                in_array($kind, ['inactive', 'invited'], true) ? $kind : 'active', $username . '@example.test']
        );
        $users[$kind] = (int) $conn->insert_id;
    }
    $target = $users['reviewer'];
    $path = 'edit_user.php?id=' . $target;
    $before = $fetch($target);
    $fields = ['username' => $before['username'], 'role' => 'reviewer',
        'first_name' => '  Avery <Admin>  ', 'last_name' => 'Morgan',
        'phone_country_code' => '+1', 'phone' => '(202) 555-0123'];
    expectAdminProfile($request($path)['status'] === 302, 'Anonymous profile editing requires login.');

    foreach (['editor', 'reviewer', 'admin'] as $kind) {
        startSecureSession();
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $users[$kind], 'username' => $fetch($users[$kind])['username'],
            'role' => $kind, 'authenticated_role' => $kind, 'auth_version' => 1,
            'auth_complete' => true, '_csrf_token' => bin2hex(random_bytes(32))];
        completeIntegrationTestMfaSession();
        $sessions[] = session_id();
        $cookie = session_name() . '=' . session_id();
        $csrf = $_SESSION['_csrf_token'];
        session_write_close();
        $post = $fields + ['csrf_token' => $csrf];
        if ($kind !== 'admin') {
            expectAdminProfile($request($path, null, $cookie)['status'] === 403
                && $request($path, $post, $cookie)['status'] === 403,
                'Only admins can open or submit the user editor, including editing themselves.');
            continue;
        }
        $before = $fetch($target);
        $form = $request($path, null, $cookie);
        expectAdminProfile($form['status'] === 200 && str_contains($form['body'], 'name="first_name"')
            && str_contains($form['body'], 'name="last_name"') && str_contains($form['body'], 'name="phone"')
            && str_contains($form['body'], 'name="profile_picture"')
            && str_contains($form['body'], 'profile_picture.php?id=' . $target . '&amp;size=full'),
            'The admin form includes personal fields and the selected user’s picture.');
        $blocked = $request($path, $post, $cookie);
        expectAdminProfile($blocked['status'] === 302 && str_contains($blocked['headers'], 'Location: admin_elevation.php?')
            && $fetch($target) === $before, 'Profile writes require recent admin elevation.');
        session_id(explode('=', $cookie, 2)[1]);
        startSecureSession();
        $_SESSION['_admin_elevated_at'] = time();
        session_write_close();
        expectAdminProfile($request($path, array_replace($post, ['csrf_token' => 'bad']), $cookie)['status'] === 400
            && $fetch($target) === $before, 'Elevated saves still require CSRF.');
        foreach (['edit_user.php?id[]=1', 'edit_user.php?id=0', 'edit_user.php?id=2147483647'] as $missingPath) {
            $missing = $request($missingPath, $post, $cookie);
            expectAdminProfile($missing['status'] === 302 && str_contains($missing['headers'], 'Location: users.php')
                && $fetch($target) === $before, 'Invalid or missing user IDs cannot update another profile.');
        }
        $adminBefore = $fetch($users['admin']);
        $demotion = $request('edit_user.php?id=' . $users['admin'], array_replace($post,
            ['username' => $adminBefore['username'], 'role' => 'reviewer']), $cookie);
        expectAdminProfile($demotion['status'] === 200 && str_contains($demotion['body'], 'retain at least one administrator')
            && $fetch($users['admin']) === $adminBefore, 'Profile editing preserves the last-administrator protection.');

        foreach ([['first_name' => str_repeat('é', 101)], ['last_name' => str_repeat('é', 101)],
            ['phone' => 'invalid'], ['role' => 'invalid'],
            ['profile_picture' => new CURLFile($invalidPath, 'image/png', 'invalid.png')],
            ['profile_picture' => new CURLFile($picturePath, 'image/png', 'avatar.png'), 'remove_profile_picture' => '1']] as $invalid) {
            $response = $request($path, array_replace($post, $invalid), $cookie);
            expectAdminProfile($response['status'] === 200 && str_contains($response['body'], "class='error'")
                && $fetch($target) === $before, 'Invalid input must leave the entire user record unchanged.');
        }
        $invalid = $request($path, array_replace($post, ['username' => '']), $cookie);
        expectAdminProfile(str_contains($invalid['body'], 'value="Avery &lt;Admin&gt;"')
            && str_contains($invalid['body'], 'value="Morgan"'), 'Validation preserves and escapes submitted profile fields.');

        $adminBefore = $fetch($users['admin']);
        $save = $request($path, $post + ['profile_picture' => new CURLFile($picturePath, 'image/png', 'avatar.png'),
            'email' => 'ignored@example.test'], $cookie);
        $saved = $fetch($target);
        expectAdminProfile($save['status'] === 302 && str_contains($save['headers'], 'Location: users.php')
            && $saved['first_name'] === 'Avery <Admin>' && $saved['last_name'] === 'Morgan'
            && $saved['phone'] === '+12025550123' && $saved['email'] === $before['email']
            && $saved['email_verified_at'] === $before['email_verified_at']
            && $saved['profile_picture_mime'] === (function_exists('imagewebp') ? 'image/webp' : 'image/png')
            && strlen($saved['profile_picture_sha256']) === 32
            && is_array(getimagesizefromstring(file_get_contents(persistentFilePath($saved['profile_picture_thumbnail_key']))))
            && (int) $saved['task_digest_enabled'] === 0 && $saved['task_digest_time'] === '16:45:00'
            && (int) $saved['task_digest_days'] === 21,
            'Admin saves normalize names/phone, store a real picture/thumbnail, and retain paused digest schedules and recovery email.');
        expectAdminProfile($fetch($users['admin']) === $adminBefore, 'Editing another user never updates the administrator’s profile.');
        $audit = $conn->execute_query("SELECT actor_user_id, target_user_id FROM security_audit_log
            WHERE event_type = 'user_profile_updated' AND target_user_id = ? ORDER BY id DESC LIMIT 1", [$target])->fetch_assoc();
        expectAdminProfile((int) $audit['actor_user_id'] === $users['admin'] && (int) $audit['target_user_id'] === $target,
            'Audit records identify both the administrator and selected user.');
        $pictureResponse = $request('profile_picture.php?id=' . $target . '&size=full', null, $cookie);
        expectAdminProfile($pictureResponse['status'] === 200 && $saved['profile_picture'] === null && $pictureResponse['body'] === file_get_contents(persistentFilePath($saved['profile_picture_key'])),
            'The administrator can view the selected user’s saved picture.');

        $schedule = $post + ['task_digest_enabled' => '1', 'task_digest_time' => '06:30', 'task_digest_days' => ['2', '8']];
        expectAdminProfile($request($path, $schedule, $cookie)['status'] === 302, 'Profile fields and digest schedule save together.');
        $scheduled = $fetch($target);
        expectAdminProfile($scheduled['profile_picture_key'] === $saved['profile_picture_key']
            && $scheduled['task_digest_time'] === '06:30:00' && (int) $scheduled['task_digest_days'] === 10,
            'Saving without an upload preserves the picture.');
        $replacement = imagecreatetruecolor(2, 2);
        imagepng($replacement, $picturePath);
        expectAdminProfile($request($path, $post + ['profile_picture' => new CURLFile($picturePath, 'image/png', 'replacement.png')], $cookie)['status'] === 302,
            'Administrators can replace existing profile pictures.');
        $scheduled = $fetch($target);
        expectAdminProfile($scheduled['profile_picture_sha256'] !== $saved['profile_picture_sha256']
            && getimagesizefromstring(file_get_contents(persistentFilePath($scheduled['profile_picture_thumbnail_key'])))[0] === 2,
            'Replacement refreshes both the original picture and its thumbnail.');
        $duplicate = $request($path, array_replace($post, ['username' => $adminBefore['username'], 'remove_profile_picture' => '1']), $cookie);
        expectAdminProfile($duplicate['status'] === 200 && $fetch($target) === $scheduled,
            'A duplicate username rolls back personal details, digest settings, and picture removal.');
        expectAdminProfile($request($path, $post + ['remove_profile_picture' => '1'], $cookie)['status'] === 302,
            'Administrators can remove pictures.');
        $removed = $fetch($target);
        foreach (['profile_picture_key', 'profile_picture_thumbnail_key', 'profile_picture', 'profile_picture_thumbnail', 'profile_picture_thumbnail_mime', 'profile_picture_mime', 'profile_picture_sha256'] as $column) {
            expectAdminProfile($removed[$column] === null, 'Picture removal clears ' . $column);
        }

        foreach (['editor', 'inactive', 'invited', 'admin'] as $targetKind) {
            $id = $users[$targetKind];
            $original = $fetch($id);
            $save = $request('edit_user.php?id=' . $id, array_replace($post,
                ['username' => $original['username'], 'role' => $original['role'],
                    'first_name' => 'Updated', 'last_name' => $targetKind]), $cookie);
            $updated = $fetch($id);
            expectAdminProfile($save['status'] === 302 && $updated['first_name'] === 'Updated'
                && $updated['last_name'] === $targetKind && $updated['account_status'] === $original['account_status'],
                'Admins can edit ' . $targetKind . ' profiles, including their own, without changing account status.');
        }
    }
    echo "Admin user profile HTTP integration tests passed.\n";
} finally {
    foreach ($users as $id) $conn->execute_query('DELETE FROM users WHERE id = ?', [$id]);
    foreach ($sessions as $id) @unlink(session_save_path() . '/sess_' . $id);
    unlink($picturePath);
    unlink($invalidPath);
}
