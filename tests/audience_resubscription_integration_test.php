<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Resubscription tests require a disposable database.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/audience_store.php';
require_once $source.'/audience_delivery_lifecycle.php';
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Resubscription qualification');
$suffix=bin2hex(random_bytes(5));$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$reject=static function(callable $action,string $why)use($expect):void{
    try{$action();}catch(InvalidArgumentException){$expect(true,$why);return;}
    throw new RuntimeException($why);
};
$priorSes=$store->sesSettings();
$priorQueue=$store->all("SELECT id,next_attempt_at FROM marketing_service_mail WHERE state='queued'");
foreach($priorQueue as$queued)$store->write('UPDATE marketing_service_mail SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY) WHERE id=?',[$queued['id']]);
try{
    $db->query("UPDATE marketing_ses_settings SET enabled=1,from_email='sender@example.test',access_key_tail='TEST',check_state='ready',checked_at=UTC_TIMESTAMP(),production_access=1,sending_enabled=1,max_daily=1000,max_rate=10,sent_last_day=0,quota_checked_at=UTC_TIMESTAMP(),aws_account_id='111122223333',region='us-east-2',subscription_arn='arn:aws:sns:us-east-2:111122223333:fixture:confirmed' WHERE audience_id=1");
    $makeForm=static function(string $mode)use($store,$suffix):array{
        $id=$store->saveCollection(['kind'=>'hosted','name'=>'Resubscribe '.$mode.' '.$suffix]);
        $document=$store->collectionResource($id)['draft'];$document['fields']=$document['required']=['email'];
        $document['opt_in']=$mode;$document['tag_ids']=$document['group_ids']=[];
        $store->saveCollection(['name'=>'Resubscribe '.$mode.' '.$suffix,'document'=>$document],$id,1);
        $store->collectionOperation($id,2,'publish');
        return $store->collectionPublished($store->collectionResource($id)['public_key']);
    };
    $forms=['double'=>$makeForm('double'),'single'=>$makeForm('single')];
    $submit=static function(array $form,string $email,?array $input=null)use($store,$suffix):array{
        if($input===null){
            $proof=$store->collectionChallenge($form,bin2hex(random_bytes(32)));
            $data=json_decode(\Dnr\Security\ApplicationKey::open($proof),true,12,JSON_THROW_ON_ERROR);$data['issued']-=5;
            $input=['email'=>$email,'permission'=>'1','proof'=>\Dnr\Security\ApplicationKey::seal(json_encode($data,JSON_THROW_ON_ERROR))];
        }
        return [$store->submitCollection($form,$input,'resubscribe-'.$suffix,'https://newsletter.example.test'),$input];
    };
    $mail=static fn(int $id):?array=>$store->one("SELECT * FROM marketing_service_mail WHERE audience_id=1 AND contact_id=? AND kind='confirmation' ORDER BY id DESC LIMIT 1",[$id]);
    $token=static function(array $job):string{
        $message=json_decode(\Dnr\Security\ApplicationKey::open($job['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR)['message'];
        if(!preg_match('/confirm=([a-f0-9]{64})/',$message['text'],$match))throw new RuntimeException('Missing confirmation link.');
        return $match[1];
    };
    $assertImportBlocked=static function(string $email)use($store,$expect):void{
        $job=$store->createImport(['headers'=>['Email'],'rows'=>[[strtoupper($email)]]],'Deleted address import');
        $store->configureImport($job,['mapping'=>['email'],'update_existing'=>'1','import_status'=>'non-subscribed'],true);
        $store->processJob($job);
        $item=$store->one('SELECT state,error_message FROM marketing_job_items WHERE job_id=?',[$job]);
        $expect($item['state']==='error'&&str_contains($item['error_message'],'permanently deleted'),'Imports cannot recreate or overwrite a permanently deleted address');
    };
    $deliver=static function()use($db):array{
        $sent=[];
        processAudienceServiceMail($db,20,static function(array $message,string $kind)use(&$sent):string{$sent[]=[$message['email'],$kind];return 'accepted';});
        return $sent;
    };
    $policy=audienceDeletedEmailSuppressionSql();
    foreach($forms as$mode=>$form){
        $email='resubscribe-'.$mode.'-'.$suffix.'@example.test';$hash=audienceDispatchEmailHash($email);
        $old=$store->savePerson(['email'=>$email,'first_name'=>'Erased profile','email_status'=>'non-subscribed']);
        // A confirmation delivered before deletion must never revive the erased record.
        $store->requestSubscriptionConfirmation($old,'https://newsletter.example.test');$oldToken=$token($mail($old));
        $deliver();
        $store->mutatePerson($old,(int)$store->person($old)['version'],'delete');
        $assertImportBlocked($email);
        $expect(!$store->confirmSubscription($oldToken),'A pre-deletion link remains unusable');
        [$result,$input]=$submit($form,$email);
        $person=$store->one('SELECT * FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email]);$id=(int)$person['id'];
        $expect($id!==$old&&$person['email_status']==='pending'&&$person['first_name']==='','Signup creates a fresh pending connection without erased profile data: '.$mode);
        $expect($result['confirmed']===true,'Both form modes require a fresh email confirmation');
        $erased=$store->one('SELECT deleted_at,first_name FROM marketing_contacts WHERE audience_id=1 AND id=?',[$old]);
        $expect($erased['deleted_at']!==null&&$erased['first_name']==='','Original connection remains erased');
        $expect((bool)$store->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=1 AND email_hash=?',[$hash]),'Signup retains the import block');
        [$replay]=$submit($form,$email,$input);
        $expect($replay['confirmed']===true&&$replay['duplicate']===true,'Receipt replay preserves the forced confirmation screen');
        $job=$mail($id);$confirmation=$token($job);
        $expect($job['state']==='queued'&&!$store->confirmSubscription($confirmation),'A queued message cannot confirm ownership');
        $expect(audienceDeletedEmailSuppressed($db,1,$id,$hash),'Delivery remains blocked while confirmation is pending');
        $expect(!$store->one("SELECT c.id FROM marketing_contacts c WHERE c.id=? AND c.email_status='subscribed' AND NOT $policy",[$id]),'Campaign candidate policy excludes pending resubscriptions');
        $assertImportBlocked($email);
        $reject(static fn()=>$submit($form,$email),'Repeated form requests obey the confirmation cooldown');
        $sent=$deliver();
        $expect(in_array([$email,'confirmation'],$sent,true)&&$mail($id)['state']==='accepted','Confirmation reaches the injected delivery transport');
        $expect($store->confirmSubscription($confirmation)&&$store->person($id)['email_status']==='subscribed','Fresh delivered form confirmation completes resubscription');
        $expect(!$store->confirmSubscription($confirmation),'The new confirmation link works only once');
        $expect(!audienceDeletedEmailSuppressed($db,1,$id,$hash),'Fresh confirmed consent permits delivery');
        $expect((bool)$store->one("SELECT c.id FROM marketing_contacts c WHERE c.id=? AND c.email_status='subscribed' AND NOT $policy",[$id]),'Campaign candidate policy includes the confirmed resubscriber');
        $assertImportBlocked($email);
        $expect((bool)$store->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=1 AND email_hash=?',[$hash]),'Confirmed resubscription still retains the import block');
        $updated=$store->savePerson(['email'=>$email,'first_name'=>'New consent profile','email_status'=>'subscribed'],$id,(int)$store->person($id)['version']);
        $expect($updated===$id&&$store->person($id)['first_name']==='New consent profile','Confirmed recipients remain editable through normal administration');
        // An existing subscriber receives a new confirmation through every form mode.
        $store->write('UPDATE marketing_consent_challenges SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE contact_id=?',[$id]);
        $submit($form,$email);
        $notice=$store->one("SELECT id FROM marketing_service_mail WHERE contact_id=? AND kind='confirmation' AND state='queued'",[$id]);
        $expect((bool)$notice,'Confirmed resubscription can receive a fresh form confirmation');
        $expect(in_array([$email,'confirmation'],$deliver(),true),'Worker delivers fresh confirmations to existing subscribers');
        if($workerPassword=configurationSecret('MYSQL_MAIL_DISPATCH_PASSWORD')){
            $worker=new mysqli((string)getenv('DB_HOST'),'dnrmaildispatch',$workerPassword,(string)getenv('MYSQL_DATABASE'));
            $expect(!audienceDeletedEmailSuppressed($worker,1,$id,$hash),'Restricted mail worker can evaluate renewed consent with existing grants');$worker->close();
        }
        $store->mutatePerson($id,(int)$store->person($id)['version'],'delete');
        $expect(!$store->confirmSubscription($confirmation),'Deleting the new connection invalidates its confirmation');
        $submit($form,$email);$next=$store->one('SELECT id,email_status FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email]);
        $expect((int)$next['id']!==$id&&$next['email_status']==='pending'&&audienceDeletedEmailSuppressed($db,1,(int)$next['id'],$hash),'A second deletion requires fresh confirmation again');
        $store->write("UPDATE marketing_service_mail SET state='cancelled',payload_ciphertext='' WHERE contact_id=? AND state='queued'",[$next['id']]);
    }
    // An admin-created challenge carries no public-form evidence and cannot exempt a tombstone.
    $person=$store->one("SELECT id,email FROM marketing_contacts WHERE audience_id=1 AND email=?",['resubscribe-double-'.$suffix.'@example.test']);$id=(int)$person['id'];
    $store->write('UPDATE marketing_consent_challenges SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE contact_id=?',[$id]);
    $store->requestSubscriptionConfirmation($id,'https://newsletter.example.test');$adminToken=$token($mail($id));$deliver();
    $expect(!$store->confirmSubscription($adminToken)&&$store->person($id)['email_status']==='pending','An administrator cannot bypass fresh public-form consent');
    // Even single opt-in cannot claim success when the required confirmation lane is down.
    $email='resubscribe-unavailable-'.$suffix.'@example.test';$id=$store->savePerson(['email'=>$email,'email_status'=>'non-subscribed']);$store->mutatePerson($id,1,'delete');
    $db->query('UPDATE marketing_ses_settings SET enabled=0 WHERE audience_id=1');
    $reject(static fn()=>$submit($forms['single'],$email),'Unavailable delivery cannot report a fresh confirmation');
    $expect(!$store->one('SELECT id FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email]),'Failed confirmation setup rolls back new signup');
    echo "Audience resubscription: $checks checks passed; no real email sent.\n";
}finally{
    foreach($priorQueue as$queued)$store->write('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$queued['next_attempt_at'],$queued['id']]);
    $keys=['enabled','from_email','access_key_tail','check_state','checked_at','production_access','sending_enabled','max_daily','max_rate','sent_last_day','quota_checked_at','aws_account_id','region','subscription_arn','last_event_at'];
    $db->execute_query('UPDATE marketing_ses_settings SET '.implode(',',array_map(static fn($key)=>$key.'=?',$keys)).' WHERE audience_id=1',array_map(static fn($key)=>$priorSes[$key],$keys));
}
