<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Review improvements integration tests skipped (requires a disposable database).\n"; exit(0);
}
$src = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $src . '/config.php';
require_once $src . '/record_merge_helpers.php';
require_once $src . '/key_rotation_helpers.php';
require_once $src . '/two_factor_helpers.php';
require_once $src . '/data_maintenance_helpers.php';
use Dnr\Security\ApplicationKey;
function reviewExpect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function reviewRejected(callable $action): void {
    try { $action(); } catch (InvalidArgumentException | RuntimeException $expected) { return; }
    throw new LogicException('Expected operation to be rejected');
}
$tag = 'review-' . bin2hex(random_bytes(5)); $orgs = $contacts = $users = []; $event = 0;
$keyring = tempnam(sys_get_temp_dir(), 'review-keyring-');
try {
    $old = base64_decode(configurationSecret('DNR_2FA_ENCRYPTION_KEY'), true);
    $new = random_bytes(32);
    file_put_contents($keyring, json_encode(['active'=>'new','legacy'=>'old','keys'=>['old'=>base64_encode($old),'new'=>base64_encode($new)]]));
    chmod($keyring, 0600); putenv('DNR_APPLICATION_KEYRING_FILE=' . $keyring);
    $nonce = random_bytes(24);
    $legacy = base64_encode($nonce . sodium_crypto_secretbox('synthetic TOTP fixture', $nonce, $old));
    for ($i=0; $i<2; $i++) {
        $conn->execute_query("INSERT INTO users (username,password,role,email,account_status,totp_secret_encrypted) VALUES (?,?,'admin',?,'active',?)",
            [$tag.$i, password_hash('SyntheticOnly123!', PASSWORD_DEFAULT), $tag.$i.'@example.invalid', $legacy]);
        $users[] = (int) $conn->insert_id;
    }
    $dry = rotateApplicationKeyBatch($conn, 'users', $users[0]-1, 2, false);
    reviewExpect($dry['needs_rotation']===2 && $dry['rewrapped']===0, 'Dry run changed data or missed keys');
    $conn->execute_query("UPDATE users SET totp_secret_encrypted='corrupt' WHERE id=?", [$users[1]]);
    reviewRejected(fn()=>rotateApplicationKeyBatch($conn, 'users', $users[0]-1, 2, true));
    reviewExpect($conn->execute_query('SELECT totp_secret_encrypted FROM users WHERE id=?',[$users[0]])->fetch_row()[0]===$legacy, 'Failed batch did not roll back');
    $conn->execute_query('UPDATE users SET totp_secret_encrypted=? WHERE id=?',[$legacy,$users[1]]);
    reviewExpect(rotateApplicationKeyBatch($conn,'users',$users[0]-1,2,true)['rewrapped']===2, 'Rewrapping did not update both rows');
    reviewExpect(rotateApplicationKeyBatch($conn,'users',$users[0]-1,2,true)['rewrapped']===0, 'Rewrapping was not idempotent');
    foreach ($users as $id) reviewExpect(ApplicationKey::open($conn->execute_query('SELECT totp_secret_encrypted FROM users WHERE id=?',[$id])->fetch_row()[0])==='synthetic TOTP fixture','Rewrapped value changed');
    $code = 'ABCD-EFGH-IJKL';
    $conn->execute_query('INSERT INTO user_recovery_codes (user_id,code_lookup_hash,key_id) VALUES (?,?,NULL)',
        [$users[0],hash_hmac('sha256',"dnr-recovery-code-v1\0" . normalizeRecoveryCode($code),$old,true)]);
    reviewExpect(applicationKeyRetirementCheck($conn,'old')['live_dependencies']['unused_recovery_codes']>=1,'Old recovery-code dependency missed');
    reviewExpect(consumeRecoveryCode($conn,$users[0],$code),'Legacy recovery code failed during rotation');
    reviewExpect(!consumeRecoveryCode($conn,$users[0],$code),'Recovery code replay accepted');
    for ($i=0;$i<3;$i++) {
        $conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)',[$tag.$i]); $orgs[]=(int)$conn->insert_id;
    }
    for ($i=0;$i<3;$i++) {
        $conn->execute_query("INSERT INTO contacts (organization_id,contact_first_name,contact_last_name,contact_role,contact_email) VALUES (?,?,'Fixture','pastor',?)",[$orgs[0],$tag.$i,$tag.'@example.invalid']);
        $contacts[]=(int)$conn->insert_id;
    }
    $conn->execute_query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status) VALUES (?,'Merge fixture','2026-10-01','2026-10-01','conference','under_review')",[$orgs[0]]); $event=(int)$conn->insert_id;
    $conn->execute_query("INSERT INTO engagement_contacts (engagement_id,contact_id,contact_role) VALUES (?,?,'primary_host')",[$event,$contacts[0]]);
    $merge = static function(string $kind,int $source,int $target,array $choices=[]) use($conn,$users):void {
        $table=mergeRecordTable($kind);
        $a=$conn->execute_query("SELECT updated_at FROM {$table} WHERE id=?",[$source])->fetch_row()[0];
        $b=$conn->execute_query("SELECT updated_at FROM {$table} WHERE id=?",[$target])->fetch_row()[0];
        mergeRecords($conn,$kind,$source,$target,$a,$b,$choices,$users[0]);
    };
    reviewRejected(fn()=>mergeRecords($conn,'contact',$contacts[0],$contacts[1],'stale','stale',[],$users[0]));
    $conn->execute_query("UPDATE contact_organizations SET role_title='Conflicting role' WHERE contact_id=?",[$contacts[0]]);
    reviewRejected(fn()=>$merge('contact',$contacts[0],$contacts[1]));
    reviewExpect((int)$conn->execute_query('SELECT is_deleted FROM contacts WHERE id=?',[$contacts[0]])->fetch_row()[0]===0,'Rejected merge archived its source');
    $conn->execute_query("UPDATE contact_organizations SET role_title='Pastor' WHERE contact_id=?",[$contacts[0]]);
    $merge('contact',$contacts[0],$contacts[1],['contact_first_name'=>'source']);
    reviewExpect((int)$conn->execute_query('SELECT merged_into_id FROM contacts WHERE id=?',[$contacts[0]])->fetch_row()[0]===$contacts[1],'Contact source was not archived with alias');
    reviewExpect((int)$conn->execute_query('SELECT contact_id FROM engagement_contacts WHERE engagement_id=?',[$event])->fetch_row()[0]===$contacts[1],'Engagement role was lost');
    reviewExpect(!\Dnr\Service\ArchiveService::setArchived($conn,'contact',$contacts[0],false),'Merged source could be restored');
    $merge('contact',$contacts[1],$contacts[2]);
    reviewExpect((int)$conn->execute_query('SELECT merged_into_id FROM contacts WHERE id=?',[$contacts[0]])->fetch_row()[0]===$contacts[2],'Prior alias was not rewired');
    $merge('organization',$orgs[0],$orgs[1]);
    reviewExpect((int)$conn->execute_query('SELECT organization_id FROM engagements WHERE id=?',[$event])->fetch_row()[0]===$orgs[1],'Engagement organization not moved');
    reviewExpect((int)$conn->execute_query('SELECT COUNT(*) FROM engagement_contacts WHERE engagement_id=?',[$event])->fetch_row()[0]===1,'Organization merge pruned valid engagement contacts');
    reviewExpect(!\Dnr\Service\ArchiveService::setArchived($conn,'organization',$orgs[0],false),'Merged organization restored');
    $speaker = (int) $conn->query('SELECT id FROM speakers ORDER BY id LIMIT 1')->fetch_row()[0];
    $conn->execute_query("INSERT INTO presentations (engagement_id,speaker_id,topic_title) VALUES (?,?,'Archive fixture')",[$event,$speaker]);
    $presentation = (int) $conn->insert_id;
    $conn->execute_query("INSERT INTO short_links (code,engagement_id,presentation_id,speaker_id,link_type,target_url)
        VALUES (?,?,?,?,'website','https://example.invalid')",[bin2hex(random_bytes(8)),$event,$presentation,$speaker]);
    $link = (int) $conn->insert_id;
    foreach ([['A',7,401],['B',11,401],['C',13,1]] as [$browser,$visits,$days]) {
        $conn->execute_query("INSERT INTO short_link_stats (link_id,visit_hour,browser,os,country,referrer,visits)
            VALUES (?,DATE_SUB(DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d 00:00:00'),INTERVAL ? DAY),?,'test','US','',?)",[$link,$days,$browser,$visits]);
    }
    putenv('DNR_STATISTICS_DETAIL_DAYS=400');
    foreach ([1,1,0] as $expectedArchived) {
        reviewExpect(archiveShortLinkStatistics($conn,1)===$expectedArchived,'Statistics batch count or rerun changed');
        reviewExpect((int)$conn->execute_query('SELECT SUM(visits) FROM short_link_report_stats WHERE link_id=?',[$link])->fetch_row()[0]===31,'Statistics archive changed totals');
    }
    reviewExpect((int)$conn->execute_query('SELECT visits FROM short_link_stats_archive WHERE link_id=?',[$link])->fetch_row()[0]===18,'Archive did not consolidate dimensions');
    echo "Merge transactions, relationships, aliases, key rotation, legacy recovery codes and statistics archival tests passed.\n";
} finally {
    foreach ($contacts as $id) $conn->execute_query('UPDATE contacts SET merged_into_id=NULL WHERE id=?',[$id]);
    foreach ($orgs as $id) $conn->execute_query('UPDATE organizations SET merged_into_id=NULL WHERE id=?',[$id]);
    if ($event) $conn->execute_query('DELETE FROM engagements WHERE id=?',[$event]);
    foreach ($contacts as $id) $conn->execute_query('DELETE FROM contacts WHERE id=?',[$id]);
    foreach ($orgs as $id) $conn->execute_query('DELETE FROM organizations WHERE id=?',[$id]);
    foreach ($users as $id) $conn->execute_query('DELETE FROM users WHERE id=?',[$id]);
    unlink($keyring); putenv('DNR_APPLICATION_KEYRING_FILE');
}
