<?php
/** Fictional, date-relative MessageMonkey data. Run only through the verified baseline operator. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden.'); }
$options = getopt('', ['apply', 'as-of:', 'media-only', 'locations-only']);
$anchor = $options['as-of'] ?? (new DateTimeImmutable('now', new DateTimeZone('America/Chicago')))->format('Y-m-d');
$today = DateTimeImmutable::createFromFormat('!Y-m-d', $anchor, new DateTimeZone('America/Chicago'));
if (!$today || $today->format('Y-m-d') !== $anchor) throw new InvalidArgumentException('Invalid Chicago date.');
$at = static fn(int $days): string => $today->modify(($days >= 0 ? '+' : '').$days.' days')->setTime(10, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$month = static function(int $offset) use ($today): string {
    $first = $today->modify('first day of this month')->modify(($offset >= 0 ? '+' : '').$offset.' months');
    return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), min((int)$today->format('d'), (int)$first->format('t')))->format('Y-m-d');
};
$plan = ['account'=>'test-account','anchor'=>$anchor,'window'=>[$month(-3),$month(9)],'contacts'=>24,'campaigns'=>9,'collection_resources'=>6,'flows'=>3,'inbox_threads'=>6,'content_images'=>6,'brand_logos'=>2,'interaction_locations'=>41,'outgoing_jobs'=>0];
if (!isset($options['apply'])) { echo json_encode($plan, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n"; exit; }
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/audience_store.php';
if (!accountsEnabled() || accountIsPrimary() || currentAccountKey() !== 'test-account' || currentAccountProfile()['account_key'] !== 'test-account') throw new RuntimeException('Test Account member only.');
$mediaOnly = isset($options['media-only']);
$locationsOnly = isset($options['locations-only']);
if ($mediaOnly && $locationsOnly) throw new InvalidArgumentException('Choose one sample import.');
// The runner owns full baseline staging and replacement. A guarded media-only
// or locations-only entry point adds examples to the live Test Account without a reset.
if ($mediaOnly || $locationsOnly) {
    $gate=$mediaOnly?'DNR_TEST_ACCOUNT_MEDIA_IMPORT':'DNR_TEST_ACCOUNT_LOCATION_IMPORT';
    if (getenv($gate) !== '1' || getenv('DNR_MAIL_TRANSPORT') !== 'log') throw new RuntimeException('Guarded Test Account sample import required.');
} elseif (getenv('DNR_TEST_ACCOUNT_SAMPLE_STAGE') !== '1' || getenv('DB_HOST') !== '127.0.0.1' || getenv('MYSQL_USER') !== 'root' || getenv('DNR_MAIL_TRANSPORT') !== 'log') throw new RuntimeException('Isolated baseline staging required.');
$user = $conn->query("SELECT * FROM users WHERE username='TestEditor'")->fetch_assoc();
if (!$user || !demoPasswordOnlyAuthenticationAllowed($user) || $user['role'] !== 'editor' || accountSecurityAccessAllowed()) throw new RuntimeException('Pinned demo policy required.');
$s = new AudienceStore($conn, 1, (int)$user['id'], 'Fictional Test Account sample');
if (!$mediaOnly && !$locationsOnly) foreach (['marketing_contacts','marketing_campaigns','marketing_collection_resources','marketing_flows','marketing_inbox_threads','marketing_design_library'] as $table) {
    if ((int)$s->one('SELECT COUNT(*) n FROM '.$table)['n']) throw new RuntimeException('Empty staged MessageMonkey data required: '.$table);
}
if ($s->one('SELECT audience_id FROM marketing_ses_settings WHERE enabled<>0 OR sending_enabled<>0 OR production_access<>0') || (!$mediaOnly && !$locationsOnly && $s->one("SELECT audience_id FROM marketing_ses_credentials WHERE credential_ciphertext<>''"))) throw new RuntimeException('Test Account SES must remain unconfigured.');
$j = static fn(mixed $v): string => json_encode($v, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
$insert = static function(string $table, array $row) use ($s): int {
    $s->write('INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',array_values($row));
    return (int)$s->db->insert_id;
};
$sampleMedia = static function() use ($s,$insert,$j): array {
    $directory='/var/www/html/assets/template-art/';
    $manifest=json_decode((string)file_get_contents($directory.'sources.json'),true,64,JSON_THROW_ON_ERROR);
    $byFile=array_column($manifest['assets']??[],null,'file');
    $samples=[
        'galilee-boat.jpg'=>'Demo · Sea of Galilee',
        'negev-sunset.jpg'=>'Demo · Negev sunset',
        'tel-aviv-market.jpg'=>'Demo · Tel Aviv market',
        'ein-gedi-waterfall.jpg'=>'Demo · Ein Gedi waterfall',
        'haifa-harbor.jpg'=>'Demo · Haifa harbor',
        'jaffa-steps.jpg'=>'Demo · Old Jaffa',
    ];
    $imageIds=[];
    foreach ($samples as $file=>$name) {
        $photo=$byFile[$file]??null;
        if (!$photo) throw new RuntimeException('Bundled sample photo is missing: '.$file);
        $bytes=file_get_contents($directory.$file);
        if ($bytes===false || hash('sha256',$bytes)!==$photo['sha256']) throw new RuntimeException('Bundled sample photo changed: '.$file);
        $image=audienceDesignImage('data:image/jpeg;base64,'.base64_encode($bytes),1000000);
        $dimensions=getimagesizefromstring($bytes);
        $data=['image'=>$image,'document'=>'','alt'=>$photo['alt'],'url'=>'','folder'=>'','bytes'=>strlen($bytes),
            'width'=>$dimensions[0],'height'=>$dimensions[1],'published'=>false,'rights_confirmed'=>false,
            'sample_dataset'=>'test-account-message-monkey-media-v1','sample_key'=>$file,
            'source_credit'=>$photo['creator'],'source_url'=>$photo['source'],'license'=>$photo['license']];
        $existing=$s->one("SELECT id FROM marketing_design_library WHERE audience_id=? AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.sample_dataset'))='test-account-message-monkey-media-v1' AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.sample_key'))=? LIMIT 1 FOR UPDATE",[$s->audienceId,$file]);
        if ($existing) {
            $s->write('UPDATE marketing_design_library SET name=?,data_json=?,actor_label=?,deleted_at=NULL,version=version+1 WHERE audience_id=? AND id=?',[$name,$j($data),$s->actorLabel,$s->audienceId,$existing['id']]);
            $imageIds[]=(int)$existing['id'];
        } else $imageIds[]=$insert('marketing_design_library',['audience_id'=>$s->audienceId,'item_type'=>'image','name'=>$name,'data_json'=>$j($data),'actor_label'=>$s->actorLabel]);
    }
    $defaultLogo=<<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="560" height="180" viewBox="0 0 560 180"><rect width="560" height="180" rx="22" fill="#e8f4f2"/><circle cx="88" cy="90" r="53" fill="#007c89"/><path d="M61 93l19 19 37-44" fill="none" stroke="#fff" stroke-width="11" stroke-linecap="round" stroke-linejoin="round"/><text x="162" y="88" fill="#183d43" font-family="Arial" font-size="43" font-weight="700">Test Account</text><text x="165" y="122" fill="#42696e" font-family="Arial" font-size="18" letter-spacing="3">SAMPLE BRAND</text></svg>
SVG;
    $secondaryLogo=<<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="560" height="180" viewBox="0 0 560 180"><rect width="560" height="180" rx="22" fill="#183d43"/><circle cx="88" cy="90" r="53" fill="#fff"/><path d="M61 93l19 19 37-44" fill="none" stroke="#007c89" stroke-width="11" stroke-linecap="round" stroke-linejoin="round"/><text x="162" y="88" fill="#fff" font-family="Arial" font-size="43" font-weight="700">Test Account</text><text x="165" y="122" fill="#bce5e3" font-family="Arial" font-size="18" letter-spacing="3">SAMPLE BRAND</text></svg>
SVG;
    $logos=audienceBrandLogos(['logo'=>'data:image/svg+xml;base64,'.base64_encode($defaultLogo),
        'secondary_logo'=>'data:image/svg+xml;base64,'.base64_encode($secondaryLogo)]);
    $context=$s->contentSettings(true);$settings=$context['settings'];$brand=is_array($settings['brand']??null)?$settings['brand']:[];
    $settings['brand']=array_replace($brand,$logos);
    $s->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=?',[$j($settings),$s->audienceId]);
    return ['image_ids'=>$imageIds,'brand_logos'=>array_keys($logos)];
};
if ($mediaOnly) {
    echo $j(['account'=>'test-account','media'=>$s->transaction($sampleMedia)]),"\n";
    exit;
}
$sampleLocations = static function() use ($s,$insert,$j,$at): array {
    $place=static fn(string $ip,string $country='',string $region='',string $city=''):array=>
        array_filter(['ip_address'=>$ip,'country_code'=>$country,'region'=>$region,'city'=>$city],static fn($value)=>$value!=='')
        +['source'=>'fictional Test Account sample; documentation IP address','sample'=>true];
    $chicago=$place('192.0.2.10','US','Illinois','Chicago');
    $toronto=$place('198.51.100.24','CA','Ontario','Toronto');
    $london=$place('203.0.113.42','GB','England','London');
    $jerusalem=$place('198.51.100.88','IL','Jerusalem District','Jerusalem');
    $haifa=$place('203.0.113.55','IL','Haifa District','Haifa');
    $madison=$place('192.0.2.90','US','Wisconsin','Madison');
    $telAviv=$place('192.0.2.201','IL','Tel Aviv','Tel Aviv');
    $nazareth=$place('198.51.100.202','IL','Northern District','Nazareth');
    $paris=$place('203.0.113.203','FR','Île-de-France','Paris');
    $berlin=$place('192.0.2.204','DE','Berlin','Berlin');
    $rome=$place('198.51.100.205','IT','Lazio','Rome');
    $sydney=$place('203.0.113.206','AU','New South Wales','Sydney');
    $melbourne=$place('192.0.2.207','AU','Victoria','Melbourne');
    $tokyo=$place('198.51.100.208','JP','Tokyo','Tokyo');
    $osaka=$place('203.0.113.209','JP','Osaka','Osaka');
    $names=['Avery','Jordan','Morgan','Riley','Casey','Taylor','Jamie','Quinn','Parker','Reese','Rowan','Skyler','Alex','Cameron','Drew','Emerson','Finley','Hayden','Kendall','Logan','Marley','Noel','Payton','Sage'];
    $places=[$chicago,$toronto,$london,$jerusalem,$haifa,$madison];
    $signupPlaces=['Avery'=>$chicago,'Jordan'=>$london,'Morgan'=>$jerusalem,'Riley'=>$madison,'Kendall'=>$madison,'Payton'=>$haifa];
    $examples=[];
    foreach ($names as $i=>$name) {
        $location=$signupPlaces[$name]??$places[$i%count($places)];
        if (!isset($signupPlaces[$name])) $location['ip_address']=['192.0.2.','198.51.100.','203.0.113.'][$i%3].(100+$i);
        $examples[]=[$name,'signup-permission',-80+$i*3,$location];
    }
    array_push($examples,
        ['Avery','subscription-confirmed',-79,$chicago],['Avery','preferences',-4,$toronto],
        ['Jordan','subscription-confirmed',-76,$london],['Morgan','preferences',-7,$haifa],
        ['Kendall','subscription-confirmed',-25,$madison],['Kendall','status',-2,$toronto],
        ['Payton','subscription-confirmed',-13,$haifa],['Payton','status',-1,$chicago],
        ['Jordan','preferences',-6,$telAviv],['Riley','preferences',-5,$nazareth],
        ['Casey','preferences',-4,$paris],['Taylor','preferences',-3,$berlin],
        ['Jamie','preferences',-2,$rome],['Quinn','preferences',-1,$sydney],
        ['Parker','preferences',-6,$melbourne],['Reese','preferences',-5,$tokyo],
        ['Rowan','preferences',-4,$osaka]
    );
    $ids=[];
    foreach ($examples as [$name,$type,$day,$location]) {
        $person=$s->one('SELECT id,email_status FROM marketing_contacts WHERE audience_id=? AND email=? AND source=? AND deleted_at IS NULL',[$s->audienceId,'demo.'.strtolower($name).'@example.invalid','Test Account sample']);
        if (!$person) throw new RuntimeException('Fictional sample connection is missing: '.$name);
        if ($type==='status' && $person['email_status']!=='unsubscribed') throw new RuntimeException('Fictional unsubscribe status changed: '.$name);
        $key=strtolower($name).'-'.$type;
        $details=['sample_dataset'=>'test-account-interaction-location-v1','sample_key'=>$key,'sample'=>true,
            'source'=>'Fictional Test Account interaction','interaction_location'=>$location];
        if ($type==='status') $details+=['from'=>'subscribed','to'=>'unsubscribed'];
        if ($type==='preferences') $details['changed_fields']=['demo_interest'];
        $existing=$s->one("SELECT id FROM marketing_contact_events WHERE contact_id=? AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.sample_dataset'))='test-account-interaction-location-v1' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.sample_key'))=? LIMIT 1 FOR UPDATE",[$person['id'],$key]);
        if ($existing) {
            $s->write('UPDATE marketing_contact_events SET details_json=?,created_at=? WHERE id=?',[$j($details),$at($day),$existing['id']]);
            $ids[]=(int)$existing['id'];
        } else $ids[]=$insert('marketing_contact_events',['contact_id'=>$person['id'],'actor_user_id'=>null,'actor_label'=>'Fictional Test Account sample','actor_type'=>'recipient','event_type'=>$type,'details_json'=>$j($details),'created_at'=>$at($day)]);
    }
    return ['event_ids'=>$ids,'connections'=>count($names)];
};
if ($locationsOnly) {
    echo $j(['account'=>'test-account','interaction_locations'=>$s->transaction($sampleLocations)]),"\n";
    exit;
}
$result = $s->transaction(function() use ($s,$insert,$j,$at,$month,$anchor,$plan,$sampleMedia,$sampleLocations): array {
    $aid=['audience_id'=>1]; $actor=['actor_label'=>$s->actorLabel]; $people=[]; $tags=[];
    foreach (['Community news','Volunteers','Event guests','Follow up'] as $name) $tags[]=$insert('marketing_tags',$aid+['name'=>$name,'created_at'=>$at(-60)]);
    $group=$insert('marketing_group_categories',$aid+['name'=>'Demo interests','display_type'=>'checkboxes']); $groups=[];
    foreach (['Community gatherings','Learning resources','Volunteer opportunities'] as $i=>$name) $groups[]=$insert('marketing_group_options',['category_id'=>$group,'name'=>$name,'position'=>$i]);
    $fields=[];
    foreach (['CITY'=>['City','text'],'NEXT_EVENT'=>['Next gathering','date']] as $tag=>[$label,$type]) $fields[$tag]=$insert('marketing_fields',$aid+['label'=>$label,'merge_tag'=>$tag,'field_type'=>$type,'default_value'=>'','options_json'=>'[]']);
    $first=['Avery','Jordan','Morgan','Riley','Casey','Taylor','Jamie','Quinn','Parker','Reese','Rowan','Skyler','Alex','Cameron','Drew','Emerson','Finley','Hayden','Kendall','Logan','Marley','Noel','Payton','Sage'];
    foreach ($first as $i=>$name) {
        $status=$i<16?'subscribed':['non-subscribed','pending','unsubscribed','cleaned'][$i%4];
        $email='demo.'.strtolower($name).'@example.invalid'; $created=$at(-80+$i*3);
        $id=$insert('marketing_contacts',$aid+['email'=>$email,'first_name'=>$name,'last_name'=>'Sample','email_status'=>$status,'source'=>'Test Account sample','notes'=>'','is_archived'=>(int)($i>=22),'is_vip'=>(int)($i<3),'language'=>'en','timezone'=>'America/Chicago','custom_json'=>$j([(string)$fields['CITY']=>['Chicago','Madison','St. Louis'][$i%3],(string)$fields['NEXT_EVENT']=>substr($at(14),0,10)]),'created_at'=>$created,'updated_at'=>$at(-1)]);
        $people[]=['id'=>$id,'email'=>$email,'name'=>$name];
        $insert('marketing_contact_tags',['contact_id'=>$id,'tag_id'=>$tags[$i%4]]);
        $insert('marketing_contact_groups',['contact_id'=>$id,'option_id'=>$groups[$i%3]]);
        $insert('marketing_contact_events',['contact_id'=>$id,'actor_user_id'=>$s->actorId]+$actor+['actor_type'=>'system','event_type'=>'subscription_changed','details_json'=>$j(['from'=>'non-subscribed','to'=>$status,'sample'=>true,'note'=>'Simulated permission history; no real signup or email.']),'created_at'=>$created]);
        if ($i<5) $insert('marketing_notes',['contact_id'=>$id,'actor_user_id'=>$s->actorId]+$actor+['body'=>'Fictional connection for exploring notes, tags, interests and activity. No messages have been sent.','created_at'=>$at(-2),'updated_at'=>$at(-2)]);
    }
    foreach (['Subscribed community'=>['email_status','is','subscribed'],'Unsubscribed connections'=>['email_status','is','unsubscribed']] as $name=>[$field,$op,$value]) $s->saveSegment('Demo · '.$name,['match'=>'all','rules'=>[['field'=>$field,'operator'=>$op,'value'=>$value]]],0,0);
    $folder=$insert('marketing_campaign_folders',$aid+['name'=>'Demo community updates','created_at'=>$at(-60)]);
    $campaigns=[];$starters=audienceDesignStarters();
    foreach (['Community newsletter','Gathering invitation','Volunteer spotlight','Resource roundup','A note of thanks','Earlier community update','Next newsletter — edit me','Personal welcome — edit me','Paused invitation — demonstration'] as $i=>$name) {
        $sent=$i<6;$date=$at($sent?-3-$i*12:1+$i);$type=$i===7?'plain-text':'regular';
        $design=$starters[['newsletter','event','thanks'][$i%3]]['design'];
        $design['rows'][0]['columns'][0][0]['text']='Demo · '.$name;
        $draft=['sample_dataset'=>'test-account-message-monkey-v1','subject'=>'Demo · '.$name,'preview_text'=>'Fictional community update for testing MessageMonkey.','from_name'=>'Test Account Community','from_email'=>'community@example.invalid','target'=>'all','recipients_saved'=>true,'text'=>"Hello *|FNAME|*,\n\nThis is fictional sample content. Explore our gathering on ".substr($at(14),0,10)." and the resource library at https://example.org/demo. No email was sent.",'tracking'=>audienceCampaignDefaultTracking()];
        if ($type==='regular') $draft['design']=$design;
        $cid=$insert('marketing_campaigns',$aid+$actor+['folder_id'=>$folder,'name'=>'Demo · '.$name,'campaign_type'=>$type,'status'=>$sent?'sent':($i===8?'paused':'draft'),'draft_json'=>$j($draft),'created_at'=>$at(-85),'updated_at'=>$date,'sent_at'=>$sent?$date:null]);$campaigns[]=$cid;
        if (!$sent) {
            if ($type==='regular') {
                $insert('marketing_design_revisions',$aid+$actor+['owner_type'=>'campaign','owner_id'=>$cid,'version'=>1,'design_json'=>$j($design),'created_at'=>$at(-1)]);
                $insert('marketing_design_comments',$aid+$actor+['owner_type'=>'campaign','owner_id'=>$cid,'actor_user_id'=>$s->actorId,'body'=>'Demo review: personalize the introduction and confirm the gathering date.','created_at'=>$at(-1),'updated_at'=>$at(-1)]);
            }
            continue;
        }
        $did=$insert('marketing_campaign_deliveries',$aid+$actor+['campaign_id'=>$cid,'confirmed_version'=>1,'state'=>'completed','due_at'=>$date,'timezone'=>'America/Chicago','snapshot_ciphertext'=>Dnr\Security\ApplicationKey::seal($j(['draft'=>$draft,'type'=>$type,'base'=>'https://example.invalid','sample'=>true])),'asset_key'=>bin2hex(random_bytes(32)),'recipient_count'=>16,'actor_id'=>$s->actorId,'created_at'=>$date,'updated_at'=>$date,'completed_at'=>$date]);
        foreach (array_slice($people,0,16) as $n=>$person) {
            $rid=$insert('marketing_campaign_recipients',$aid+['delivery_id'=>$did,'contact_id'=>$person['id'],'email_hash'=>audienceDispatchEmailHash($person['email']),'context_ciphertext'=>Dnr\Security\ApplicationKey::seal($j(['email'=>$person['email'],'FNAME'=>$person['name']])),'token_hash'=>bin2hex(random_bytes(32)),'message_id'=>'<demo-'.$cid.'-'.$n.'@example.invalid>','state'=>'accepted','next_attempt_at'=>$date,'accepted_at'=>$date,'phase'=>'main']);
            $events=['send',$n===15?'hard-bounce':'delivered']; if($n<10-$i)$events[]='open';if($n<5-$i/2)$events[]='click';
            foreach($events as $event) $insert('marketing_message_events',$aid+['recipient_id'=>$rid,'event_type'=>$event,'event_key'=>bin2hex(random_bytes(32)),'source'=>in_array($event,['open','click'],true)?'tracking':'provider','event_at'=>$date,'details_json'=>$j(['sample'=>true,'bot'=>$n===0,'country'=>['US','GB','IL'][$n%3],'device'=>$n%2?'Mobile':'Desktop','client'=>'Firefox','link_hash'=>hash('sha256','https://example.org/demo'),'link_label'=>'example.org/demo'])]);
        }
    }
    foreach (['newsletter','welcome','event'] as $type) $insert('marketing_email_templates',$aid+$actor+['name'=>'Demo · '.ucfirst($type),'folder'=>'Demo templates','design_json'=>$j($starters[$type]['design']),'created_at'=>$at(-20),'updated_at'=>$at(-2)]);
    $planId=$insert('marketing_campaign_plans',$aid+$actor+['name'=>'Demo · Community outreach calendar','data_json'=>$j(['goal'=>audienceCampaignGoals()[0],'start'=>$month(-3),'end'=>$month(9),'notes'=>'Fictional planning examples. Email touchpoints are plans only; nothing is scheduled to send.','tracking'=>audienceCampaignDefaultTracking()]),'created_at'=>$at(-80),'updated_at'=>$at(-1)]);
    for($m=-3;$m<=9;$m++) $insert('marketing_campaign_touchpoints',$aid+$actor+['plan_id'=>$planId,'campaign_id'=>$m===0?$campaigns[6]:null,'touchpoint_type'=>$m===0?'email':($m%2?'task':'event'),'name'=>'Demo · '.($m===0?'Prepare next newsletter':'Community planning '.$month($m)),'start_at'=>$month($m).' 10:00:00','end_at'=>$month($m).' 11:00:00','state'=>$m<0?'complete':($m===0?'in-progress':'not-started'),'data_json'=>$j(['notes'=>'Planning example; no delivery is queued.']),'updated_at'=>$at(-1)]);
    $resources=[];
    foreach (AUDIENCE_COLLECTION_KINDS as $kind) {
        $name='Demo · '.ucfirst($kind).' '.($kind==='survey'?'feedback':'signup');$doc=audienceCollectionDefaults($kind,$name);$doc['intro']='Fictional Test Account example. Preview and edit this draft; no confirmation email will be sent.';
        $resource=$insert('marketing_collection_resources',$aid+['kind'=>$kind,'name'=>$name,'public_key'=>bin2hex(random_bytes(32)),'draft_json'=>$j($doc),'state'=>'draft','created_at'=>$at(-30),'updated_at'=>$at(-1)]);$resources[]=$resource;
        $revision=$insert('marketing_collection_revisions',$aid+['resource_id'=>$resource,'document_json'=>$j($doc),'created_at'=>$at(-30)]);
        foreach(array_slice($people,0,5) as $n=>$person) {
            $date=$at(-5-$n);$insert('marketing_collection_visits',['resource_id'=>$resource,'day'=>substr($date,0,10),'visitor_hash'=>hash('sha256','demo-'.$person['id']),'created_at'=>$date]);
            if($n<3)$insert('marketing_collection_responses',$aid+['resource_id'=>$resource,'revision_id'=>$revision,'receipt_hash'=>bin2hex(random_bytes(32)),'visitor_hash'=>hash('sha256','demo-'.$person['id']),'contact_id'=>$person['id'],'payload_ciphertext'=>Dnr\Security\ApplicationKey::seal($j(['email'=>$person['email'],'first_name'=>$person['name'],'answers'=>['q1'=>['Great','Good','Could be better'][$n]],'sample'=>true])),'created_at'=>$date]);
        }
    }
    foreach(['series','cleanup','split'] as $i=>$template) {
        $doc=audienceFlowDefault($template);if($template==='split')$doc['trigger']['id']=$tags[0];
        foreach($doc['nodes'] as &$node)if($node['type']==='email')$node['campaign_id']=$campaigns[6];unset($node);
        foreach($doc['nodes'] as &$node)if($node['type']==='condition')$node['rules']=['match'=>'all','rules'=>[['field'=>'tags','operator'=>'any','value'=>[$tags[0]]]]];unset($node);
        $doc=audienceFlowDocument($doc);$errors=$s->flowValidate($doc);if($errors)throw new RuntimeException(implode('; ',$errors));
        $fid=$insert('marketing_flows',$aid+['name'=>'Demo · '.audienceFlowTemplates()[$template]['name'],'state'=>$i===1?'paused':'draft','draft_json'=>$j($doc),'cursor_json'=>'{}','paused_nodes_json'=>'[]','created_at'=>$at(-25),'updated_at'=>$at(-1)]);
        $rev=$insert('marketing_flow_revisions',$aid+$actor+['flow_id'=>$fid,'document_json'=>$j($doc),'created_at'=>$at(-25)]);
        if($i===1) {
            $s->write('UPDATE marketing_flows SET published_revision=? WHERE id=?',[$rev,$fid]);
            foreach(array_slice($people,-2) as $n=>$person) {
                $run=$insert('marketing_flow_runs',$aid+['flow_id'=>$fid,'revision_id'=>$rev,'contact_id'=>$person['id'],'entry_key'=>'demo-'.$n,'node_key'=>$doc['start'],'state'=>'completed','due_at'=>$at(-4),'started_at'=>$at(-4),'updated_at'=>$at(-4),'ended_at'=>$at(-4)]);
                $insert('marketing_flow_steps',$aid+['run_id'=>$run,'node_key'=>$doc['start'],'state'=>'completed','result_json'=>$j(['sample'=>true,'note'=>'Simulated archive example; no email.']),'created_at'=>$at(-4),'completed_at'=>$at(-4)]);
            }
        }
    }
    $labels=[];foreach(['Needs follow-up','Event question','Feedback'] as $name)$labels[]=$insert('marketing_inbox_labels',$aid+['name'=>'Demo · '.$name]);
    foreach(['Gathering accessibility question','Thank you for the newsletter','Volunteer availability','Resource request','Survey feedback','Archived duplicate question'] as $i=>$subject) {
        $person=$people[$i];$date=$at(-1-$i);
        $thread=$insert('marketing_inbox_threads',$aid+['contact_id'=>$person['id'],'sender_email'=>$person['email'],'sender_name'=>$person['name'].' Sample','subject'=>'Demo · '.$subject,'source'=>['email','direct','contact','email','survey','direct'][$i],'source_ref'=>$i===4?$resources[4]:0,'status'=>$i===5?'trash':($i===1?'done':'todo'),'sender_verified'=>0,'last_activity_at'=>$date,'created_at'=>$date]);
        $body='Fictional message for testing: '.$subject.'. Could you share the next steps? No external email was received or sent.';
        $mid=$insert('marketing_inbox_messages',$aid+['thread_id'=>$thread,'direction'=>'inbound','source_key'=>bin2hex(random_bytes(32)),'references_json'=>'[]','sender_email'=>$person['email'],'sender_name'=>$person['name'].' Sample','body_text'=>$body,'body_html'=>'<p>'.$body.'</p>','occurred_at'=>$date,'created_at'=>$date]);
        $s->write('UPDATE marketing_inbox_threads SET last_message_id=?,last_inbound_id=? WHERE id=?',[$mid,$mid,$thread]);
        $insert('marketing_inbox_thread_labels',$aid+['thread_id'=>$thread,'label_id'=>$labels[$i%3]]);
        if($i===0) {
            $insert('marketing_inbox_messages',$aid+['thread_id'=>$thread,'direction'=>'comment','source_key'=>bin2hex(random_bytes(32)),'references_json'=>'[]','actor_id'=>$s->actorId,'sender_name'=>'TestEditor','body_text'=>'Demo internal note: check the venue accessibility information before replying.','body_html'=>'<p>Demo internal note: check the venue accessibility information before replying.</p>','occurred_at'=>$date,'created_at'=>$date]);
            $text="Fictional venue checklist\nStep-free access: available\nAccessible seating: confirm with coordinator\n";
            $insert('marketing_inbox_attachments',$aid+['message_id'=>$mid,'filename'=>'demo-venue-checklist.txt','mime_type'=>'text/plain','byte_size'=>strlen($text),'sha256'=>hash('sha256',$text),'content'=>$text,'created_at'=>$date]);
            $insert('marketing_inbox_drafts',$aid+['actor_id'=>$s->actorId,'contact_id'=>$person['id'],'thread_id'=>$thread,'subject'=>'Re: Demo · '.$subject,'from_name'=>'Test Account Community','body_html'=>'<p>Thank you for asking. We are checking the venue details and will update this sample reply.</p>','state'=>'draft','created_at'=>$date,'updated_at'=>$date]);
        }
        if($i===1) $insert('marketing_inbox_messages',$aid+['thread_id'=>$thread,'direction'=>'outbound','source_key'=>bin2hex(random_bytes(32)),'references_json'=>'[]','actor_id'=>$s->actorId,'sender_name'=>'TestEditor','body_text'=>'Simulated historical reply: thank you for your encouragement. This message was never sent.','body_html'=>'<p>Simulated historical reply: thank you for your encouragement. This message was never sent.</p>','occurred_at'=>$date,'created_at'=>$date]);
        $s->write('UPDATE marketing_inbox_threads SET last_message_id=(SELECT MAX(id) FROM marketing_inbox_messages WHERE thread_id=?) WHERE id=?',[$thread,$thread]);
    }
    foreach(['Welcome reply','Event information','Thank you for feedback'] as $name) $insert('marketing_inbox_templates',$aid+['name'=>'Demo · '.$name,'subject'=>$name,'body_html'=>'<p>Hello *|FNAME|*,</p><p>Thank you for connecting with our fictional community. Edit this sample response to try the inbox composer.</p>','updated_at'=>$at(-1)]);
    // Reports are business data embedded in audience settings. Validate against
    // the rehearsal anchor too, so future leap-day rehearsals remain supported.
    $reports=[];
    foreach(['email'=>'Email performance','audience'=>'Audience growth'] as $dataset=>$name) {
        $input=['dataset'=>$dataset,'start'=>substr($at(-90),0,10),'end'=>$anchor,'messages_mode'=>'all','columns'=>$dataset==='email'?['accepted','open_rate','click_rate','bounced']:['added','subscriptions','optouts'],'plots'=>$dataset==='email'?['accepted','click_rate']:['subscriptions']];
        $window=audienceAnalyticsWindow($input,new DateTimeImmutable($anchor.' 23:59:59',new DateTimeZone('UTC')));
        $reports[]=['key'=>substr(hash('sha256','test-account-demo-'.$dataset),0,24),'owner'=>$s->actorId,'version'=>1,'favorites'=>[],'shared'=>true,'name'=>'Demo · '.$name,'description'=>'Simulated Test Account evidence. No messages were sent.','created_at'=>$anchor.'T10:00:00Z','updated_at'=>$anchor.'T10:00:00Z','definition'=>audienceAnalyticsReportDefinition($input)+array_intersect_key($window,array_flip(['start','end','bots','compare','channel','messages','messages_mode','segment']))];
    }
    $s->write("UPDATE marketing_audiences SET settings_json=JSON_SET(settings_json,'$.analytics_reports',CAST(? AS JSON)) WHERE id=1",[$j($reports)]);
    $media=$sampleMedia();
    $locations=$sampleLocations();
    return $plan+['campaign_ids'=>$campaigns,'media'=>$media,'interaction_location_samples'=>$locations,'fictional'=>true,'ses_unchanged'=>true];
});
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
