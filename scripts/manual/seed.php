<?php
/** Fictional documentation fixtures. Run ONLY in a disposable preview database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('DNR_MANUAL_FIXTURE') !== 'disposable') {
    throw new RuntimeException('Set DNR_MANUAL_FIXTURE=disposable only in an isolated documentation preview.');
}
require '/var/www/html/bootstrap.php';
require_once '/var/www/html/presentation_helpers.php';
require_once '/var/www/html/follow_up_task_helpers.php';
require_once '/var/www/html/chron_log_helpers.php';
$user = $conn->execute_query('SELECT id,username FROM users WHERE username=?', [getenv('DNR_MANUAL_USER')])->fetch_assoc();
if (!$user) throw new RuntimeException('Choose a preview account with DNR_MANUAL_USER.');
$uid=(int)$user['id'];
function sampleInsert(string $table,array $values): int {
    global $conn;
    $conn->execute_query('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
    return (int)$conn->insert_id;
}
$old=$conn->execute_query("SELECT id FROM organizations WHERE organization_name='Cedar Grove Community - Manual Example'")->fetch_assoc();
if($old) throw new RuntimeException('Manual sample records already exist; reuse the saved fixture manifest.');
$conn->begin_transaction();
try {
    $org=sampleInsert('organizations',['organization_name'=>'Cedar Grove Community - Manual Example','affiliation'=>'Community education','distinctives'=>'Weekend learning and service','website_url'=>'https://example.org','email'=>'office@example.org','phone'=>'+13125550120','physical_address_line_1'=>'100 Example Avenue','physical_city'=>'Chicago','physical_state'=>'Illinois','physical_zipcode'=>'60601','physical_country'=>'United States of America','mailing_address_line_1'=>'PO Box 100','mailing_city'=>'Chicago','mailing_state'=>'Illinois','mailing_zipcode'=>'60601','mailing_country'=>'United States of America','notes'=>'Fictional training organization. Host prefers a planning call two weeks before each event.']);
    $contact=sampleInsert('contacts',['organization_id'=>$org,'contact_first_name'=>'Jordan','contact_last_name'=>'Parker','contact_role'=>'admin','contact_email'=>'jordan.parker@example.org','contact_phone'=>'+13125550121','contact_notes'=>'Fictional primary host. Coordinates venue access and speaker arrival.']);
    // The database trigger maintains the primary organization affiliation.
    $speaker=saveSpeaker($conn,['name'=>'Alex Morgan - Example Speaker','email'=>'alex.morgan@example.org','phone'=>'+13125550122','bio'=>'Alex is a fictional speaker used to demonstrate planning, presentation resources, and follow-up.','website_url'=>'https://example.org','bio_url'=>'https://example.org/bio','donations_url'=>'https://example.org/give','connection_url'=>'https://example.org/connect','blog_url'=>'https://example.org/articles','books_url'=>'https://example.org/books','custom_links'=>[['label'=>'Resource Library','url'=>'https://example.org/resources']]]);
    $event=sampleInsert('engagements',['organization_id'=>$org,'event_title'=>'Community Learning Weekend','event_description'=>'Two practical sessions with discussion, a resource table, and time for host follow-up. Fictional manual example.','event_start_date'=>'2026-09-12','event_end_date'=>'2026-09-13','event_type'=>'conference','confirmation_status'=>'under_review','caller_user_id'=>$uid,'book_table'=>1,'brochures'=>1,'travel_covered'=>'yes','travel_amount'=>250,'compensation_type'=>'honorarium','housing_type'=>'Provided','housing_amount'=>180,'event_address_line_1'=>'100 Example Avenue','event_city'=>'Chicago','event_state'=>'Illinois','event_zipcode'=>'60601','event_country'=>'United States of America']);
    sampleInsert('engagement_contacts',['engagement_id'=>$event,'contact_id'=>$contact,'contact_role'=>'primary_host','created_by'=>$uid]);
    sampleInsert('engagement_contacts',['engagement_id'=>$event,'contact_id'=>$contact,'contact_role'=>'on_site_contact','created_by'=>$uid]);
    $pdf=new TCPDF();$pdf->setPrintHeader(false);$pdf->setPrintFooter(false);$pdf->AddPage();$pdf->SetFont('helvetica','',20);$pdf->Write(0,"Community Learning Weekend\n\nSpeaker Notes\n\nFictional training material\n\n1. Welcome and purpose\n2. Discussion questions\n3. Next steps and resources");$bytes=$pdf->Output('','S');
    $rows=normalizeEngagementPresentations([['speaker_id'=>$speaker,'topic_title'=>'Building Lasting Connections','presentation_date'=>'2026-09-12','presentation_time'=>'10:00 AM','duration_minutes'=>60,'expected_attendance'=>120],['speaker_id'=>$speaker,'topic_title'=>'Turning Ideas Into Action','presentation_date'=>'2026-09-13','presentation_time'=>'09:30 AM','duration_minutes'=>45,'expected_attendance'=>100]],'2026-09-12','2026-09-13',$speaker);
    $rows[0]['asset_changes']=['speaker_notes'=>['action'=>'replace','asset'=>['data'=>$bytes,'filename'=>'community-learning-speaker-notes.pdf','size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes,true)]]];
    syncEngagementPresentations($conn,$event,$rows,$uid);
    generateEngagementFollowUpChecklist($conn,$event,$uid,$uid,false);
    $task=sampleInsert('follow_up_tasks',['title'=>'Confirm host arrival and room setup','details'=>'Call Jordan, verify the arrival time, and record the agreed room layout in Chron.','status'=>'in_progress','priority'=>'high','due_date'=>'2026-09-09','subject_type'=>'engagement','engagement_id'=>$event,'assigned_to'=>$uid,'created_by'=>$uid]);
    sampleInsert('follow_up_tasks',['title'=>'Review travel confirmation','details'=>'Waiting for the host to confirm the pickup point.','status'=>'waiting','priority'=>'normal','due_date'=>'2026-09-08','waiting_on'=>'Jordan Parker','subject_type'=>'engagement','engagement_id'=>$event,'assigned_to'=>$uid,'created_by'=>$uid]);
    $chron=insertEntityChronLogEntry($conn,'engagement',$event,'Host confirmed the venue and requested a resource table. Next step: review arrival arrangements on September 9.',$uid,$user['username']);
    insertEntityChronLogEntry($conn,'organization',$org,'Introduced the planning team. The host prefers email for schedule changes.',$uid,$user['username']);
    insertEntityChronLogEntry($conn,'contact',$contact,'Jordan is the primary host and on-site contact for the weekend.',$uid,$user['username']);
    $inquiry=sampleInsert('booking_inquiries',['title'=>'Autumn Leadership Workshop','organization_id'=>$org,'primary_contact_id'=>$contact,'request_summary'=>'Host requests a one-day workshop for 80 people. Confirm date availability and room requirements.','preferred_start_date'=>'2026-10-17','preferred_end_date'=>'2026-10-17','alternate_start_date'=>'2026-10-24','alternate_end_date'=>'2026-10-24','event_city'=>'Chicago','event_state'=>'Illinois','event_country'=>'United States of America','source'=>'referral','source_detail'=>'Community Learning Weekend host','owner_user_id'=>$uid,'priority'=>'high','stage'=>'qualified','next_action'=>'Send date options to Jordan','next_action_due_date'=>'2026-09-10','created_by'=>$uid]);
    insertEntityChronLogEntry($conn,'inquiry',$inquiry,'Spoke with Jordan. Audience and format are a good fit; dates are still being checked.',$uid,$user['username']);
    foreach(['new'=>'Regional Study Day','awaiting_details'=>'Winter Community Forum','proposal_sent'=>'Spring Planning Retreat'] as $stage=>$title)sampleInsert('booking_inquiries',['title'=>$title,'organization_id'=>$org,'primary_contact_id'=>$contact,'owner_user_id'=>$uid,'stage'=>$stage,'next_action'=>'Confirm the next planning step','next_action_due_date'=>'2026-09-11','created_by'=>$uid]);
    $mail=sampleInsert('inbound_email_messages',['transport'=>'file','transport_key'=>'manual020-fictional','deduplication_hash'=>hash('sha256','manual020-fictional',true),'rfc_message_id'=>'manual020@example.org','gateway_address'=>'records@example.org','sender_name'=>'Jordan Parker','sender_address'=>'jordan.parker@example.org','to_addresses'=>'["records@example.org"]','cc_addresses'=>'[]','subject'=>'Community Learning Weekend - arrival details','sent_at'=>'2026-09-09 13:30:00','received_at'=>'2026-09-09 13:31:00','body_text'=>"Hello team,\n\nThe hall opens at 8:30 a.m. We will have the resource table ready near the entrance. Please confirm your arrival time.\n\nThank you,\nJordan\n\nFictional documentation example.",'attachment_names'=>'["venue-layout.pdf"]','raw_headers'=>'From: Jordan Parker <jordan.parker@example.org>','status'=>'review','review_reason'=>'Manual review required: no signed engagement marker.']);
    $conn->commit();
    echo json_encode(compact('org','contact','speaker','event','task','inquiry','mail'),JSON_PRETTY_PRINT),PHP_EOL;
}catch(Throwable $e){$conn->rollback();throw $e;}
