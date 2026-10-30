<?php

declare(strict_types=1);

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
        applicationLog('error', 'Unable to prepare the database audit context', ['error' => $conn->error]);
        return false;
    }

    $stmt->bind_param('iss', $actor_user_id, $actor_username, $ip_address);
    $success = $stmt->execute();
    if (!$success) {
        applicationLog('error', 'Unable to set the database audit context', ['error' => $stmt->error]);
    }
    $stmt->close();

    return $success;
}

function auditLogSchemaAvailable(mysqli $conn) {
    return true;
}

function requireAuditLogSchema(mysqli $conn) {
    // Schema readiness is checked by the container health check. Runtime
    // requests avoid information_schema queries and surface query failures in
    // the route that needs the unavailable table.
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
        applicationLog('error', 'Unable to prepare an audit log event', ['error' => $conn->error]);
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
        applicationLog('error', 'Unable to record an audit log event', ['error' => $stmt->error]);
    }
    $stmt->close();

    return $success;
}

function failedLoginAuditEvent($attempted_username, $details, $user = null) {
    $known_user = is_array($user) && !empty($user['id']) && isset($user['username']);
    $username = $known_user
        ? trim((string) $user['username'])
        : trim((string) $attempted_username);

    return [
        'event_category' => 'login',
        'event_type' => 'failed_login',
        'actor_user_id' => null,
        'actor_username' => null,
        'target_user_id' => $known_user ? (int) $user['id'] : null,
        'target_username' => $username === '' ? null : $username,
        'entity_type' => 'users',
        'entity_id' => $known_user ? (int) $user['id'] : null,
        'entity_label' => $username === '' ? '(blank username)' : $username,
        'details' => (string) $details,
    ];
}

function recordFailedLoginAttempt(mysqli $conn, $attempted_username, $details, $user = null) {
    return recordAuditEvent(
        $conn,
        failedLoginAuditEvent($attempted_username, $details, $user)
    );
}
