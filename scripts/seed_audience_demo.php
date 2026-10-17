<?php
declare(strict_types=1);
// Explicit local-only, additive fixtures in the account's single audience.
if (PHP_SAPI !== 'cli' || getenv('DNR_SEED_AUDIENCE_DEMO') !== 'local-only') exit("Set DNR_SEED_AUDIENCE_DEMO=local-only in the localhost deployment.\n");
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: '/var/www/html') . '/bootstrap.php';
require_once (getenv('DNR_TEST_SOURCE_DIR') ?: '/var/www/html') . '/audience_store.php';
$db = applicationDatabaseConnection();
if (getenv('DNR_PUBLIC_BASE_URL') && !in_array(parse_url(getenv('DNR_PUBLIC_BASE_URL'), PHP_URL_HOST), ['localhost','127.0.0.1'], true)) throw new RuntimeException('Demo seeding is restricted to localhost.');
$existing = $db->query("SELECT id FROM marketing_audiences WHERE id=1 AND JSON_UNQUOTE(JSON_EXTRACT(settings_json,'$.fixture'))='audience-stage1b-v1'")->fetch_assoc();
if ($existing) { echo json_encode(['audience_id'=>(int)$existing['id'],'status'=>'already seeded'])."\n"; exit; }
$actor = (int)($db->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetch_assoc()['id'] ?? 0);
if (!$actor) throw new RuntimeException('No local administrator exists.');
$audience = 1;
$store = new AudienceStore($db, $audience, $actor, 'Demo data');
$store->transaction(function() use ($store, $audience) {
    $current=$store->one('SELECT settings_json FROM marketing_audiences WHERE id=1 FOR UPDATE');
    $settings=audienceJson($current['settings_json']);
    if(($settings['fixture']??'')==='audience-stage1b-v1')return;
    $tags=[]; foreach(['Newsletter','Volunteers','Event attendees','Partners','Spring outreach','Fall conference','First-time visitors','Follow up','Community, local','Needs translation'] as $name) $tags[]=$store->saveTag($name,0,0);
    foreach([
        ['name'=>'Interests','display_type'=>'checkboxes','options'=>[['name'=>'News and updates'],['name'=>'Events'],['name'=>'Volunteer opportunities']]],
        ['name'=>'Email frequency','display_type'=>'radio','options'=>[['name'=>'Weekly'],['name'=>'Monthly'],['name'=>'Major announcements']]],
        ['name'=>'Region','display_type'=>'dropdown','options'=>[['name'=>'Midwest'],['name'=>'Northeast'],['name'=>'West']]],
        ['name'=>'Internal review','display_type'=>'hidden','options'=>[['name'=>'Reviewed'],['name'=>'Needs follow-up']]],
    ] as $g) $store->saveGroup($g,0,0);
    $groups=[];foreach($store->groups()as$g)$groups[$g['name']]=array_map('intval',array_column($g['options'],'id'));
    $definitions=[
        ['label'=>'City','merge_tag'=>'CITY','field_type'=>'text'],
        ['label'=>'Interest score','merge_tag'=>'SCORE','field_type'=>'number'],
        ['label'=>'Last attended','merge_tag'=>'ATTENDED','field_type'=>'date'],
        ['label'=>'Birthday','merge_tag'=>'BIRTHDAY','field_type'=>'birthday'],
        ['label'=>'Preferred language','merge_tag'=>'PREFLANG','field_type'=>'dropdown','options_text'=>"English\nSpanish\nFrench"],
        ['label'=>'Topics','merge_tag'=>'TOPICS','field_type'=>'checkboxes','options_text'=>"Community\nLearning\nService"],
        ['label'=>'Phone','merge_tag'=>'PHONE','field_type'=>'phone'],
        ['label'=>'Mailing address','merge_tag'=>'ADDRESS','field_type'=>'address'],
    ];
    $fields=[];foreach($definitions as$i=>$f)$fields[]=$store->saveField($f+['is_public'=>'1','position'=>$i],0,0);
    $first=['Avery','Jordan','Morgan','Taylor','Casey','Riley','Cameron','Quinn','Alex','Drew','Skyler','Harper','Jamie','Emerson','Sage','Parker','Rowan','Reese','Finley','Hayden','Sam','Noor','Amari','Robin'];
    $last=['Rivera','Chen','Patel','Bennett','Morgan','Nguyen','Alvarez','Brooks','Reed','Singh','Williams','Kim'];
    $cities=['Chicago','Madison','Milwaukee','Minneapolis','Indianapolis','Detroit','Cleveland','St. Louis'];
    $people=[];
    for($i=0;$i<84;$i++) {
        $status=$i%12<8?'subscribed':(['non-subscribed','unsubscribed','pending','cleaned'][$i%12-8]);
        $input=['email'=>sprintf('person%03d@audience.example.test',$i+1),'first_name'=>$first[$i%count($first)],'last_name'=>$last[intdiv($i,3)%count($last)],'email_status'=>$status,'consent_note'=>'Fictional local fixture: simulated signup permission; never send to these addresses.','tag_ids'=>array_values(array_unique([$tags[$i%count($tags)],$tags[0],$tags[($i+3)%count($tags)]])),'group_ids'=>[$groups['Interests'][$i%3],$groups['Email frequency'][$i%3],$groups['Region'][$i%3],$groups['Internal review'][$i%2]],'language'=>$i%5===0?'es':'en','timezone'=>'America/Chicago','custom'=>[
            $fields[0]=>$cities[$i%8],$fields[1]=>(string)(($i*13)%101),$fields[2]=>gmdate('Y-m-d',time()-($i%40)*86400),$fields[3]=>sprintf('%02d-%02d',($i%12)+1,($i%27)+1),$fields[4]=>$i%5===0?'Spanish':'English',$fields[5]=>[$i%2?'Learning':'Community','Service'],$fields[6]=>sprintf('+1 202-555-%04d',100+$i),$fields[7]=>($i+100).' Example Lane, Example City, IL 60000',]];
        $id=$store->savePerson($input,0,0,['Demo signup form','Demo event registration','Demo import'][$i%3]);$people[]=$id;
        $store->write('UPDATE marketing_contacts SET created_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? DAY),updated_at=DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? DAY) WHERE id=?',[$i*2,$i%12,$id]);
        if($i%10===0)$store->saveNote($id,'Fictional example: prefers community event updates. Use this profile to test notes, tags, groups, and filters.',0,0);
        if($i>=78){$p=$store->person($id);$store->mutatePerson($id,(int)$p['version'],'archive');}
    }
    foreach([
        'Subscribed newsletter'=>['match'=>'all','rules'=>[['field'=>'email_status','operator'=>'is','value'=>'subscribed'],['field'=>'tags','operator'=>'any','value'=>[$tags[0]]]]],
        'Midwest community — advanced'=>['match'=>'all','rules'=>[['field'=>'email_status','operator'=>'is','value'=>'subscribed'],['match'=>'any','rules'=>[['field'=>'custom:'.$fields[0],'operator'=>'is','value'=>'Chicago'],['field'=>'custom:'.$fields[1],'operator'=>'gte','value'=>'70']]],['field'=>'groups','operator'=>'any','value'=>[$groups['Region'][0]]]]],
        'Recently added'=>['match'=>'all','rules'=>[['field'=>'created_at','operator'=>'last_days','value'=>'30']]],
        'Not subscribed'=>['match'=>'any','rules'=>[['field'=>'email_status','operator'=>'is','value'=>'unsubscribed'],['field'=>'email_status','operator'=>'is','value'=>'non-subscribed']]],
    ]as$name=>$rules)$store->saveSegment($name,$rules,0,0);
    $job=$store->createImport(audienceParseImport("Email,First Name,Last Name\nimported001@audience.example.test,Alex,Harbor\nimported002@audience.example.test,Noor,Meadow\nimported003@audience.example.test,Sam,Brook\nimported003@audience.example.test,Sam,Duplicate\ninvalid-address,Taylor,Needs correction"),'Demo import — results and error example');
    $store->configureImport($job,['mapping'=>['email','first_name','last_name'],'import_status'=>'non-subscribed','tag_ids'=>[$tags[6]]],true);$store->processJob($job);
    $draft=$store->createImport(audienceParseImport("Email,First Name,City\ndraft001@audience.example.test,Quinn,Chicago\ndraft002@audience.example.test,Reese,Madison"),'Demo import — continue the wizard');
    $store->configureImport($draft,['mapping'=>['email','first_name','custom:'.$fields[0]],'import_status'=>'non-subscribed'],false);
    $export=$store->queueSelection('export','Demo export — all active people',$store->where(audienceListFilters([])));while($store->processJob($export)['state']!=='complete'){}
    $settings['fixture']='audience-stage1b-v1';
    $store->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=1',[json_encode($settings,JSON_THROW_ON_ERROR)]);
});
echo json_encode(['audience_id'=>$audience,'status'=>'seeded','people'=>(int)$store->one('SELECT COUNT(*) n FROM marketing_contacts WHERE audience_id=?',[$audience])['n']])."\n";
