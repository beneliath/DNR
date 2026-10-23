<?php
declare(strict_types=1);

// Local preview only. Re-running adds missing fixtures without resetting conversations.
if(PHP_SAPI!=='cli'||getenv('DNR_LOCAL_SAMPLE_DATA')!=='localhost:8080')exit("Explicit localhost sample-data opt-in required.\n");
if(!is_file('/usr/local/etc/php/conf.d/zz-dnr-development.ini'))throw new RuntimeException('Development bind-mounted runtime required.');
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/audience_store.php';
if(currentAccountKey()!=='shalom-in-messiah')throw new RuntimeException('Only the requested local account may be seeded.');
$db=applicationDatabaseConnection();
$options=getopt('',['actor:','read-state-only']);
$actor=isset($options['actor'])?$db->execute_query("SELECT id FROM users WHERE id=? AND role IN ('admin','editor') AND account_status='active'",[(int)$options['actor']])->fetch_assoc():$db->query("SELECT id FROM users WHERE username='codex-review-admin' AND role='admin' AND account_status='active'")->fetch_assoc();
if(!$actor)throw new RuntimeException('Choose an existing local editor or administrator with --actor=ID.');
$store=new AudienceStore($db,1,(int)$actor['id'],'Sample team');
if(!$store->inboxAvailable())throw new RuntimeException('The qualified Inbox schema is required.');
if(isset($options['read-state-only'])){
    $result=$store->transaction(function()use($store,$actor):array{
        $created=[];
        foreach([
            ['unread-parking','Casey Reed','Sample · Unread: parking for the next gathering','Could you share the parking details for our next gathering?',false],
            ['unread-resources','Riley Taylor','Sample · Unread: recommended reading','Do you have a recommended reading list for someone joining for the first time?',false],
            ['read-welcome','Jamie Brooks','Sample · Read: thank you for the welcome','Thank you for helping us feel so welcome. We look forward to joining you again.',true],
            ['read-newsletter','Morgan Ellis','Sample · Read: newsletter received','The newsletter came through beautifully. Thanks for keeping everyone up to date.',true],
        ]as[$slug,$name,$subject,$body,$read]){
            $key=hash('sha256','inbox-read-state-preview:'.$actor['id'].':'.$slug);
            $existing=$store->one('SELECT thread_id FROM marketing_inbox_messages WHERE audience_id=1 AND source_key=?',[$key]);
            if($existing)continue;
            $id=$store->inboxReceive('direct',0,$slug.'@example.test',$name,$subject,"Fictional local sample message for testing read and unread styling.\n\n".$body,$key,false,[],0,[],true);
            $store->inboxMarkRead($id,$read);
            $store->write('UPDATE marketing_inbox_notifications SET read_at=UTC_TIMESTAMP() WHERE audience_id=1 AND thread_id=?',[$id]);
            $created[]=['thread'=>$id,'read'=>$read];
        }
        return ['created'=>$created,'read_state_owner'=>(int)$actor['id'],'external_messages_sent'=>0];
    });
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";exit;
}
$now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
$stamp=static fn(int $minutes):string=>$now->modify('-'.$minutes.' minutes')->format('Y-m-d H:i:s.u');
$prefix=''; // Subjects and sample connection notes identify these as fictional fixtures.
$fixtures=[
    ['avery','Avery Morgan','email','A few questions about the November gathering',"Hi team,\n\nWe’re planning to attend the November gathering with a few friends. Could you confirm when doors open and whether we need to register everyone separately?\n\nOne of our guests uses a wheelchair. Is there an accessible entrance near the main parking area?\n\nThank you for your help!\nAvery",12,'todo','Events',true],
    ['jordan','Jordan Lee','contact','Interested in volunteering this fall',"Hello! I’d love to help with welcoming guests at an upcoming event. I’m available on Saturday mornings and have experience at the welcome desk.\n\nWhat’s the best next step?",48,'todo','Volunteer',false],
    ['samira','Samira Patel','survey','Community survey: more small-group opportunities',"What would you like to see next?\nMore opportunities to meet in smaller groups.\n\nWhat worked well?\nThe discussion after the last gathering made it easy to meet new people.\n\nAnything else?\nA weekday evening option would be wonderful for those who work weekends.",130,'todo','Feedback',true],
    ['noah','Noah Williams','direct','Can I share the newsletter with our group?',"Thanks for the latest community update. The story about the neighborhood project really resonated with our small group.\n\nMay I share a link to the newsletter in our group chat?",220,'todo','Community',true],
    ['elena','Elena Rivera','email','Photos from the community afternoon',"Hi everyone,\n\nWe had a wonderful time at the community afternoon. I’ve attached a small sample image for you to review.\n\nPlease let me know who I should contact about sharing more photos.\n\nWarmly,\nElena",420,'todo','Community',true],
    ['oliver','Oliver Chen','contact','Planning a visit with our community group',"Good afternoon,\n\nWe’re bringing a group of twelve to the next gathering. Could we reserve a few seats together, and is there somewhere nearby to have lunch afterward?\n\nI’m happy to send more details if helpful.",1500,'todo','Events',false],
    ['maya','Maya Thompson','survey','Thank you for making newcomers feel welcome',"What did you enjoy most?\nThe welcome team remembered our names and helped us find a place to sit.\n\nWould you come again?\nAbsolutely. We’ve already invited two friends.",2700,'done','Feedback',true],
    ['ben','Ben Carter','email','Re: Volunteer check-in details',"Perfect, thank you for confirming. I’ll arrive at 8:30 and meet the welcome team at the front desk.\n\nSee you Saturday!",3900,'done','Volunteer',true],
    ['leah','Leah Brooks','direct','A resource suggestion for the next newsletter',"I found the recommended reading in the last edition very helpful. Could we include a short list of resources for people visiting for the first time?\n\nI’d be glad to help put a few ideas together.",5400,'todo','Community',true],
    ['test-duplicate','Preview Duplicate','contact','Sample duplicate submission',"This is a fictional duplicate contact-form submission, placed in Trash to let you test restoring a conversation.",6800,'trash','Feedback',false],
];
$result=$store->transaction(function()use($store,$fixtures,$stamp,$prefix,$actor):array{
    $labels=[];foreach(['Events','Volunteer','Feedback','Community']as$name){$row=$store->one('SELECT id FROM marketing_inbox_labels WHERE audience_id=1 AND name=? AND deleted_at IS NULL',[$name]);$labels[$name]=$row?(int)$row['id']:$store->saveInboxNamed('label',['name'=>$name]);}
    $templates=[
        ['A warm welcome','Thanks for reaching out, *|FNAME|*','<p>Hi *|FNAME|*,</p><p>Thank you for reaching out. We’re glad to hear from you and would love to help.</p><p>Could you share a little more about what you have in mind?</p><p>Warmly,<br>The team</p>'],
        ['Event questions','Your questions about our next gathering','<p>Hi *|FNAME|*,</p><p>We’re looking forward to welcoming you! Here are the details for the upcoming gathering:</p><ul><li>Date and time: [add details]</li><li>Location: [add address]</li><li>Registration: [add registration link]</li></ul><p>Let us know if you have any questions or accessibility needs so we can help you plan your visit.</p><p>Warmly,<br>The team</p>'],
        ['Volunteer next steps','Thanks for offering to help, *|FNAME|*','<p>Hi *|FNAME|*,</p><p>Thank you for offering your time and talents. We’d love to learn more about how you would like to help.</p><p>Which days usually work best for you, and is there a particular area you’re interested in?</p><p>We’ll connect you with the right team and share the next steps.</p><p>With gratitude,<br>The team</p>'],
        ['Thank you for your feedback','We appreciate your feedback, *|FNAME|*','<p>Hi *|FNAME|*,</p><p>Thank you for taking the time to share your experience. Your feedback helps us understand what is working well and where we can improve.</p><p>We’ll share your thoughts with the team. If there is anything else you would like us to know, please reply here.</p><p>With appreciation,<br>The team</p>'],
        ['Share a resource','The resource you asked about','<p>Hi *|FNAME|*,</p><p>Thanks for your interest! Here is the resource we discussed:</p><p>[Add the resource title, a short description, and its link.]</p><p>Please let us know if you have trouble opening it or would like help finding something else.</p><p>Warmly,<br>The team</p>'],
        ['A friendly follow-up','Following up on our conversation','<p>Hi *|FNAME|*,</p><p>I’m following up on our recent conversation to see whether you have any remaining questions.</p><p>[Add a brief update or the next step.]</p><p>Whenever you’re ready, just reply to this message. We’re happy to help.</p><p>Warmly,<br>The team</p>'],
    ];
    foreach($templates as[$name,$subject,$html]){
        $name='Sample · '.$name;
        if(!$store->one('SELECT id FROM marketing_inbox_templates WHERE audience_id=1 AND name=? AND deleted_at IS NULL',[$name]))$store->saveInboxNamed('template',['name'=>$name,'subject'=>$subject,'body_html'=>$html]);
    }
    $threads=[];$created=0;
    foreach($fixtures as$i=>[$slug,$name,$source,$subject,$body,$minutes,$state,$label,$linked]){
        $key=hash('sha256','inbox-redesign-preview-v1-'.$slug);
        $existing=$store->one('SELECT thread_id FROM marketing_inbox_messages WHERE audience_id=1 AND source_key=?',[$key]);
        if($existing){$threads[$slug]=(int)$existing['thread_id'];continue;}
        $email='inbox-preview-'.$slug.'@example.test';
        if($linked&&!$store->one('SELECT id FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email])){[$first,$last]=explode(' ',$name,2);$store->write("INSERT INTO marketing_contacts(audience_id,email,first_name,last_name,email_status,notes) VALUES (1,?,?,?,'subscribed',?)",[$email,$first,$last,'Fictional localhost Inbox preview connection. No real consent or external sending.']);}
        $id=$store->inboxReceive($source,0,$email,$name,'Sample · '.$subject,$prefix.$body,$key,$linked);
        $threads[$slug]=$id;$created++;
        $t=$store->inboxThread($id);
        $store->inboxThreadOperation($id,(int)$t['version'],'labels',['labels'=>[$labels[$label]]]);
        if($slug==='avery'){
            $store->write('UPDATE marketing_inbox_messages SET occurred_at=? WHERE audience_id=1 AND thread_id=?',[$stamp(390),$id]);
            $store->write("INSERT INTO marketing_inbox_messages(audience_id,thread_id,direction,source_key,references_json,sender_name,actor_id,body_text,body_html,occurred_at) VALUES (1,?,'outbound',?,JSON_ARRAY(),'Sample team',?,?,?,?)",[$id,hash('sha256','inbox-redesign-preview-avery-reply'),$actor['id'],'Sample reply for preview only. No email was sent. Doors open at 9:30; you can register the group together.','<p>Hi Avery,</p><p>Doors open at 9:30, and you can register your group together. There is an accessible entrance beside the main parking area.</p><p>We look forward to welcoming you!</p><p><small>Sample reply for preview only. No email was sent.</small></p>',$stamp(340)]);
            $store->inboxComment($id,'<p>Sample team note: confirm the accessible entrance signage with the welcome team before following up. This note is visible only to our team.</p>');
            $store->write("UPDATE marketing_inbox_messages SET occurred_at=? WHERE audience_id=1 AND thread_id=? AND direction='comment'",[$stamp(70),$id]);
            $store->inboxReceive('email',0,$email,$name,$subject,$prefix."That’s very helpful, thank you! There will be six of us in total. Is there a recommended drop-off point for our guest near the accessible entrance?",hash('sha256','inbox-redesign-preview-avery-followup'),true,[],$id);
        }
        if($slug==='elena'){
            $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
            $file=audienceInboxAttachment('sample-photo.png',$png,false);$last=$store->inboxThread($id)['last_message_id'];
            $store->write('INSERT INTO marketing_inbox_attachments(audience_id,message_id,filename,mime_type,byte_size,sha256,content) VALUES (1,?,?,?,?,?,?)',[$last,$file['filename'],$file['content_type'],strlen($png),hash('sha256',$png),$png]);
        }
        if($slug==='jordan'){
            $store->inboxComment($id,'<p>Sample team note: share the welcome-team orientation details. Jordan is available on Saturday mornings.</p>');
            $draft=$store->saveInboxDraft(['thread_id'=>$id,'from_name'=>'Sample team','subject'=>'Re: '.$subject,'body_html'=>'<p>Hi Jordan,</p><p>Thank you for offering to help! Here are the next steps for joining our welcome team.</p><p>Sample draft only. Add your own details to test editing.</p>','include_optout'=>'1']);
            $store->write('UPDATE marketing_inbox_drafts SET updated_at=? WHERE audience_id=1 AND id=?',[$stamp($minutes),$draft]);
        }
        if($slug!=='avery')$store->write('UPDATE marketing_inbox_messages SET occurred_at=? WHERE audience_id=1 AND thread_id=?',[$stamp($minutes),$id]);
        else$store->write('UPDATE marketing_inbox_messages SET occurred_at=? WHERE audience_id=1 AND id=?',[$stamp($minutes),$store->inboxThread($id)['last_message_id']]);
        $store->write('UPDATE marketing_inbox_threads SET last_activity_at=? WHERE audience_id=1 AND id=?',[$stamp($minutes),$id]);
        if($state!=='todo'){$t=$store->inboxThread($id);$store->inboxThreadOperation($id,(int)$t['version'],$state);}
        if(in_array($slug,['noah','oliver','maya','ben','leah'],true))$store->inboxMarkRead($id,true);
        // Fixture notifications are marked read before commit, so mail workers cannot alert real users.
        $store->write('UPDATE marketing_inbox_notifications SET read_at=UTC_TIMESTAMP() WHERE audience_id=1 AND thread_id=?',[$id]);
    }
    return ['created'=>$created,'sample_conversations'=>$threads,'draft_owner'=>(int)$actor['id'],'external_messages_sent'=>0];
});
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
