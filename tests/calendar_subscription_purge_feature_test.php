<?php

function expectCalendarSubscriptionPurge($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Calendar subscription purge feature test failed: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . '/../src/calendar_subscription.php');
$helpers = file_get_contents(__DIR__ . '/../src/calendar_helpers.php');
$audit_log = file_get_contents(__DIR__ . '/../src/audit_log.php');

expectCalendarSubscriptionPurge(
    str_contains($helpers, 'function purgeRevokedCalendarSubscriptions')
        && str_contains($helpers, 'DELETE FROM calendar_subscriptions')
        && str_contains($helpers, 'WHERE user_id = ? AND revoked_at IS NOT NULL')
        && str_contains($helpers, '$stmt->bind_param(\'i\', $user_id);'),
    'purging must delete only revoked subscriptions owned by the signed-in user.'
);
expectCalendarSubscriptionPurge(
    str_contains($page, 'elseif ($action === \'purge_revoked\')')
        && str_contains($page, '$conn->begin_transaction();')
        && str_contains($page, "'event_type' => 'calendar_subscriptions_purged'")
        && str_contains($page, 'Unable to audit revoked subscription cleanup.')
        && str_contains($page, '$conn->rollback();'),
    'the destructive cleanup and its audit event must succeed or roll back together.'
);
expectCalendarSubscriptionPurge(
    str_contains($page, 'name="action" value="purge_revoked"')
        && str_contains($page, '<?php echo csrfInput(); ?>')
        && str_contains($page, 'Active subscriptions will not be affected. This cannot be undone.')
        && str_contains($page, 'Purge <?php echo $revoked_subscription_count; ?> Revoked Token'),
    'the purge control must be CSRF-protected, counted, and explicitly confirmed.'
);
expectCalendarSubscriptionPurge(
    str_contains($audit_log, "'calendar_subscriptions_purged' => 'Revoked calendar subscriptions purged'"),
    'the audit log should present a readable label for purge events.'
);

echo "Calendar subscription purge feature tests passed.\n";
