<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('DNR_MANUAL_FIXTURE')!=='disposable'||getenv('DNR_ACCOUNT_KEY')!=='message-monkey-guide')exit(64);
require '/var/www/html/bootstrap.php';require '/var/www/html/audience_store.php';
require '/opt/dnr/tests/audience_analytics_fixtures.php';
$db=applicationDatabaseConnection();$user=$db->query("SELECT id FROM users WHERE username='avery.guide'")->fetch_assoc();
if(!$user)throw new RuntimeException('Create the guide account first.');
$_SESSION=['user_id'=>(int)$user['id'],'role'=>'admin','username'=>'avery.guide'];
$s=new AudienceStore($db,1,(int)$user['id'],'Guide example');
if (($argv[1] ?? '') === '--signup-examples') {
    $form = $s->one("SELECT id FROM marketing_collection_resources WHERE name='Community newsletter signup'");
    if (!$form || $s->one("SELECT id FROM marketing_contacts WHERE email='pending-guide@example.test'")) {
        throw new RuntimeException('Start with the existing fictional guide seed and a fresh signup example.');
    }
    $id = (int) $form['id'];
    $resource = $s->collectionResource($id);
    $document = array_replace($resource['draft'], [
        'title' => 'Community Newsletter Signup',
        'intro' => 'Get community event dates, stories, and volunteer opportunities by email.',
        'background' => '#e7f6f3',
        'fields' => ['email'],
        'required' => ['email'],
        'group_ids' => [],
        'tag_ids' => [$s->ensureTag('Community newsletter')],
        'opt_in' => 'double',
    ]);
    $s->saveCollection(['name' => $resource['name'], 'document' => $document], $id, (int) $resource['version']);
    $s->collectionOperation($id, (int) $resource['version'] + 1, 'publish');
    $published = $s->collectionPublished($resource['public_key']);

    // Temporarily simulate SES readiness while creating a pending consent record.
    // This preview has no dispatch worker or SES credentials.
    $ses = $s->sesSettings();
    $settings = [
        'enabled' => 1, 'from_email' => 'newsletter@example.test', 'access_key_tail' => 'TEST',
        'check_state' => 'ready', 'checked_at' => gmdate('Y-m-d H:i:s'), 'production_access' => 1,
        'sending_enabled' => 1, 'max_daily' => 1000, 'max_rate' => 10,
        'aws_account_id' => '111122223333', 'region' => 'us-east-2',
        'subscription_arn' => 'arn:aws:sns:us-east-2:111122223333:fixture:confirmed',
    ];
    $columns = array_keys($settings);
    $sql = 'UPDATE marketing_ses_settings SET ' . implode(',', array_map(static fn($key) => $key . '=?', $columns)) . ' WHERE audience_id=1';
    $s->write($sql, array_values($settings));
    try {
        $challenge = audienceJson(\Dnr\Security\ApplicationKey::open(
            $s->collectionChallenge($published, bin2hex(random_bytes(32)))
        ));
        $challenge['issued'] -= 5;
        $s->submitCollection($published, [
            'proof' => \Dnr\Security\ApplicationKey::seal(json_encode($challenge, JSON_THROW_ON_ERROR)),
            'email' => 'pending-guide@example.test', 'permission' => '1',
        ], 'guide-signup-example', 'https://newsletter.example.test');
    } finally {
        $s->write($sql, array_map(static fn($key) => $ses[$key], $columns));
    }
    $contact = $s->one("SELECT id, email_status FROM marketing_contacts WHERE email='pending-guide@example.test'");
    if (($contact['email_status'] ?? '') !== 'pending') {
        throw new RuntimeException('The fictional subscriber should be pending confirmation.');
    }
    // The public appearance figure uses single opt-in so the hosted page can
    // be shown without any simulated delivery configuration left enabled.
    $publicName = 'Public signup appearance example';
    $publicId = $s->saveCollection([
        'name' => $publicName, 'kind' => 'hosted',
        'document' => array_replace($document, ['opt_in' => 'single']),
    ]);
    $s->collectionOperation($publicId, 1, 'publish');
    echo json_encode([
        'public_key' => $s->collectionResource($publicId)['public_key'],
        'contact_id' => (int) $contact['id'],
    ], JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}
if($s->one("SELECT id FROM marketing_campaigns WHERE name='Community news - November'"))throw new RuntimeException('Guide examples already exist.');
$logo='data:image/png;base64,'.base64_encode(file_get_contents('/var/www/html/assets/dnr-logo-email.png'));
$context=$s->contentSettings();$s->saveBrand(['organization_name'=>'Community Learning','mailing_address'=>"100 Example Avenue\nChicago, IL 60601",'logo'=>$logo,'background'=>'#f5f7f7','body'=>'#ffffff','text'=>'#21262a','link'=>'#007c89','button'=>'#007c89','buttonText'=>'#ffffff','font'=>'Arial','fontSize'=>'16'],(int)$context['version']);
$s->saveContentItem(['name'=>'MOED community logo','image'=>$logo,'alt'=>'MOED logo']);
$campaigns=[];$templates=[];
foreach(['newsletter'=>'Community news - November','event'=>'Community gathering invitation','welcome'=>'Welcome to our community']as$key=>$name){
    $design=audienceDesignStarters()[$key]['design'];
    $templates[$key]=$s->createEmailTemplate($name.' template',$design);
    $id=$s->createCampaign(['name'=>$name,'campaign_type'=>'regular','target'=>'all']);
    foreach(['from'=>['from_name'=>'Community Learning','from_email'=>'newsletter@example.test'],'subject'=>['subject'=>$name,'preview_text'=>'Stories, events, and ways to connect.']]as$section=>$data){$c=$s->campaign($id);$s->saveCampaignSection($id,(int)$c['version'],$section,$data);}
    $c=$s->campaign($id);$s->saveEmailDesign('campaign',$id,(int)$c['version'],$design);$campaigns[$key]=$id;
}
$plain=$s->createCampaign(['name'=>'A personal thank-you','campaign_type'=>'plain-text','target'=>'all']);
foreach(['from'=>['from_name'=>'Avery Morgan','from_email'=>'avery@example.test'],'subject'=>['subject'=>'Thank you for being part of our community'],'content'=>['text'=>"Hello *|FNAME|*,\n\nThank you for joining us. We look forward to sharing our next community gathering with you.\n\nAvery"]]as$section=>$data){$c=$s->campaign($plain);$s->saveCampaignSection($plain,(int)$c['version'],$section,$data);}
$campaigns['plain']=$plain;
$plan=$s->saveCampaignPlan(['name'=>'November community outreach','goal'=>'Promote an event','start'=>'2026-11-01','end'=>'2026-11-30','notes'=>'Fictional guide example. Review invitations, reminders, and follow-up.'],0,0);
foreach([['task','Review event details','2026-11-02T09:00','2026-11-02T10:00',0],['email','Send community invitation','2026-11-05T10:00','2026-11-05T10:30',$campaigns['event']],['event','Community gathering','2026-11-14T10:00','2026-11-14T12:00',0]]as[$type,$name,$start,$end,$campaign])$s->saveCampaignTouchpoint(['plan_id'=>(string)$plan,'touchpoint_type'=>$type,'name'=>$name,'state'=>'not-started','start_at'=>$start,'end_at'=>$end,'campaign_id'=>(string)$campaign,'notes'=>'Fictional planning example.'],0,0);
$collections=[];
foreach(['hosted'=>'Community newsletter signup','embedded'=>'Website signup form','popup'=>'Stay connected popup','landing'=>'Join the community gathering','survey'=>'Community gathering feedback','preferences'=>'Subscriber preferences']as$kind=>$name)$collections[$kind]=$s->saveCollection(['kind'=>$kind,'name'=>$name,'starter'=>$kind==='survey'?'event-scale':($kind==='landing'?'grow':'')]);
$flows=[];
foreach(['welcome'=>'Welcome new connections','nurture'=>'Keep our community connected']as$starter=>$name){$doc=audienceFlowDefault($starter);foreach($doc['nodes']as&$node)if($node['type']==='email')$node['campaign_id']=$campaigns['welcome'];unset($node);$flows[$starter]=$s->saveFlow($name,$doc);}
$threads=[];
foreach(['Question about the next gathering','Thank you for the newsletter','An idea for a community story']as$i=>$subject)$threads[]=$s->inboxReceive('direct',0,['avery.rivera@example.test','morgan.chen@example.test','jordan.patel@example.test'][$i],['Avery Rivera','Morgan Chen','Jordan Patel'][$i],$subject,['Could you share the time and location for our next gathering?','Thank you for keeping us informed. I enjoyed the latest community update.','We would love to share a volunteer story in the next newsletter.'][$i],hash('sha256','guide-conversation-'.$i),false);
$s->saveInboxNamed('label',['name'=>'Event questions']);
$s->saveInboxNamed('label',['name'=>'Follow-up']);
$s->saveInboxNamed('template',['name'=>'Thanks for getting in touch','subject'=>'Thank you for your message','body_html'=>'<p>Thank you for reaching out. We appreciate hearing from you and will follow up with the details shortly.</p>']);
$analytics=audienceAnalyticsFixture($s,'Example');
$presets=audienceAnalyticsPresets();$report=$s->analyticsSaveReport(['name'=>'Monthly email performance','description'=>'Fictional guide examples; no messages were sent.','dataset'=>'email','start'=>$presets[90]['start'],'end'=>$presets[90]['end'],'shared'=>'1','messages_mode'=>'selected','messages'=>$analytics['campaigns'],'columns'=>['accepted','open_rate','click_rate','bounced'],'plots'=>['accepted','click_rate']],true);
echo json_encode(['campaigns'=>$campaigns,'templates'=>$templates,'plan'=>$plan,'collections'=>$collections,'flows'=>$flows,'threads'=>$threads,'report'=>$report,'analytics'=>$analytics,'contacts'=>$s->all('SELECT id FROM marketing_contacts WHERE is_archived=0 ORDER BY id LIMIT 3'),'jobs'=>$s->all('SELECT id,state FROM marketing_jobs ORDER BY id'),'external_messages_sent'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
