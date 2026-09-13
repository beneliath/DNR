<?php
declare(strict_types=1);
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src') . '/two_factor_helpers.php';

/** Mark an explicitly synthetic integration session as having completed MFA. */
function completeIntegrationTestMfaSession(): void {
    if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
        throw new RuntimeException('Disposable integration fixtures required.');
    }
    global $conn;
    $user = fetchAuthenticationUserById($conn, (int) $_SESSION['user_id']);
    if (empty($user['two_factor_enabled'])) {
        enableTwoFactorForUser($conn, (int) $user['id'], generateTotpSecret(), 0, (int) $user['auth_version']);
        $user = fetchAuthenticationUserById($conn, (int) $user['id']);
    }
    $_SESSION['auth_version'] = (int) $user['auth_version'];
    $_SESSION['two_factor_verified_at'] = time();
}

/** Complete the real next-login enrollment for disposable HTTP fixtures. */
function finishIntegrationTestEnrollment(callable $request, array $login): array {
    if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
        throw new RuntimeException('Disposable integration fixtures required.');
    }
    static $codes = [];
    $clientId = spl_object_id($request);
    if (str_contains($login['headers'], 'Location: verify_2fa.php')) {
        $form = $request('verify_2fa.php');
        preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $form['body'], $csrf);
        if (empty($codes[$clientId])) throw new RuntimeException('Test recovery codes unavailable.');
        return $request('verify_2fa.php', ['csrf_token' => html_entity_decode($csrf[1], ENT_QUOTES),
            'authentication_code' => array_shift($codes[$clientId])]);
    }
    if (!str_contains($login['headers'], 'Location: setup_2fa.php')) return $login;
    $setup = $request('setup_2fa.php');
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/', $setup['body'], $csrf);
    preg_match('/<code class="manual-secret">([^<]+)<\/code>/', $setup['body'], $secret);
    if (empty($secret[1]) || empty($csrf[1])) throw new RuntimeException('Test enrollment form unavailable.');
    $confirm = $request('setup_2fa.php', ['csrf_token' => html_entity_decode($csrf[1], ENT_QUOTES), 'action' => 'confirm',
        'authentication_code' => createTotp($secret[1], 'integration fixture')->now()]);
    if (!str_contains($confirm['headers'], 'Location: two_factor_recovery_codes.php')) throw new RuntimeException('Test enrollment failed.');
    $recovery = $request('two_factor_recovery_codes.php');
    preg_match_all('/<code>([^<]+)<\/code>/', $recovery['body'], $matches);
    $codes[$clientId] = $matches[1];
    return $request('login.php');
}
