<?php
declare(strict_types=1);
require_once __DIR__.'/audience_mail_budget.php';
require_once __DIR__.'/audience_engagement.php';
require_once __DIR__.'/audience_campaign_advanced.php';

function audienceTestRecipients(string $input):array
{
    $emails=[];foreach(preg_split('/[,;\s]+/',trim($input))?:[]as$email){$email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254)throw new InvalidArgumentException('Enter valid test email addresses, separated by commas.');$emails[]=$email;}
    $emails=array_values(array_unique($emails));if(!$emails||count($emails)>10)throw new InvalidArgumentException('Send a test to between 1 and 10 addresses.');return $emails;
}
function audienceExperimentVariants(array $draft):array
{
    $base=['subject'=>$draft['subject'],'from_name'=>$draft['from_name'],'design'=>$draft['design']??null,'local'=>null,'content'=>0];
    if(empty($draft['experiment']))return [$base];
    $saved=$draft['experiment'];
    $experiment=audienceCampaignExperiment(['variables'=>$saved['variables']??[],'test_percent'=>$saved['percent']??100,'test_hours'=>$saved['hours']??4,'winner'=>$saved['winner']??'clicks'],$saved['kind']??'ab');
    $key=array_key_first($experiment['variables']);$variants=[];
    foreach($experiment['variables'][$key]as$i=>$value){$copy=$base;switch($key){case 'subject':$copy['subject']=$value;break;case 'from':$copy['from_name']=$value;break;case 'content':$copy['content']=$i;$copy['design']=$i?($draft['design_variants'][$i]??$draft['design']):$draft['design'];break;case 'time':$copy['local']=$value;break;}$variants[]=$copy;}
    return $variants;
}
function audienceRecipientDue(array $send,string $zone,?int $now=null):string
{
    if(($send['mode']??'')!=='schedule'||($send['delivery']??'specific')!=='timewarp')return audienceDispatchDue($send,$now);
    $now??=time();if(strtotime(($send['utc']??'').' UTC')<$now+86400)throw new InvalidArgumentException('Schedule Timewarp at least 24 hours ahead.');
    $copy=$send;$copy['delivery']='specific';if(in_array($zone,DateTimeZone::listIdentifiers(),true))$copy['timezone']=$zone;
    // Per-recipient DST gaps move forward to the first valid local instant; repeated hours use PHP's first occurrence once.
    $local=new DateTimeImmutable($copy['local'],new DateTimeZone($copy['timezone']));
    if($local->getTimestamp()<$now+300)throw new InvalidArgumentException('A recipient’s local send time is too soon. Choose a later date.');
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function audienceTrackMessage(array $snapshot,string $html,string $text,string $token):array
{
    if(empty($snapshot['tracking_key'])||!empty($snapshot['test']))return [$html,$text];$base=$snapshot['base'];$key=$snapshot['tracking_key'];$tracking=$snapshot['draft']['tracking']??[];
    $link=static function(string $url)use($base,$key,$token):string{
        if(strlen($url)>2048||!str_starts_with($url,'https://')||str_starts_with($url,$base.'/audience_'))return $url;
        $encoded=rtrim(strtr(base64_encode($url),'+/','-_'),'=');return $base.'/audience_track.php?'.http_build_query(['token'=>$token,'url'=>$encoded,'sig'=>hash_hmac('sha256',$encoded,$key)]);
    };
    if(!empty($tracking['clicks']))$html=preg_replace_callback('~href="(https://[^"]+)"~',static fn($m)=>'href="'.htmlspecialchars($link(html_entity_decode($m[1],ENT_QUOTES,'UTF-8')),ENT_QUOTES,'UTF-8').'"',$html);
    if(!empty($tracking['plain_clicks']))$text=preg_replace_callback('~https://[^\s<>]+~',static fn($m)=>$link($m[0]),$text);
    if(!empty($tracking['opens'])){$pixel='<img src="'.htmlspecialchars($base.'/audience_track.php?'.http_build_query(['token'=>$token,'open'=>'1','sig'=>hash_hmac('sha256','open',$key)]),ENT_QUOTES,'UTF-8').'" width="1" height="1" alt="" style="display:block;border:0">';$html=str_contains($html,'</body>')?str_replace('</body>',$pixel.'</body>',$html):$html.$pixel;}
    return [$html,$text];
}
trait AudienceDeliveryRuntime
{
    public function deliveryRuntimeAvailable():bool{return audienceMailPolicyAvailable($this->db);}
    public function deliveryPolicy():array{return $this->one('SELECT * FROM marketing_delivery_policy WHERE audience_id=?',[$this->audienceId])??[];}
    public function saveDeliveryPolicy(int $version,array $input):void
    {
        $p=audienceMailPolicyInput($input);$this->write('UPDATE marketing_delivery_policy SET bulk_approved=?,hourly_budget=?,daily_budget=?,hourly_reserve=?,daily_reserve=?,interval_seconds=?,version=version+1 WHERE audience_id=? AND version=?',[$p['bulk_approved'],$p['hourly_budget'],$p['daily_budget'],$p['hourly_reserve'],$p['daily_reserve'],$p['interval_seconds'],$this->audienceId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('Delivery settings changed. Reload before saving.');
    }
    public function rotateDeliveryEventKey(int $version):void
    {
        $this->write('UPDATE marketing_delivery_policy SET event_key_ciphertext=?,version=version+1 WHERE audience_id=? AND version=?',[\Dnr\Security\ApplicationKey::seal(bin2hex(random_bytes(32))),$this->audienceId,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('Delivery settings changed. Reload before replacing the event key.');
    }
    public function queueCampaignTest(int $id,int $version,array $input,array $audience,string $base,string $kind='campaign',int $variant=0):int
    {
        $emails=audienceTestRecipients(audienceText($input,'test_recipients',3000));$key=audienceText($input,'request_key',64);if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new InvalidArgumentException('Reload the test-email dialog.');
        return $this->transaction(function()use($id,$version,$input,$audience,$base,$emails,$key,$kind,$variant){
            $row=$kind==='template'?$this->emailTemplate($id):$this->campaign($id,true);if($kind!=='template'&&(!empty($row['draft']['experiment'])||!empty($row['draft']['design_variants'])))throw new InvalidArgumentException('A/B testing is not available in MessageMonkey.');if((int)$row['version']!==$version)throw new InvalidArgumentException('This email changed. Reload before sending a test.');
            if(!$this->sesAvailable())throw new InvalidArgumentException('Configure SES in Email delivery first.');
            if($kind==='template'){$draft=['design'=>$row['design'],'subject'=>audienceText($input,'test_subject',200)?:$row['name'],'from_email'=>audienceText($input,'test_from',254),'from_name'=>'MOED MessageMonkey'];$row['campaign_type']='regular';}else{$draft=$row['draft'];if($variant>0)$draft['design']=$this->campaignDesign($row,$variant);}$ready=$this->sendingReadiness($draft['from_email']??'',audienceJson($audience['settings_json']),true);if(!$ready['ready'])throw new InvalidArgumentException(implode(' ',$ready['reasons']));
            if(empty($draft['subject'])||($row['campaign_type']==='plain-text'?empty($draft['text']):empty($draft['design'])))throw new InvalidArgumentException('Add a subject and email content before sending a test.');
            $recent=(int)$this->one("SELECT COUNT(*) n FROM marketing_service_mail WHERE audience_id=? AND actor_id=? AND kind='test' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)",[$this->audienceId,$this->actorId])['n'];
            $operations=[];foreach($emails as$email)$operations[$email]=hash('sha256',$this->actorId.':'.$kind.':'.$id.':'.$version.':'.$key.':'.$email);$existing=(int)$this->one('SELECT COUNT(*) n FROM marketing_service_mail WHERE audience_id=? AND operation_key IN ('.implode(',',array_fill(0,count($operations),'?')).')',[$this->audienceId,...array_values($operations)])['n'];
            if($recent+count($emails)-$existing>50)throw new InvalidArgumentException('Wait before sending more tests. Each editor can queue 50 test recipients per hour.');
            foreach($emails as$email){$operation=$operations[$email];$snapshot=['ses'=>audienceSesBinding($this->sesSettings()),'draft'=>$draft,'type'=>$row['campaign_type'],'base'=>audienceDispatchBase($base),'identity'=>$this->designRecipientContext(0,$audience),'asset_key'=>$operation,'test'=>true];$context=['EMAIL'=>$email,'FNAME'=>'Test','LNAME'=>'Recipient'];foreach($this->fields()as$f)$context[$f['merge_tag']]=$f['default_value'];$message=audienceDispatchMessage($snapshot,$context,str_repeat('0',64),'<test-'.substr($operation,0,40).'@message-monkey.invalid>');$message['subject']='[Test] '.$message['subject'];$message['text']=audienceText($input,'test_instructions',1000)."\n\n".$message['text'];$payload=['snapshot'=>$snapshot,'message'=>$message];
                $this->write("INSERT IGNORE INTO marketing_service_mail(audience_id,kind,actor_id,actor_label,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (?,'test',?,?,?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))",[$this->audienceId,$this->actorId,$this->actorLabel,$operation,\Dnr\Security\ApplicationKey::seal(json_encode($payload,JSON_THROW_ON_ERROR)),$message['message_id']]);
            }return count($emails);
        });
    }

    public function campaignRuntimeOperation(int $id,int $version,string $operation,string $winner=''):void
    {
        if($operation==='winner')throw new InvalidArgumentException('A/B testing is not available in MessageMonkey.');
        require_once __DIR__.'/audience_delivery_lifecycle.php';
        $lock=audienceDispatchLock($this->db,$this->audienceId,$id);
        try{
            $this->campaign($id);$job=$this->campaignDelivery($id);
            if(!$job||(int)$job['version']!==$version)throw new InvalidArgumentException('Delivery changed. Reload before continuing.');
            if($operation==='winner'){
                if(!in_array($job['state'],['scheduled','sending','completed'],true)||!$job['experiment_due_at']||$job['winner_key']!==null)throw new InvalidArgumentException('This test cannot select a winner now.');
                if((int)$this->one("SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE delivery_id=? AND phase='test' AND state IN ('queued','processing')",[$job['id']])['n'])throw new InvalidArgumentException('Wait until every test message has finished processing.');
                audienceChooseWinner($this->db,(int)$job['id'],$winner);
            }elseif($operation==='cancel-resend'){
                if(!$job['resend_due_at']||$job['resend_created_at'])throw new InvalidArgumentException('The automatic resend has already started or was cancelled.');
                $this->write('UPDATE marketing_campaign_deliveries SET resend_created_at=UTC_TIMESTAMP(),version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$job['id']]);
            }else throw new InvalidArgumentException('Unknown delivery operation.');
        }finally{$this->db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function deliveryReport(int $delivery,bool $testOnly=false):array
    {
        $phase=$testOnly?" AND r.phase='test'":"";
        $columns=audienceEngagementColumns();
        $metrics=['opens','clicks','total_opens','total_clicks','delivered','bounced','failed','complaints','receipt','detected_bot','unsubscribed','delayed','rejected'];
        $sums=implode(',',array_map(static fn($key)=>"SUM(IF(state='accepted',`$key`,0)) `$key`",$metrics));
        return $this->all("SELECT variant_key,COUNT(*) messages,SUM(state='accepted') accepted,$sums FROM (
            SELECT r.id,r.variant_key,r.state,$columns,
            COALESCE(MAX(e.event_type='delay' AND e.source='provider'),0) AS `delayed`,
            EXISTS(SELECT 1 FROM marketing_contact_events ce WHERE ce.contact_id=r.contact_id AND ce.event_type='status' AND JSON_UNQUOTE(JSON_EXTRACT(ce.details_json,'$.to'))='unsubscribed' AND JSON_UNQUOTE(JSON_EXTRACT(ce.details_json,'$.recipient_id'))=CAST(r.id AS CHAR)) unsubscribed
            FROM marketing_campaign_recipients r LEFT JOIN marketing_message_events e ON e.recipient_id=r.id AND e.audience_id=r.audience_id AND e.event_at<=UTC_TIMESTAMP()
            WHERE r.audience_id=? AND r.delivery_id=? $phase GROUP BY r.id
        ) observations GROUP BY variant_key ORDER BY variant_key",[$this->audienceId,$delivery]);
    }
    public function requestSubscriptionConfirmation(int $personId,string $base,array $collection=[]):void
    {
        $this->transaction(function()use($personId,$base,$collection){
            $ses=$this->sesSettings();if(audienceSesReasons($ses,$ses['from_email']??''))throw new InvalidArgumentException('Configure SES in Email delivery before requesting a confirmation.');
            $person=$this->person($personId,true);if($person['is_archived']||$person['email_status']==='cleaned')throw new InvalidArgumentException('This address is not eligible for a subscription confirmation.');
            $recent=(int)$this->one('SELECT COUNT(*) n FROM marketing_consent_challenges WHERE audience_id=? AND contact_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR)',[$this->audienceId,$personId])['n'];if($recent>=3)throw new InvalidArgumentException('A confirmation has already been requested. Check your inbox or try again tomorrow.');
            $last=$this->one('SELECT created_at FROM marketing_consent_challenges WHERE audience_id=? AND contact_id=? ORDER BY id DESC LIMIT 1',[$this->audienceId,$personId]);if($last&&strtotime($last['created_at'].' UTC')>time()-60)throw new InvalidArgumentException('Wait one minute before requesting another confirmation.');
            $token=bin2hex(random_bytes(32));$operation=hash('sha256',$token);$url=audienceDispatchBase($base).'/audience_subscription.php?confirm='.$token;$message=['email'=>$person['email'],'subject'=>'Confirm your email subscription','text'=>"Confirm that you want to receive emails from this audience:\n\n".$url."\n\nThis link expires in 24 hours. Nothing changes until you confirm. If you did not request this, ignore this email.",'html'=>null,'from_name'=>'MOED MessageMonkey','from_email'=>$ses['from_email'],'ses_binding'=>audienceSesBinding($ses),'to_name'=>'','message_id'=>'<confirm-'.substr($operation,0,40).'@message-monkey.invalid>'];
            $this->write("INSERT INTO marketing_service_mail(audience_id,contact_id,kind,operation_key,payload_ciphertext,message_id,next_attempt_at,expires_at) VALUES (?,?,'confirmation',?,?,?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))",[$this->audienceId,$personId,$operation,\Dnr\Security\ApplicationKey::seal(json_encode(['message'=>$message],JSON_THROW_ON_ERROR)),$message['message_id']]);$mail=(int)$this->db->insert_id;
            $this->write('UPDATE marketing_consent_challenges SET used_at=UTC_TIMESTAMP() WHERE audience_id=? AND contact_id=? AND used_at IS NULL',[$this->audienceId,$personId]);
            $this->write('INSERT INTO marketing_consent_challenges(audience_id,contact_id,email_hash,token_hash,service_mail_id,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))',[$this->audienceId,$personId,audienceDispatchEmailHash($person['email']),hash('sha256',$token),$mail]);
            $challengeId=(int)$this->db->insert_id;
            // Mail payloads are erased after delivery; retain only the scoped attribution IDs.
            if($collection){$context=$this->collectionConversionContext($collection)+['challenge_id'=>$challengeId];$this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Signup form','recipient','collection-confirmation',?)",[$personId,json_encode($context,JSON_THROW_ON_ERROR)]);}
        });
    }
    public function confirmSubscription(string $token):bool
    {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))return false;
        return $this->transaction(function()use($token){
            $challenge=$this->one("SELECT q.*,m.state mail_state FROM marketing_consent_challenges q JOIN marketing_service_mail m ON m.id=q.service_mail_id AND m.audience_id=q.audience_id AND m.contact_id=q.contact_id WHERE q.audience_id=? AND q.token_hash=? FOR UPDATE",[$this->audienceId,hash('sha256',$token)]);
            if(!$challenge||$challenge['used_at']||strtotime($challenge['expires_at'].' UTC')<=time()||$challenge['mail_state']!=='accepted')return false;
            $person=$this->person((int)$challenge['contact_id'],true);if($person['is_archived']||$person['email_status']==='cleaned'||!hash_equals($challenge['email_hash'],audienceDispatchEmailHash($person['email'])))return false;
            if($this->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=?',[$this->audienceId,$challenge['email_hash']]))return false;
            $this->write("UPDATE marketing_contacts SET email_status='subscribed',version=version+1 WHERE audience_id=? AND id=?",[$this->audienceId,$person['id']]);$this->write('DELETE FROM marketing_email_optouts WHERE audience_id=? AND email_hash=?',[$this->audienceId,$challenge['email_hash']]);$this->write('UPDATE marketing_consent_challenges SET used_at=UTC_TIMESTAMP() WHERE id=?',[$challenge['id']]);
            $this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Email recipient','recipient','subscription-confirmed',?)",[$person['id'],json_encode(['from'=>$person['email_status'],'to'=>'subscribed','source'=>'Delivered single-use confirmation'],JSON_THROW_ON_ERROR)]);
            $attribution=$this->one("SELECT details_json FROM marketing_contact_events WHERE contact_id=? AND event_type='collection-confirmation' AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.challenge_id'))=? ORDER BY id DESC LIMIT 1",[$person['id'],(string)$challenge['id']]);
            if($person['email_status']!=='subscribed'&&$attribution&&$this->collectionAvailable())$this->recordCollectionConversion((int)$person['id'],audienceJson($attribution['details_json']),'double');
            return true;
        });
    }
    public function savePublicPreferences(int $personId,int $version,array $input,?array $scope=null):void
    {
        $this->transaction(function()use($personId,$version,$input,$scope){
            $person=$this->person($personId,true);if($person['is_archived'])throw new InvalidArgumentException('These preferences are unavailable.');if((int)$person['version']!==$version)throw new InvalidArgumentException('Your preferences changed. Reload before saving.');
            $custom=$person['custom'];$values=is_array($input['custom']??null)?$input['custom']:[];foreach($this->fields()as$f)if($f['is_public']&&($scope===null||in_array('custom:'.$f['id'],$scope['fields'],true)))$custom[$f['id']]=audienceFieldValue($f,$values[$f['id']]??'');
            $public=[];$groups=[];foreach($this->groups()as$g)if($g['display_type']!=='hidden'&&($scope===null||in_array((int)$g['id'],$scope['group_ids'],true))){foreach($g['options']as$o)$public[]=(int)$o['id'];$groups[]=$g;}
            $chosen=audienceIds([...(array)($input['group_ids']??[]),...array_filter((array)($input['group_pick']??[]))]);if(array_diff($chosen,$public))throw new InvalidArgumentException('Choose valid interests.');
            foreach($groups as$g)if(in_array($g['display_type'],['radio','dropdown'],true)&&count(array_intersect($chosen,array_column($g['options'],'id')))>1)throw new InvalidArgumentException('Choose one interest for '.$g['name'].'.');
            if($scope!==null){foreach(['first_name','last_name']as$key)if(!in_array($key,$scope['fields'],true))$input[$key]=$person[$key];$input['timezone']=$person['timezone'];}
            $zone=audienceText($input,'timezone',80);if($zone!==''&&!in_array($zone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Choose a valid time zone.');
            $this->write('UPDATE marketing_contacts SET first_name=?,last_name=?,custom_json=?,timezone=?,version=version+1 WHERE audience_id=? AND id=?',[audienceText($input,'first_name',100),audienceText($input,'last_name',100),json_encode((object)$custom,JSON_THROW_ON_ERROR),$zone,$this->audienceId,$personId]);
            foreach($public as$option)$this->write('DELETE FROM marketing_contact_groups WHERE contact_id=? AND option_id=?',[$personId,$option]);foreach($chosen as$option)$this->write('INSERT INTO marketing_contact_groups(contact_id,option_id) VALUES (?,?)',[$personId,$option]);
            $changed=[];foreach(['first_name','last_name']as$key)if($person[$key]!==audienceText($input,$key,100))$changed[]=$key;if($person['timezone']!==$zone)$changed[]='timezone';foreach($custom as$key=>$value)if(($person['custom'][$key]??'')!==$value)$changed[]=(string)$key;
            $this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Email recipient','recipient','preferences',?)",[$personId,json_encode(['source'=>'Private email preferences','changed_fields'=>$changed],JSON_THROW_ON_ERROR)]);
            $beforeGroups=$person['group_ids'];$afterGroups=$this->person($personId)['group_ids'];if($beforeGroups!==$afterGroups)$this->event($personId,'groups_changed',['added'=>array_values(array_diff($afterGroups,$beforeGroups)),'removed'=>array_values(array_diff($beforeGroups,$afterGroups))]);
        });
    }
    public function recordDeliveryEvent(int $recipientId,string $type,string $key,string $source,string $at,array $details=[]):bool
    {
        if(!in_array($type,['open','click','delivered','soft-bounce','hard-bounce','complaint','send','delay','rejected'],true)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!in_array($source,['browser','provider'],true))throw new InvalidArgumentException('Invalid delivery event.');
        return $this->transaction(function()use($recipientId,$type,$key,$source,$at,$details){
            $recipient=$this->one('SELECT * FROM marketing_campaign_recipients WHERE audience_id=? AND id=? FOR UPDATE',[$this->audienceId,$recipientId]);if(!$recipient)throw new InvalidArgumentException('This recipient is unavailable.');
            if($source==='browser'&&!in_array($type,['open','click'],true))throw new InvalidArgumentException('Only a verified provider can record delivery or suppression events.');
            $this->write('INSERT IGNORE INTO marketing_message_events(audience_id,recipient_id,event_type,event_key,source,event_at,details_json) VALUES (?,?,?,?,?,?,?)',[$this->audienceId,$recipientId,$type,$key,$source,$at,json_encode($details,JSON_THROW_ON_ERROR)]);$inserted=(int)$this->one('SELECT ROW_COUNT() n')['n']===1;if(!$inserted)return false;
            if(in_array($type,['hard-bounce','complaint'],true)){
                $person=$this->one('SELECT id,email,email_status FROM marketing_contacts WHERE audience_id=? AND id=? AND deleted_at IS NULL FOR UPDATE',[$this->audienceId,$recipient['contact_id']]);
                $confirmed=$person?$this->one("SELECT created_at FROM marketing_contact_events WHERE contact_id=? AND event_type='subscription-confirmed' ORDER BY id DESC LIMIT 1",[$person['id']]):null;
                if($person&&hash_equals($recipient['email_hash'],audienceDispatchEmailHash($person['email']))&&(!$confirmed||new DateTimeImmutable($confirmed['created_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable($at,new DateTimeZone('UTC')))){$status=$type==='hard-bounce'?'cleaned':'unsubscribed';if($person['email_status']==='cleaned')$status='cleaned';$this->write('UPDATE marketing_contacts SET email_status=?,version=version+1 WHERE audience_id=? AND id=?',[$status,$this->audienceId,$person['id']]);if($type==='complaint')$this->write('INSERT IGNORE INTO marketing_email_optouts(audience_id,email_hash) VALUES (?,?)',[$this->audienceId,$recipient['email_hash']]);$this->write("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Authenticated event source','provider','status',?)",[$person['id'],json_encode(['from'=>$person['email_status'],'to'=>$status,'source'=>$type],JSON_THROW_ON_ERROR)]);}
            }return true;
        });
    }
}
