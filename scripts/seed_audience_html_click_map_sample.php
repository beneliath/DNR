<?php
/** Idempotent HTML newsletter Click map fixture for the localhost:8080 preview. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('DNR_LOCAL_SAMPLE_DATA') !== 'localhost:8080') {
    exit("Explicit localhost sample-data opt-in required.\n");
}
if (!is_file('/usr/local/etc/php/conf.d/zz-dnr-development.ini')) {
    throw new RuntimeException('Development bind-mounted runtime required.');
}
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/audience_store.php';
if (currentAccountKey() !== 'shalom-in-messiah') {
    throw new RuntimeException('Only the local primary account may be seeded.');
}

$db = applicationDatabaseConnection();
$actor = $db->query("SELECT id FROM users WHERE role='admin' AND account_status='active' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$actor) throw new RuntimeException('An active local administrator is required.');
$store = new AudienceStore($db, 1, (int)$actor['id'], 'Fictional localhost HTML newsletter sample');
$name = 'Sample · HTML newsletter click map';
$dataset = 'localhost-html-click-map-v1';
$result = $store->transaction(function () use ($store, $name, $dataset): array {
    $existing = $store->one('SELECT id,draft_json FROM marketing_campaigns WHERE audience_id=? AND name=? AND deleted_at IS NULL LIMIT 1 FOR UPDATE',
        [$store->audienceId, $name]);
    if ($existing) {
        if ((audienceJson($existing['draft_json'])['sample_dataset'] ?? '') !== $dataset) {
            throw new RuntimeException('A campaign with the sample name already exists.');
        }
        $delivery = $store->one('SELECT id FROM marketing_campaign_deliveries WHERE audience_id=? AND campaign_id=? AND confirmed_version=1',
            [$store->audienceId, $existing['id']]);
        if (!$delivery) throw new RuntimeException('The existing HTML sample delivery is incomplete.');
        return ['name'=>$name, 'delivery_id'=>(int)$delivery['id'], 'created'=>false, 'external_messages_sent'=>0];
    }

    $design = audienceDesignStarters()['newsletter']['design'];
    $design['rows'][1]['columns'][0][0]['text'] = 'Community Newsletter';
    $design['rows'][1]['columns'][0][1]['text'] = 'Good things are growing together. Here are a few highlights from our community this month.';
    $design['rows'][1]['columns'][0][2]['text'] = 'See gathering details';
    $design['rows'][1]['columns'][0][2]['url'] = 'https://example.test/sample/gathering';
    $design['rows'][2]['columns'][0][1]['text'] = 'Explore the latest story';
    $design['rows'][2]['columns'][0][2]['html'] = '<p>Read about people and projects helping our neighborhood. <a href="https://example.test/sample/story">Read the story</a>.</p>';
    $design = audienceDesign($design);
    $draft = [
        'sample_dataset'=>$dataset, 'subject'=>'Sample · Community Newsletter',
        'preview_text'=>'Fictional HTML newsletter for viewing the Click map.',
        'from_name'=>'Sample Community', 'from_email'=>'newsletter@example.invalid',
        'target'=>'all', 'recipients_saved'=>true,
        'text'=>'Fictional HTML newsletter. Links and recipients are examples only.',
        'tracking'=>audienceCampaignDefaultTracking(), 'design'=>$design,
    ];
    $json = static fn(array $data): string => json_encode($data, JSON_THROW_ON_ERROR);
    $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d H:i:s');
    $store->write("INSERT INTO marketing_campaigns(audience_id,name,campaign_type,status,draft_json,actor_label,created_at,updated_at,sent_at) VALUES (?,?,'regular','sent',?,?,?,?,?)",
        [$store->audienceId,$name,$json($draft),$store->actorLabel,$at,$at,$at]);
    $campaignId = (int)$store->db->insert_id;
    $snapshot = \Dnr\Security\ApplicationKey::seal($json(['draft'=>$draft,'type'=>'regular','base'=>'https://example.invalid','sample'=>true]));
    $store->write("INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label,created_at,updated_at,completed_at) VALUES (?,?,1,'completed',?,'America/Chicago',?,?,2,?,?,?,?,?)",
        [$store->audienceId,$campaignId,$at,$snapshot,bin2hex(random_bytes(32)),$store->actorId,$store->actorLabel,$at,$at,$at]);
    $deliveryId = (int)$store->db->insert_id;
    $gathering = 'https://example.test/sample/gathering';
    $story = 'https://example.test/sample/story';
    foreach (['one','two'] as $index=>$suffix) {
        $email = 'html-click-map-sample-'.$suffix.'@example.invalid';
        $contact = $store->one('SELECT id,deleted_at FROM marketing_contacts WHERE audience_id=? AND email=? LIMIT 1',
            [$store->audienceId,$email]);
        if ($contact && $contact['deleted_at'] !== null) throw new RuntimeException('A sample connection was deleted: '.$email);
        if (!$contact) {
            $store->write("INSERT INTO marketing_contacts(audience_id,email,first_name,last_name,email_status,is_archived,notes) VALUES (?,?,?,?,'unsubscribed',1,?)",
                [$store->audienceId,$email,'Sample',ucfirst($suffix),'Fictional localhost HTML Click map recipient. Archived and unsubscribed; .invalid address cannot receive mail.']);
            $contact = ['id'=>(int)$store->db->insert_id];
        }
        $store->write("INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,state,next_attempt_at,accepted_at,phase) VALUES (?,?,?,?,?,?,?,'accepted',?,?,'main')",
            [$store->audienceId,$deliveryId,$contact['id'],audienceDispatchEmailHash($email),
                \Dnr\Security\ApplicationKey::seal($json(['email'=>$email,'FNAME'=>'Sample'])),
                bin2hex(random_bytes(32)),'<html-click-map-'.$suffix.'-'.bin2hex(random_bytes(4)).'@example.invalid>',$at,$at]);
        $recipientId = (int)$store->db->insert_id;
        foreach ([$gathering,...($index===0?[$story]:[])] as $target) {
            $store->write("INSERT INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,'click',?,'tracking',?,?)",
                [$store->audienceId,$recipientId,bin2hex(random_bytes(32)),$at,
                    $json(['sample_dataset'=>$dataset,'sample'=>true,'bot'=>false,
                        'link_hash'=>hash('sha256',$target),'link_label'=>parse_url($target,PHP_URL_HOST).parse_url($target,PHP_URL_PATH)])]);
        }
    }
    return ['name'=>$name,'delivery_id'=>$deliveryId,'created'=>true,'external_messages_sent'=>0];
});
echo json_encode($result, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
