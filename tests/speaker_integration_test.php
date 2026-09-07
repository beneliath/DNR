<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Speaker integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/speaker_helpers.php';
require_once $sourceDirectory . '/presentation_helpers.php';

function expectSpeakerIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Speaker integration failed: ' . $message);
    }
}
function speakerSqlMustFail(mysqli $conn, string $sql, array $expectedCodes): void
{
    try {
        $conn->query($sql);
        throw new RuntimeException('The database accepted a prohibited speaker change.');
    } catch (mysqli_sql_exception $exception) {
        expectSpeakerIntegration(in_array($exception->getCode(), $expectedCodes, true), 'Expected permission, null, or foreign-key rejection: ' . $exception->getMessage());
    }
}
$conn->begin_transaction();
try {
    $seed = $conn->query('SELECT id, name, email, phone FROM speakers ORDER BY id LIMIT 1')->fetch_assoc();
    expectSpeakerIntegration($seed['name'] === 'Olivier Melnick'
        && $seed['email'] === 'olivier@shalominmessiah.com' && $seed['phone'] === '+19494002892', 'Olivier is seeded with the requested details.');
    $speakerId = saveSpeaker($conn, ['name' => 'Guest <Speaker>', 'email' => 'guest@example.com', 'phone' => '+1 949 400 2892']);
    $speaker = fetchSpeaker($conn, $speakerId);
    expectSpeakerIntegration($speaker['version'] === 1 && isset(fetchSpeakerOptions($conn)[$speakerId]), 'New speakers are immediately selectable.');
    speakerSqlMustFail($conn, 'DELETE FROM speakers WHERE id = ' . $speakerId, [1142]);

    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Speaker integration')");
    $orgId = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status)
        VALUES ($orgId, 'Speaker integration', '2026-10-01', '2026-10-02', 'conference', 'under_review')");
    $eventId = (int) $conn->insert_id;
    $normalize = static fn(array $rows): array => normalizeEngagementPresentations($rows, '2026-10-01', '2026-10-02', (int) $seed['id'], true);
    syncEngagementPresentations($conn, $eventId, $normalize([['topic_title' => 'Guest talk', 'speaker_id' => $speakerId]]));
    $presentation = $conn->query("SELECT * FROM presentations WHERE engagement_id = $eventId")->fetch_assoc();
    $presentationId = (int) $presentation['id'];
    expectSpeakerIntegration((int) $presentation['speaker_id'] === $speakerId, 'Presentations persist the selected speaker.');
    expectSpeakerIntegration(!syncEngagementPresentations($conn, $eventId, $normalize([$presentation])), 'Unchanged presentations are not rewritten.');
    expectSpeakerIntegration(syncEngagementPresentations($conn, $eventId, $normalize([array_replace($presentation, ['speaker_id' => $seed['id']])])), 'Changing only the speaker persists.');
    syncEngagementPresentations($conn, $eventId, $normalize([$presentation]));
    try {
        syncEngagementPresentations($conn, $eventId, $normalize([array_replace($presentation, ['speaker_id' => 2147483647])]));
        throw new RuntimeException('An unknown speaker was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
    speakerSqlMustFail($conn, "UPDATE presentations SET speaker_id = NULL WHERE id = $presentationId", [1048]);
    speakerSqlMustFail($conn, "UPDATE presentations SET speaker_id = 2147483647 WHERE id = $presentationId", [1452]);
    $revision = (int) $conn->query('SELECT revision FROM calendar_feed_revision WHERE id = 1')->fetch_assoc()['revision'];
    saveSpeaker($conn, ['name' => 'Updated Speaker', 'email' => 'updated@example.com', 'phone' => '+44 20 7946 0018'], $speakerId, 1);
    expectSpeakerIntegration((int) $conn->query('SELECT revision FROM calendar_feed_revision WHERE id = 1')->fetch_assoc()['revision'] > $revision, 'Speaker edits invalidate cached calendars.');
    try {
        saveSpeaker($conn, ['name' => 'Stale edit', 'email' => 'stale@example.com', 'phone' => '+1 949 400 2892'], $speakerId, 1);
        throw new RuntimeException('A stale speaker edit was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
    $conn->query("UPDATE presentations SET is_archived = 1 WHERE id = $presentationId");
    $archived = fetchArchivedEngagementPresentations($conn, $eventId);
    expectSpeakerIntegration($archived[0]['speaker_name'] === 'Updated Speaker' && (int) $archived[0]['speaker_id'] === $speakerId, 'Archived presentations retain their speaker and reflect edits.');
    speakerSqlMustFail($conn, 'DELETE FROM speakers WHERE id = ' . $speakerId, [1142]);
    $audit = $conn->query("SELECT COUNT(*) AS total FROM security_audit_log WHERE entity_type = 'speakers' AND entity_id = $speakerId")->fetch_assoc();
    expectSpeakerIntegration((int) $audit['total'] === 2, 'Successful speaker creation and editing are audited.');
    echo "Speaker integration tests passed.\n";
} finally {
    $conn->rollback();
}
