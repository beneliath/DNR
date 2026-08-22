<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Beta integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';
require_once $source_directory . '/functions.php';
require_once $source_directory . '/presentation_helpers.php';
require_once $source_directory . '/calendar_helpers.php';

function expectBetaIntegration($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Beta integration test failed: {$message}\n");
        exit(1);
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$suffix = bin2hex(random_bytes(4));
$organization_id = 0;
$engagement_id = 0;
$subscription_user_id = 0;

register_shutdown_function(static function () use (
    $conn,
    &$organization_id,
    &$engagement_id,
    &$subscription_user_id
) {
    try {
        if ($subscription_user_id > 0) {
            $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
            $stmt->bind_param('i', $subscription_user_id);
            $stmt->execute();
            $stmt->close();
        }
        if ($engagement_id > 0) {
            $stmt = $conn->prepare('DELETE FROM engagements WHERE id = ?');
            $stmt->bind_param('i', $engagement_id);
            $stmt->execute();
            $stmt->close();
        }
        if ($organization_id > 0) {
            $stmt = $conn->prepare('DELETE FROM organizations WHERE id = ?');
            $stmt->bind_param('i', $organization_id);
            $stmt->execute();
            $stmt->close();
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        clearAuthenticationRateLimits($conn, 'login', '');
    } catch (Throwable $exception) {
        fwrite(STDERR, "Beta integration cleanup failed: {$exception->getMessage()}\n");
    }
});

$organization_name = 'Beta Test Organization ' . $suffix;
$organization_stmt = $conn->prepare(
    'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
);
$organization_stmt->bind_param('s', $organization_name);
$organization_stmt->execute();
$organization_id = $conn->insert_id;
$organization_stmt->close();

$engagement_stmt = $conn->prepare(
    "INSERT INTO engagements
        (organization_id, event_title, event_start_date, event_end_date,
         event_type, confirmation_status)
     VALUES (?, ?, '2026-09-10', '2026-09-12', 'conference', 'under_review')"
);
$event_title = 'Beta Test Engagement ' . $suffix;
$engagement_stmt->bind_param('is', $organization_id, $event_title);
$engagement_stmt->execute();
$engagement_id = $conn->insert_id;
$engagement_stmt->close();

$presentation_stmt = $conn->prepare(
    "INSERT INTO presentations
        (engagement_id, topic_title, presentation_date, presentation_time, speaker_name)
     VALUES (?, 'Existing Presentation', '2026-09-11', '14:00:00', 'Beta Speaker')"
);
$presentation_stmt->bind_param('i', $engagement_id);
$presentation_stmt->execute();
$presentation_id = $conn->insert_id;
$presentation_stmt->close();

$conn->begin_transaction();
$caught_missing_presentation = false;
try {
    $shrink_stmt = $conn->prepare(
        "UPDATE engagements SET event_end_date = '2026-09-10' WHERE id = ?"
    );
    $shrink_stmt->bind_param('i', $engagement_id);
    $shrink_stmt->execute();
    $shrink_stmt->close();
    syncEngagementPresentations($conn, $engagement_id, []);
    $conn->commit();
} catch (InvalidArgumentException $exception) {
    $caught_missing_presentation = str_contains($exception->getMessage(), 'Every active presentation');
    $conn->rollback();
}
expectBetaIntegration(
    $caught_missing_presentation,
    'a crafted submission that omits an active presentation must be rejected.'
);

$verification_stmt = $conn->prepare(
    'SELECT e.event_end_date, p.is_archived
     FROM engagements e
     INNER JOIN presentations p ON p.engagement_id = e.id
     WHERE e.id = ? AND p.id = ?'
);
$verification_stmt->bind_param('ii', $engagement_id, $presentation_id);
$verification_stmt->execute();
$unchanged = $verification_stmt->get_result()->fetch_assoc();
$verification_stmt->close();
expectBetaIntegration(
    $unchanged['event_end_date'] === '2026-09-12' && (int) $unchanged['is_archived'] === 0,
    'the rejected date-range change must roll back every engagement and presentation write.'
);

try {
    requirePresentationDateWithinEngagement(
        'Outside Presentation',
        '2026-09-13',
        '2026-09-10',
        '2026-09-12'
    );
    expectBetaIntegration(false, 'an out-of-range restored presentation must be rejected.');
} catch (InvalidArgumentException $exception) {
    expectBetaIntegration(
        str_contains($exception->getMessage(), 'between the engagement start and end dates'),
        'the restore validation should identify the date-range conflict.'
    );
}

$invalid_range_rejected = false;
try {
    $invalid_stmt = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status)
         VALUES (?, 'Invalid Range', '2026-10-02', '2026-10-01', 'conference', 'under_review')"
    );
    $invalid_stmt->bind_param('i', $organization_id);
    $invalid_stmt->execute();
} catch (mysqli_sql_exception $exception) {
    $invalid_range_rejected = true;
}
expectBetaIntegration($invalid_range_rejected, 'the database must reject an inverted engagement date range.');

requireActiveOrganization($conn, $organization_id);
$archive_stmt = $conn->prepare('UPDATE organizations SET is_deleted = 1 WHERE id = ?');
$archive_stmt->bind_param('i', $organization_id);
$archive_stmt->execute();
$archive_stmt->close();
try {
    requireActiveOrganization($conn, $organization_id);
    expectBetaIntegration(false, 'archived organizations must be rejected by write workflows.');
} catch (InvalidArgumentException $exception) {
    expectBetaIntegration(true, 'archived organization rejected.');
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
for ($attempt = 0; $attempt < 8; $attempt++) {
    $rate_state = recordLoginRateLimitFailure($conn);
}
expectBetaIntegration(
    !empty($rate_state['blocked']) && loginRateLimitIsBlocked($conn),
    'repeated login failures must activate the per-IP rate limit.'
);

$subscription_username = 'calendar-purge-' . $suffix;
$subscription_password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
$subscription_user_stmt = $conn->prepare(
    "INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')"
);
$subscription_user_stmt->bind_param('ss', $subscription_username, $subscription_password);
$subscription_user_stmt->execute();
$subscription_user_id = (int) $conn->insert_id;
$subscription_user_stmt->close();

$active_subscription = createCalendarSubscription($conn, $subscription_user_id, 'Active calendar');
$revoked_subscription_one = createCalendarSubscription($conn, $subscription_user_id, 'Revoked calendar one');
$revoked_subscription_two = createCalendarSubscription($conn, $subscription_user_id, 'Revoked calendar two');
expectBetaIntegration(
    revokeCalendarSubscription($conn, $subscription_user_id, $revoked_subscription_one['id'])
        && revokeCalendarSubscription($conn, $subscription_user_id, $revoked_subscription_two['id']),
    'the purge integration fixture should contain two revoked subscriptions.'
);
expectBetaIntegration(
    purgeRevokedCalendarSubscriptions($conn, $subscription_user_id) === 2,
    'purging should remove every revoked subscription owned by the user.'
);
$remaining_subscription_stmt = $conn->prepare(
    'SELECT id, revoked_at FROM calendar_subscriptions WHERE user_id = ? ORDER BY id'
);
$remaining_subscription_stmt->bind_param('i', $subscription_user_id);
$remaining_subscription_stmt->execute();
$remaining_subscriptions = $remaining_subscription_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$remaining_subscription_stmt->close();
expectBetaIntegration(
    count($remaining_subscriptions) === 1
        && (int) $remaining_subscriptions[0]['id'] === (int) $active_subscription['id']
        && $remaining_subscriptions[0]['revoked_at'] === null,
    'purging revoked subscriptions must preserve the active token.'
);

echo "Beta integration tests passed.\n";
