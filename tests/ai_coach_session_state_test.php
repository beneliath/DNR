<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/functions.php';

function expectCoachSession(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

session_start();
try {
    putenv('DNR_AI_COACH_ENABLED=1');
    $_SESSION = ['user_id' => 123, 'role' => 'admin', '_csrf_token' => bin2hex(random_bytes(32))];
    $csrf = generateCsrfToken();
    releaseApplicationSessionLock();
    session_start();
    expectCoachSession(($_SESSION['_ai_coach_storage_token'] ?? null) === $csrf, 'Read-only pages persist the Coach identity before releasing the session lock');
    $key = aiCoachStorageKey();
    $paneKey = aiCoachPaneKey();
    expectCoachSession($key === hash('sha256', generateCsrfToken() . ':conversational-workflows-v2:admin'), 'Existing conversation keys survive the update');
    session_regenerate_id(true);
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    expectCoachSession(aiCoachStorageKey() === $key, 'Admin Unlock must preserve the conversation and pane state');
    expectCoachSession(aiCoachPaneKey() === $paneKey, 'Admin Unlock retains the pane identity');
    $_SESSION['role'] = 'reviewer';
    expectCoachSession(aiCoachStorageKey() !== $key, 'Role previews retain conversation isolation');
    expectCoachSession(aiCoachPaneKey() === $paneKey, 'Role preview navigation retains the pane choice');
    $_SESSION['role'] = 'admin';
    expectCoachSession(aiCoachStorageKey() === $key, 'Returning from a role preview restores the original identity');
    beginPendingAuthentication(['id' => 123, 'username' => 'test-user', 'role' => 'admin', 'auth_version' => 1]);
    expectCoachSession(!isset($_SESSION['_ai_coach_storage_token']), 'A new login clears the previous Coach identity');
    $_SESSION['role'] = 'admin';
    expectCoachSession(aiCoachStorageKey() !== $key, 'A new login cannot restore the previous session conversation');
    expectCoachSession(aiCoachPaneKey() !== $paneKey, 'A new login cannot restore the previous pane choice');
} finally {
    session_destroy();
}
echo "AI Coach session state tests passed.\n";
