<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || !getenv('DNR_TEST_WEB_PASSWORD_FILE')) {
    echo "Merge privileges integration skipped (requires disposable web credentials).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/record_merge_helpers.php';

function mergePrivilegeExpect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$web = new mysqli((string) (getenv('DB_HOST') ?: 'db'), 'dnruser',
    configurationSecret('DNR_TEST_WEB_PASSWORD'), (string) (getenv('MYSQL_DATABASE') ?: 'dnr'));
$web->set_charset('utf8mb4');
mergePrivilegeExpect(str_starts_with($web->query('SELECT CURRENT_USER()')->fetch_row()[0], 'dnruser@'), 'Use the actual web database identity.');
foreach (['SELECT payload_ciphertext FROM engagement_email_deliveries LIMIT 0',
    "UPDATE engagement_email_messages SET subject=subject WHERE 1=0"] as $forbidden) {
    try { $web->query($forbidden); }
    catch (mysqli_sql_exception $error) {
        if (in_array($error->getCode(), [1142, 1143], true)) continue;
        throw $error;
    }
    throw new RuntimeException('Web privileges exposed private payloads or allowed rewriting message content.');
}
$tag = 'merge-privileges-' . bin2hex(random_bytes(5));
$organizations = $contacts = []; $actor = $event = 0;
try {
    $conn->execute_query("INSERT INTO users (username,password,role,email,account_status) VALUES (?,?,'admin',?,'active')",
        [$tag, password_hash('SyntheticOnly123!', PASSWORD_DEFAULT), $tag . '@example.invalid']);
    $actor = (int) $conn->insert_id;
    for ($i = 0; $i < 2; $i++) {
        $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', [$tag . $i]);
        $organizations[] = (int) $conn->insert_id;
        $conn->execute_query("INSERT INTO contacts (organization_id,contact_first_name,contact_last_name,contact_role,contact_email) VALUES (?,?,'Fixture','pastor',?)",
            [$organizations[$i], $tag . $i, $tag . $i . '@example.invalid']);
        $contacts[] = (int) $conn->insert_id;
    }
    $conn->execute_query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status) VALUES (?,?,'2026-10-01','2026-10-01','conference','under_review')",
        [$organizations[0], $tag]);
    $event = (int) $conn->insert_id;
    $conn->execute_query('INSERT INTO engagement_email_messages (engagement_id,organization_id,subject,body_text,created_by) VALUES (?,?,?,?,?)',
        [$event, $organizations[0], 'Synthetic subject', 'Original body', $actor]);
    $message = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO engagement_email_deliveries (message_id,contact_id,recipient_name,recipient_email,recipient_roles_json,payload_ciphertext,status) VALUES (?,?,'Fixture','fixture@example.invalid','[]','private synthetic payload','sent')",
        [$message, $contacts[0]]);
    $delivery = (int) $conn->insert_id;
    $merge = static function (string $kind, int $from, int $to) use ($web, $actor): int {
        $table = mergeRecordTable($kind);
        $sourceVersion = $web->execute_query("SELECT updated_at FROM {$table} WHERE id=?", [$from])->fetch_row()[0];
        $targetVersion = $web->execute_query("SELECT updated_at FROM {$table} WHERE id=?", [$to])->fetch_row()[0];
        mergeRecords($web, $kind, $from, $to, $sourceVersion, $targetVersion, [], $actor);
        return (int) $web->execute_query('SELECT MAX(id) FROM record_merge_journal WHERE actor_user_id=?', [$actor])->fetch_row()[0];
    };
    $journal = $merge('contact', $contacts[0], $contacts[1]);
    mergePrivilegeExpect((int) $web->execute_query('SELECT contact_id FROM engagement_email_deliveries WHERE id=?', [$delivery])->fetch_row()[0] === $contacts[1], 'Contact merge did not move the email reference.');
    $saved = unserialize(\Dnr\Security\ApplicationKey::open($web->execute_query('SELECT snapshot_ciphertext FROM record_merge_journal WHERE id=?', [$journal])->fetch_row()[0]), ['allowed_classes' => false]);
    mergePrivilegeExpect(array_keys($saved['before']['engagement_email_deliveries'][0]) === ['id', 'contact_id'], 'Journal captured fields beyond the email reference.');
    $conn->execute_query("UPDATE engagement_email_deliveries SET attempts=attempts+1,last_error='Later delivery metadata' WHERE id=?", [$delivery]);
    undoRecordMerge($web, $journal, $actor);
    $restored = $conn->execute_query('SELECT contact_id,attempts,last_error,payload_ciphertext FROM engagement_email_deliveries WHERE id=?', [$delivery])->fetch_assoc();
    mergePrivilegeExpect((int) $restored['contact_id'] === $contacts[0] && (int) $restored['attempts'] === 1
        && $restored['last_error'] === 'Later delivery metadata' && $restored['payload_ciphertext'] === 'private synthetic payload', 'Undo rewrote delivery history or payload.');
    $journal = $merge('organization', $organizations[0], $organizations[1]);
    mergePrivilegeExpect((int) $web->execute_query('SELECT organization_id FROM engagement_email_messages WHERE id=?', [$message])->fetch_row()[0] === $organizations[1], 'Organization merge did not move the email reference.');
    undoRecordMerge($web, $journal, $actor);
    mergePrivilegeExpect((int) $web->execute_query('SELECT organization_id FROM engagement_email_messages WHERE id=?', [$message])->fetch_row()[0] === $organizations[0], 'Organization undo did not restore the email reference.');
    $journal = $merge('contact', $contacts[0], $contacts[1]);
    $web->execute_query('UPDATE engagement_email_deliveries SET contact_id=NULL WHERE id=?', [$delivery]);
    try { undoRecordMerge($web, $journal, $actor); throw new LogicException('Changed email reference was overwritten.'); }
    catch (InvalidArgumentException $expected) {}
    $web->execute_query('UPDATE engagement_email_deliveries SET contact_id=? WHERE id=?', [$contacts[1], $delivery]);
    undoRecordMerge($web, $journal, $actor);
    $conn->execute_query('DELETE FROM engagement_email_messages WHERE id=?', [$message]);
    foreach (['contact' => $contacts, 'organization' => $organizations] as $kind => $ids) {
        undoRecordMerge($web, $merge($kind, $ids[0], $ids[1]), $actor);
    }
    echo "Merge privileges integration passed: contact/organization merge and undo, empty email history, private payload protection, delivery history preservation, and changed-reference rejection.\n";
} finally {
    if ($event) $conn->execute_query('DELETE FROM engagements WHERE id=?', [$event]);
    foreach ($contacts as $id) $conn->execute_query('UPDATE contacts SET merged_into_id=NULL WHERE id=?', [$id]);
    foreach ($contacts as $id) $conn->execute_query('DELETE FROM contacts WHERE id=?', [$id]);
    foreach ($organizations as $id) $conn->execute_query('UPDATE organizations SET merged_into_id=NULL WHERE id=?', [$id]);
    foreach ($organizations as $id) $conn->execute_query('DELETE FROM organizations WHERE id=?', [$id]);
    if ($actor) {
        $conn->execute_query('DELETE FROM record_merge_journal WHERE actor_user_id=?', [$actor]);
        $conn->execute_query('DELETE FROM users WHERE id=?', [$actor]);
    }
    $web->close();
}
