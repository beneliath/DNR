<?php
/** Fictional, idempotent interaction locations for the localhost:8080 preview. */
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
$store = new AudienceStore($db, 1, (int)$actor['id'], 'Fictional localhost map sample');
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
// These public DNS addresses are looked up offline. Neither belongs to a sample member.
$signupIp = '8.8.8.8';
$preferencesIp = '2001:4860:4860::8888';
$signup = audienceInteractionLocation(['REMOTE_ADDR'=>$signupIp]);
$preferences = audienceInteractionLocation(['REMOTE_ADDR'=>$preferencesIp]);
if (empty($signup['city']) || empty($signup['region']) || empty($preferences['region']) || empty($preferences['city'])) {
    throw new RuntimeException('A readable City MMDB is required to preview both recorded and current estimates.');
}
$place = static fn(string $country, string $region = '', string $city = ''): array =>
    array_filter(['country_code'=>$country, 'region'=>$region, 'city'=>$city], static fn(string $value): bool => $value !== '');
$fixtures = [
    ['chicago', 'Chicago', 'signup-permission', 0, $place('US','Illinois','Chicago')],
    ['toronto', 'Toronto', 'preferences', 1, $place('CA','Ontario','Toronto')],
    ['london', 'London', 'subscription-confirmed', 2, $place('GB','England','London')],
    ['jerusalem', 'Jerusalem', 'survey-response', 3, $place('IL','Jerusalem District','Jerusalem')],
    ['haifa', 'Haifa', 'status', 4, $place('IL','Haifa District','Haifa')],
    ['madison', 'Madison', 'signup-permission', 5, $place('US','Wisconsin','Madison')],
    ['australia', 'Australia', 'preferences', 6, $place('AU')],
    ['chicago-again', 'Chicago', 'preferences', 1, $place('US','Illinois','Chicago'), 'chicago'],
    ['paris', 'Paris', 'preferences', 2, $place('FR','Île-de-France','Paris')],
    ['berlin', 'Berlin', 'survey-response', 3, $place('DE','Berlin','Berlin')],
    ['tokyo', 'Tokyo', 'signup-permission', 4, $place('JP','Tokyo','Tokyo')],
    ['nairobi', 'Nairobi', 'preferences', 5, $place('KE','Nairobi','Nairobi')],
    ['new-york', 'New York', 'subscription-confirmed', 6, $place('US','New York','New York')],
    ['ip-lookup', 'IP Lookup', 'signup-permission', 5, ['country_code'=>$signup['country_code'],'ip_address'=>$signupIp]],
    ['ip-lookup-preferences', 'IP Lookup', 'preferences', 1, $preferences, 'ip-lookup'],
];
$result = $store->transaction(function () use ($store, $fixtures, $now): array {
    $createdContacts = 0;
    $createdEvents = 0;
    $previewId = null;
    foreach ($fixtures as $index => $fixture) {
        [$key, $name, $type, $daysAgo, $location] = $fixture;
        $email = 'interaction-map-sample-'.($fixture[5] ?? $key).'@example.invalid';
        $contact = $store->one('SELECT id,deleted_at FROM marketing_contacts WHERE audience_id=? AND email=? LIMIT 1',
            [$store->audienceId, $email]);
        if ($contact && $contact['deleted_at'] !== null) throw new RuntimeException('A map sample connection was deleted: '.$email);
        if (!$contact) {
            // Keep fixtures out of send audiences, even when localhost uses SMTP.
            $store->write("INSERT INTO marketing_contacts(audience_id,email,first_name,last_name,email_status,is_archived,notes) VALUES (?,?,?,?,'unsubscribed',1,?)",
                [$store->audienceId, $email, 'Sample', $name,
                    'Fictional localhost Interaction Locations preview. Archived and unsubscribed; .invalid address cannot receive mail.']);
            $contact = ['id'=>(int)$store->db->insert_id];
            $createdContacts++;
        }
        if ($key === 'ip-lookup') $previewId = (int)$contact['id'];
        $location += ['ip_address'=>'192.0.2.'.($index+1), 'source'=>'fictional localhost sample'];
        $details = ['sample_dataset'=>'localhost-interaction-map-v1', 'sample_key'=>$key,
            'sample'=>true, 'interaction_location'=>$location];
        if ($type === 'status') $details += ['from'=>'subscribed', 'to'=>'unsubscribed'];
        $json = json_encode($details, JSON_THROW_ON_ERROR);
        $at = $now->modify('-'.$daysAgo.' days')->modify('-1 hour')->format('Y-m-d H:i:s');
        $existing = $store->one("SELECT id FROM marketing_contact_events WHERE contact_id=? AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.sample_dataset'))='localhost-interaction-map-v1' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.sample_key'))=? LIMIT 1 FOR UPDATE",
            [$contact['id'], $key]);
        if ($existing) {
            $store->write('UPDATE marketing_contact_events SET details_json=?,created_at=? WHERE id=?',[$json,$at,$existing['id']]);
        } else {
            $store->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json,created_at) VALUES (?,NULL,'Fictional localhost map sample','recipient',?,?,?)",
                [$contact['id'],$type,$json,$at]);
            $createdEvents++;
        }
    }
    return ['created_contacts'=>$createdContacts,'created_events'=>$createdEvents,
        'sample_interactions'=>count($fixtures),'preview_contact_id'=>$previewId,'external_messages_sent'=>0];
});
echo json_encode($result, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
