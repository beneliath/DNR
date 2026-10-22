<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Audience storage integration tests skipped (requires disposable database).\n";
    exit(0);
}
require_once __DIR__ . '/audience_helpers_test.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// A standalone fixture can run with only the Audience migration. The normal
// integration harness uses its existing account database and credentials.
if (getenv('AUDIENCE_TEST_DATABASE')) {
    $db = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('MYSQL_USER') ?: 'root', getenv('MYSQL_PASSWORD') ?: '', getenv('AUDIENCE_TEST_DATABASE'));
} else {
    require_once (getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src') . '/bootstrap.php';
    $db = applicationDatabaseConnection();
}
$db->set_charset('utf8mb4');
$suffix = bin2hex(random_bytes(6));
$base = ['email' => "audience-$suffix@example.com", 'first_name' => 'Test', 'last_name' => 'Connection', 'email_status' => 'non-subscribed', 'tags' => "Tag-$suffix", 'notes' => 'Test note'];
$id = audienceSaveContact($db, $base, 0, 0, 1);
$row = $db->execute_query('SELECT * FROM marketing_contacts WHERE id=?', [$id])->fetch_assoc();
audienceExpect($row['email_status'] === 'non-subscribed' && (int) $row['version'] === 1, 'New connections must not be automatically subscribed.');
audienceReject(fn() => audienceSaveContact($db, $base, 0, 0, 1));
audienceReject(fn() => audienceSaveContact($db, $base, $id, 99, 1));
audienceReject(fn() => audienceSaveContact($db, array_replace($base, ['email_status' => 'subscribed']), $id, 1, 1));
$subscribe = array_replace($base, ['email_status' => 'subscribed', 'consent_note' => 'Signed signup form today']);
audienceSaveContact($db, $subscribe, $id, 1, 1);
audienceArchiveContact($db, $id, 2, true, 1);
audienceReject(fn() => audienceArchiveContact($db, $id, 2, false, 1));
audienceArchiveContact($db, $id, 3, false, 1);
$row = $db->execute_query('SELECT * FROM marketing_contacts WHERE id=?', [$id])->fetch_assoc();
audienceExpect($row['email_status'] === 'subscribed' && (int) $row['version'] === 4 && (int) $row['is_archived'] === 0, 'Restore must preserve subscription state and concurrency version.');
[$where, $params] = audienceWhere(audienceFilters(['tag' => "Tag-$suffix", 'status' => 'subscribed']));
$matches = $db->execute_query("SELECT id FROM marketing_contacts c WHERE $where", $params)->fetch_all(MYSQLI_ASSOC);
audienceExpect(in_array($id, array_map('intval', array_column($matches, 'id')), true), 'Tag and subscription filters must combine.');
audienceSaveContact($db, array_replace($base, ['email_status' => 'unsubscribed']), $id, 4, 1);
$matches = $db->execute_query("SELECT id FROM marketing_contacts c WHERE $where", $params)->fetch_all(MYSQLI_ASSOC);
audienceExpect(!in_array($id, array_map('intval', array_column($matches, 'id')), true), 'Saved criteria must reflect later subscription changes.');
$events = $db->execute_query('SELECT * FROM marketing_contact_events WHERE contact_id=? ORDER BY id', [$id])->fetch_all(MYSQLI_ASSOC);
audienceExpect(count($events) === 5 && str_contains($events[1]['details_json'], 'Signed signup form today'), 'Failed writes must not add events; subscription evidence must persist.');
audienceSaveContact($db, array_replace($base, ['email_status' => 'cleaned']), $id, 5, 1);
audienceReject(fn() => audienceSaveContact($db, $subscribe, $id, 6, 1));
audienceReject(fn() => audienceSaveContact($db, $base, $id, 6, 1));
audienceSaveContact($db, array_replace($subscribe, ['email' => "corrected-$suffix@example.com"]), $id, 6, 1);
echo "Audience database creation, duplicates, concurrency, consent history, tags, filters, archive/restore, and suppression passed.\n";
