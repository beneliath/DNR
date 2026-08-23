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
