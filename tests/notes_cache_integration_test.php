<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Notes cache integration skipped (disposable maintenance database required).\n"; exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/notes_cache_helpers.php';
function expectPurge(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$origin = 'https://moed.example.com';
$conn->begin_transaction();
try {
    $conn->query('DELETE FROM notes_cache_purge_queue');
    $speaker = (int) $conn->query('SELECT id FROM speakers LIMIT 1')->fetch_assoc()['id'];
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Cache test')"); $org = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status)
        VALUES ($org,'Cache test','2026-10-01','2026-10-01','conference','under_review')"); $event = (int) $conn->insert_id;
    $conn->query("INSERT INTO presentations (engagement_id,speaker_id,topic_title) VALUES ($event,$speaker,'Cache test')"); $pid = (int) $conn->insert_id;
    $code = bin2hex(random_bytes(8));
    $conn->query("INSERT INTO short_links (code,engagement_id,presentation_id,speaker_id,link_type)
        VALUES ('$code',$event,$pid,$speaker,'notes')");
    $job = static fn(): ?array => $conn->query("SELECT * FROM notes_cache_purge_queue WHERE code='$code'")->fetch_assoc();
    expectPurge($job() !== null, 'New links purge previously cached responses.');
    $conn->query("INSERT INTO presentation_notes (presentation_id,speaker_id,pdf,filename,size,sha256)
        VALUES ($pid,$speaker,'%PDF-1.4','test.pdf',8,UNHEX(SHA2('%PDF-1.4',256)))");
    $before = $job();
    $conn->query('SAVEPOINT before_rollback');
    $conn->query("UPDATE presentation_notes SET filename='rolled-back.pdf' WHERE presentation_id=$pid");
    expectPurge((int) $job()['generation'] > (int) $before['generation'], 'Replacement enqueues invalidation.');
    $conn->query('ROLLBACK TO SAVEPOINT before_rollback');
    expectPurge($job() === $before, 'A rolled-back edit also rolls back its purge job.');
    $calls = [];
    $purge = static function (array $urls) use (&$calls): void { $calls[] = $urls; };
    expectPurge(processNotesCachePurges($conn, $purge, $origin), 'First purge succeeds.');
    expectPurge($calls === [[notesCachePurgeUrl($origin,$code)]] && (int)$job()['pass'] === 1, 'Only the affected URL is purged and an in-flight safeguard remains.');
    expectPurge(processNotesCachePurges($conn,$purge,$origin) && count($calls) === 1, 'Delayed second purge is not sent early.');
    $conn->query("UPDATE notes_cache_purge_queue SET next_attempt_at=UTC_TIMESTAMP(6)");
    expectPurge(!processNotesCachePurges($conn,static function(array $urls):void{throw new RuntimeException('simulated outage');},$origin), 'Provider failure is reported.');
    expectPurge((int)$job()['attempts'] === 1 && $job()['last_error'] !== null, 'Failed purge survives with a scheduled retry.');
    expectPurge(!processNotesCachePurges($conn,$purge,$origin), 'An idle backoff pass must not report recovery.');
    $conn->query("UPDATE notes_cache_purge_queue SET next_attempt_at=UTC_TIMESTAMP(6)");
    expectPurge(processNotesCachePurges($conn,$purge,$origin) && $job() === null, 'Successful second purge clears the job.');
    $conn->query("UPDATE presentation_notes SET pdf=NULL,sha256=NULL WHERE presentation_id=$pid");
    expectPurge($job() !== null, 'Removing notes is invalidated.');
    expectPurge(processNotesCachePurges($conn,static function(array $urls)use($conn,$pid):void{
        $conn->query("UPDATE presentation_notes SET filename='concurrent.pdf' WHERE presentation_id=$pid");
    },$origin), 'An edit can commit while the network purge is running.');
    expectPurge((int)$job()['pass'] === 0, 'A stale worker result cannot acknowledge a newer edit.');
    $conn->query('DELETE FROM notes_cache_purge_queue');
    $conn->query("UPDATE short_links SET is_enabled=0 WHERE code='$code'");
    expectPurge($job() !== null, 'Disabling a link queues its PDF.');
    $conn->query('DELETE FROM notes_cache_purge_queue');
    $conn->query('SAVEPOINT before_deletion');
    $conn->query("DELETE FROM presentations WHERE id=$pid");
    expectPurge($job() !== null, 'Presentation deletion survives foreign-key cascades.');
    $conn->query('ROLLBACK TO SAVEPOINT before_deletion');
    $conn->query("DELETE FROM engagements WHERE id=$event");
    expectPurge($job() !== null, 'Engagement deletion survives foreign-key cascades.');
    echo "Notes cache integration tests passed: transaction rollback, retries, concurrent edits, and cascaded deletions.\n";
} finally { $conn->rollback(); }
