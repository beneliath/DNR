<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Data maintenance integration tests skipped (requires a disposable database).\n";
    exit(0);
}
$src = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $src . '/config.php';
require_once $src . '/data_maintenance_helpers.php';
$original = getenv('DNR_AI_HISTORY_RETENTION_DAYS');
$ids = [];
function maintenanceExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
try {
    putenv('DNR_AI_HISTORY_RETENTION_DAYS=90');
    foreach (['expired', 'recent', 'reviewed', 'feedback', 'previous_review', 'queued', 'generating', 'pending'] as $kind) {
        $conn->execute_query("INSERT INTO ai_coach_requests
            (created_at, user_role, page_path, question, conversation_json, outcome, model_name, application_version, guidance_revision)
            VALUES (DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY), 'editor', 'index.php', ?, JSON_ARRAY(), ?, 'test', 'test', ?)",
            [$kind === 'recent' ? 89 : 91, 'Retention test ' . $kind, $kind === 'pending' ? 'pending' : 'complete', str_repeat('0', 64)]);
        $ids[$kind] = (int) $conn->insert_id;
    }
    $conn->execute_query("UPDATE ai_coach_requests SET review_status='useful' WHERE id=?", [$ids['reviewed']]);
    $conn->execute_query("UPDATE ai_coach_requests SET user_feedback='helpful', feedback_version=1 WHERE id=?", [$ids['feedback']]);
    $conn->execute_query('UPDATE ai_coach_requests SET review_version=1 WHERE id=?', [$ids['previous_review']]);
    foreach (['queued', 'generating'] as $state) {
        $conn->execute_query('INSERT INTO ai_coach_jobs (request_id, request_json, state, deadline_at) VALUES (?, JSON_OBJECT(), ?, UTC_TIMESTAMP())', [$ids[$state], $state]);
    }
    // A completed job payload should disappear with the expired ordinary request.
    $conn->execute_query("INSERT INTO ai_coach_jobs (request_id, request_json, state, deadline_at) VALUES (?, JSON_OBJECT(), 'complete', UTC_TIMESTAMP())", [$ids['expired']]);
    maintainApplicationData($conn);
    foreach ($ids as $kind => $id) {
        $exists = $conn->execute_query('SELECT id FROM ai_coach_requests WHERE id=?', [$id])->num_rows > 0;
        maintenanceExpect($exists === ($kind !== 'expired'), 'Unexpected retention result for ' . $kind);
    }
    maintenanceExpect($conn->execute_query('SELECT request_id FROM ai_coach_jobs WHERE request_id=?', [$ids['expired']])->num_rows === 0, 'Expired job payload survived');
    $conn->execute_query('UPDATE ai_coach_requests SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 91 DAY) WHERE id=?', [$ids['recent']]);
    putenv('DNR_AI_HISTORY_RETENTION_DAYS=0');
    maintainApplicationData($conn);
    maintenanceExpect($conn->execute_query('SELECT id FROM ai_coach_requests WHERE id=?', [$ids['recent']])->num_rows === 1, 'Disabled retention deleted history');
    echo "Data maintenance retention integration tests passed.\n";
} finally {
    foreach ($ids as $id) $conn->execute_query('DELETE FROM ai_coach_requests WHERE id=?', [$id]);
    putenv($original === false ? 'DNR_AI_HISTORY_RETENTION_DAYS' : 'DNR_AI_HISTORY_RETENTION_DAYS=' . $original);
}
