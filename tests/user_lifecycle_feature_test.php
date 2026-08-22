<?php

require_once __DIR__ . '/../src/email_helpers.php';
require_once __DIR__ . '/../src/user_lifecycle_helpers.php';

function expectUserLifecycle($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "User lifecycle feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn($path) => file_get_contents($root . '/' . $path);

expectUserLifecycle(
    normalizeAccountEmail('  User.Name+tag@Example.ORG ') === 'user.name+tag@example.org',
    'account email addresses should be validated and canonicalized.'
);
try {
    normalizeAccountEmail("bad\n@example.org");
    expectUserLifecycle(false, 'an invalid email address should be rejected.');
} catch (InvalidArgumentException $exception) {
    expectUserLifecycle(true, 'the invalid email address was rejected.');
}

$raw_token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$token_hash = userEmailTokenHash($raw_token);
expectUserLifecycle(
    is_string($token_hash)
        && strlen($token_hash) === 32
        && userEmailTokenHash('short') === null,
    'email tokens should use strict base64url bearer values and binary SHA-256 digests.'
);
expectUserLifecycle(
    userEmailTokenLifetime('invitation') === 604800
        && userEmailTokenLifetime('verification') === 86400
        && userEmailTokenLifetime('recovery') === 3600,
    'invitation, verification, and recovery links should have bounded purpose-specific lifetimes.'
);

$old_base_url = getenv('DNR_PUBLIC_BASE_URL');
putenv('DNR_PUBLIC_BASE_URL=https://moed.example.test/app');
expectUserLifecycle(
    applicationPublicUrl('verify_email.php', ['token' => 'abc'])
        === 'https://moed.example.test/app/verify_email.php?token=abc',
    'emailed links should use the configured external application URL.'
);
if ($old_base_url === false) {
    putenv('DNR_PUBLIC_BASE_URL');
} else {
    putenv('DNR_PUBLIC_BASE_URL=' . $old_base_url);
}

$migration = $read('migrations/20260823_add_user_lifecycle_and_email_tokens.sql');
$initial_schema = $read('init.sql');
expectUserLifecycle(
    str_contains($migration, "account_status ENUM('invited', 'active', 'inactive')")
        && str_contains($migration, 'email_verified_at DATETIME')
        && str_contains($migration, 'GENERATED ALWAYS AS')
        && str_contains($migration, 'CREATE TABLE user_email_tokens')
        && str_contains($migration, 'auth_version INT UNSIGNED NOT NULL')
        && str_contains($migration, 'token_hash BINARY(32)')
        && str_contains($initial_schema, '20260823_add_user_lifecycle_and_email_tokens.sql'),
    'fresh and upgraded databases should contain durable lifecycle state and hashed email tokens.'
);

$lifecycle = $read('src/user_lifecycle_helpers.php');
expectUserLifecycle(
    str_contains($lifecycle, 'auth_version = auth_version + 1')
        && str_contains($lifecycle, 'UPDATE calendar_subscriptions SET revoked_at = UTC_TIMESTAMP()')
        && str_contains($lifecycle, 'UPDATE follow_up_tasks SET assigned_to = NULL')
        && str_contains($lifecycle, 'UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()')
        && str_contains($lifecycle, "'event_type' => 'user_deactivated'")
        && !str_contains($lifecycle, 'DELETE FROM security_audit_log'),
    'deactivation should atomically revoke access and assignments without deleting audit history.'
);

expectUserLifecycle(
    str_contains($read('src/functions.php'), "\$user['account_status'] !== 'active'")
        && str_contains($read('src/login.php'), "\$user['account_status'] === 'active'")
        && str_contains($read('src/calendar_helpers.php'), "user.account_status = \\'active\\'")
        && str_contains($read('src/follow_up_task_helpers.php'), "account_status = 'active'")
        && str_contains($read('src/follow_up_task_helpers.php'), "account_status = 'active' FOR UPDATE")
        && strpos($read('src/add_task.php'), '$conn->begin_transaction()')
            < strpos($read('src/add_task.php'), 'normalizeFollowUpTaskInput(')
        && str_contains($read('src/app/Domain/EngagementInput.php'), "account_status = 'active'"),
    'inactive accounts should be rejected by sessions, login, calendar feeds, and transactionally serialized assignments.'
);

$register = $read('src/register.php');
$users = $read('src/users.php');
$recovery = $read('src/recover_password.php');
$accept_invitation = $read('src/accept_invitation.php');
$page_actions = $read('src/assets/js/page-actions.js');
expectUserLifecycle(
    str_contains($register, 'inviteUserAccount(')
        && str_contains($register, 'sendInvitationEmail(')
        && str_contains($accept_invitation, "require_once __DIR__ . '/user_lifecycle_helpers.php';")
        && str_contains($accept_invitation, "account_status = 'active'")
        && str_contains($read('src/verify_email.php'), 'email_verified_at = UTC_TIMESTAMP()')
        && str_contains($recovery, 'verified_email = LOWER(?)')
        && str_contains($recovery, 'password_recovered_by_email')
        && str_contains($recovery, 'auth_version = auth_version + 1'),
    'invitation acceptance, email verification, and verified-email recovery should be wired end to end.'
);

expectUserLifecycle(
    str_contains($register, 'data-invitation-form')
        && str_contains($register, 'data-invitation-submit-status')
        && str_contains($register, 'aria-live="polite"')
        && str_contains($users, 'data-submitting-label="Resending invitation&hellip;"')
        && str_contains($users, 'Emailing a new activation link&hellip;')
        && str_contains($page_actions, 'initializeInvitationSubmission()')
        && str_contains($page_actions, "document.querySelectorAll('[data-invitation-form]')")
        && str_contains($page_actions, "button.dataset.submittingLabel || 'Sending invitation…'")
        && str_contains($page_actions, 'button.disabled = true')
        && str_contains($page_actions, "form.dataset.submitting === 'true'"),
    'invitation submission should immediately show accessible progress and prevent duplicate sends.'
);

echo "User lifecycle feature tests passed.\n";
