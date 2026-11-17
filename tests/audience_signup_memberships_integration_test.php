<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Signup membership tests require a disposable database.\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';
require_once $source.'/audience_store.php';
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Signup membership qualification');
$suffix=bin2hex(random_bytes(5));$checks=0;$people=[];$priorSes=$store->sesSettings();
$expect=static function(bool $ok,string $why)use(&$checks):void{if(!$ok)throw new RuntimeException($why);++$checks;};
$same=static function(array $actual,array $expected):bool{sort($actual);sort($expected);return $actual===$expected;};
$groupCount=0;
$group=static function(string $type)use($store,$suffix,&$groupCount):array{
    $id=$store->saveGroup(['name'=>$type.' '.++$groupCount.' '.$suffix,'display_type'=>$type,'options'=>[['name'=>'One'],['name'=>'Two']]],0,0);
    return [$id,array_map('intval',array_column($store->all('SELECT id FROM marketing_group_options WHERE category_id=? ORDER BY position',[$id]),'id'))];
};
$makeForm=static function(string $kind,string $mode,array $tags,array $groups)use($store,$suffix):array{
    $id=$store->saveCollection(['kind'=>$kind,'name'=>'Membership '.$kind.' '.$mode.' '.$suffix]);
    $d=$store->collectionResource($id)['draft'];$d['opt_in']=$mode;$d['fields']=$d['required']=['email'];
    $d['tag_ids']=$tags;$d['group_ids']=$groups;$d['origins']=['https://newsletter.example.test'];
    $store->saveCollection(['name'=>'Membership '.$kind.' '.$mode.' '.$suffix,'document'=>$d],$id,1);$store->collectionOperation($id,2,'publish');
    return $store->collectionPublished($store->collectionResource($id)['public_key']);
};
$person=static function(string $status='subscribed',array $tags=[],array $groups=[])use($store,$suffix,&$people):array{
    $email='membership-'.count($people).'-'.$suffix.'@example.test';
    $id=$store->savePerson(['email'=>$email,'email_status'=>$status,'consent_note'=>'Fixture permission','tag_ids'=>$tags,'group_ids'=>$groups]);$people[]=$id;
    return [$id,$email];
};
$submit=static function(array $form,string $email,array $groups=[],?array $input=null,array $extra=[])use($store,$suffix):array{
    if($input===null){
        $proof=audienceJson(\Dnr\Security\ApplicationKey::open($store->collectionChallenge($form,bin2hex(random_bytes(32)))));$proof['issued']-=5;
        $input=$extra+['proof'=>\Dnr\Security\ApplicationKey::seal(json_encode($proof,JSON_THROW_ON_ERROR)),'email'=>$email,'permission'=>'1','group_ids'=>$groups];
    }
    return [$store->submitCollection($form,$input,'memberships-'.$suffix,'https://newsletter.example.test'),$input];
};
$challenge=static function(int $id)use($store):array{
    $row=$store->one('SELECT q.id,q.service_mail_id,m.payload_ciphertext FROM marketing_consent_challenges q JOIN marketing_service_mail m ON m.id=q.service_mail_id WHERE q.contact_id=? ORDER BY q.id DESC LIMIT 1',[$id]);
    $message=audienceJson(\Dnr\Security\ApplicationKey::open($row['payload_ciphertext']))['message'];
    preg_match('/confirm=([a-f0-9]{64})/',$message['text'],$match);return $row+['token'=>$match[1]];
};
$accept=static function(array $challenge)use($store):void{$store->write("UPDATE marketing_service_mail SET state='accepted',payload_ciphertext='' WHERE id=?",[$challenge['service_mail_id']]);};
$events=static fn(int $id)=>$store->all("SELECT event_type,details_json FROM marketing_contact_events WHERE contact_id=? AND actor_type='recipient' AND event_type IN ('tags_changed','groups_changed') ORDER BY id",[$id]);
$age=static fn(int $id)=>$store->write('UPDATE marketing_consent_challenges SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 MINUTE) WHERE contact_id=?',[$id]);
try{
    $db->query("UPDATE marketing_ses_settings SET enabled=1,from_email='sender@example.test',access_key_tail='TEST',check_state='ready',checked_at=UTC_TIMESTAMP(),production_access=1,sending_enabled=1,max_daily=1000,max_rate=1000,sent_last_day=0,quota_checked_at=UTC_TIMESTAMP(),aws_account_id='111122223333',region='us-east-2',subscription_arn='arn:aws:sns:us-east-2:111122223333:fixture:confirmed' WHERE audience_id=1");
    $tags=[];foreach(['news','prayer letter','The Truth About Israel']as$name)$tags[]=$store->ensureTag($name.' '.$suffix);
    $retained=$store->ensureTag('Retained '.$suffix);$unselected=$store->ensureTag('Unselected '.$suffix);
    [$checkbox,$checkboxOptions]=$group('checkboxes');[$radio,$radioOptions]=$group('radio');[$dropdown,$dropdownOptions]=$group('dropdown');[$hidden,$hiddenOptions]=$group('hidden');
    $selected=[$checkboxOptions[1],$radioOptions[1],$dropdownOptions[1]];
    $forms=[];
    foreach(['hosted','embedded','popup','landing']as$kind)foreach(['single','double']as$mode){
        $form=$makeForm($kind,$mode,$tags,[$checkbox,$radio,$dropdown]);$forms[]=$form;
        foreach(['new','subscribed','pending','non-subscribed','unsubscribed','archived']as$status){
            $label=$kind.'/'.$mode.'/'.$status;
            if($status==='new'){$id=0;$email='new-'.$form['id'].'-'.$suffix.'@example.test';$beforeTags=$beforeGroups=[];}
            else{
                $beforeTags=[$retained,$tags[0]];$beforeGroups=[$checkboxOptions[0],$hiddenOptions[0]];
                [$id,$email]=$person($status==='archived'?'subscribed':$status,$beforeTags,$beforeGroups);
                if($status==='archived')$store->mutatePerson($id,(int)$store->person($id)['version'],'archive');
            }
            [$result,$input]=$submit($form,$email,$selected);
            if(!$id){$id=(int)$store->one('SELECT id FROM marketing_contacts WHERE audience_id=1 AND email=?',[$email])['id'];$people[]=$id;}
            $needsConfirmation=$status!=='new'||$mode==='double';$expect($result['confirmed']===$needsConfirmation,$label.' preserves opt-in semantics');
            if($needsConfirmation){
                $before=$store->person($id);$expect($same($before['tag_ids'],$beforeTags)&&$same($before['group_ids'],$beforeGroups),$label.' leaves memberships unchanged before confirmation');
                $q=$challenge($id);$expect(!$store->confirmSubscription($q['token'])&&!$events($id),$label.' cannot apply or trigger additions before delivery');
                $accept($q);$expect($store->confirmSubscription($q['token']),$label.' confirms successfully');
            }
            $after=$store->person($id);
            $expect($same($after['tag_ids'],array_values(array_unique([...$beforeTags,...$tags])))&&$same($after['group_ids'],[...$beforeGroups,...$selected]),$label.' adds every configured tag and selected interest while preserving memberships');
            $expect($after['email_status']==='subscribed'&&!$after['is_archived'],$label.' completes subscription/restoration');
            $changes=$events($id);$expect(count($changes)===2,$label.' emits one tag event and one interest event');
            foreach($changes as$change){
                $details=audienceJson($change['details_json']);$isTag=$change['event_type']==='tags_changed';
                $expect($same($details['added'],$isTag?array_values(array_diff($tags,$beforeTags)):$selected)&&$details['removed']===[],$label.' events describe only new additions');
                $expect(audienceFlowEventMatches(['type'=>$isTag?'tag':'group','id'=>$isTag?$tags[1]:$selected[0]],['type'=>$change['event_type'],'details'=>$details]),$label.' emits the event consumed by matching automations');
            }
            [$duplicate]=$submit($form,$email,$selected,$input);
            $expect($duplicate['duplicate']&&$events($id)===$changes,$label.' replay cannot add duplicate tags or automation events');
            if($needsConfirmation)$expect(!$store->confirmSubscription($q['token'])&&$events($id)===$changes,$label.' used confirmation cannot repeat additions');
        }
    }
    $form=$forms[0];
    // Repeating the full signup with a new proof is also idempotent.
    [$id,$email]=$person('subscribed');$submit($form,$email,$selected);$q=$challenge($id);$accept($q);$store->confirmSubscription($q['token']);$changes=$events($id);
    $age($id);$submit($form,$email,$selected);$q=$challenge($id);$accept($q);
    $expect($store->confirmSubscription($q['token'])&&$events($id)===$changes,'Fresh repeated signup does not retrigger membership automations');
    // A saved exclusive interest wins over a pending, conflicting signup choice.
    [$id,$email]=$person('subscribed',[$retained],[$radioOptions[0]]);$submit($form,$email,$selected);$q=$challenge($id);
    $store->mutatePerson($id,(int)$store->person($id)['version'],'group',['option_id'=>$dropdownOptions[0]]);$accept($q);$store->confirmSubscription($q['token']);
    $expect($same($store->person($id)['group_ids'],[$radioOptions[0],$dropdownOptions[0],$checkboxOptions[1]]),'Existing and concurrently saved exclusive choices are preserved without invalid multiple selections');
    // Memberships come from the submitted revision, not a later draft or publication.
    $snapshot=$makeForm('landing','double',$tags,[$checkbox]);[$id,$email]=$person();$submit($snapshot,$email,[$checkboxOptions[1]]);$q=$challenge($id);
    $row=$store->collectionResource((int)$snapshot['id']);$draft=$row['draft'];$draft['tag_ids']=[$unselected];$draft['group_ids']=[];
    $store->saveCollection(['name'=>$row['name'],'document'=>$draft],(int)$row['id'],(int)$row['version']);$row=$store->collectionResource((int)$row['id']);$store->collectionOperation((int)$row['id'],(int)$row['version'],'publish');
    $accept($q);$expect($store->confirmSubscription($q['token'])&&$same($store->person($id)['tag_ids'],$tags)&&$store->person($id)['group_ids']===[$checkboxOptions[1]],'Republishing cannot change pending signup memberships');
    $deletedForm=$makeForm('landing','double',$tags,[$checkbox]);[$id,$email]=$person();$submit($deletedForm,$email,[$checkboxOptions[1]]);$q=$challenge($id);
    foreach(['archive','delete']as$operation){$row=$store->collectionResource((int)$deletedForm['id'],false,true);$store->collectionOperation((int)$row['id'],(int)$row['version'],$operation);}
    $accept($q);$expect($store->confirmSubscription($q['token'])&&$same($store->person($id)['tag_ids'],$tags)&&$store->person($id)['group_ids']===[$checkboxOptions[1]],'Delivered signup retains approved memberships even after the form is deleted');
    // Already-issued confirmations without a membership snapshot still apply their published tags.
    [$id,$email]=$person();$submit($form,$email);$q=$challenge($id);
    $attribution=$store->one("SELECT id,details_json FROM marketing_contact_events WHERE contact_id=? AND event_type='collection-confirmation' ORDER BY id DESC LIMIT 1",[$id]);$context=audienceJson($attribution['details_json']);
    $pending=audienceJson(\Dnr\Security\ApplicationKey::open($context['profile_ciphertext']));unset($pending['memberships']);$context['profile_ciphertext']=\Dnr\Security\ApplicationKey::seal(json_encode($pending,JSON_THROW_ON_ERROR));
    $store->write('UPDATE marketing_contact_events SET details_json=? WHERE id=?',[json_encode($context,JSON_THROW_ON_ERROR),$attribution['id']]);$accept($q);
    $expect($store->confirmSubscription($q['token'])&&$same($store->person($id)['tag_ids'],$tags),'Pre-upgrade confirmation applies tags from its original revision');
    foreach(['expired','superseded','rearchived','deleted','cleaned']as$invalid){
        [$id,$email]=$person();$submit($form,$email,$selected);$q=$challenge($id);$accept($q);
        if($invalid==='expired')$store->write('UPDATE marketing_consent_challenges SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?',[$q['id']]);
        elseif($invalid==='superseded'){$age($id);$submit($forms[1],$email,$selected);}
        elseif($invalid==='cleaned')$store->write("UPDATE marketing_contacts SET email_status='cleaned' WHERE id=?",[$id]);
        else $store->mutatePerson($id,(int)$store->person($id)['version'],$invalid==='deleted'?'delete':'archive');
        $expect(!$store->confirmSubscription($q['token'])&&!$store->one('SELECT contact_id FROM marketing_contact_tags WHERE contact_id=?',[$id])&&!$store->one('SELECT contact_id FROM marketing_contact_groups WHERE contact_id=?',[$id]),$invalid.' confirmation cannot add memberships');
    }
    // Definitions withdrawn or hidden after submission cannot be reintroduced by confirmation.
    $withdrawn=$store->ensureTag('Withdrawn '.$suffix);$archivedTag=$store->ensureTag('Archived '.$suffix);
    [$removedGroup,$removedOptions]=$group('checkboxes');[$privateGroup,$privateOptions]=$group('checkboxes');[$archivedGroup,$archivedOptions]=$group('checkboxes');
    $changed=$makeForm('hosted','double',[$withdrawn,$archivedTag,$tags[0]],[$removedGroup,$privateGroup,$archivedGroup]);[$id,$email]=$person();
    $submit($changed,$email,[$removedOptions[0],$privateOptions[0],$archivedOptions[0]]);$q=$challenge($id);
    $store->write('DELETE FROM marketing_tags WHERE audience_id=1 AND id=?',[$withdrawn]);$store->write('UPDATE marketing_tags SET archived_at=UTC_TIMESTAMP() WHERE id=?',[$archivedTag]);
    $store->write('DELETE FROM marketing_group_options WHERE id=?',[$removedOptions[0]]);$store->write("UPDATE marketing_group_categories SET display_type='hidden' WHERE id=?",[$privateGroup]);$store->write('UPDATE marketing_group_categories SET archived_at=UTC_TIMESTAMP() WHERE id=?',[$archivedGroup]);
    $accept($q);$expect($store->confirmSubscription($q['token'])&&$store->person($id)['tag_ids']===[$tags[0]]&&!$store->person($id)['group_ids'],'Removed, archived, or newly private definitions are skipped without blocking confirmation');
    // Anonymous submissions cannot select operator tags or interests outside the published form.
    [$id,$email]=$person();$submit($form,$email,[],null,['tag_ids'=>[$unselected]]);$q=$challenge($id);$accept($q);$store->confirmSubscription($q['token']);
    $expect($same($store->person($id)['tag_ids'],$tags),'Submitted tag IDs cannot override the configured operator tags');
    [$id,$email]=$person();
    try{$submit($form,$email,[$hiddenOptions[0]]);throw new RuntimeException('Hidden interest was accepted');}catch(InvalidArgumentException){$expect(!$events($id),'Hidden submitted interests are rejected before staging');}
    $store->write("INSERT INTO marketing_audiences(name,settings_json) VALUES (?,JSON_OBJECT())",['Membership isolation '.$suffix]);$foreign=new AudienceStore($db,(int)$db->insert_id,0,'Foreign membership fixture');
    $foreignTag=$foreign->ensureTag('Foreign tag '.$suffix);$foreignGroup=$foreign->saveGroup(['name'=>'Foreign group '.$suffix,'display_type'=>'checkboxes','options'=>[['name'=>'Foreign option']]],0,0);$foreignOption=(int)$foreign->groups(false,false)[0]['options'][0]['id'];
    [$id,$email]=$person();
    try{$submit($form,$email,[$foreignOption]);throw new RuntimeException('Foreign interest was accepted');}catch(InvalidArgumentException){$expect(!$events($id),'Foreign audience interests cannot enter pending signup additions');}
    $movedTag=$store->ensureTag('Moved tag '.$suffix);[$movedGroup,$movedOptions]=$group('checkboxes');
    $moved=$makeForm('landing','double',[$movedTag],[$movedGroup]);$submit($moved,$email,[$movedOptions[0]]);$q=$challenge($id);
    $store->write('UPDATE marketing_tags SET audience_id=? WHERE id=?',[$foreign->audienceId,$movedTag]);$store->write('UPDATE marketing_group_categories SET audience_id=? WHERE id=?',[$foreign->audienceId,$movedGroup]);$accept($q);
    $expect($store->confirmSubscription($q['token'])&&!$store->one('SELECT contact_id FROM marketing_contact_tags WHERE contact_id=?',[$id])&&!$store->one('SELECT contact_id FROM marketing_contact_groups WHERE contact_id=?',[$id]),'Confirmation rechecks audience ownership of staged tags and interests');
    [$changedGroup,$changedOptions]=$group('checkboxes');$changed=$makeForm('hosted','double',[],[$changedGroup]);[$id,$email]=$person();$submit($changed,$email,$changedOptions);$q=$challenge($id);
    $store->write("UPDATE marketing_group_categories SET display_type='radio' WHERE id=?",[$changedGroup]);$accept($q);
    $expect($store->confirmSubscription($q['token'])&&!$store->person($id)['group_ids'],'A category changed to single choice cannot receive multiple staged interests');
    // A confirmation requested outside a signup must not inherit another form's memberships.
    [$id,$email]=$person('unsubscribed');$store->requestSubscriptionConfirmation($id,'https://newsletter.example.test');$q=$challenge($id);$accept($q);
    $expect($store->confirmSubscription($q['token'])&&!$store->person($id)['tag_ids']&&!$store->person($id)['group_ids'],'Administrator confirmation has no implicit form memberships');
    echo "Signup memberships: $checks checks passed; no real email sent.\n";
}finally{
    foreach($people as$id)$store->write("UPDATE marketing_service_mail SET state='cancelled',payload_ciphertext='' WHERE contact_id=? AND state='queued'",[$id]);
    $keys=['enabled','from_email','access_key_tail','check_state','checked_at','production_access','sending_enabled','max_daily','max_rate','sent_last_day','quota_checked_at','aws_account_id','region','subscription_arn'];
    $db->execute_query('UPDATE marketing_ses_settings SET '.implode(',',array_map(static fn($key)=>$key.'=?',$keys)).' WHERE audience_id=1',array_map(static fn($key)=>$priorSes[$key],$keys));
}
