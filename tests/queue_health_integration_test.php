<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("Queue health integration tests skipped (requires a disposable database).\n");
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/queue_health_helpers.php';
function queueIntegrationExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$db = applicationDatabaseConnection();
$baseline = array_column(applicationQueueHealth($db)['queues'], null, 'key');
$suffix = bin2hex(random_bytes(6));
$db->begin_transaction();
try {
    $db->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'admin')", ['queue-health-' . $suffix, password_hash($suffix, PASSWORD_DEFAULT)]);
    $user = (int) $db->insert_id;
    // Use the web role: the report needs only queue metadata, never encrypted
    // account-email payloads. These fixtures and all outcomes roll back below.
    foreach ([['pending', '-30 MINUTE', null, 'NOW'], ['pending', '1 DAY', null, 'NOW'],
              ['processing', '-1 HOUR', '-1 HOUR', 'NOW'], ['delivery_uncertain', '-1 HOUR', '-1 HOUR', 'NOW'],
              ['failed', '-1 HOUR', '-1 HOUR', 'NOW'], ['failed', '-2 DAY', '-2 DAY', '-2 DAY']] as [$state, $due, $started, $changed]) {
        $db->execute_query("INSERT INTO user_email_tokens(user_id,purpose,email,auth_version,token_hash,expires_at) VALUES (?,'verification','fixture@example.test',1,?,UTC_TIMESTAMP()+INTERVAL 1 DAY)", [$user, random_bytes(32)]);
        $token = (int) $db->insert_id;
        $startSql = $started === null ? 'NULL' : 'UTC_TIMESTAMP()+INTERVAL ' . $started;
        $changeSql = $changed === 'NOW' ? 'UTC_TIMESTAMP()' : 'UTC_TIMESTAMP()+INTERVAL ' . $changed;
        $db->execute_query("INSERT INTO email_outbox(token_id,user_id,purpose,status,next_attempt_at,processing_started_at,updated_at) VALUES (?,?,'verification',?,UTC_TIMESTAMP()+INTERVAL $due,$startSql,$changeSql)", [$token, $user, $state]);
    }
    $db->query("INSERT INTO marketing_audiences(name,settings_json) VALUES ('Queue health fixture',JSON_OBJECT())");
    $audience = (int) $db->insert_id;
    $db->execute_query("INSERT INTO marketing_campaigns(audience_id,name,draft_json,actor_label) VALUES (?,'Queue health',JSON_OBJECT(),'Fixture')", [$audience]);
    $campaign = (int) $db->insert_id;
    foreach ([['scheduled', '1 DAY'], ['paused', '-1 HOUR'], ['sending', '-1 HOUR']] as $i => [$state, $due]) {
        $db->execute_query("INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label)
            VALUES (?,?,?,?,UTC_TIMESTAMP()+INTERVAL $due,'UTC','synthetic',?,1,?,'Fixture')", [$audience, $campaign, $i + 1, $state, bin2hex(random_bytes(32)), $user]);
        $delivery = (int) $db->insert_id;
        $db->execute_query("INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,state,next_attempt_at)
            VALUES (?,?,1,?,'synthetic',?,?,'queued',UTC_TIMESTAMP()-INTERVAL 30 MINUTE)", [$audience, $delivery, bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), bin2hex(random_bytes(32))]);
    }
    foreach ([['queued', 0], ['processing', 0], ['complete', 2]] as [$state, $errors]) {
        $db->execute_query("INSERT INTO marketing_jobs(audience_id,job_type,name,state,actor_user_id,actor_label,params_json,errors,created_at,updated_at)
            VALUES (?,'import','Queue fixture',?,?,'Fixture',JSON_OBJECT(),?,UTC_TIMESTAMP()-INTERVAL 10 MINUTE,UTC_TIMESTAMP()-INTERVAL 10 MINUTE)", [$audience, $state, $user, $errors]);
    }
    $db->execute_query("INSERT INTO marketing_jobs(audience_id,job_type,name,state,actor_user_id,actor_label,params_json)
        VALUES (?,'export','Removed history','failed',?,'Fixture',JSON_OBJECT('history_deleted',true))", [$audience, $user]);
    $report = applicationQueueHealth($db);
    $queues = array_column($report['queues'], null, 'key');
    $mail = $queues['account_mail']; $before = $baseline['account_mail'];
    queueIntegrationExpect(!$report['healthy'], 'Delivery failures are unhealthy even without a worker exception');
    queueIntegrationExpect($mail['queued'] === $before['queued'] + 1, 'Future mail is excluded from overdue work');
    queueIntegrationExpect($mail['stalled'] === $before['stalled'] + 1, 'Stale processing claims are visible');
    queueIntegrationExpect($mail['uncertain'] === $before['uncertain'] + 1, 'Uncertain outcomes stay visible');
    queueIntegrationExpect($mail['failed'] === $before['failed'] + 1, 'Old terminal failures do not cause perpetual new-failure alerts');
    queueIntegrationExpect($mail['oldest_due_seconds'] >= 1800, 'Oldest due age is measured from availability');
    queueIntegrationExpect($queues['campaigns']['queued'] === $baseline['campaigns']['queued'] + 1, 'Future and paused campaigns do not create false overdue alerts');
    queueIntegrationExpect($queues['audience_jobs']['stalled'] === $baseline['audience_jobs']['stalled'] + 1, 'Stopped bulk jobs are detected');
    queueIntegrationExpect($queues['audience_jobs']['partial'] === $baseline['audience_jobs']['partial'] + 1, 'Completed jobs with rejected rows remain actionable');
    queueIntegrationExpect($queues['audience_jobs']['failed'] === $baseline['audience_jobs']['failed'], 'Removed export history does not generate unresolvable alerts');
    queueIntegrationExpect(count($queues) === 8 && !str_contains(json_encode($report), 'fixture@example.test'), 'Every queue is covered without recipient data');
    echo "Queue health real-schema and restricted-role tests passed.\n";
} finally {
    $db->rollback();
}
