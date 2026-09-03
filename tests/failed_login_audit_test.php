<?php

require_once __DIR__ . '/../src/functions.php';

function expectFailedLoginAudit($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Failed login audit test failed: {$message}\n");
        exit(1);
    }
}

$known_user_event = failedLoginAuditEvent(
    'different casing',
    'Incorrect password',
    ['id' => 42, 'username' => 'KnownUser']
);
expectFailedLoginAudit(
    $known_user_event['event_category'] === 'login'
        && $known_user_event['event_type'] === 'failed_login'
        && $known_user_event['actor_user_id'] === null
        && $known_user_event['actor_username'] === null
        && $known_user_event['target_user_id'] === 42
        && $known_user_event['target_username'] === 'KnownUser'
        && $known_user_event['entity_id'] === 42
        && $known_user_event['entity_label'] === 'KnownUser'
        && $known_user_event['details'] === 'Incorrect password',
    'A known account failure should identify the target account without treating it as authenticated.'
);

$unknown_user_event = failedLoginAuditEvent('NotAUser', 'Unknown username');
expectFailedLoginAudit(
    $unknown_user_event['target_user_id'] === null
        && $unknown_user_event['target_username'] === 'NotAUser'
        && $unknown_user_event['entity_id'] === null
        && $unknown_user_event['entity_label'] === 'NotAUser',
    'An unknown username attempt should preserve the attempted username without a user ID.'
);

$blank_user_event = failedLoginAuditEvent('  ', 'Unknown username');
expectFailedLoginAudit(
    $blank_user_event['target_username'] === null
        && $blank_user_event['entity_label'] === '(blank username)',
    'A blank username attempt should remain visible without storing an empty target username.'
);

$login_source = file_get_contents(__DIR__ . '/../src/login.php');
$two_factor_source = file_get_contents(__DIR__ . '/../src/verify_2fa.php');
$enrollment_source = file_get_contents(__DIR__ . '/../src/setup_2fa.php');
$audit_log_source = file_get_contents(__DIR__ . '/../src/audit_log.php');

expectFailedLoginAudit(
    str_contains($login_source, 'recordFailedLoginAttempt(')
        && str_contains($login_source, 'passwordAuthenticationIsAccepted($user, $password_valid)')
        && str_contains($login_source, "'Unknown username'")
        && str_contains($login_source, "'Incorrect password'"),
    'Password sign-in failures should be sent to the audit log.'
);
expectFailedLoginAudit(
    str_contains($two_factor_source, 'recordFailedLoginAttempt(')
        && str_contains($two_factor_source, "'Incorrect authentication code'"),
    'Two-factor sign-in failures should be sent to the audit log.'
);
expectFailedLoginAudit(
    str_contains($enrollment_source, 'recordFailedLoginAttempt(')
        && str_contains($enrollment_source, "'Incorrect two-factor enrollment code'"),
    'Required two-factor enrollment failures should be sent to the audit log.'
);
expectFailedLoginAudit(
    str_contains($audit_log_source, "'failed_login' => 'Failed login'"),
    'The Audit Log page should label failed login events clearly.'
);

echo "Failed login audit tests passed.\n";
