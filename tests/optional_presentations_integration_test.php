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
require_once $sourceDirectory . '/follow_up_task_helpers.php';

function expectOptionalPresentationsIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("Optional presentations integration test failed: {$message}");
    }
}

function fetchOptionalPresentationsIntegration(mysqli $conn, int $engagementId): array
{
    $stmt = $conn->prepare(
        'SELECT id, topic_title, presentation_date, presentation_time, speaker_id,
                duration_minutes, expected_attendance, actual_attendance
         FROM presentations WHERE engagement_id = ? AND is_archived = 0 ORDER BY id'
    );
    $stmt->bind_param('i', $engagementId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$defaultSpeaker = defaultSpeakerId(fetchSpeakerOptions($conn));
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
    $conn->query("INSERT INTO speakers (name, email, phone) VALUES ('Guest Speaker', 'guest@example.com', '+19494002892')");
    $guestSpeakerId = (int) $conn->insert_id;
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

    $conn->execute_query("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')",
        ['presentation-task-' . bin2hex(random_bytes(4)), password_hash('test', PASSWORD_DEFAULT)]);
    $actor = (int) $conn->insert_id;
    $template = createStandardEventTask($conn, ['title' => EVENT_PRESENTATION_TASK_TITLE, 'priority' => 'normal',
        'due_anchor' => 'event_start', 'due_offset_days' => '0', 'sort_order' => '100'], $actor);
    $key = fetchStandardEventTask($conn, $template)['template_key'];
    $conn->execute_query("INSERT INTO follow_up_tasks
        (title, template_key, subject_type, engagement_id, status, waiting_on)
        VALUES ('Customized event copy', ?, 'engagement', ?, 'waiting', 'Speaker'), (?, NULL, 'engagement', ?, 'open', NULL)",
        [$key, $engagementId, EVENT_PRESENTATION_TASK_TITLE, $engagementId]);
    $conn->execute_query("INSERT INTO follow_up_tasks
        (title, template_key, subject_type, engagement_id, status, waiting_on)
        VALUES ('Customized schedule task', ?, 'engagement', ?, 'waiting', 'Speaker')",
        [EVENT_CONFIRM_PRESENTATIONS_TASK_KEY, $engagementId]);
    $readTask = static fn(?string $templateKey = null) => $conn->execute_query('SELECT status, waiting_on, completed_by, completed_at, updated_at
        FROM follow_up_tasks WHERE engagement_id = ? AND template_key = ?', [$engagementId, $templateKey ?? $key])->fetch_assoc();

    $untouchedRow = [
        'topic_title' => '',
        'presentation_date' => '',
        'presentation_time' => '',
        'speaker_id' => $defaultSpeaker,
        'duration_minutes' => '60',
    ];
    $untouched = $normalize([$untouchedRow]);
    expectOptionalPresentationsIntegration(
        $untouched === []
            && !syncEngagementPresentations($conn, $engagementId, $untouched)
            && fetchOptionalPresentationsIntegration($conn, $engagementId) === []
            && $readTask()['status'] === 'waiting',
        'creating an event with an untouched default-only row must not create a presentation.'
    );

    $partialRow = array_replace($untouchedRow, [
        'speaker_id' => $guestSpeakerId,
        'duration_minutes' => '',
    ]);
    expectOptionalPresentationsIntegration(
        syncEngagementPresentations($conn, $engagementId, $normalize([$untouchedRow, $partialRow]), $actor),
        'a partial presentation must be saved without a title, date, time, or duration.'
    );
    $completion = $readTask();
    expectOptionalPresentationsIntegration(
        $completion['status'] === 'completed' && $completion['waiting_on'] === null
            && $readTask(EVENT_CONFIRM_PRESENTATIONS_TASK_KEY)['status'] === 'waiting'
            && (int) $completion['completed_by'] === $actor && $completion['completed_at'] !== null
            && $conn->execute_query('SELECT status FROM follow_up_tasks WHERE engagement_id = ? AND template_key IS NULL',
                [$engagementId])->fetch_column() === 'open',
        'saving a presentation must complete the generated task and leave a same-title manual task open.'
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
        !syncEngagementPresentations($conn, $engagementId, $normalize([$partialRow])) && $readTask() === $completion,
        'saving unchanged NULL values must not report a presentation change.'
    );

    $completeRow = array_replace($partialRow, [
        'topic_title' => 'Details supplied later',
        'presentation_date' => $endDate,
        'presentation_time' => '3:30 PM',
        'duration_minutes' => '45',
    ]);
    foreach (['topic_title', 'presentation_date', 'presentation_time'] as $missing) {
        $incompleteRow = array_replace($completeRow, [$missing => '']);
        syncEngagementPresentations($conn, $engagementId, $normalize([$incompleteRow]), $actor);
        expectOptionalPresentationsIntegration($readTask(EVENT_CONFIRM_PRESENTATIONS_TASK_KEY)['status'] === 'waiting',
            "The scheduling task must stay pending without {$missing}.");
    }
    $conn->query('SAVEPOINT before_confirmed_schedule');
    syncEngagementPresentations($conn, $engagementId, $normalize([$completeRow]), $actor);
    $conn->query('ROLLBACK TO SAVEPOINT before_confirmed_schedule');
    expectOptionalPresentationsIntegration($readTask(EVENT_CONFIRM_PRESENTATIONS_TASK_KEY)['status'] === 'waiting',
        'Rolling back a presentation save must also roll back task completion.');
    expectOptionalPresentationsIntegration(
        syncEngagementPresentations($conn, $engagementId, $normalize([$completeRow]), $actor),
        'an existing partial presentation must accept details added later.'
    );
    $confirmed = $readTask(EVENT_CONFIRM_PRESENTATIONS_TASK_KEY);
    expectOptionalPresentationsIntegration($confirmed['status'] === 'completed' && $confirmed['waiting_on'] === null
        && (int) $confirmed['completed_by'] === $actor && $confirmed['completed_at'] !== null,
        'Saving the last required detail must complete the associated scheduling task.');
    syncEngagementPresentations($conn, $engagementId, $normalize([$completeRow]), $actor);
    expectOptionalPresentationsIntegration($readTask(EVENT_CONFIRM_PRESENTATIONS_TASK_KEY) === $confirmed,
        'Saving the same schedule twice must preserve the completion history.');
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
    expectOptionalPresentationsIntegration($readTask(EVENT_CONFIRM_PRESENTATIONS_TASK_KEY) === $confirmed,
        'Later edits must preserve the completed task.');

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
