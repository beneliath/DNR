<?php
/** Original fictional demo data. Refuses any deployment except Test Account. */
declare(strict_types=1);

function demoMonth(DateTimeImmutable $date, int $months): DateTimeImmutable {
    $first = $date->modify('first day of this month')->modify(sprintf('%+d months', $months));
    return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), min((int)$date->format('d'), (int)$first->format('t')));
}
function demoSchedule(DateTimeImmutable $today): array {
    $end = demoMonth($today, 9); $dates = [];
    for ($m=-3; $m<=9; $m++) {
        $d=demoMonth($today,$m); $dates[]=$d;
        if ($d->modify('+7 days')<=$end) $dates[]=$d->modify('+7 days');
    }
    foreach ([-1,1,3,14,21] as $days) $dates[]=$today->modify(sprintf('%+d days',$days));
    usort($dates, static fn($a,$b)=>$a<=>$b);
    return $dates;
}
if (PHP_SAPI!=='cli') throw new RuntimeException('CLI only.');
$options=getopt('', ['apply','as-of:','assets:','user:','app-root:']);
$asOf=$options['as-of'] ?? (new DateTimeImmutable('now',new DateTimeZone('America/Chicago')))->format('Y-m-d');
$today=DateTimeImmutable::createFromFormat('!Y-m-d',$asOf,new DateTimeZone('America/Chicago'));
if (!$today || $today->format('Y-m-d')!==$asOf) throw new InvalidArgumentException('Use a valid --as-of YYYY-MM-DD.');
$dates=demoSchedule($today); $start=demoMonth($today,-3); $end=demoMonth($today,9);
if (!isset($options['apply'])) {
    echo json_encode(['fixture_version'=>1,'account'=>'test-account','as_of'=>$asOf,
        'window'=>[$start->format('Y-m-d'),$end->format('Y-m-d')],
        'event_dates'=>array_map(static fn($d)=>$d->format('Y-m-d'),$dates)],JSON_PRETTY_PRINT),"\n"; exit;
}
if (getenv('DNR_ACCOUNT_KEY')!=='test-account' || getenv('DNR_ACCOUNT_MODE')!=='member') {
    throw new RuntimeException('Refusing to seed any account other than the Test Account member.');
}
$root=$options['app-root'] ?? '/var/www/html';
require $root.'/config.php'; require_once $root.'/functions.php';
foreach (['presentation_helpers','presentation_slidedeck_helpers','chron_log_helpers','follow_up_task_helpers','engagement_contact_helpers',
    'booking_inquiry_helpers','workflow_task_helpers','map_helpers','reimbursement_submission_helpers','data_consistency_helpers'] as $helper) require_once $root.'/'.$helper.'.php';
$profile=$conn->query('SELECT account_key,name FROM account_profile WHERE id=1')->fetch_assoc();
if (($profile['account_key'] ?? '')!=='test-account' || ($profile['name'] ?? '')!=='Test Account') throw new RuntimeException('Test Account identity mismatch.');
$user=$conn->execute_query('SELECT * FROM users WHERE username=?',[$options['user'] ?? 'TestAdmin'])->fetch_assoc();
if (!$user || $user['role']!=='admin' || $user['account_status']!=='active' || $user['is_superadmin'] || $user['platform_identity_id']) throw new RuntimeException('Choose an active ordinary Test Account Admin.');
$uid=(int)$user['id']; $username=$user['username'];
$assets=rtrim($options['assets'] ?? '/tmp/test-account-assets','/');
foreach (['speaker-notes.pdf','host-guide.pdf','community-teaching.pptx','avatar-0.png','avatar-1.png','avatar-2.png','avatar-3.png','avatar-4.png','avatar-5.png'] as $file) if (!is_file($assets.'/'.$file)) throw new RuntimeException('Missing demo asset: '.$file);
function demoInsert(string $table,array $values): int {
    global $conn;
    $conn->execute_query('INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($values)).'`) VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_map(static fn($v)=>is_bool($v)?(int)$v:$v,array_values($values)));
    return (int)$conn->insert_id;
}
function demoDate(int $days): string { global $today; return $today->modify(sprintf('%+d days',$days))->format('Y-m-d'); }
function demoChron(string $type,int $id,string $text,int $days=-2): int {
    global $conn,$uid,$username;
    insertEntityChronLogEntry($conn,$type,$id,$text,$uid,$username); $id=(int)$conn->insert_id;
    $table=chronLogEntityConfiguration($type)['table'];
    $conn->execute_query('UPDATE '.$table.' SET created_at=?,updated_at=? WHERE id=?',[demoDate($days).' 10:00:00',demoDate($days).' 10:00:00',$id]);
    return $id;
}
function demoPortrait(int $i): array {
    global $assets;
    $bytes=file_get_contents($assets.'/avatar-'.($i%6).'.png');
    return ['data'=>$bytes,'thumbnail_data'=>$bytes,'mime_type'=>'image/png','thumbnail_mime_type'=>'image/png','sha256'=>hash('sha256',$bytes,true)];
}
setDatabaseAuditContext($conn,$uid,$username);
if (!$conn->query("SELECT GET_LOCK('test-account-demo-seed',0)")->fetch_row()[0]) throw new RuntimeException('Another seed is running.');
$conn->begin_transaction();
try {
    foreach (['organizations','contacts','speakers','engagements','booking_inquiries','follow_up_tasks','reimbursement_expenses'] as $table) if ((int)$conn->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0]) throw new RuntimeException('Demo database is not empty. Back up and use the guarded refresh operator first.');
    $hosts=[
        ['Olive Branch Bible Fellowship','Plano','TX','75074',33.0198,-96.6989],
        ['Cypress Coast Chapel','Sarasota','FL','34236',27.3364,-82.5307],
        ['Cedar Ridge Community Church','Franklin','TN','37064',35.9251,-86.8689],
        ['New Harbor Messianic Fellowship','Savannah','GA','31401',32.0809,-81.0912],
        ['Mountain Lantern Ministries','Colorado Springs','CO','80903',38.8339,-104.8214],
        ['Desert Springs Bible Chapel','Mesa','AZ','85201',33.4152,-111.8315],
        ['Pacific Grove Teaching Center','Ventura','CA','93001',34.2746,-119.2290],
        ['Riverstone Fellowship','Greenville','SC','29601',34.8526,-82.3940],
        ['Prairie Light Community Church','Tulsa','OK','74103',36.1540,-95.9928],
        ['Lakeside Scripture Institute','Madison','WI','53703',43.0731,-89.4012],
        ['Harbor Bridge Outreach','Norfolk','VA','23510',36.8508,-76.2859],
        ['Hill Country Learning Fellowship','Georgetown','TX','78626',30.6333,-97.6779],
        ['Juniper Hills Bible Church','Bend','OR','97701',44.0582,-121.3153],
        ['Maple Crossing Fellowship','Grand Rapids','MI','49503',42.9634,-85.6681],
        ['Oak Valley Study Network','Lexington','KY','40507',38.0406,-84.5037],
        ['Beacon Way Community Chapel','Asheville','NC','28801',35.5951,-82.5515],
    ];
    $orgs=[]; $contacts=[];
    $names=[['Avery','Lawson'],['Dana','Pierce'],['Morgan','Hayes'],['Jamie','Ellis'],['Riley','Bennett'],['Casey','Reed'],['Taylor','Brooks'],['Robin','Hart']];
    foreach ($hosts as $i=>$host) {
        [$name,$city,$state,$zip]=$host;
        $oid=demoInsert('organizations',['organization_name'=>$name,'affiliation'=>$i%3===0?'Independent Bible fellowship':'Community teaching network',
            'distinctives'=>'Scripture study, respectful conversation, and community service','website_url'=>'https://example.org',
            'email'=>'host'.($i+1).'@example.invalid','phone'=>'+1214555'.sprintf('%04d',100+$i),
            'physical_city'=>$city,'physical_state'=>$state,'physical_zipcode'=>$zip,'physical_country'=>'United States of America',
            'mailing_address_line_1'=>'PO Box '.(200+$i),'mailing_city'=>$city,'mailing_state'=>$state,'mailing_zipcode'=>$zip,'mailing_country'=>'United States of America',
            'notes'=>'Fictional demo host. Map pins mark city centers rather than real venues. Host prefers a planning call and a clear resource-table arrangement.',
            'created_at'=>demoDate(-88).' 09:00:00']);
        $orgs[]=$oid; $contacts[$i]=[];
        for($j=0;$j<2;$j++) {
            [$first,$last]=$names[($i+$j)%count($names)];
            $cid=demoInsert('contacts',['organization_id'=>$oid,'contact_first_name'=>$first,'contact_last_name'=>$last,
                'contact_role'=>$j?'admin':'pastor','contact_email'=>strtolower($first).'.'.$i.'.'.$j.'@example.invalid',
                'contact_phone'=>'+1214555'.sprintf('%04d',120+$i*2+$j),'contact_birthday'=>$today->modify(sprintf('%+d days',$i-4+$j))->format('m-d'),
                'contact_notes'=>$j?'Fictional coordinator for venue, travel, and billing.':'Fictional primary host; prefers a short phone call before booking.']);
            $contacts[$i][]=$cid;
            if($i<3) {
                $p=storePersistentPortrait($conn,demoPortrait($i+$j),'demo-contact-'.$cid);
                $conn->execute_query('UPDATE contacts SET contact_photo_key=?,contact_photo_thumbnail_key=?,contact_photo_mime=?,contact_photo_thumbnail_mime=?,contact_photo_sha256=?,contact_photo_updated_at=UTC_TIMESTAMP() WHERE id=?',[$p['storage_key'],$p['thumbnail_key'],'image/png','image/png',$p['sha256'],$cid]);
            }
            demoChron('contact',$cid,'Introduced the planning team. Please confirm arrival details, dietary needs, and room setup. Fictional training conversation.',-35+$i);
        }
        demoChron('organization',$oid,'Host is interested in a teaching weekend and a discussion session. Budget includes local transport; confirm lodging separately.',-55+$i);
    }
    demoInsert('contact_organizations',['contact_id'=>$contacts[0][1],'organization_id'=>$orgs[1],'role_title'=>'Regional event coordinator']);
    demoInsert('contacts',['organization_id'=>null,'contact_first_name'=>'Cameron','contact_last_name'=>'Fields','contact_role'=>'other','contact_role_other'=>'Independent event planner','contact_email'=>'cameron.fields@example.invalid','contact_phone'=>'+12145550190','contact_notes'=>'Unaffiliated demo contact. Add an organization affiliation while exploring.']);
    $archivedOrg=demoInsert('organizations',['organization_name'=>'Willow Brook Fellowship - Retired Demo Host','is_deleted'=>1,'notes'=>'Archived example: restore this organization to practice recovery.']);
    demoInsert('contacts',['organization_id'=>$archivedOrg,'contact_first_name'=>'Lee','contact_last_name'=>'Walsh','contact_role'=>'admin','contact_email'=>'lee.walsh@example.invalid','is_deleted'=>1]);
    foreach(['Northstar Community Fellowship','Northstar Community Fellowship'] as $i=>$name) demoInsert('organizations',['organization_name'=>$name,'physical_city'=>$i?'Raleigh':'Durham','physical_state'=>'NC','notes'=>'Separate fictional branches with the same name. Use this pair to explore duplicate review or merging.']);
    demoInsert('contacts',['organization_id'=>$orgs[0],'contact_first_name'=>'Avery','contact_last_name'=>'Lawson','contact_role'=>'pastor','contact_email'=>'avery.duplicate@example.invalid','contact_notes'=>'Intentional fictional duplicate for merge practice; no event assignments.']);
    $speakers=[];
    foreach(['Elias Linden','Naomi Brooks','Micah Calder','Joanna Rivers'] as $i=>$name) $speakers[]=saveSpeaker($conn,[
        'name'=>$name,'email'=>'speaker'.($i+1).'@example.invalid','phone'=>'+1214555'.sprintf('%04d',160+$i),
        'bio'=>$name.' is a fictional teacher who explores biblical history, community dialogue, and practical discipleship. This profile and its avatar are demo content.',
        'website_url'=>'https://example.org','bio_url'=>'https://example.org/#biography','donation_url'=>'https://example.org/#support',
        'connection_url'=>'https://example.org/#connect','blog_url'=>'https://example.org/#articles','books_url'=>'https://example.org/#books',
        'custom_links'=>[['label'=>'Study resources','url'=>'https://example.org/#study'],['label'=>'Host information','url'=>'https://example.org/#host']]],null,null,demoPortrait($i));
    $titles=['Hope and History Weekend','Scripture in Context Workshop','Community Questions Forum','Living with Purpose Conference','Biblical Roots Study Day','Faith and Friendship Gathering','Passover Learning Evening','Listening Across Generations'];
    $topics=['Reading the Prophets in Context','A Journey through the Biblical Feasts','Hope and Responsibility in Community','History, Memory, and Respectful Dialogue','Questions from the Audience','Hospitality and Practical Discipleship','The Story of Redemption','Teaching across Generations'];
    $events=[]; $presentations=[];
    foreach($dates as $i=>$date) {
        $h=$i%count($hosts);$duration=$i%3===0?2:0; $finish=min($date->modify('+'.$duration.' days'),$end); $past=$finish<$today;
        $state=($i===10?'postponed':($i===13?'canceled':($past && $i!==5?'completed':'active')));
        $id=demoInsert('engagements',['organization_id'=>$orgs[$h],'event_title'=>$titles[$i%8].' - '.$hosts[$h][1],
            'event_description'=>'Fictional '.($duration?'multi-day':'one-day').' teaching event with '.($i%2?'a host discussion':'a resource table and audience questions').'. Edit dates, contacts, presentations, travel, and financials to explore the app.',
            'event_start_date'=>$date->format('Y-m-d'),'event_end_date'=>$finish->format('Y-m-d'),
            'event_type'=>['conference','study or teaching','service','Passover Seder','other'][$i%5],'event_type_other'=>$i%5===4?'Community panel':null,
            'confirmation_status'=>$past?'confirmed':['work_in_progress','under_review','confirmed'][$i%3],
            'lifecycle_status'=>'active','caller_user_id'=>$uid,'book_table'=>$i%2,'brochures'=>$i%3!==1,
            'travel_covered'=>['yes','no','unknown'][$i%3],'travel_amount'=>$i%3===2?null:(string)(120+$i*17),
            'compensation_type'=>['Honorarium','Offering','Honorarium and Offering','Other','Unknown'][$i%5],
            'other_compensation'=>$i%5===3?'Host supplies meals and resources':null,'housing_type'=>['Provided','Not Provided','Other','Unknown'][$i%4],
            'other_housing'=>$i%4===2?'Host family guest room':null,'housing_amount'=>$i%4===3?null:(string)(150+$i*8),
            'event_city'=>$hosts[$h][1],'event_state'=>$hosts[$h][2],'event_zipcode'=>$hosts[$h][3],'event_country'=>'United States of America']);
        $events[]=$id;
        syncEngagementContacts($conn,$id,[['contact_id'=>$contacts[$h][0],'contact_role'=>'primary_host'],['contact_id'=>$contacts[$h][1],'contact_role'=>'on_site_contact'],['contact_id'=>$contacts[$h][1],'contact_role'=>'billing'],['contact_id'=>$contacts[$h][1],'contact_role'=>'travel'],['contact_id'=>$contacts[$h][1],'contact_role'=>'materials']],$uid,false);
        $rows=[];
        for($j=0;$j<($duration?3:1);$j++) $rows[]=['speaker_id'=>$speakers[($i+$j)%4],'topic_title'=>$topics[($i+$j)%8],
            'presentation_date'=>($j===2?$finish:$date)->format('Y-m-d'),'presentation_time'=>['09:30','11:00','14:00'][$j],
            'duration_minutes'=>$i%7===0?null:($j===1?45:60),'expected_attendance'=>$i%6===0?null:60+$i*9,
            'actual_attendance'=>$past?45+$i*8+$j*5:null];
        $rows=normalizeEngagementPresentations($rows,$date->format('Y-m-d'),$finish->format('Y-m-d'),$speakers[$i%4]);
        if($i%3===0 || ($date>=$today && $date<=$today->modify('+21 days'))) {
            $bytes=file_get_contents($assets.'/'.($i%2?'host-guide':'speaker-notes').'.pdf');
            $rows[0]['asset_changes']['speaker_notes']=['action'=>'replace','asset'=>['data'=>$bytes,'filename'=>'demo-speaker-notes-'.$i.'.pdf','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes,true)]];
            if($i%2===0) $rows[0]['asset_changes']['ppt_slidedeck']=['action'=>'replace','asset'=>presentationSlidedeckFromPath($assets.'/community-teaching.pptx','community-teaching-'.$i.'.pptx',false)];
        }
        syncEngagementPresentations($conn,$id,$rows,$uid);
        $ids=$conn->execute_query('SELECT id FROM presentations WHERE engagement_id=? ORDER BY id',[$id])->fetch_all(MYSQLI_ASSOC);$presentations[]=(int)$ids[0]['id'];
        generateEngagementFollowUpChecklist($conn,$id,$uid,$uid,false);
        if($past && $i!==5) $conn->execute_query("UPDATE follow_up_tasks SET status='completed',completed_by=?,completed_at=? WHERE engagement_id=?",[$uid,$finish->modify('+1 day')->format('Y-m-d').' 15:00:00',$id]);
        if($state==='completed') {
            if($i<4) {
                demoInsert('engagement_financial_reports',['engagement_id'=>$id,'giving_income_received'=>(string)(600+$i*225),'travel_received'=>(string)(120+$i*17),'lodging_received'=>'0.00','book_table_received'=>$i%2?'185.50':null,'notes'=>'Fictional final closeout: receipts reconciled with host.','closed_by'=>$uid,'updated_by'=>$uid,'closed_at'=>$finish->modify('+1 day')->format('Y-m-d').' 15:00:00']);
                if($i===1){$conn->query("SET @dnr_financial_correction_reason='Demo correction: reconcile the final resource-table count'");$conn->execute_query('UPDATE engagement_financial_reports SET book_table_received=210.50,updated_by=? WHERE engagement_id=?',[$uid,$id]);}
            } else demoInsert('engagement_financial_drafts',['engagement_id'=>$id,'giving_income_received'=>'450.00','travel_received'=>null,'book_table_received'=>'0.00','notes'=>'Draft example: confirm travel reimbursement with the host before closeout.','updated_by'=>$uid]);
        }
        if(in_array($state,['postponed','canceled'],true)) $conn->execute_query("UPDATE follow_up_tasks SET status='canceled' WHERE engagement_id=? AND status<>'completed'",[$id]);
        $conn->execute_query('UPDATE engagements SET lifecycle_status=?,cancellation_reason=?,lifecycle_changed_by=? WHERE id=?',[$state,$state==='canceled'?'Fictional host withdrew because of venue renovations':null,$uid,$id]);
        $eng=$conn->execute_query('SELECT * FROM engagements WHERE id=?',[$id])->fetch_assoc();$address=engagementMapAddress($eng);$hash=engagementMapAddressHash($address);
        demoInsert('engagement_map_pins',['engagement_id'=>$id,'address_hash'=>$hash,'latitude'=>$hosts[$h][4],'longitude'=>$hosts[$h][5],'confirmed_by'=>$uid]);
        demoChron('engagement',$id,'Fictional host planning call: confirm the room, resource table, dietary needs, and audience questions. Map pin is an approximate city center.',-min(80,10+$i));
        demoChron('engagement',$id,$past?'Demo event debrief: audience engaged well; thank the host and reconcile receipts.':'Demo planning note: review the schedule and send a final event brief to the host.',-1);
    }
    $conn->execute_query('UPDATE engagements SET rescheduled_to_engagement_id=? WHERE id=?',[$events[14],$events[10]]);
    $pid=demoInsert('presentations',['engagement_id'=>$events[0],'speaker_id'=>$speakers[0],'topic_title'=>'Original session outline - archived example','presentation_date'=>$dates[0]->format('Y-m-d'),'is_archived'=>1,'archived_by'=>$uid,'archived_at'=>demoDate(-30).' 10:00:00']);
    $conn->execute_query('UPDATE engagements SET is_deleted=1 WHERE id=?',[$events[2]]);
    $conn->execute_query('DELETE FROM engagement_contacts WHERE engagement_id=? AND contact_role=?',[$events[0],'travel']);
    foreach(['open','in_progress','waiting','completed','canceled'] as $i=>$status) {
        demoInsert('follow_up_tasks',['title'=>['Call host about room setup','Review upcoming teaching resources','Waiting for travel confirmation','Send host thank-you - completed','Replace canceled planning call'][$i],
            'details'=>'Fictional task. Edit, reassign, complete, or archive it while testing.','status'=>$status,'priority'=>['urgent','high','normal','low','normal'][$i],
            'due_date'=>demoDate($i-2),'waiting_on'=>$status==='waiting'?'Host travel coordinator':null,'subject_type'=>['contact','organization','general','general','general'][$i],
            'contact_id'=>$i===0?$contacts[0][0]:null,'organization_id'=>$i===1?$orgs[0]:null,'assigned_to'=>$uid,'created_by'=>$uid,'completed_by'=>$status==='completed'?$uid:null,'completed_at'=>$status==='completed'?demoDate(-1).' 11:00:00':null]);
    }
    demoInsert('follow_up_tasks',['title'=>'Choose an owner for the regional resource inventory','status'=>'open','priority'=>'normal','subject_type'=>'general','due_date'=>demoDate(5),'created_by'=>$uid]);
    demoInsert('follow_up_tasks',['title'=>'Old planning note - archived task','status'=>'completed','completed_by'=>$uid,'completed_at'=>demoDate(-10).' 09:00:00','priority'=>'low','subject_type'=>'general','assigned_to'=>$uid,'created_by'=>$uid,'is_archived'=>1,'archived_by'=>$uid,'archived_at'=>demoDate(-10).' 09:00:00']);
    $stages=['new','contacted','qualified','awaiting_details','proposal_sent','booked','declined'];
    foreach(range(0,20) as $i) {
        $h=$i%16;$stage=$stages[$i%7];$preferred=demoMonth($today,1+$i%8)->modify('+'.($i%5).' days');
        $next=in_array($stage,['booked','declined'],true)?null:['Call the host','Confirm audience and format','Review venue details','Send proposed dates','Follow up on the proposal'][$i%5];
        $qid=demoInsert('booking_inquiries',['title'=>['Regional Teaching Weekend','Host Training Retreat','Evening Questions Forum','Biblical History Workshop'][$i%4].' - '.$hosts[$h][1],
            'organization_id'=>$orgs[$h],'primary_contact_id'=>$contacts[$h][0],'request_summary'=>'Fictional booking request for '.(80+$i*10).' attendees. Confirm schedule, host responsibilities, and speaker availability.',
            'preferred_start_date'=>$preferred->format('Y-m-d'),'preferred_end_date'=>$preferred->format('Y-m-d'),'alternate_start_date'=>$preferred->modify('+7 days')->format('Y-m-d'),'alternate_end_date'=>$preferred->modify('+7 days')->format('Y-m-d'),
            'event_city'=>$hosts[$h][1],'event_state'=>$hosts[$h][2],'event_country'=>'United States of America','source'=>['email','phone','website','referral','mattermost','other'][$i%6],
            'source_detail'=>'Fictional demo intake','owner_user_id'=>$uid,'priority'=>['normal','high','urgent','low'][$i%4],'stage'=>$stage,
            'next_action'=>$next,'next_action_due_date'=>$next?demoDate($i-4):null,'created_by'=>$uid,'stage_changed_by'=>$uid,
            'decline_reason'=>$stage==='declined'?'Fictional host chose a different season':null,
            'converted_engagement_id'=>$stage==='booked'?$events[15+$i%6]:null,'converted_by'=>$stage==='booked'?$uid:null,'converted_at'=>$stage==='booked'?demoDate(-5).' 10:00:00':null]);
        insertBookingInquiryStageHistory($conn,$qid,null,'new','Fictional inquiry received.',$uid,$username);
        if($stage!=='new') insertBookingInquiryStageHistory($conn,$qid,'new',$stage,'Demo progression: reviewed the request with the host.',$uid,$username);
        if($next) saveInquiryNextActionTask($conn,$qid,['next_action'=>$next,'next_action_due_date'=>demoDate($i-4),'owner_user_id'=>$uid,'priority'=>['normal','high','urgent','low'][$i%4]],null,$uid);
        if($i===20) $conn->execute_query('UPDATE booking_inquiries SET archived_at=?,archived_by=? WHERE id=?',[demoDate(-2).' 10:00:00',$uid,$qid]);
        demoChron('inquiry',$qid,'Fictional intake conversation: audience and teaching format are a good fit. Check proposed dates and confirm the planning contact.',-12);
    }
    foreach(range(0,5) as $i) {
        $mid=demoInsert('engagement_email_messages',['engagement_id'=>$events[9+$i],'organization_id'=>$orgs[(9+$i)%16],
            'template_key'=>'custom','template_label'=>'Demo correspondence (simulated)','subject'=>'[DEMO] Host planning and arrival details',
            'body_text'=>"Fictional correspondence - no email was sent.\n\nPlease confirm the arrival time, resource table, and final presentation schedule. Thank you for hosting our teaching weekend.",
            'created_by'=>$uid,'created_by_username_snapshot'=>$username,'created_at'=>demoDate(-6+$i).' 10:00:00']);
        demoInsert('engagement_email_deliveries',['message_id'=>$mid,'contact_id'=>$contacts[(9+$i)%16][0],
            'recipient_name'=>'Fictional host','recipient_email'=>'host'.($i+1).'@example.invalid','recipient_type'=>['to','cc','bcc'][$i%3],
            'recipient_roles_json'=>'["primary_host"]','payload_ciphertext'=>null,'status'=>$i===5?'failed':'sent','attempts'=>1,
            'sent_at'=>$i===5?null:demoDate(-6+$i).' 10:01:00','last_error'=>$i===5?'Simulated demo failure; no delivery attempted':null]);
        $chron=demoChron('engagement',$events[9+$i],'Fictional outgoing host planning message. No email was sent.',-6+$i);
        $conn->execute_query('UPDATE engagement_chron_entries SET outbound_email_message_id=? WHERE id=?',[$mid,$chron]);
        if($i===2) createEmailFollowUpTask($conn,$mid,'engagement',$events[11],$uid,demoDate(2));
    }
    foreach(['review','review','processed','rejected'] as $i=>$status) {
        $key='test-account-demo-'.$asOf.'-'.$i;
        $mail=demoInsert('inbound_email_messages',['transport'=>'file','transport_key'=>$key,'deduplication_hash'=>hash('sha256',$key,true),
            'rfc_message_id'=>$key.'@example.invalid','gateway_address'=>'demo@example.invalid','sender_name'=>'Fictional host coordinator','sender_address'=>'host1@example.invalid',
            'to_addresses'=>'["demo@example.invalid"]','cc_addresses'=>'[]','subject'=>['[DEMO] Venue layout and arrival','[DEMO] New teaching request','[DEMO] Confirmed host arrangements','[DEMO] Rejected duplicate example'][$i],
            'received_at'=>demoDate(-3+$i).' 11:00:00','sent_at'=>demoDate(-3+$i).' 10:59:00','body_text'=>'Fictional incoming message. The room opens at 8:30; please confirm the arrival plan and resource table. Use Mail Review to attach this to a record or create an inquiry.',
            'attachment_names'=>'[]','raw_headers'=>'From: Fictional Host <host1@example.invalid>','status'=>$status,
            'review_reason'=>$status==='review'?'Fictional mail awaiting a record assignment':null,'processed_by'=>$status==='processed'?$uid:null,'processed_at'=>$status==='processed'?demoDate(-1).' 11:01:00':null]);
        if($status==='processed') {$chron=demoChron('engagement',$events[9],'Fictional incoming host message: venue and arrival confirmed.',-1);$conn->execute_query('UPDATE engagement_chron_entries SET inbound_email_message_id=? WHERE id=?',[$mail,$chron]);}
    }
    $conn->execute_query('UPDATE reimbursement_setup SET organization_name=?,bookkeeper_first_name=?,bookkeeper_last_name=?,bookkeeper_email=?,bookkeeper_phone=?,reviewer_email=?,cc_email=?,version=version+1 WHERE id=1',
        ['Test Account Demo Ministry','Harper','Stone','bookkeeper@example.invalid','+12145550180','reviewer@example.invalid','demo-copy@example.invalid']);
    $centers=$conn->query("SELECT id,description FROM reimbursement_cost_centers WHERE description IN ('Food','Lodging','Airfare','Printing/Duplication','Gas') AND is_archived=0 ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $expenses=[];
    foreach(range(0,15) as $i) {
        $c=$centers[$i%count($centers)]; $d=demoDate(-85+$i*5);$amount=1850+$i*1725;
        $eid=demoInsert('reimbursement_expenses',['user_id'=>$uid,'expense_date'=>$d,'merchant'=>['Trailway Travel','Cedar Lantern Inn','Harvest Table Cafe','Clearprint Resources','Prairie Fuel Stop'][$i%5],
            'description'=>'Fictional '.strtolower($c['description']).' for a teaching trip; demo expense #'.($i+1),'amount_cents'=>$amount,'cost_center_id'=>$c['id'],'is_archived'=>$i===15?1:0]);
        $expenses[]=$eid;
        if($i===14) continue; // One unreceipted editable expense is intentional.
        $pdf=new TCPDF();$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->AddPage();$pdf->SetFont('helvetica','',14);
        $pdf->MultiCell(0,10,"TEST ACCOUNT - FICTIONAL RECEIPT\n\nDemo expense #".($i+1)."\nDate: ".$d."\nCategory: ".$c['description']."\nTotal: $".number_format($amount/100,2)."\n\nNo real purchase or payment occurred.");
        $bytes=$pdf->Output('','S');$filename='demo-receipt-'.($i+1).'.pdf';$key=storePersistentFile($conn,$bytes,$filename,'application/pdf');
        demoInsert('reimbursement_receipts',['expense_id'=>$eid,'storage_key'=>$key,'filename'=>$filename,'content_type'=>'application/pdf','scan_state'=>'unscanned','report_key'=>$key,'report_status'=>'ready']);
        reimbursementEvent($conn,'expense',$eid,'saved','Fictional seed expense',$uid);
    }
    foreach(range(0,2) as $i) {
        $ids=array_slice($expenses,$i*4,4);
        $request=demoInsert('reimbursement_requests',['request_hash'=>bin2hex(random_bytes(8)),'user_id'=>$uid,'start_date'=>demoDate(-90+$i*20),'end_date'=>demoDate(-65+$i*20),'bookkeeper_note'=>'Fictional demo trip. Verify expenses, receipts, and download the package.']);
        foreach($ids as $eid) demoInsert('reimbursement_request_items',['request_id'=>$request,'expense_id'=>$eid]);
        reimbursementEvent($conn,'request',$request,'draft_created','Fictional seed draft',$uid);
        if($i<2) {
            $context=reimbursementSubmissionContext($conn,$request,$uid);$path=reimbursementBuildPackage($context);
            try{$key=storePersistentFile($conn,file_get_contents($path),reimbursementPackageBase($context['request']).'.zip','application/zip');}finally{unlink($path);}
            submitReimbursementRequest($conn,$request,$uid,false);
            demoInsert('reimbursement_submissions',['id'=>$request,'attachment_key'=>$key]);
            $conn->execute_query('UPDATE reimbursement_requests SET owner_name_snapshot=?,submitted_at=?,is_archived=?,correction_note=?,correction_at=? WHERE id=?',
                ['Test Account Administrator',demoDate(-60+$i*20).' 12:00:00',$i===1?1:0,$i===0?'Demo correction note: verify the lodging allocation.':'',$i===0?demoDate(-3).' 12:00:00':null,$request]);
            reimbursementEvent($conn,'request',$request,'submitted','Simulated demo submission; no email was queued',$uid);
        }
    }
    $stats=$conn->query('SELECT id FROM short_links ORDER BY id LIMIT 18')->fetch_all(MYSQLI_ASSOC);
    foreach($stats as $i=>$link) demoInsert('short_link_stats',['link_id'=>$link['id'],'visit_hour'=>demoDate(-2).' 10:00:00','browser'=>['Chrome','Safari','Firefox'][$i%3],'os'=>['Windows','macOS','Android'][$i%3],'country'=>['US','CA','GB'][$i%3],'referrer'=>'demo.invalid','visits'=>5+$i*3]);
    $consistency=applicationDataConsistencyCounts($conn);
    if(array_sum($consistency)!==0) throw new RuntimeException('Demo consistency check failed: '.json_encode($consistency));
    $conn->commit();
    $summary=['fixture_version'=>1,'account'=>'test-account','as_of'=>$asOf,'window'=>[$start->format('Y-m-d'),$end->format('Y-m-d')],'owner'=>$username,'consistency'=>$consistency,'counts'=>[]];
    foreach(['organizations','contacts','speakers','engagements','presentations','follow_up_tasks','booking_inquiries','engagement_financial_reports','engagement_financial_drafts','engagement_financial_revisions','reimbursement_expenses','reimbursement_requests','reimbursement_receipts','short_links','stored_files'] as $table) $summary['counts'][$table]=(int)$conn->query('SELECT COUNT(*) FROM '.$table)->fetch_row()[0];
    echo json_encode($summary,JSON_PRETTY_PRINT),"\n";
}catch(Throwable $e){$conn->rollback();throw $e;}
finally{$conn->query("SELECT RELEASE_LOCK('test-account-demo-seed')");}
