<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    exit("Flow queue regressions require a disposable database.\n");
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/audience_flow_runtime.php';
require_once $source.'/audience_dispatch_worker.php';
$store = new AudienceStore($conn, 1, 0, 'Flow queue regression');
$expect = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$heldFlows = $store->all("SELECT id FROM marketing_flows WHERE audience_id=1 AND state='running'");
$heldJobs = $store->all("SELECT id,state FROM marketing_campaign_deliveries WHERE audience_id=1 AND state IN ('scheduled','sending')");
$settings = $store->sesSettings();
$audienceSettings = $store->one('SELECT settings_json FROM marketing_audiences WHERE id=1')['settings_json'];
$flowIds = []; $suffix = bin2hex(random_bytes(5)); $clock = time();
$newFlow = static function(array $document, string $name) use ($store, &$flowIds): array {
    $id = $store->saveFlow($name, $document); $flowIds[] = $id;
    $store->flowOperation($id, 1, 'activate'); return $store->flow($id);
};
$enroll = static function(array $flow, int $person) use ($store, $clock): int {
    return $store->flowLock((int)$flow['id'], fn()=>$store->transaction(fn()=>audienceFlowEnroll(
        $store, $flow, $flow['document'], $person, 'fixture:'.bin2hex(random_bytes(8)), $clock)));
};
$newPerson = static fn(string $name): int=>$store->savePerson(['email'=>$name.'-'.$suffix.'@example.test',
    'email_status'=>'subscribed', 'consent_note'=>'Fictional disposable fixture consent']);
try {
    foreach ($heldFlows as $flow) $store->write("UPDATE marketing_flows SET state='paused' WHERE id=?", [$flow['id']]);
    foreach ($heldJobs as $job) $store->write("UPDATE marketing_campaign_deliveries SET state='paused' WHERE id=?", [$job['id']]);
    $person = $newPerson('capacity');
    $document = audienceFlowDefault(); $document['trigger'] = ['type'=>'manual'];
    $document['start'] = 'finish'; $document['nodes'] = [['id'=>'finish','type'=>'exit']];
    for ($i=0; $i<101; ++$i) $last = $newFlow($document, 'Capacity '.$suffix.' '.$i);
    $run = $enroll($last, $person);
    processAudienceFlows($conn, 1, $clock);
    $expect($store->one('SELECT state FROM marketing_flow_runs WHERE id=?', [$run])['state']==='exited',
        'The 101st running flow must be processed in the same paged scan.');
    foreach ($flowIds as $id) $store->write("UPDATE marketing_flows SET state='paused' WHERE id=?", [$id]);

    // No provider is contacted: this fixture qualifies a fictional sender for a callback transport.
    $conn->query("UPDATE marketing_ses_settings SET enabled=1,from_email='sender@flow-queue.example',access_key_tail='TEST',check_state='ready',checked_at=UTC_TIMESTAMP(),production_access=1,sending_enabled=1,max_daily=100000,max_rate=100,subscription_arn='arn:aws:sns:us-east-1:123456789012:fixture:review' WHERE audience_id=1");
    $fixtureSettings = audienceJson($audienceSettings);
    $fixtureSettings['brand'] = is_array($fixtureSettings['brand']??null) ? $fixtureSettings['brand'] : [];
    $fixtureSettings['brand']['mailing_address'] = '123 Fictional Fixture Road';
    $store->write('UPDATE marketing_audiences SET settings_json=? WHERE id=1', [json_encode($fixtureSettings,JSON_THROW_ON_ERROR)]);
    $campaign = $store->createCampaign(['name'=>'Queue regression '.$suffix,'campaign_type'=>'plain-text']);
    foreach (['from'=>['from_name'=>'Fixture','from_email'=>'sender@flow-queue.example'],
        'subject'=>['subject'=>'Queue regression'], 'content'=>['text'=>'Fictional fixture content.']] as $section=>$input) {
        $current = $store->campaign($campaign);
        $store->saveCampaignSection($campaign, (int)$current['version'], $section, $input);
    }
    $document['start'] = 'mail';
    $document['nodes'] = [['id'=>'mail','type'=>'email','campaign_id'=>$campaign,'next'=>'delay'],
        ['id'=>'delay','type'=>'delay','seconds'=>86400,'next'=>'']];
    $document['settings']['after'] = '00:00'; $document['settings']['before'] = '23:59';
    $document['settings']['weekdays'] = [1,2,3,4,5,6,7];
    $document['settings']['recipient_timezone'] = false; $document['settings']['timezone'] = 'UTC';
    $flow = $newFlow($document, 'Queued unsubscribe '.$suffix);
    $run = $enroll($flow, $person);
    processAudienceFlows($conn, 10, $clock);
    $delivery = $store->one('SELECT id FROM marketing_campaign_deliveries WHERE flow_run_id=?', [$run]);
    $expect($delivery !== null, 'Email step must queue a native delivery.');
    $contact = $store->person($person); $store->mutatePerson($person, (int)$contact['version'], 'unsubscribe');
    processAudienceFlows($conn, 10, $clock+31);
    $receipt = $store->one('SELECT state FROM marketing_campaign_recipients WHERE delivery_id=?', [$delivery['id']]);
    $expect($receipt['state']==='suppressed', 'Flow advancement must suppress its queued delivery before leaving the email step.');
    $expect($store->one('SELECT state FROM marketing_campaign_deliveries WHERE id=?', [$delivery['id']])['state']==='completed',
        'A suppressed email must close its delivery.');

    // Simulate a delivery orphaned by an older release, ahead of a valid later email.
    $store->write("UPDATE marketing_campaign_recipients SET state='queued' WHERE delivery_id=?", [$delivery['id']]);
    $store->write("UPDATE marketing_campaign_deliveries SET state='sending',due_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?", [$delivery['id']]);
    $laterPerson = $newPerson('later'); $laterRun = $enroll($flow, $laterPerson);
    processAudienceFlows($conn, 10, $clock+32);
    $laterDelivery = $store->one('SELECT id FROM marketing_campaign_deliveries WHERE flow_run_id=?', [$laterRun]);
    $store->write('UPDATE marketing_campaign_deliveries SET due_at=UTC_TIMESTAMP() WHERE id=?', [$laterDelivery['id']]);
    $store->write('UPDATE marketing_campaign_recipients SET next_attempt_at=UTC_TIMESTAMP() WHERE delivery_id=?', [$laterDelivery['id']]);
    $sent = [];
    processAudienceCampaignDelivery($conn, 10, static function(array $message) use (&$sent): string { $sent[]=$message['email']; return 'accepted'; });
    $expect($sent===[$store->person($laterPerson)['email']], 'An orphaned older email must not block or send ahead of the valid later email.');
    $contact = $store->person($laterPerson); $store->mutatePerson($laterPerson, (int)$contact['version'], 'unsubscribe');
    processAudienceFlows($conn, 10, $clock+63);
    $step = audienceJson($store->one("SELECT result_json FROM marketing_flow_steps WHERE run_id=? AND node_key='mail'", [$laterRun])['result_json']);
    $expect(($step['receipt']??'')==='accepted', 'Unsubscribing after acceptance must preserve the accepted receipt.');

    $uncertainPerson = $newPerson('uncertain'); $uncertainRun = $enroll($flow, $uncertainPerson);
    processAudienceFlows($conn, 10, $clock+64);
    $uncertainDelivery = $store->one('SELECT id FROM marketing_campaign_deliveries WHERE flow_run_id=?', [$uncertainRun]);
    $store->write("UPDATE marketing_campaign_recipients SET state='uncertain' WHERE delivery_id=?", [$uncertainDelivery['id']]);
    $contact = $store->person($uncertainPerson); $store->mutatePerson($uncertainPerson, (int)$contact['version'], 'unsubscribe');
    processAudienceFlows($conn, 10, $clock+95);
    $expect($store->one('SELECT state FROM marketing_flow_runs WHERE id=?', [$uncertainRun])['state']==='failed'
        && $store->one('SELECT state FROM marketing_campaign_recipients WHERE delivery_id=?', [$uncertainDelivery['id']])['state']==='uncertain',
        'Withdrawal of consent must not overwrite or replay an uncertain delivery receipt.');
    echo "Flow queue regressions passed: 101 running flows, queued suppression, legacy orphan isolation, accepted and uncertain receipts.\n";
} finally {
    foreach ($flowIds as $id) $store->write("UPDATE marketing_flows SET state='paused' WHERE id=?", [$id]);
    foreach ($heldFlows as $flow) $store->write("UPDATE marketing_flows SET state='running' WHERE id=?", [$flow['id']]);
    foreach ($heldJobs as $job) $store->write('UPDATE marketing_campaign_deliveries SET state=? WHERE id=?', [$job['state'],$job['id']]);
    $columns = ['enabled','from_email','access_key_tail','check_state','checked_at','production_access','sending_enabled','max_daily','max_rate','subscription_arn'];
    $store->write('UPDATE marketing_ses_settings SET '.implode(',',array_map(static fn($column)=>$column.'=?',$columns)).' WHERE audience_id=1',
        array_map(static fn($column)=>$settings[$column],$columns));
    $store->write('UPDATE marketing_audiences SET settings_json=? WHERE id=1', [$audienceSettings]);
}
