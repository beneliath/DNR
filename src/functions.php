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
    $stmt = $conn->prepare('SELECT username, role, auth_version, must_change_password FROM users WHERE id = ?');
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

    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    setDatabaseAuditContext($conn, $user_id, (string) $user['username']);
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
            error_log('Unable to record the successful login timestamp: ' . $stmt->error);
        }
    } else {
        error_log('Unable to prepare the successful login timestamp update: ' . $conn->error);
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
        'details' => $two_factor_verified ? 'Two-factor authentication verified' : 'Password authentication verified',
    ]);

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

// Attach the authenticated user to database-trigger audit entries for this
// connection. MySQL user variables are scoped to one connection and never
// contain credentials or authentication factors.
function setDatabaseAuditContext(mysqli $conn, $user_id = null, $username = null) {
    $actor_user_id = $user_id === null ? null : (int) $user_id;
    $actor_username = $username === null ? null : substr((string) $username, 0, 50);
    $ip_address = requestIpAddress();
    $stmt = $conn->prepare(
        'SET @dnr_actor_user_id = ?, @dnr_actor_username = ?, @dnr_request_ip = ?'
    );

    if (!$stmt) {
        error_log('Unable to prepare the database audit context: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('iss', $actor_user_id, $actor_username, $ip_address);
    $success = $stmt->execute();
    if (!$success) {
        error_log('Unable to set the database audit context: ' . $stmt->error);
    }
    $stmt->close();

    return $success;
}

function requestIpAddress($server = null) {
    $server = is_array($server) ? $server : $_SERVER;
    $remote_address = trim((string) ($server['REMOTE_ADDR'] ?? ''));
    $remote_address = filter_var($remote_address, FILTER_VALIDATE_IP)
        ? $remote_address
        : null;

    if ($remote_address !== null && isTrustedProxyAddress($remote_address)) {
        $forwarded_for = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        foreach (explode(',', $forwarded_for) as $forwarded_address) {
            $forwarded_address = trim($forwarded_address);
            if (filter_var($forwarded_address, FILTER_VALIDATE_IP)) {
                $cloudflare_address = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
                $cloudflare_ray = trim((string) ($server['HTTP_CF_RAY'] ?? ''));
                if (isTrustedCloudflareProxyAddress($forwarded_address)
                    && filter_var($cloudflare_address, FILTER_VALIDATE_IP)
                    && preg_match('/^[0-9a-f]{16,32}(?:-[a-z]{3})?$/i', $cloudflare_ray)
                ) {
                    return substr($cloudflare_address, 0, 45);
                }

                return substr($forwarded_address, 0, 45);
            }
        }
    }

    return $remote_address === null ? null : substr($remote_address, 0, 45);
}

function isTrustedProxyAddress($ip_address) {
    $configured_proxies = getenv('DNR_TRUSTED_PROXY_IPS');
    if (!is_string($configured_proxies) || trim($configured_proxies) === '') {
        $configured_proxies = '192.168.65.1';
    }

    return isAddressInTrustedNetworks($ip_address, $configured_proxies);
}

function isTrustedCloudflareProxyAddress($ip_address) {
    $configured_proxies = getenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
    if (!is_string($configured_proxies) || trim($configured_proxies) === '') {
        $configured_proxies = '172.18.0.14';
    }

    return isAddressInTrustedNetworks($ip_address, $configured_proxies);
}

function isAddressInTrustedNetworks($ip_address, $configured_networks) {
    if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
        return false;
    }

    $trusted_networks = array_filter(array_map('trim', explode(',', $configured_networks)));
    foreach ($trusted_networks as $trusted_network) {
        if (ipAddressMatchesNetwork($ip_address, $trusted_network)) {
            return true;
        }
    }

    return false;
}

function ipAddressMatchesNetwork($ip_address, $trusted_network) {
    if (strpos($trusted_network, '/') === false) {
        return $ip_address === $trusted_network;
    }

    [$network_address, $prefix_length] = array_map('trim', explode('/', $trusted_network, 2));
    if (!ctype_digit($prefix_length)) {
        return false;
    }

    $packed_address = @inet_pton($ip_address);
    $packed_network = @inet_pton($network_address);
    if ($packed_address === false
        || $packed_network === false
        || strlen($packed_address) !== strlen($packed_network)
    ) {
        return false;
    }

    $prefix_length = (int) $prefix_length;
    $maximum_prefix_length = strlen($packed_address) * 8;
    if ($prefix_length < 0 || $prefix_length > $maximum_prefix_length) {
        return false;
    }

    $whole_bytes = intdiv($prefix_length, 8);
    if (substr($packed_address, 0, $whole_bytes) !== substr($packed_network, 0, $whole_bytes)) {
        return false;
    }

    $remaining_bits = $prefix_length % 8;
    if ($remaining_bits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remaining_bits)) & 0xff;
    return (ord($packed_address[$whole_bytes]) & $mask)
        === (ord($packed_network[$whole_bytes]) & $mask);
}

function auditLogSchemaAvailable(mysqli $conn) {
    $result = $conn->query(
        "SHOW COLUMNS FROM security_audit_log LIKE 'event_category'"
    );
    return $result && $result->num_rows === 1;
}

function requireAuditLogSchema(mysqli $conn) {
    if (!auditLogSchemaAvailable($conn)) {
        error_log('DNR audit log migration has not been applied.');
        http_response_code(503);
        exit('DNR is being upgraded. The audit log database migration is required.');
    }
}

function auditUsernameForId(mysqli $conn, $user_id) {
    if ($user_id === null || (int) $user_id < 1) {
        return null;
    }

    if ((int) ($_SESSION['user_id'] ?? 0) === (int) $user_id
        && isset($_SESSION['username'])
    ) {
        return substr((string) $_SESSION['username'], 0, 50);
    }

    $user_id = (int) $user_id;
    $stmt = $conn->prepare('SELECT username FROM users WHERE id = ?');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user ? substr((string) $user['username'], 0, 50) : null;
}

// Record semantic events (such as successful logins and security actions).
// Row-level database changes are recorded by triggers created by the audit
// migration. Audit failures are logged without blocking authentication on a
// deployment where application files precede the database migration.
function recordAuditEvent(mysqli $conn, array $event) {
    $event_category = substr((string) ($event['event_category'] ?? 'security'), 0, 30);
    $event_type = substr((string) ($event['event_type'] ?? ''), 0, 50);
    if ($event_type === '') {
        return false;
    }

    $actor_user_id = isset($event['actor_user_id']) ? (int) $event['actor_user_id'] : null;
    $target_user_id = isset($event['target_user_id']) ? (int) $event['target_user_id'] : null;
    $actor_username = array_key_exists('actor_username', $event)
        ? $event['actor_username']
        : auditUsernameForId($conn, $actor_user_id);
    $target_username = array_key_exists('target_username', $event)
        ? $event['target_username']
        : auditUsernameForId($conn, $target_user_id);
    $actor_username = $actor_username === null ? null : substr((string) $actor_username, 0, 50);
    $target_username = $target_username === null ? null : substr((string) $target_username, 0, 50);
    $entity_type = isset($event['entity_type'])
        ? substr((string) $event['entity_type'], 0, 50)
        : null;
    $entity_id = isset($event['entity_id']) ? (int) $event['entity_id'] : null;
    $entity_label = isset($event['entity_label'])
        ? substr((string) $event['entity_label'], 0, 255)
        : null;
    $details = isset($event['details'])
        ? substr((string) $event['details'], 0, 255)
        : null;
    $ip_address = requestIpAddress();

    $stmt = $conn->prepare(
        'INSERT INTO security_audit_log (
            actor_user_id, actor_username, target_user_id, target_username,
            event_category, event_type, entity_type, entity_id, entity_label,
            details, ip_address
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('Unable to prepare an audit log event: ' . $conn->error);
        return false;
    }

    $stmt->bind_param(
        'isissssisss',
        $actor_user_id,
        $actor_username,
        $target_user_id,
        $target_username,
        $event_category,
        $event_type,
        $entity_type,
        $entity_id,
        $entity_label,
        $details,
        $ip_address
    );
    $success = $stmt->execute();
    if (!$success) {
        error_log('Unable to record an audit log event: ' . $stmt->error);
    }
    $stmt->close();

    return $success;
}

// Check if the logged-in user has any of the specified roles
function hasRole($roles = []) {
    return (isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true));
}

function canArchiveEntries($role) {
    return in_array($role, ['admin', 'editor'], true);
}

function canDeleteEntries($role) {
    return $role === 'admin';
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
    if (!canArchiveEntries($_SESSION['role'] ?? null)) {
        return false;
    }

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
    if (!canDeleteEntries($_SESSION['role'] ?? null)) {
        return false;
    }

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

    $chron_stmt = $conn->prepare(
        'DELETE FROM engagement_chron_entries WHERE engagement_id = ?'
    );
    $presentation_stmt = $conn->prepare(
        'DELETE FROM presentations WHERE engagement_id = ?'
    );
    $engagement_stmt = $conn->prepare('DELETE FROM engagements WHERE id = ?');
    if (!$chron_stmt || !$presentation_stmt || !$engagement_stmt) {
        if ($chron_stmt) {
            $chron_stmt->close();
        }
        if ($presentation_stmt) {
            $presentation_stmt->close();
        }
        if ($engagement_stmt) {
            $engagement_stmt->close();
        }
        $conn->rollback();
        return false;
    }

    $chron_stmt->bind_param('i', $engagement_id);
    $chron_entries_deleted = $chron_stmt->execute();
    $chron_stmt->close();

    $presentation_stmt->bind_param('i', $engagement_id);
    $presentations_deleted = $presentation_stmt->execute();
    $presentation_stmt->close();

    $engagement_stmt->bind_param('i', $engagement_id);
    $engagement_deleted = $engagement_stmt->execute()
        && $engagement_stmt->affected_rows === 1;
    $engagement_stmt->close();

    if (!$chron_entries_deleted || !$presentations_deleted || !$engagement_deleted || !$conn->commit()) {
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
        'DELETE ce FROM engagement_chron_entries ce
         INNER JOIN engagements e ON e.id = ce.engagement_id
         WHERE e.organization_id = ?',
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

function actionIconSvg($action)
{
    static $icons = [
        'view' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>',
        'edit' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>',
        'archive' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v12h14V8M9 12h6"/></svg>',
        'restore' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
        'delete' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 10v6M14 10v6"/></svg>',
    ];

    return $icons[$action] ?? '';
}
?>
