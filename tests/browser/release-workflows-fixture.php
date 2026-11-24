<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable' || getenv('DNR_MAIL_TRANSPORT') !== 'log') {
    throw new RuntimeException('Browser workflows require a disposable database and log mail transport.');
}
require '/var/www/html/bootstrap.php';
require '/var/www/html/audience_store.php';

$suffix = $argv[2] ?? '';
if (!preg_match('/\A[a-f0-9]{12}\z/', $suffix)) {
    throw new InvalidArgumentException('Invalid browser fixture identifier.');
}
$email = "recipient-$suffix@example.test";
$name = "Browser Campaign $suffix";
$store = new AudienceStore($conn, 1, 0, 'Browser fixture');
if ($argv[1] === 'dispatch') {
    require '/var/www/html/audience_dispatch_worker.php';
    $campaign = $store->one('SELECT id FROM marketing_campaigns WHERE name=?', [$name]);
    $delivery = $campaign ? $store->campaignDelivery((int) $campaign['id']) : null;
    if (!$delivery || (int) $delivery['recipient_count'] !== 1) {
        throw new RuntimeException('The browser did not queue exactly one fixture recipient.');
    }
    $sent = [];
    processAudienceCampaignDelivery($conn, 3, static function (array $message) use (&$sent, $email): string {
        if ($message['email'] !== $email) {
            throw new RuntimeException('Unexpected simulated campaign recipient.');
        }
        $sent[] = $message['email'];
        return 'accepted';
    });
    if ($sent !== [$email] || $store->campaignDelivery((int) $campaign['id'])['state'] !== 'completed') {
        throw new RuntimeException('The simulated delivery did not produce a completed receipt.');
    }
    exit;
}
if ($argv[1] !== 'seed') {
    throw new InvalidArgumentException('Unknown browser fixture action.');
}

$conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', ["Browser Organization $suffix"]);
$organization = (int) $conn->insert_id;
$conn->execute_query("UPDATE marketing_audiences SET settings_json=JSON_SET(settings_json,
    '$.brand', JSON_OBJECT('mailing_address', '12 Fictional Browser Test Road')) WHERE id=1");
$conn->query("UPDATE marketing_ses_settings SET enabled=1, from_email='sender@example.test',
    access_key_tail='TEST', check_state='ready', checked_at=UTC_TIMESTAMP(),
    production_access=1, sending_enabled=1, max_daily=1000, max_rate=10,
    aws_account_id='111122223333', region='us-east-2', configuration_set='fixture',
    topic_arn='arn:aws:sns:us-east-2:111122223333:fixture',
    subscription_arn='arn:aws:sns:us-east-2:111122223333:fixture:confirmed' WHERE audience_id=1");
$conn->execute_query('INSERT INTO marketing_tags(audience_id,name) VALUES (1,?)', ["Browser recipients $suffix"]);
$tag = (int) $conn->insert_id;
$conn->execute_query("INSERT INTO marketing_contacts(audience_id,email,first_name,email_status,notes)
    VALUES (1,?,'Browser Recipient','subscribed','')", [$email]);
$conn->execute_query('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)', [(int) $conn->insert_id, $tag]);

$campaign = $store->createCampaign(['name' => $name, 'campaign_type' => 'plain-text']);
foreach ([
    'to' => ['target' => 'tag:' . $tag],
    'from' => ['from_name' => 'Fixture Team', 'from_email' => 'sender@example.test'],
    'subject' => ['subject' => 'Browser release qualification'],
    'content' => ['text' => 'Fictional test newsletter.'],
    'send_time' => ['send_mode' => 'now', 'timezone' => 'UTC', 'delivery' => 'specific'],
] as $section => $input) {
    $version = (int) $store->campaign($campaign)['version'];
    $store->saveCampaignSection($campaign, $version, $section, $input);
}

$store->reviewCampaignDispatch($campaign, $store->one('SELECT * FROM marketing_audiences WHERE id=1'), currentAccountPublicUrl());

echo json_encode(['organization' => $organization, 'campaign' => $campaign, 'recipient' => $email], JSON_THROW_ON_ERROR);
