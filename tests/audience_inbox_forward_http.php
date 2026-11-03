<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
require_once $source.'/audience_delivery_lifecycle.php';

(function () use ($ib,$editor,$reviewer,$inboxPath,$inboxCsrf,$suffix,$png) {
    $sender='forward-contact-'.$suffix.'@example.test';
    $person=$ib->savePerson(['email'=>$sender,'email_status'=>'unsubscribed']);
    $thread=$ib->inboxReceive('email',0,$sender,'Original sender','First subject','Message to forward',hash('sha256',$sender),true,
        [],0,[audienceInboxAttachment('Small image.png',$png,false)]);
    $original=(int)$ib->inboxThread($thread)['last_inbound_id'];
    $ib->inboxReceive('email',0,$sender,'Original sender','Different subject','Other email body',hash('sha256',$sender.'-2'),true,[],$thread);
    $note=$ib->inboxComment($thread,'<p>Private team discussion</p>');
    for($i=1;$i<10;$i++)$ib->inboxComment($thread,'<p>Follow-up note</p>');
    $notesPage=$editor($inboxPath.'&thread='.$thread.'&message_size=10');
    audienceHttpExpect(str_contains($notesPage['body'],'data-open-dialog="inbox-forward-dialog"')&&str_contains($notesPage['body'],'<strong>Different subject</strong>'),'A page of internal notes can still forward the most recent incoming email');

    $ids=[];$addresses=[];
    foreach(['editor','reviewer','unverified'] as $kind){
        $addresses[]=$address="forward-$kind-$suffix@example.test";
        $ib->write('INSERT INTO users(username,password,role,email,email_verified_at) VALUES (?,?,?,?,?)',
            ["forward-$kind-$suffix",'!', $kind==='reviewer'?'reviewer':'editor',$address,$kind==='unverified'?null:gmdate('Y-m-d H:i:s')]);
        $ids[]=(int)$ib->db->insert_id;
    }
    $page=$editor($inboxPath.'&thread='.$thread);
    preg_match('/name="forward_key" value="([a-f0-9]{32})"/',$page['body'],$key);
    audienceHttpExpect(isset($key[1])&&str_contains($page['body'],$addresses[1])&&!str_contains($page['body'],$addresses[2]),'Forward dialog lists only verified current MOED mailboxes');
    $form=['action'=>'inbox_forward','csrf_token'=>$inboxCsrf,'format'=>'json','forward_key'=>$key[1],
        'thread_id'=>$thread,'message_id'=>$original,'recipient_ids'=>[$ids[0],$ids[1],$ids[0]]];
    $post=static fn(array $changes=[])=>$editor($inboxPath,array_replace($form,$changes));
    audienceHttpExpect($reviewer($inboxPath,$form)['status']===403&&$post(['csrf_token'=>'bad'])['status']===400,'Forward requires Inbox permission and CSRF');
    foreach([['recipient_ids'=>[]],['recipient_ids'=>[$ids[0],$ids[2]]],['recipient_ids'=>['external@example.test']],['message_id'=>$note]] as $invalid){
        audienceHttpExpect($post($invalid)['status']===422,'Invalid recipient sets and private notes cannot be forwarded');
    }
    audienceHttpExpect(!$ib->one('SELECT service_mail_id FROM marketing_inbox_forwards WHERE message_id=?',[$original]),'Invalid requests enqueue no partial copies');
    $priorMode=getenv('DNR_MAIL_TRANSPORT');
    $first=$post();
    if($priorMode==='smtp'){
        audienceHttpExpect($first['status']===200&&$post()['status']===200,'Submitting the same SMTP forward twice succeeds idempotently');
    }else{
        audienceHttpExpect($first['status']===422&&str_contains($first['body'],'MOED SMTP'),'Log or disabled mail cannot be submitted as a forward');
    }
    putenv('DNR_MAIL_TRANSPORT=smtp');
    try {
        $queue=static fn(string $key,array $users)=>$ib->queueInboxForward($thread,$original,$users,$key);
        $queue($key[1],$form['recipient_ids']);$queue($key[1],$form['recipient_ids']);
        $jobs=$ib->all('SELECT s.* FROM marketing_service_mail s JOIN marketing_inbox_forwards f ON f.service_mail_id=s.id WHERE f.message_id=? ORDER BY s.id',[$original]);
        audienceHttpExpect(count($jobs)===2&&$ib->inboxForwardStates([$thread])[$thread]==='pending','Two distinct recipients have independent pending receipts');
        $profile='audience.php?view=contact&id='.$person.'&activity_type=inbox_forward';
        $pending=$editor($profile);
        audienceHttpExpect(str_contains($pending['body'],'Queued for:')&&!str_contains($pending['body'],'<h3>Email forwarded</h3>'),'Queued mail is not shown as forwarded');

        $frozen=json_decode(\Dnr\Security\ApplicationKey::open($jobs[0]['payload_ciphertext']),true)['message'];
        $ib->write('UPDATE users SET email_verified_at=NULL WHERE email=?',[$frozen['email']]);
        $denied=false;
        try{audienceInboxServiceReady($ib->db,$jobs[0],$frozen);}catch(DomainException){$denied=true;}
        audienceHttpExpect($denied,'Dispatch rechecks a recipient whose mailbox is no longer verified');
        $ib->write('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE email=?',[$frozen['email']]);

        audienceHttpExpect(!isset($frozen['ses_binding'])&&!isset($frozen['from_email'])&&$frozen['subject']==='Fwd: First subject'
            &&str_contains($frozen['text'],'Message to forward')&&!str_contains($frozen['text'],'Other email body')&&!str_contains($frozen['text'],'Private team discussion'),
            'The encrypted copy retains only the selected message and leaves sender configuration to the SMTP worker');
        // Here we exercise the HTTP views against accepted receipts. The separate
        // dispatch-role test below uses a real loopback SMTP exchange for its receipts.
        foreach($jobs as $job)$ib->write("UPDATE marketing_service_mail SET state='accepted' WHERE id=?",[$job['id']]);
        $sent=array_slice($addresses,0,2);
        $accepted=$editor($profile);
        audienceHttpExpect(substr_count($accepted['body'],'<h3>Email forwarded</h3>')===1&&str_contains($accepted['body'],implode(', ',$sent))&&str_contains($accepted['body'],'First subject'),'One connection activity entry lists both forwarded addresses and the selected subject');
        audienceHttpExpect(!str_contains($reviewer($profile)['body'],$addresses[0]),'Inbox recipient history stays private to Inbox staff');
        audienceHttpExpect(str_contains($editor($inboxPath)['body'],'data-forward-state="forwarded" data-forward-thread="'.$thread.'"'),'The Conversations row retains its forwarded icon');

        $queue(bin2hex(random_bytes(16)),[$ids[0]]);
        $ib->inboxThreadOperation($thread,(int)$ib->inboxThread($thread)['version'],'trash');
        $states=$ib->all('SELECT s.state FROM marketing_service_mail s JOIN marketing_inbox_forwards f ON f.service_mail_id=s.id WHERE f.message_id=? ORDER BY s.id',[$original]);
        audienceHttpExpect(array_column($states,'state')===['accepted','accepted','cancelled'],'Trash cancels only unsent forwards');
        $ib->inboxThreadOperation($thread,(int)$ib->inboxThread($thread)['version'],'purge');
        audienceHttpExpect(!$ib->one('SELECT service_mail_id FROM marketing_inbox_forwards WHERE message_id=?',[$original])
            &&$ib->one('SELECT payload_ciphertext FROM marketing_service_mail WHERE id=?',[$jobs[0]['id']])['payload_ciphertext']==='','Purge erases forwarded content and recipient associations');
        $workerThread=$ib->inboxReceive('email',0,$sender,'SMTP fixture','SMTP forwarding fixture','Original SMTP fixture body',hash('sha256','smtp-'.$suffix),true,
            [],0,[audienceInboxAttachment('Small image.png',$png,false)]);
        $workerMessage=(int)$ib->inboxThread($workerThread)['last_inbound_id'];
        $ib->queueInboxForward($workerThread,$workerMessage,[$ids[0],$ids[1]],bin2hex(random_bytes(16)));
        $ib->write('UPDATE marketing_service_mail s JOIN marketing_inbox_forwards f ON f.service_mail_id=s.id SET s.next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE f.audience_id=1 AND f.message_id=?',[$workerMessage]);
    }finally{putenv($priorMode===false?'DNR_MAIL_TRANSPORT':'DNR_MAIL_TRANSPORT='.$priorMode);}
})();
