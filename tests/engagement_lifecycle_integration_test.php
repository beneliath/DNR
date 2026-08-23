<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Engagement lifecycle integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/engagement_lifecycle_helpers.php';

function expectEngagementLifecycleIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Engagement lifecycle integration test failed: {$message}");
    }
}

$suffix = bin2hex(random_bytes(4));
$conn->begin_transaction();
try {
    $username = 'lifecycle-test-' . $suffix;
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $userStmt = $conn->prepare(
        "INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')"
    );
    $userStmt->bind_param('ss', $username, $passwordHash);
    $userStmt->execute();
    $userId = (int) $conn->insert_id;
    $userStmt->close();

    $organizationIds = [];
    $organizationStmt = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    foreach (['Primary', 'Other'] as $prefix) {
        $organizationName = $prefix . ' Lifecycle Organization ' . $suffix;
        $organizationStmt->bind_param('s', $organizationName);
        $organizationStmt->execute();
        $organizationIds[] = (int) $conn->insert_id;
    }
    $organizationStmt->close();

    $engagementIds = [];
    $engagementStmt = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status, lifecycle_status, is_deleted)
         VALUES (?, ?, ?, ?, 'conference', 'under_review', 'active', 0)"
    );
    foreach ([
        [$organizationIds[0], 'Original Event ' . $suffix, '2026-09-10', '2026-09-12'],
        [$organizationIds[0], 'Replacement Event ' . $suffix, '2026-10-10', '2026-10-12'],
        [$organizationIds[1], 'Other Event ' . $suffix, '2026-11-10', '2026-11-12'],
    ] as [$organizationId, $title, $startDate, $endDate]) {
        $engagementStmt->bind_param('isss', $organizationId, $title, $startDate, $endDate);
        $engagementStmt->execute();
        $engagementIds[] = (int) $conn->insert_id;
    }
    $engagementStmt->close();

    validateEngagementRescheduleLink(
        $conn,
        $organizationIds[0],
        'postponed',
        $engagementIds[1],
        $engagementIds[0]
    );
    $linkStmt = $conn->prepare(
        "UPDATE engagements
         SET lifecycle_status = 'postponed', rescheduled_to_engagement_id = ?
         WHERE id = ?"
    );
    $linkStmt->bind_param('ii', $engagementIds[1], $engagementIds[0]);
    $linkStmt->execute();
    $linkStmt->close();

    $target = fetchEngagementRescheduleTarget($conn, $engagementIds[0]);
    $sources = fetchEngagementRescheduleSources($conn, $engagementIds[1]);
    expectEngagementLifecycleIntegration(
        $target !== null
            && (int) $target['id'] === $engagementIds[1]
            && count($sources) === 1
            && (int) $sources[0]['id'] === $engagementIds[0],
        'replacement links should be readable in both directions.'
    );

    $missingCancellationReasonRejected = false;
    try {
        $conn->query(
            "UPDATE engagements
             SET lifecycle_status = 'canceled', cancellation_reason = NULL
             WHERE id = {$engagementIds[0]}"
        );
    } catch (mysqli_sql_exception) {
        $missingCancellationReasonRejected = true;
    }
    expectEngagementLifecycleIntegration(
        $missingCancellationReasonRejected,
        'the database should reject canceled events without a reason.'
    );

    $selfReplacementRejected = false;
    try {
        $conn->query(
            "UPDATE engagements
             SET rescheduled_to_engagement_id = {$engagementIds[0]}
             WHERE id = {$engagementIds[0]}"
        );
    } catch (mysqli_sql_exception) {
        $selfReplacementRejected = true;
    }
    expectEngagementLifecycleIntegration(
        $selfReplacementRejected,
        'the database should reject a self-referencing replacement event.'
    );

    $otherOrganizationRejected = false;
    try {
        validateEngagementRescheduleLink(
            $conn,
            $organizationIds[0],
            'canceled',
            $engagementIds[2],
            $engagementIds[0]
        );
    } catch (InvalidArgumentException) {
        $otherOrganizationRejected = true;
    }
    expectEngagementLifecycleIntegration(
        $otherOrganizationRejected,
        'replacement events from another organization should be rejected.'
    );

    $cycleRejected = false;
    try {
        validateEngagementRescheduleLink(
            $conn,
            $organizationIds[0],
            'postponed',
            $engagementIds[0],
            $engagementIds[1]
        );
    } catch (InvalidArgumentException) {
        $cycleRejected = true;
    }
    expectEngagementLifecycleIntegration(
        $cycleRejected,
        'replacement events should not form cycles.'
    );

    $taskStmt = $conn->prepare(
        "INSERT INTO follow_up_tasks
            (title, status, subject_type, engagement_id, created_by, completed_at)
         VALUES (?, ?, 'engagement', ?, ?, ?)"
    );
    foreach ([
        ['Open lifecycle task', 'open', null],
        ['Completed lifecycle task', 'completed', '2026-08-23 12:00:00'],
    ] as [$taskTitle, $taskStatus, $completedAt]) {
        $taskStmt->bind_param(
            'ssiis',
            $taskTitle,
            $taskStatus,
            $engagementIds[0],
            $userId,
            $completedAt
        );
        $taskStmt->execute();
    }
    $taskStmt->close();

    expectEngagementLifecycleIntegration(
        cancelEngagementFollowUpTasks($conn, $engagementIds[0]) === 1,
        'canceling an engagement should cancel only its open tasks.'
    );
    $statusResult = $conn->query(
        "SELECT status, COUNT(*) AS status_count
         FROM follow_up_tasks
         WHERE engagement_id = {$engagementIds[0]}
         GROUP BY status"
    );
    $statuses = [];
    while ($statusRow = $statusResult->fetch_assoc()) {
        $statuses[(string) $statusRow['status']] = (int) $statusRow['status_count'];
    }
    expectEngagementLifecycleIntegration(
        ($statuses['canceled'] ?? 0) === 1
            && ($statuses['completed'] ?? 0) === 1,
        'completed tasks should remain completed when an event is canceled.'
    );

    $conn->rollback();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

echo "Engagement lifecycle integration tests passed.\n";
