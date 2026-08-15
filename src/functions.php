<?php
// Start a cookie-only session with safe defaults for HTTP development and HTTPS deployment.
function startSecureSession() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $forwarded_proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $forwarded_proto === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// A password-only or pre-2FA session must never satisfy application authorization.
function isLoggedIn() {
    return isset($_SESSION['user_id'], $_SESSION['auth_version'])
        && ($_SESSION['auth_complete'] ?? false) === true;
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
    $stmt = $conn->prepare('SELECT auth_version, must_change_password FROM users WHERE id = ?');
    if (!$stmt) {
        error_log('DNR authentication schema is unavailable: ' . $conn->error);
        http_response_code(503);
        exit('DNR is being upgraded. The authentication database migration is required.');
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || (int) $user['auth_version'] !== (int) $_SESSION['auth_version']) {
        session_unset();
        session_regenerate_id(true);
        header('Location: login.php');
        exit();
    }

    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $password_change_pages = ['two_factor_settings.php', 'two_factor_recovery_codes.php'];
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
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['auth_version'],
        $_SESSION['auth_complete'],
        $_SESSION['two_factor_verified_at'],
        $_SESSION['must_change_password']
    );

    $_SESSION['_pending_auth'] = [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'issued_at' => time(),
    ];
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function getPendingAuthentication() {
    $pending = $_SESSION['_pending_auth'] ?? null;

    if (!is_array($pending)
        || empty($pending['user_id'])
        || empty($pending['username'])
        || empty($pending['role'])
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
function beginPasswordRecovery($eligible_user = null) {
    session_regenerate_id(true);
    unset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['auth_version'],
        $_SESSION['auth_complete'],
        $_SESSION['two_factor_verified_at'],
        $_SESSION['_pending_auth'],
        $_SESSION['_two_factor_enrollment']
    );

    $_SESSION['_password_recovery'] = [
        'user_id' => is_array($eligible_user) ? (int) $eligible_user['id'] : 0,
        'auth_version' => is_array($eligible_user) ? (int) $eligible_user['auth_version'] : 0,
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
    $user_id = (int) $user['id'];
    $stmt = $conn->prepare(
        'UPDATE users
         SET last_login_at = UTC_TIMESTAMP(),
             last_updated_at = last_updated_at
         WHERE id = ?'
    );

    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            error_log('Unable to record the successful login timestamp: ' . $stmt->error);
        }
    } else {
        error_log('Unable to prepare the successful login timestamp update: ' . $conn->error);
    }

    session_regenerate_id(true);
    unset($_SESSION['_pending_auth'], $_SESSION['_two_factor_enrollment']);

    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['auth_version'] = (int) $user['auth_version'];
    $_SESSION['auth_complete'] = true;
    $_SESSION['two_factor_verified_at'] = $two_factor_verified ? time() : null;
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function authenticationDestination(array $user) {
    if (!empty($user['must_change_password'])) {
        return 'two_factor_settings.php?password_reset_required=1';
    }

    return 'engagements.php';
}

function twoFactorRecoveryCodesDestination($initial_login = false) {
    return $initial_login ? 'engagements.php' : 'two_factor_settings.php';
}

function hasRecentTwoFactorVerification($maximum_age_seconds = 300) {
    $verified_at = $_SESSION['two_factor_verified_at'] ?? null;
    return is_int($verified_at) && (time() - $verified_at) <= $maximum_age_seconds;
}

// Check if the logged-in user has a specific role
function checkRole($role) {
    return (isset($_SESSION['role']) && $_SESSION['role'] === $role);
}

// Check if the logged-in user has any of the specified roles
function hasRole($roles = []) {
    return (isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true));
}

// Generate one CSRF token per session for state-changing requests.
function generateCsrfToken() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

// Render the hidden token field used by POST forms.
function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Validate a submitted CSRF token without leaking the session token.
function validateCsrfToken($token) {
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

// Stop a state-changing request before it reaches the database.
function requireValidCsrfToken() {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid or expired request token. Please reload the page and try again.');
    }
}

// Keep archive operations consistent across each of the record lists. The
// existing is_deleted columns are the reversible archive flag; permanent
// deletion is handled separately below.
function archiveEntity(mysqli $conn, $entity, $id) {
    return setEntityArchived($conn, $entity, $id, true);
}

function restoreEntity(mysqli $conn, $entity, $id) {
    return setEntityArchived($conn, $entity, $id, false);
}

function setEntityArchived(mysqli $conn, $entity, $id, $is_archived) {
    $tables = [
        'contact' => 'contacts',
        'engagement' => 'engagements',
        'event' => 'engagements',
        'organization' => 'organizations',
    ];

    if (!isset($tables[$entity]) || (int) $id < 1) {
        return false;
    }

    $archive_value = $is_archived ? 1 : 0;
    $stmt = $conn->prepare(
        "UPDATE {$tables[$entity]} SET is_deleted = ? WHERE id = ?"
    );
    if (!$stmt) {
        return false;
    }

    $id = (int) $id;
    $stmt->bind_param('ii', $archive_value, $id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

// Permanently remove an entity. Organization deletion explicitly removes its
// dependent records so the operation behaves consistently on existing
// databases whose foreign keys do not use ON DELETE CASCADE.
function permanentlyDeleteEntity(mysqli $conn, $entity, $id) {
    $id = (int) $id;
    if ($id < 1) {
        return false;
    }

    if ($entity === 'organization') {
        return permanentlyDeleteOrganization($conn, $id);
    }

    if ($entity === 'engagement' || $entity === 'event') {
        return permanentlyDeleteEngagement($conn, $id);
    }

    if ($entity !== 'contact') {
        return false;
    }

    $stmt = $conn->prepare('DELETE FROM contacts WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $id);
    $success = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();

    return $success;
}

function permanentlyDeleteEngagement(mysqli $conn, $engagement_id) {
    if (!$conn->begin_transaction()) {
        return false;
    }

    $presentation_stmt = $conn->prepare(
        'DELETE FROM presentations WHERE engagement_id = ?'
    );
    $engagement_stmt = $conn->prepare('DELETE FROM engagements WHERE id = ?');
    if (!$presentation_stmt || !$engagement_stmt) {
        if ($presentation_stmt) {
            $presentation_stmt->close();
        }
        if ($engagement_stmt) {
            $engagement_stmt->close();
        }
        $conn->rollback();
        return false;
    }

    $presentation_stmt->bind_param('i', $engagement_id);
    $presentations_deleted = $presentation_stmt->execute();
    $presentation_stmt->close();

    $engagement_stmt->bind_param('i', $engagement_id);
    $engagement_deleted = $engagement_stmt->execute()
        && $engagement_stmt->affected_rows === 1;
    $engagement_stmt->close();

    if (!$presentations_deleted || !$engagement_deleted || !$conn->commit()) {
        $conn->rollback();
        return false;
    }

    return true;
}

function permanentlyDeleteOrganization(mysqli $conn, $organization_id) {
    if (!$conn->begin_transaction()) {
        return false;
    }

    $queries = [
        'DELETE p FROM presentations p
         INNER JOIN engagements e ON e.id = p.engagement_id
         WHERE e.organization_id = ?',
        'DELETE FROM engagements WHERE organization_id = ?',
        'DELETE FROM contacts WHERE organization_id = ?',
        'DELETE FROM organizations WHERE id = ?',
    ];

    foreach ($queries as $index => $query) {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            $conn->rollback();
            return false;
        }

        $stmt->bind_param('i', $organization_id);
        $success = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        $is_organization_delete = $index === count($queries) - 1;
        if (!$success || ($is_organization_delete && $affected_rows !== 1)) {
            $conn->rollback();
            return false;
        }
    }

    if (!$conn->commit()) {
        $conn->rollback();
        return false;
    }

    return true;
}
?>
