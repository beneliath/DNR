<?php

declare(strict_types=1);

function persistApplicationSession(): void {
    if (!session_write_close()) throw new RuntimeException('Unable to persist the application session.');
}

function reopenApplicationSession(string $id): void {
    session_id($id);
    if (!session_start()) throw new RuntimeException('Unable to resume the application session.');
}

/**
 * Move periodic rotations through a short-lived, unauthenticated forwarding
 * record. Native session locks serialize requests on the successor, so drafts,
 * CSRF tokens and logout always use one current session instead of copied state.
 * Login and privilege changes still destroy their previous session immediately.
 */
function rotateApplicationSession(int $now): void {
    $old_id = session_id();
    $data = $_SESSION;
    $_SESSION = ['_session_transition_at' => $now];
    if (!session_regenerate_id(false)) {
        $_SESSION = $data;
        throw new RuntimeException('Unable to rotate the application session.');
    }
    $new_id = session_id();
    $_SESSION = $data;
    $_SESSION['_session_rotated_at'] = $now;
    persistApplicationSession();

    // Publish the pointer only after the new session exists. The old record
    // deliberately contains no identity, credentials, CSRF token or draft.
    reopenApplicationSession($old_id);
    if (session_id() === $old_id) {
        $_SESSION = ['_session_transition_at' => $now, '_session_successor_id' => $new_id];
    }
    persistApplicationSession();
    reopenApplicationSession($new_id);
}

function resumeApplicationSessionTransition(): void {
    for ($attempt = 0; isset($_SESSION['_session_transition_at']); $attempt++) {
        $issued_at = $_SESSION['_session_transition_at'];
        $age = is_int($issued_at) ? time() - $issued_at : -1;
        if ($age < 0 || $age > 30) {
            // A stale forwarding cookie must never restore authentication.
            session_unset();
            session_regenerate_id(true);
            return;
        }
        $successor = $_SESSION['_session_successor_id'] ?? null;
        if (is_string($successor) && $successor !== session_id()
            && preg_match('/\A[a-zA-Z0-9,-]{16,256}\z/', $successor) === 1
        ) {
            persistApplicationSession();
            // Keep strict mode enabled. A successor destroyed by logout or a
            // privilege change becomes anonymous, never rebuilt from old data.
            reopenApplicationSession($successor);
            return;
        }
        if ($attempt >= 100) {
            persistApplicationSession();
            abortApplication(503, 'The session is changing. Please retry the request.');
        }
        // The rotating request is persisting its successor. Drop the old lock
        // while waiting, otherwise it could never publish the forwarding ID.
        persistApplicationSession();
        usleep(10000);
        reopenApplicationSession(session_id());
    }
}

// Start a cookie-only session with safe defaults for HTTP development and HTTPS deployment.
function startSecureSession() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $is_https = requestUsesHttps();
    if (applicationRequiresHttps() && !$is_https) {
        http_response_code(400);
        exit('HTTPS is required for this deployment.');
    }
    sendApplicationSecurityHeaders();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => accountCookiePath(true),
        'secure' => $is_https || applicationRequiresHttps(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    if (accountsEnabled()) session_name('MOED_' . substr(hash('sha256', currentAccountKey()), 0, 16));
    session_start();
    resumeApplicationSessionTransition();
    if (accountsEnabled() && isset($_SESSION['_account_key']) && $_SESSION['_account_key'] !== currentAccountKey()) {
        session_unset(); session_regenerate_id(true);
    }

    $now = time();
    $idle_timeout = max(300, (int) (getenv('DNR_SESSION_IDLE_SECONDS') ?: 43200));
    $absolute_timeout = max($idle_timeout, (int) (getenv('DNR_SESSION_ABSOLUTE_SECONDS') ?: 86400));
    $rotation_interval = max(300, (int) (getenv('DNR_SESSION_ROTATION_SECONDS') ?: 900));
    $started_at = (int) ($_SESSION['_session_started_at'] ?? $now);
    $last_seen_at = (int) ($_SESSION['_session_last_seen_at'] ?? $now);

    if (($now - $last_seen_at) > $idle_timeout || ($now - $started_at) > $absolute_timeout) {
        session_unset();
        session_regenerate_id(true);
        $started_at = $now;
    } elseif (($now - (int) ($_SESSION['_session_rotated_at'] ?? $started_at)) >= $rotation_interval) {
        rotateApplicationSession($now);
    }

    $_SESSION['_session_started_at'] = $started_at;
    $_SESSION['_session_last_seen_at'] = $now;
    $_SESSION['_session_rotated_at'] = (int) ($_SESSION['_session_rotated_at'] ?? $now);
}

/**
 * Persist the current session and release its file lock before slow read-only
 * database work or response streaming. Session values remain readable for the
 * remainder of the request, but callers must perform every required session
 * mutation (including CSRF token creation) before invoking this helper.
 */
function releaseApplicationSessionLock(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }

    // Read-only pages render the Coach after releasing this lock.
    if (aiCoachEnabled() && !empty($_SESSION['user_id'])) aiCoachStorageKey();

    $released = session_write_close();
    if (!$released) {
        applicationLog('warning', 'Unable to release the PHP session lock early');
    }

    return $released;
}
