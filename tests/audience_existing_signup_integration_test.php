<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Existing signup tests require a disposable database.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/audience_store.php';
require_once $source.'/audience_delivery_lifecycle.php';
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Existing signup qualification');$suffix=bin2hex(random_bytes(5));$checks=0;
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$reject=static function(callable $call,string $why)use($expect):void{try{$call();}catch(InvalidArgumentException){$expect(true,$why);return;}throw new RuntimeException($why);};
$priorSes=$store->sesSettings();$priorQueue=$store->all("SELECT id,next_attempt_at FROM marketing_service_mail WHERE state='queued'");
foreach($priorQueue as$row)$store->write('UPDATE marketing_service_mail SET next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY) WHERE id=?',[$row['id']]);
$people=[];
try{
    $db->query("UPDATE marketing_ses_settings SET enabled=1,from_email='sender@example.test',access_key_tail='TEST',check_state='ready',checked_at=UTC_TIMESTAMP(),production_access=1,sending_enabled=1,max_daily=1000,max_rate=10,sent_last_day=0,quota_checked_at=UTC_TIMESTAMP(),aws_account_id='111122223333',region='us-east-2',subscription_arn='arn:aws:sns:us-east-2:111122223333:fixture:confirmed' WHERE audience_id=1");
    $address=$store->saveField(['label'=>'Address '.$suffix,'merge_tag'=>'ADDR_'.strtoupper($suffix),'field_type'=>'address','is_public'=>'1'],0,0);
    $phone=$store->saveField(['label'=>'Phone '.$suffix,'merge_tag'=>'PHONE_'.strtoupper($suffix),'field_type'=>'text','is_public'=>'1'],0,0);
    $details=['first_name'=>'Submitted private first','last_name'=>'Submitted private last','custom'=>[$address=>['address1'=>'123 Private Street','address2'=>'','city'=>'Private City','region'=>'TN','postal_code'=>'37024','country'=>'US'],$phone=>'+1 555 0199']];
    $makeForm=static function(string $kind,string $mode)use($store,$suffix,$address,$phone):array{
        $id=$store->saveCollection(['kind'=>$kind,'name'=>'Existing '.$kind.' '.$mode.' '.$suffix]);
        $d=$store->collectionResource($id)['draft'];$d['opt_in']=$mode;$d['fields']=['email','first_name','last_name','custom:'.$address,'custom:'.$phone];$d['required']=['email'];$d['tag_ids']=$d['group_ids']=[];$d['origins']=['https://newsletter.example.test'];
        $store->saveCollection(['name'=>'Existing '.$kind.' '.$mode.' '.$suffix,'document'=>$d],$id,1);$store->collectionOperation($id,2,'publish');
        return $store->collectionPublished($store->collectionResource($id)['public_key']);
    };
    $submit=static function(array $form,string $email,array $values,?array $payload=null)use($store,$suffix):array{
        if($payload===null){$proof=$store->collectionChallenge($form,bin2hex(random_bytes(32)));$p=json_decode(\Dnr\Security\ApplicationKey::open($proof),true,12,JSON_THROW_ON_ERROR);$p['issued']-=5;$payload=$values+['email'=>$email,'permission'=>'1','proof'=>\Dnr\Security\ApplicationKey::seal(json_encode($p,JSON_THROW_ON_ERROR))];}
        return [$store->submitCollection($form,$payload,'existing-'.$suffix,'https://newsletter.example.test'),$payload];
    };
    $latest=static function(int $id)use($store):array{
        $mail=$store->one("SELECT * FROM marketing_service_mail WHERE audience_id=1 AND contact_id=? AND kind='confirmation' ORDER BY id DESC LIMIT 1",[$id]);
        $message=audienceJson(\Dnr\Security\ApplicationKey::open($mail['payload_ciphertext']))['message'];preg_match('/confirm=([a-f0-9]{64})/',$message['text'],$match);
        return [$mail,$message,$match[1]];
    };
    $deliver=static function()use($db):array{$sent=[];processAudienceServiceMail($db,30,static function(array $message,string $kind)use(&$sent):string{$sent[]=[$message['email'],$kind];return 'accepted';});return $sent;};
    $newPerson=static function(string $label,string $status='subscribed',string $first='Recorded first')use($store,$suffix,&$people):array{
        $email=$label.'-'.$suffix.'@example.test';$id=$store->savePerson(['email'=>$email,'first_name'=>$first,'email_status'=>$status,'consent_note'=>'Fixture prior consent']);$people[]=$id;return [$id,$email];
    };
    $forms=[];
    foreach(['hosted','embedded','popup','landing']as$kind)foreach(['single','double']as$mode){
        $form=$makeForm($kind,$mode);$forms[]=$form;[$id,$email]=$newPerson($kind.'-'.$mode);
        [$result,$input]=$submit($form,$email,$details);
        $person=$store->person($id);$expect($result['confirmed']===true&&$person['email_status']==='subscribed','Every signup kind and mode requests confirmation without interrupting an existing subscription');
        $expect($person['last_name']===''&&($person['custom'][$address]??'')==='','Existing profile additions stay pending before ownership proof');
        [$mail,$message,$token]=$latest($id);
        $expect($mail['state']==='queued'&&!$store->confirmSubscription($token),'Queued confirmations cannot apply pending profile additions');
        $body=$message['subject']."\n".$message['text'].($message['html']??'');
        foreach([$email,'Recorded first',$details['first_name'],$details['last_name'],'123 Private Street','Private City',$details['custom'][$phone]]as$private)$expect(!str_contains($body,$private),'The email subject/body contains no recipient profile information');
        $expect($message['to_name']===''&&!isset($message['profile']),'Confirmation email has no personalized recipient name or pending profile data');
        $event=$store->one("SELECT details_json FROM marketing_contact_events WHERE contact_id=? AND event_type='collection-confirmation' ORDER BY id DESC LIMIT 1",[$id]);
        $expect(isset(audienceJson($event['details_json'])['profile_ciphertext'])&&!str_contains($event['details_json'],$details['last_name']),'Pending profile is encrypted separately from the email');
        [$duplicate]=$submit($form,$email,$details,$input);
        $expect($duplicate['duplicate']===true&&(int)$store->one("SELECT COUNT(*) n FROM marketing_service_mail WHERE contact_id=?",[$id])['n']===1,'Receipt replay cannot send another email');
        $expect(in_array([$email,'confirmation'],$deliver(),true),'The general service-mail worker delivers the confirmation');
        $expect($store->one('SELECT payload_ciphertext FROM marketing_service_mail WHERE id=?',[$mail['id']])['payload_ciphertext']==='','Delivered email payload is erased without losing staged additions');
        $expect($store->confirmSubscription($token),'Delivered confirmation completes for every signup form kind and mode');
        $person=$store->person($id);
        $expect($person['first_name']==='Recorded first'&&$person['last_name']===$details['last_name']&&$person['custom'][$phone]===$details['custom'][$phone]&&audienceAddressParts($person['custom'][$address])['city']==='Private City','Confirmation fills missing profile fields and preserves recorded information');
        $expect(!$store->confirmSubscription($token),'Confirmation cannot be replayed');
        $event=$store->one("SELECT details_json FROM marketing_contact_events WHERE contact_id=? AND event_type='collection-confirmation' ORDER BY id DESC LIMIT 1",[$id]);
        $expect(!isset(audienceJson($event['details_json'])['profile_ciphertext']),'Applied pending profile is removed from confirmation evidence');
    }
    // Cross-form attempts share the same cooldown and daily allowance.
    $id=$people[0];$email=$store->person($id)['email'];$other=$forms[count($forms)-1];
    $reject(static fn()=>$submit($other,$email,$details),'Another form cannot bypass the confirmation cooldown');
    $age=static fn(int $id)=>$store->write('UPDATE marketing_consent_challenges SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE contact_id=?',[$id]);
    $age($id);$submit($other,$email,$details);[$freshMail,,$freshToken]=$latest($id);
    $expect($freshMail['state']==='queued','A fresh cross-form request sends confirmation instead of a suppressed status notice');$deliver();$store->confirmSubscription($freshToken);
    $age($id);$submit($forms[1],$email,$details);$deliver();$age($id);
    $reject(static fn()=>$submit($forms[2],$email,$details),'A fourth confirmation is rejected across all forms in the same audience');
    $expect((int)$store->one("SELECT COUNT(*) n FROM marketing_service_mail WHERE contact_id=? AND kind='confirmation'",[$id])['n']===3,'An anonymous requester cannot queue more than three confirmation emails per connection per day');
    foreach(['pending','non-subscribed','unsubscribed']as$status){
        [$id,$email]=$newPerson('existing-'.$status,$status,'');$submit($forms[0],$email,$details);[,,$token]=$latest($id);
        $expect($store->person($id)['first_name']===''&&$store->person($id)['email_status']===$status,'Existing non-subscribers stay unchanged until confirmation');$deliver();
        $expect($store->confirmSubscription($token)&&$store->person($id)['email_status']==='subscribed'&&$store->person($id)['first_name']===$details['first_name'],'Confirmation can enrich and subscribe other eligible existing connections');
    }
    [$id,$email]=$newPerson('superseded','subscribed','');$submit($forms[0],$email,$details);[,,$oldToken]=$latest($id);$age($id);
    $newDetails=array_replace($details,['last_name'=>'New pending surname']);$submit($other,$email,$newDetails);[,,$newToken]=$latest($id);$deliver();
    $expect(!$store->confirmSubscription($oldToken)&&$store->person($id)['last_name']==='','Superseded confirmations cannot apply stale additions');
    $person=$store->person($id);$store->savePerson(['email'=>$email,'first_name'=>'Saved while pending','last_name'=>'Recorded while pending','email_status'=>'subscribed'],$id,(int)$person['version']);
    $expect($store->confirmSubscription($newToken)&&$store->person($id)['last_name']==='Recorded while pending','Concurrent recorded values win when a pending confirmation completes');
    [$id,$email]=$newPerson('expired','subscribed','');$submit($forms[0],$email,$details);[$mail,,$token]=$latest($id);$deliver();$store->write('UPDATE marketing_consent_challenges SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE service_mail_id=?',[$mail['id']]);
    $expect(!$store->confirmSubscription($token)&&$store->person($id)['last_name']==='','Expired confirmation cannot apply profile information');
    [$id,$email]=$newPerson('erased','subscribed','');$submit($forms[0],$email,$details);[,,$token]=$latest($id);$deliver();$store->mutatePerson($id,(int)$store->person($id)['version'],'delete');
    $expect(!$store->confirmSubscription($token)&&!$store->one("SELECT id FROM marketing_contact_events WHERE contact_id=? AND JSON_CONTAINS_PATH(details_json,'one','$.profile_ciphertext')",[$id]),'Permanent deletion erases pending additions and invalidates the link');
    [$id,$email]=$newPerson('unavailable');$db->query('UPDATE marketing_ses_settings SET enabled=0 WHERE audience_id=1');
    $reject(static fn()=>$submit($forms[0],$email,$details),'A single opt-in form cannot claim a confirmation was sent when delivery is unavailable');
    $expect($store->person($id)['last_name']==='','Unavailable delivery cannot mutate the existing profile');
    echo "Existing signup confirmation: $checks checks passed; no real email sent.\n";
}finally{
    foreach($people as$id)$store->write("UPDATE marketing_service_mail SET state='cancelled',payload_ciphertext='' WHERE contact_id=? AND state='queued'",[$id]);
    foreach($priorQueue as$row)$store->write('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$row['next_attempt_at'],$row['id']]);
    $keys=['enabled','from_email','access_key_tail','check_state','checked_at','production_access','sending_enabled','max_daily','max_rate','sent_last_day','quota_checked_at','aws_account_id','region','subscription_arn','last_event_at'];
    $db->execute_query('UPDATE marketing_ses_settings SET '.implode(',',array_map(static fn($key)=>$key.'=?',$keys)).' WHERE audience_id=1',array_map(static fn($key)=>$priorSes[$key],$keys));
}
