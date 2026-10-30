<?php

declare(strict_types=1);

// A password-only or pre-2FA session must never satisfy application authorization.
function isLoggedIn() {
    return isset($_SESSION['user_id'], $_SESSION['auth_version'])
        && ($_SESSION['auth_complete'] ?? false) === true;
}

/**
 * Return the role assigned to the signed-in account, independent of an active
 * SuperAdmin role preview.
 */
function authenticatedRole() {
    return (string) ($_SESSION['authenticated_role'] ?? $_SESSION['role'] ?? '');
}

/**
 * Normalize the only roles a SuperAdmin may preview.
 */
function rolePreviewRole($role) {
    if (!is_scalar($role)) {
        return null;
    }

    $role = (string) $role;
    return in_array($role, ['admin', 'editor', 'reviewer'], true) ? $role : null;
}

function activeRolePreview() {
    if (!authenticatedSuperAdmin()) {
        return null;
    }

    return rolePreviewRole($_SESSION['_role_preview'] ?? null);
}

/**
 * Apply or stop a SuperAdmin role preview. Authorization checks continue
 * to read $_SESSION['role'], so existing page and action guards exercise the
 * selected role exactly as they do for that type of account.
 */
function setRolePreview($role) {
    if (!authenticatedSuperAdmin() || !is_scalar($role)) {
        return false;
    }

    $role = (string) $role;
    if ($role === 'superadmin') {
        unset($_SESSION['_role_preview']);
        $_SESSION['role'] = 'admin';
        return true;
    }

    $preview_role = rolePreviewRole($role);
    if ($preview_role === null) {
        return false;
    }

    $_SESSION['_role_preview'] = $preview_role;
    $_SESSION['role'] = $preview_role;
    return true;
}

/**
 * Return a local UI destination only when the selected preview role may open
 * that page. Unknown, external, and role-restricted destinations fall back to
 * the Dashboard.
 */
function safeRolePreviewReturnUrl($return_url, $role) {
    $fallback = 'dashboard.php';
    if (!is_scalar($return_url) || !is_scalar($role)) {
        return $fallback;
    }

    $return_url = trim((string) $return_url);
    $role = (string) $role;
    if ($return_url === ''
        || !in_array($role, ['superadmin', 'admin', 'editor', 'reviewer'], true)
        || preg_match('/[\x00-\x1F\x7F]/', $return_url) === 1
        || str_contains($return_url, '\\')
    ) {
        return $fallback;
    }

    $parts = parse_url($return_url);
    if (!is_array($parts)
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['port'])
    ) {
        return $fallback;
    }

    $page = (string) ($parts['path'] ?? '');
    if ($page === ''
        || basename($page) !== $page
        || preg_match('/\A[a-z0-9_]+\.php\z/i', $page) !== 1
    ) {
        return $fallback;
    }

    $shared_pages = [
        'audience.php',
        'search.php',
        'speakers.php',
        'short_links.php',
        'view_speaker.php',
        'contacts.php',
        'dashboard.php',
        'engagements.php',
        'email_templates.php',
        'reimbursements.php',
        'reimbursement_expense.php',
        'reimbursement_request.php',
        'reimbursement_requests.php',
        'reimbursement_submit.php',
        'help.php',
        'inquiries.php',
        'map.php',
        'mattermost.php',
        'organizations.php',
        'outbound_mail.php',
        'profile.php',
        'setup_2fa.php',
        'standard_tasks.php',
        'tasks.php',
        'two_factor_recovery_codes.php',
        'two_factor_settings.php',
        'view_calendar.php',
        'view_contact.php',
        'view_engagement.php',
        'view_inquiry.php',
        'view_organization.php',
        'view_standard_task.php',
    ];
    $editor_pages = [
        'task_bulk.php',
        'edit_speaker.php',
        'add_contact.php',
        'add_inquiry.php',
        'add_organization.php',
        'add_standard_task.php',
        'add_task.php',
        'close_engagement.php',
        'compose_engagement_email.php',
        'compose_inquiry_email.php',
        'convert_inquiry.php',
        'edit_contact.php',
        'edit_engagement.php',
        'edit_email_template.php',
        'reimbursement_cost_centers.php',
        'edit_inquiry.php',
        'edit_organization.php',
        'edit_standard_task.php',
        'edit_task.php',
        'inbound_mail.php',
        'index.php',
        'map_pin.php',
        'restore_chron_entries.php',
        'restore_entity_chron_entries.php',
        'restore_presentations.php',
    ];
    $administrator_pages = [
        'account_settings.php',
        'admin_elevation.php',
        'audit_log.php',
        'database_maintenance.php',
        'edit_user.php',
        'reimbursement_setup.php',
        'operations.php',
        'record_merge.php',
        'register.php',
        'reset_presentation_stats.php',
        'reset_user_password.php',
        'users.php',
    ];

    $allowed = in_array($page, $shared_pages, true)
        || ($role === 'editor' && in_array($page, $editor_pages, true))
        || (in_array($role, ['superadmin', 'admin'], true)
            && (in_array($page, $editor_pages, true) || in_array($page, $administrator_pages, true)))
        || ($role === 'superadmin' && in_array($page, [
            'accounts.php', 'mail_review.php', 'ai_coach_requests.php',
            'ai_coach_improvements.php', 'network_diagnostics.php',
        ], true));
    if ($page === 'database_maintenance.php' && accountIsPrimary() && $role !== 'superadmin') {
        $allowed = false;
    }
    if (!$allowed) {
        return $fallback;
    }

    $destination = $page;
    if (array_key_exists('query', $parts)) {
        $destination .= '?' . $parts['query'];
    }
    if (array_key_exists('fragment', $parts)) {
        $destination .= '#' . $parts['fragment'];
    }
    return $destination;
}

function isApplicationRootRequest(array $server) {
    $request_path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($request_path)) {
        return false;
    }

    $script_name = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/index.php'));
    $script_directory = rtrim(dirname($script_name), '/.');
    $application_root = ($script_directory !== '' ? $script_directory : '') . '/';

    return $request_path === $application_root;
}

// Ensure that the user is logged in; if not, redirect to the login page
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php"); // Redirect to login page if not logged in
        exit(); // Ensure no further code is executed after the redirect
    }

    global $conn;
    if (!($conn instanceof mysqli)) {
        http_response_code(500);
        exit('Authentication service unavailable.');
    }

    $user_id = (int) $_SESSION['user_id'];
    $accountColumns = accountsEnabled() ? ', is_superadmin, platform_identity_id' : '';
    $stmt = $conn->prepare(
        'SELECT id, username, role, auth_version, must_change_password, account_status, two_factor_enabled,
                first_name, last_name, profile_picture_updated_at, display_timezone' . $accountColumns . '
         FROM users
         WHERE id = ?'
    );
    if (!$stmt) {
        applicationLog('error', 'Authentication schema is unavailable', ['error' => $conn->error]);
        http_response_code(503);
        exit(applicationBrandName() . ' is being upgraded. The authentication database migration is required.');
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user
        || $user['account_status'] !== 'active'
        || (int) $user['auth_version'] !== (int) $_SESSION['auth_version']
        || ((!demoPasswordOnlyAuthenticationAllowed($user) || empty($_SESSION['_demo_password_only_auth']))
            && (empty($user['two_factor_enabled']) || empty($_SESSION['two_factor_verified_at'])))
    ) {
        session_unset();
        session_regenerate_id(true);
        header('Location: login.php');
        exit();
    }

    if (accountsEnabled()) {
        currentAccountProfile(); // Fail closed on a misconfigured database/Account pairing.
        $_SESSION['_account_key'] = currentAccountKey();
        $_SESSION['is_superadmin'] = accountIsPrimary() && !empty($user['is_superadmin']);
        if (!empty($user['platform_identity_id'])) {
            try {
                $identity = platformCall('validate', platformIdentityPayload());
                if ((int) $identity['id'] !== (int) $user['platform_identity_id']) throw new RuntimeException('Invalid identity.');
                $_SESSION['is_superadmin'] = true;
                $user['username'] = $identity['username'];
                $user['first_name'] = $identity['first_name'];
                $user['last_name'] = $identity['last_name'];
                $user['display_timezone'] = $identity['display_timezone']??null;
            } catch (Throwable $error) {
                session_unset(); session_regenerate_id(true);
                http_response_code(403); exit('SuperAdmin access could not be verified. Return to the primary Account.');
            }
            if (in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['profile.php', 'two_factor_settings.php', 'two_factor_recovery_codes.php'], true)) {
                header('Location: ' . accountPrimaryPublicUrl() . '/profile.php'); exit;
            }
        } elseif (!empty($_SESSION['_platform_identity'])) {
            session_unset(); session_regenerate_id(true); http_response_code(403); exit('Forbidden.');
        }
    }
    $_SESSION['username'] = (string) $user['username'];
    $authenticated_role = (string) $user['role'];
    $_SESSION['authenticated_role'] = $authenticated_role;
    $preview_role = activeRolePreview();
    if ($preview_role === null) {
        unset($_SESSION['_role_preview']);
    }
    $_SESSION['role'] = $preview_role ?? $authenticated_role;
    $_SESSION['profile_first_name'] = trim((string) ($user['first_name'] ?? ''));
    $_SESSION['display_timezone'] = $user['display_timezone']??null;
    $profile_display_name = trim(
        trim((string) ($user['first_name'] ?? ''))
        . ' '
        . trim((string) ($user['last_name'] ?? ''))
    );
    $_SESSION['profile_display_name'] = $profile_display_name !== ''
        ? $profile_display_name
        : (string) $user['username'];
    $_SESSION['profile_picture_version'] = ($user['profile_picture_updated_at'] ?? null) !== null
        ? (int) strtotime((string) $user['profile_picture_updated_at'])
        : 0;
    setDatabaseAuditContext($conn, $user_id, (string) $user['username']);
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $password_change_pages = [
        'two_factor_settings.php',
        'two_factor_recovery_codes.php',
        'profile_picture.php',
    ];
    if ($_SESSION['must_change_password'] && !in_array($current_script, $password_change_pages, true)) {
        header('Location: two_factor_settings.php?password_reset_required=1');
        exit();
    }
}

function requireAdmin() {
    requireLogin();

    if (!checkRole('admin')) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

// Keep a password-verified identity separate until its required second factor succeeds.
function beginPendingAuthentication(array $user) {
    session_regenerate_id(true);
    unset(
        $_SESSION['_ai_coach_storage_token'],
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['authenticated_role'],
        $_SESSION['auth_version'],
        $_SESSION['auth_complete'],
        $_SESSION['two_factor_verified_at'],
        $_SESSION['_demo_password_only_auth'],
        $_SESSION['must_change_password'],
        $_SESSION['_role_preview'], $_SESSION['_platform_identity'], $_SESSION['is_superadmin'],
        $_SESSION['_admin_elevated_at'], $_SESSION['_admin_elevation_expires_at'], $_SESSION['_platform_admin_elevation']
    );

    $_SESSION['_pending_auth'] = [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'auth_version' => (int) $user['auth_version'],
        'issued_at' => time(),
    ];
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function getPendingAuthentication() {
    $pending = $_SESSION['_pending_auth'] ?? null;

    // A fully authenticated user may have a separate in-progress 2FA
    // enrollment. The absence of a pending-login record must not erase it.
    if ($pending === null) {
        return null;
    }

    if (!is_array($pending)
        || empty($pending['user_id'])
        || empty($pending['username'])
        || empty($pending['role'])
        || !isset($pending['auth_version'])
        || !isset($pending['issued_at'])
        || (time() - (int) $pending['issued_at']) > 600
    ) {
        unset($_SESSION['_pending_auth'], $_SESSION['_two_factor_enrollment']);
        return null;
    }

    return $pending;
}

// Password recovery is allowed only after proving possession of an enrolled
// authenticator or one unused recovery code. Keep that proof short-lived.
function beginPasswordRecovery($eligible_user = null, $attempted_username = '') {
    session_regenerate_id(true);
    unset(
        $_SESSION['_ai_coach_storage_token'],
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['authenticated_role'],
        $_SESSION['auth_version'],
        $_SESSION['auth_complete'],
        $_SESSION['two_factor_verified_at'],
        $_SESSION['_pending_auth'],
        $_SESSION['_two_factor_enrollment'],
        $_SESSION['_role_preview']
    );

    $_SESSION['_password_recovery'] = [
        'user_id' => is_array($eligible_user) ? (int) $eligible_user['id'] : 0,
        'auth_version' => is_array($eligible_user) ? (int) $eligible_user['auth_version'] : 0,
        'account_identifier' => authenticationRateLimitAccountIdentifier($attempted_username),
        'stage' => 'verify',
        'issued_at' => time(),
        'verified_at' => null,
        'attempts' => 0,
    ];
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function getPasswordRecovery($required_stage = null, $now = null) {
    $recovery = $_SESSION['_password_recovery'] ?? null;
    $now = $now ?? time();

    if (!is_array($recovery)
        || !isset($recovery['user_id'], $recovery['auth_version'], $recovery['stage'], $recovery['issued_at'])
        || ($now - (int) $recovery['issued_at']) > 600
        || ($required_stage !== null && $recovery['stage'] !== $required_stage)
        || ($recovery['stage'] === 'reset'
            && (!isset($recovery['verified_at']) || ($now - (int) $recovery['verified_at']) > 300))
    ) {
        unset($_SESSION['_password_recovery']);
        return null;
    }

    return $recovery;
}

function markPasswordRecoveryVerified() {
    $recovery = getPasswordRecovery('verify');
    if (!$recovery) {
        return false;
    }

    session_regenerate_id(true);
    $recovery['stage'] = 'reset';
    $recovery['verified_at'] = time();
    $recovery['attempts'] = 0;
    $_SESSION['_password_recovery'] = $recovery;
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return true;
}

function clearPasswordRecovery() {
    unset($_SESSION['_password_recovery']);
}

function completeAuthentication(mysqli $conn, array $user, $two_factor_verified = false) {
    if (($user['account_status'] ?? 'active') !== 'active') {
        throw new RuntimeException('Inactive accounts cannot complete authentication.');
    }
    $demo_password_only = demoPasswordOnlyAuthenticationAllowed($user);
    $pending = $_SESSION['_pending_auth'] ?? [];
    if ($demo_password_only && ((int) ($pending['user_id'] ?? 0) !== (int) $user['id']
        || (int) ($pending['auth_version'] ?? 0) !== (int) $user['auth_version']
        || !is_int($pending['issued_at'] ?? null) || time() - $pending['issued_at'] < 0
        || time() - $pending['issued_at'] > 600)) {
        throw new RuntimeException('A recent password-verified demo identity is required.');
    }
    if (!$demo_password_only && (empty($user['two_factor_enabled']) || !$two_factor_verified)) {
        throw new RuntimeException('All accounts must complete two-factor authentication before signing in.');
    }
    $user_id = (int) $user['id'];
    setDatabaseAuditContext($conn, $user_id, (string) $user['username']);
    $stmt = $conn->prepare(
        'UPDATE users
         SET last_login_at = UTC_TIMESTAMP(),
             last_updated_at = last_updated_at
         WHERE id = ?'
    );

    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            applicationLog('error', 'Unable to record the successful login timestamp', ['error' => $stmt->error]);
        }
    } else {
        applicationLog('error', 'Unable to prepare the successful login timestamp update', ['error' => $conn->error]);
    }

    recordAuditEvent($conn, [
        'event_category' => 'login',
        'event_type' => 'successful_login',
        'actor_user_id' => $user_id,
        'actor_username' => (string) $user['username'],
        'target_user_id' => $user_id,
        'target_username' => (string) $user['username'],
        'entity_type' => 'users',
        'entity_id' => $user_id,
        'entity_label' => (string) $user['username'],
        'details' => $demo_password_only ? 'Operator-approved Test Account password-only demo sign-in' : 'Two-factor authentication verified',
    ]);

    session_regenerate_id(true);
    unset(
        $_SESSION['_pending_auth'],
        $_SESSION['_two_factor_enrollment'],
        $_SESSION['_role_preview'],
        $_SESSION['_ai_coach_storage_token']
    );

    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['authenticated_role'] = (string) $user['role'];
    $_SESSION['auth_version'] = (int) $user['auth_version'];
    $_SESSION['auth_complete'] = true;
    if (accountsEnabled()) {
        $_SESSION['_account_key'] = currentAccountKey();
        $_SESSION['is_superadmin'] = accountIsPrimary() && !empty($user['is_superadmin']);
    }
    if ($demo_password_only) {
        $_SESSION['_demo_password_only_auth'] = true;
        unset($_SESSION['two_factor_verified_at']);
    } else {
        $_SESSION['two_factor_verified_at'] = time();
        unset($_SESSION['_demo_password_only_auth']);
    }
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function authenticationDestination(array $user) {
    if (!empty($user['must_change_password'])) {
        return 'two_factor_settings.php?password_reset_required=1';
    }

    return 'dashboard.php';
}

function twoFactorRecoveryCodesDestination($initial_login = false) {
    return $initial_login ? 'dashboard.php' : 'two_factor_settings.php';
}

function hasRecentTwoFactorVerification($maximum_age_seconds = 300) {
    $verified_at = $_SESSION['two_factor_verified_at'] ?? null;
    return is_int($verified_at) && (time() - $verified_at) <= $maximum_age_seconds;
}

// Check if the logged-in user has a specific role
function checkRole($role) {
    return (isset($_SESSION['role']) && $_SESSION['role'] === $role);
}
