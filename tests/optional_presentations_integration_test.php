<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Optional presentations integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/config.php';
require_once $sourceDirectory . '/presentation_helpers.php';

function expectOptionalPresentationsIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Optional presentations integration test failed: {$message}");
    }
}

function fetchOptionalPresentationsIntegration(mysqli $conn, int $engagementId): array
{
    $stmt = $conn->prepare(
        'SELECT id, topic_title, presentation_date, presentation_time, speaker_name,
                duration_minutes, expected_attendance, actual_attendance
         FROM presentations WHERE engagement_id = ? AND is_archived = 0 ORDER BY id'
    );
    $stmt->bind_param('i', $engagementId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$defaultSpeaker = 'Default Speaker';
$startDate = '2026-11-14';
$endDate = '2026-11-15';
$normalize = static fn(array $rows): array => normalizeEngagementPresentations(
    $rows,
    $startDate,
    $endDate,
    $defaultSpeaker,
    true
);

$conn->begin_transaction();
try {
    $organizationName = 'Optional Presentations Test ' . bin2hex(random_bytes(4));
    $organizationStmt = $conn->prepare('INSERT INTO organizations (organization_name) VALUES (?)');
    $organizationStmt->bind_param('s', $organizationName);
    $organizationStmt->execute();
    $organizationId = (int) $conn->insert_id;
    $organizationStmt->close();

    $engagementStmt = $conn->prepare(
        "INSERT INTO engagements
            (organization_id, event_title, event_start_date, event_end_date,
             event_type, confirmation_status)
         VALUES (?, 'Details to follow', ?, ?, 'conference', 'under_review')"
    );
    $engagementStmt->bind_param('iss', $organizationId, $startDate, $endDate);
    $engagementStmt->execute();
    $engagementId = (int) $conn->insert_id;
    $engagementStmt->close();

    $untouchedRow = [
        'topic_title' => '',
        'presentation_date' => '',
        'presentation_time' => '',
        'speaker_name' => $defaultSpeaker,
        'duration_minutes' => '60',
    ];
    $untouched = $normalize([$untouchedRow]);
    expectOptionalPresentationsIntegration(
        $untouched === []
            && !syncEngagementPresentations($conn, $engagementId, $untouched)
            && fetchOptionalPresentationsIntegration($conn, $engagementId) === [],
        'creating an event with an untouched default-only row must not create a presentation.'
    );

    $partialRow = array_replace($untouchedRow, [
        'speaker_name' => 'Guest Speaker',
        'duration_minutes' => '',
    ]);
    expectOptionalPresentationsIntegration(
        syncEngagementPresentations($conn, $engagementId, $normalize([$untouchedRow, $partialRow])),
        'a partial presentation must be saved without a title, date, time, or duration.'
    );
    $stored = fetchOptionalPresentationsIntegration($conn, $engagementId);
    expectOptionalPresentationsIntegration(
        count($stored) === 1
            && $stored[0]['topic_title'] === ''
            && $stored[0]['presentation_date'] === null
            && $stored[0]['presentation_time'] === null
            && $stored[0]['duration_minutes'] === null,
        'unknown presentation values must survive storage as empty title and NULL date/time/duration.'
    );
    $presentationId = (int) $stored[0]['id'];
    $partialRow['id'] = (string) $presentationId;
    expectOptionalPresentationsIntegration(
        !syncEngagementPresentations($conn, $engagementId, $normalize([$partialRow])),
        'saving unchanged NULL values must not report a presentation change.'
    );

    $completeRow = array_replace($partialRow, [
        'topic_title' => 'Details supplied later',
        'presentation_date' => $endDate,
        'presentation_time' => '3:30 PM',
        'duration_minutes' => '45',
    ]);
    expectOptionalPresentationsIntegration(
        syncEngagementPresentations($conn, $engagementId, $normalize([$completeRow])),
        'an existing partial presentation must accept details added later.'
    );
    $stored = fetchOptionalPresentationsIntegration($conn, $engagementId);
    expectOptionalPresentationsIntegration(
        count($stored) === 1
            && (int) $stored[0]['id'] === $presentationId
            && $stored[0]['topic_title'] === 'Details supplied later'
            && $stored[0]['presentation_date'] === $endDate
            && $stored[0]['presentation_time'] === '15:30:00'
            && (int) $stored[0]['duration_minutes'] === 45,
        'editing must fill the original row with normalized presentation details.'
    );

    $clearedRow = array_replace($untouchedRow, [
        'id' => (string) $presentationId,
        'duration_minutes' => '',
    ]);
    $cleared = $normalize([$clearedRow]);
    expectOptionalPresentationsIntegration(
        count($cleared) === 1
            && syncEngagementPresentations($conn, $engagementId, $cleared),
        'clearing every optional field on a saved presentation must preserve its identity.'
    );
    $stored = fetchOptionalPresentationsIntegration($conn, $engagementId);
    expectOptionalPresentationsIntegration(
        count($stored) === 1
            && (int) $stored[0]['id'] === $presentationId
            && $stored[0]['topic_title'] === ''
            && $stored[0]['presentation_date'] === null
            && $stored[0]['presentation_time'] === null
            && $stored[0]['duration_minutes'] === null
            && !syncEngagementPresentations($conn, $engagementId, $cleared),
        'cleared values must remain NULL on readback and unchanged on a subsequent save.'
    );

    foreach ([0, 1441, -1] as $invalidDuration) {
        $rejected = false;
        try {
            $durationStmt = $conn->prepare('UPDATE presentations SET duration_minutes = ? WHERE id = ?');
            $durationStmt->bind_param('ii', $invalidDuration, $presentationId);
            $durationStmt->execute();
        } catch (mysqli_sql_exception $exception) {
            $rejected = in_array($exception->getCode(), [1264, 3819], true);
        } finally {
            $durationStmt->close();
        }
        expectOptionalPresentationsIntegration(
            $rejected,
            "the database must still reject the invalid duration {$invalidDuration}."
        );
    }

    $legacyRow = ['topic_title' => 'Older submission without a duration field'];
    expectOptionalPresentationsIntegration(
        syncEngagementPresentations($conn, $engagementId, $normalize([$clearedRow, $legacyRow])),
        'a submission that omits the duration field must remain supported.'
    );
    $stored = fetchOptionalPresentationsIntegration($conn, $engagementId);
    expectOptionalPresentationsIntegration(
        count($stored) === 2
            && $stored[0]['duration_minutes'] === null
            && (int) $stored[1]['duration_minutes'] === 60,
        'a missing duration field must keep the legacy default while an explicit blank stays NULL.'
    );

    echo "Optional presentations integration tests passed.\n";
} finally {
    $conn->rollback();
}
