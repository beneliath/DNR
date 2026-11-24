<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("Audience worker integration tests skipped (requires a disposable database).\n");
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/audience_job_worker.php';
function jobWorkerExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function jobWorkerConnection(): mysqli {
    $db = new mysqli(getenv('DB_HOST') ?: 'db', getenv('MYSQL_USER') ?: 'dnruser',
        trim((string) file_get_contents((string) getenv('MYSQL_PASSWORD_FILE'))), getenv('MYSQL_DATABASE') ?: 'dnr');
    $db->set_charset('utf8mb4');
    return $db;
}
$db = applicationDatabaseConnection();
$suffix = bin2hex(random_bytes(6));
$db->execute_query("INSERT INTO users(username,password,role,account_status) VALUES (?,?,'editor','active')", ['job-worker-' . $suffix, password_hash($suffix, PASSWORD_DEFAULT)]);
$actor = (int) $db->insert_id;
$db->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Worker fixture',JSON_OBJECT())");
$audience = (int) $db->insert_id;
$store = new AudienceStore($db, $audience, $actor, 'Worker fixture');
$other = jobWorkerConnection();
$child = null;
$queue = static function (string $prefix, int $rows = 3) use ($store, $suffix): int {
    $csv = "Email,First Name\n";
    for ($i = 0; $i < $rows; ++$i) $csv .= "$prefix-$i-$suffix@example.test,Original\n";
    $id = $store->createImport(audienceParseImport($csv), 'Worker fixture');
    $store->configureImport($id, ['mapping' => ['email', 'first_name'], 'import_status' => 'non-subscribed'], true);
    return $id;
};
try {
    $id = $queue('bounded', 5);
    jobWorkerExpect(processAudienceJobs($db, 2, 1, $audience) === 1, 'A worker claims a bounded batch');
    jobWorkerExpect((int) $store->job($id)['processed'] === 2, 'Only two rows commit');
    $store->cancelJob($id);
    jobWorkerExpect(processAudienceJobs($db, 2, 1, $audience) === 0 && (int) $store->job($id)['processed'] === 2, 'Cancellation preserves committed rows and stops pending rows');

    $first = $queue('locked', 1);
    $second = $queue('parallel', 1);
    $db->begin_transaction();
    $db->execute_query('SELECT id FROM marketing_jobs WHERE id=? FOR UPDATE', [$first]);
    jobWorkerExpect(processAudienceJobs($other, 2, 1, $audience) === 1, 'A second worker skips the locked claim');
    jobWorkerExpect((new AudienceStore($other, $audience, $actor, 'Worker fixture'))->job($second)['state'] === 'complete', 'Another job progresses while the first claim is held');
    $db->rollback();
    processAudienceJobs($db, 2, 1, $audience);
    jobWorkerExpect($store->job($first)['state'] === 'complete', 'Released claims are eligible again');

    $id = $queue('revoked');
    processAudienceJobs($db, 1, 1, $audience);
    $db->execute_query("UPDATE users SET account_status='inactive' WHERE id=?", [$actor]);
    processAudienceJobs($db, 2, 1, $audience);
    jobWorkerExpect($store->job($id)['state'] === 'failed' && (int) $store->job($id)['processed'] === 1, 'Revocation stops the next batch without undoing committed rows');
    $db->execute_query("UPDATE users SET account_status='active' WHERE id=?", [$actor]);
    $store->resumeJob($id);
    processAudienceJobs($db, 2, 1, $audience);
    jobWorkerExpect($store->job($id)['state'] === 'complete' && (int) $store->job($id)['processed'] === 3, 'Resume processes only remaining rows');

    $person = $store->one('SELECT id,version FROM marketing_contacts WHERE audience_id=? ORDER BY id LIMIT 1', [$audience]);
    $id = $store->queueSelection('bulk', 'Delete fixture', ['c.audience_id=? AND c.id=?', [$audience, $person['id']]], ['action' => 'delete']);
    processAudienceJobs($db, 2, 1, $audience);
    jobWorkerExpect($store->job($id)['state'] === 'failed' && $store->person((int) $person['id'])['deleted_at'] === null, 'Editors cannot execute queued deletes');

    $id = $queue('crash', 3);
    // Hold an item so a real child worker claims the job, writes inside its
    // transaction, then blocks before it can commit. SIGKILL simulates a crash.
    $db->begin_transaction();
    $db->execute_query('SELECT id FROM marketing_job_items WHERE job_id=? ORDER BY id LIMIT 1 FOR UPDATE', [$id]);
    $code = 'require ' . var_export($source . '/bootstrap.php', true) . '; require ' . var_export($source . '/audience_job_worker.php', true)
        . '; processAudienceJobs(applicationDatabaseConnection(),40,1,' . $audience . ');';
    $child = proc_open([PHP_BINARY, '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    jobWorkerExpect(is_resource($child), 'Start the isolated child worker');
    $claimed = false;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        try { $other->execute_query('SELECT id FROM marketing_jobs WHERE id=? FOR UPDATE NOWAIT', [$id]); }
        catch (mysqli_sql_exception $error) { if ($error->getCode() !== 3572) throw $error; $claimed = true; break; }
        usleep(50000);
    }
    jobWorkerExpect($claimed, 'Child worker holds the transactional claim');
    proc_terminate($child, 9);
    proc_close($child); $child = null;
    $db->rollback();
    jobWorkerExpect((int) $store->job($id)['processed'] === 0, 'Killed batches leave no committed checkpoint');
    jobWorkerExpect((int) $store->one('SELECT COUNT(*) n FROM marketing_contacts WHERE audience_id=? AND email LIKE ?', [$audience, 'crash-%'])['n'] === 0, 'Killed batches leave no partial connection writes');
    processAudienceJobs($db, 2, 1, $audience);
    processAudienceJobs($db, 2, 1, $audience);
    jobWorkerExpect($store->job($id)['state'] === 'complete' && (int) $store->job($id)['added'] === 3, 'Replacement worker completes the interrupted job exactly once');
    jobWorkerExpect(processAudienceJobs($db, 2, 1, $audience) === 0, 'Terminal jobs are not replayed');
    echo "Audience worker batching, cancellation, concurrent claims, revocation, delete authorization, crash rollback and recovery passed.\n";
} finally {
    if (is_resource($child)) { proc_terminate($child, 9); proc_close($child); }
    $db->rollback();
    $other->close();
    // These isolated fixtures remain inside the disposable database, allowing
    // inspection on failure without requiring broader DELETE privileges.
}
