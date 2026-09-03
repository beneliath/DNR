<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Financial tracking integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/functions.php';
require_once $sourceDirectory . '/financial_report_helpers.php';

function expectFinancialTrackingIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Financial tracking integration test failed: {$message}");
    }
}

$suffix = bin2hex(random_bytes(4));
$userId = 0;
$organizationId = 0;
$engagementIds = [];

try {
    $username = 'finance-test-' . $suffix;
    $passwordHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $userStmt = $conn->prepare(
        "INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')"
    );
    $userStmt->bind_param('ss', $username, $passwordHash);
    $userStmt->execute();
    $userId = (int) $conn->insert_id;
    $userStmt->close();

    $organizationName = 'Financial Test Organization ' . $suffix;
    $organizationStmt = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    $organizationStmt->bind_param('s', $organizationName);
    $organizationStmt->execute();
    $organizationId = (int) $conn->insert_id;
    $organizationStmt->close();

    $eventStmt = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status, is_deleted)
         VALUES (?, ?, ?, ?, 'conference', 'under_review', ?)"
    );
    foreach ([
        ['Older archived event ' . $suffix, '2026-01-10', '2026-01-12', 1],
        ['Newer event ' . $suffix, '2026-02-10', '2026-02-12', 0],
        ['Closeout readiness event ' . $suffix, '2026-03-10', '2026-03-20', 0],
    ] as [$title, $startDate, $endDate, $isDeleted]) {
        $eventStmt->bind_param(
            'isssi',
            $organizationId,
            $title,
            $startDate,
            $endDate,
            $isDeleted
        );
        $eventStmt->execute();
        $engagementIds[] = (int) $conn->insert_id;
    }
    $eventStmt->close();

    // The older event is closed later on purpose. "Last giving" must still
    // follow the event date rather than data-entry order or close timestamp.
    $reportStmt = $conn->prepare(
        'INSERT INTO engagement_financial_reports
            (engagement_id, giving_income_received, lodging_received,
             travel_received, closed_by, updated_by, closed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ([
        [$engagementIds[0], '100.00', '10.00', '20.00', '2026-08-20 12:00:00.000000'],
        [$engagementIds[1], '300.00', '20.00', '50.00', '2026-08-19 12:00:00.000000'],
    ] as [$engagementId, $giving, $lodging, $travel, $closedAt]) {
        $reportStmt->bind_param(
            'isssiis',
            $engagementId,
            $giving,
            $lodging,
            $travel,
            $userId,
            $userId,
            $closedAt
        );
        $reportStmt->execute();
    }
    $reportStmt->close();

    $readinessEngagementId = $engagementIds[2];
    $presentationStmt = $conn->prepare(
        "INSERT INTO presentations
            (engagement_id, topic_title, presentation_date, presentation_time,
             speaker_name, is_archived, archived_by, archived_at)
         VALUES (?, ?, ?, '10:00:00', 'Financial Test Speaker', ?, ?, ?)"
    );
    foreach ([
        ['Opening presentation', '2026-03-10', 0, null, null],
        ['Last active presentation', '2026-03-12', 0, null, null],
        ['Later archived presentation', '2026-03-18', 1, $userId, '2026-03-01 12:00:00'],
    ] as [$topic, $presentationDate, $isArchived, $archivedBy, $archivedAt]) {
        $presentationStmt->bind_param(
            'issiis',
            $readinessEngagementId,
            $topic,
            $presentationDate,
            $isArchived,
            $archivedBy,
            $archivedAt
        );
        $presentationStmt->execute();
    }
    $presentationStmt->close();

    $taskStmt = $conn->prepare(
        "INSERT INTO follow_up_tasks
            (title, status, due_date, subject_type, engagement_id, created_by,
             completed_by, completed_at)
         VALUES (?, ?, ?, 'engagement', ?, ?, ?, ?)"
    );
    foreach ([
        ['Incomplete before last presentation', 'open', '2026-03-11', $readinessEngagementId, null, null],
        ['Incomplete on last presentation', 'waiting', '2026-03-12', $readinessEngagementId, null, null],
        ['Canceled on last presentation', 'canceled', '2026-03-12', $readinessEngagementId, null, null],
        ['Completed before last presentation', 'completed', '2026-03-10', $readinessEngagementId, $userId, '2026-03-10 12:00:00'],
        ['Incomplete after last presentation', 'open', '2026-03-13', $readinessEngagementId, null, null],
        ['Incomplete without a due date', 'open', null, $readinessEngagementId, null, null],
        ['Other engagement task', 'open', '2026-03-01', $engagementIds[1], null, null],
    ] as [$title, $status, $dueDate, $taskEngagementId, $completedBy, $completedAt]) {
        $taskStmt->bind_param(
            'sssiiis',
            $title,
            $status,
            $dueDate,
            $taskEngagementId,
            $userId,
            $completedBy,
            $completedAt
        );
        $taskStmt->execute();
    }
    $taskStmt->close();

    $readiness = fetchEngagementCloseoutTaskReadiness($conn, $readinessEngagementId);
    expectFinancialTrackingIntegration(
        $readiness['last_presentation_date'] === '2026-03-12',
        'the closeout cutoff should use the last active presentation and ignore a later archived one.'
    );
    expectFinancialTrackingIntegration(
        array_column($readiness['blocking_tasks'], 'title') === [
            'Incomplete before last presentation',
            'Incomplete on last presentation',
            'Canceled on last presentation',
        ],
        'only same-event tasks due through the inclusive cutoff and not marked completed should hold closeout.'
    );
    expectFinancialTrackingIntegration(
        engagementCloseoutTaskHoldMessage($readiness)
            === '3 tasks due on or before the last presentation (2026-03-12) must be marked completed before this event can be closed out.',
        'the closeout hold should explain the blocker count and cutoff date.'
    );
    $conn->begin_transaction();
    try {
        $lockedReadiness = fetchEngagementCloseoutTaskReadiness(
            $conn,
            $readinessEngagementId,
            true
        );
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
    expectFinancialTrackingIntegration(
        $lockedReadiness === $readiness,
        'the transactional closeout check should lock and classify the same presentation and task rows.'
    );
    $undatedReadiness = fetchEngagementCloseoutTaskReadiness($conn, $engagementIds[0]);
    expectFinancialTrackingIntegration(
        $undatedReadiness['last_presentation_date'] === null
            && $undatedReadiness['blocking_tasks'] === [],
        'an engagement without an active dated presentation should have no date-bounded task hold.'
    );

    $completeBlockersStmt = $conn->prepare(
        "UPDATE follow_up_tasks
         SET status = 'completed', completed_by = ?, completed_at = '2026-03-12 18:00:00'
         WHERE engagement_id = ? AND due_date <= '2026-03-12' AND status <> 'completed'"
    );
    $completeBlockersStmt->bind_param('ii', $userId, $readinessEngagementId);
    $completeBlockersStmt->execute();
    $completeBlockersStmt->close();
    $readyAfterCompletion = fetchEngagementCloseoutTaskReadiness($conn, $readinessEngagementId);
    expectFinancialTrackingIntegration(
        $readyAfterCompletion['blocking_tasks'] === []
            && engagementCloseoutTaskHoldMessage($readyAfterCompletion) === '',
        'the hold should clear after every task through the last presentation is marked completed.'
    );

    $summary = fetchOrganizationFinancialSummary($conn, $organizationId);
    expectFinancialTrackingIntegration(
        (int) $summary['closed_event_count'] === 2
            && (float) $summary['lifetime_giving'] === 400.0
            && (float) $summary['average_event_giving'] === 200.0,
        'lifetime and average giving should aggregate every finalized event report.'
    );
    expectFinancialTrackingIntegration(
        (int) $summary['last_event_id'] === $engagementIds[1]
            && (float) $summary['last_event_giving'] === 300.0,
        'last giving should come from the latest event even when reports are entered out of order.'
    );
    expectFinancialTrackingIntegration(
        (float) $summary['lifetime_lodging'] === 30.0
            && (float) $summary['lifetime_travel'] === 70.0,
        'lodging and travel receipts should aggregate independently from giving.'
    );
} finally {
    if ($organizationId > 0) {
        $deleteEventsStmt = $conn->prepare('DELETE FROM engagements WHERE organization_id = ?');
        $deleteEventsStmt->bind_param('i', $organizationId);
        $deleteEventsStmt->execute();
        $deleteEventsStmt->close();

        $deleteOrganizationStmt = $conn->prepare('DELETE FROM organizations WHERE id = ?');
        $deleteOrganizationStmt->bind_param('i', $organizationId);
        $deleteOrganizationStmt->execute();
        $deleteOrganizationStmt->close();
    }
    if ($userId > 0) {
        $deleteUserStmt = $conn->prepare('DELETE FROM users WHERE id = ?');
        $deleteUserStmt->bind_param('i', $userId);
        $deleteUserStmt->execute();
        $deleteUserStmt->close();
    }
}

echo "Financial tracking integration tests passed.\n";
