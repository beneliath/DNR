<?php
declare(strict_types=1);

function audienceDispatchEmailHash(string $email):string { return hash('sha256',strtolower(trim($email))); }

function audienceDispatchBase(string $base):string
{
    $base=rtrim($base,'/');$host=strtolower((string)parse_url($base,PHP_URL_HOST));
    if(!filter_var($base,FILTER_VALIDATE_URL)||parse_url($base,PHP_URL_SCHEME)!=='https'||!$host||!str_contains($host,'.')||$host==='localhost'||str_ends_with($host,'.localhost')||filter_var($host,FILTER_VALIDATE_IP)||parse_url($base,PHP_URL_USER)!==null||parse_url($base,PHP_URL_PASS)!==null||parse_url($base,PHP_URL_QUERY)!==null||parse_url($base,PHP_URL_FRAGMENT)!==null)throw new InvalidArgumentException('Configure this account’s public HTTPS address before sending. Subscription links must be reachable by recipients.');
    return $base;
}

function audienceDispatchDue(array $send,?int $now=null):string
{
    $now??=time();$mode=$send['mode']??'';
    if(!in_array($mode,['now','schedule'],true)||($mode==='schedule'&&!in_array($send['delivery']??'specific',['specific','optimized','timewarp'],true)))throw new InvalidArgumentException('Choose Send now or Schedule. Tag-triggered delivery belongs to Automations.');
    if($mode==='now')return gmdate('Y-m-d H:i:s',$now);
    $zone=$send['timezone']??'';$local=$send['local']??'';
    if(!in_array($zone,DateTimeZone::listIdentifiers(),true)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/D',$local))throw new InvalidArgumentException('Choose a valid delivery date, time and time zone.');
    $date=new DateTimeImmutable($local,new DateTimeZone($zone));
    if($date->format('Y-m-d H:i')!==$local)throw new InvalidArgumentException('That local time does not exist because of daylight saving time.');
    // A repeated fall-back hour must be resolved by choosing an unambiguous time.
    $transitions=(new DateTimeZone($zone))->getTransitions($date->getTimestamp()-86400,$date->getTimestamp()+86400);$previous=null;
    foreach($transitions?:[]as$t){if($previous!==null&&$previous>$t['offset']){$wall=$t['ts']+$t['offset'];$localEpoch=strtotime($local.' UTC');if($localEpoch>=$wall&&$localEpoch<$wall+$previous-$t['offset'])throw new InvalidArgumentException('That local time occurs twice because of daylight saving time. Choose a time outside the repeated hour.');}$previous=$t['offset'];}
    if($date->getTimestamp()<$now+300)throw new InvalidArgumentException('Schedule your email at least 5 minutes in the future.');
    if($date->getTimestamp()>$now+366*86400)throw new InvalidArgumentException('Schedule your email within the next year.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

/** Every recipient gets an identity/address/footer, including custom plain text. */
function audienceDispatchMessage(array $snapshot,array $context,string $token,string $messageId,string $variantKey='0'):array
{
    $draft=$snapshot['draft'];if(isset($snapshot['variants'][(int)$variantKey])){$v=$snapshot['variants'][(int)$variantKey];$draft['subject']=$v['subject'];$draft['from_name']=$v['from_name'];if($v['design']!==null)$draft['design']=$v['design'];}if(!empty($snapshot['is_resend'])&&!empty($draft['resend']['subject']))$draft['subject']=$draft['resend']['subject'];$base=audienceDispatchBase($snapshot['base']);
    $link=!empty($snapshot['test'])?$base.'/audience_subscription.php?test=1':$base.'/audience_subscription.php?token='.$token;
    $context=array_replace($snapshot['identity'],$context,['UNSUB'=>$link,'UPDATE_PROFILE'=>$link.'&preferences=1','ARCHIVE'=>$link.'&email=1']);
    $subject=audienceDesignMerge((string)$draft['subject'],$context,false);
    $html=null;
    if($snapshot['type']==='plain-text')$text=audienceDesignMerge((string)$draft['text'],$context,false);
    else{$html=audienceDesignHtml($draft['design'],$context);$text=audienceDesignPlainText($draft['design'],$context);}
    if(function_exists('audienceCollectionMerge')){
        $text=audienceCollectionMerge($text,$snapshot,$token,false);
        if($html!==null)$html=audienceCollectionMerge($html,$snapshot,$token,true);
    }
    $reminder=audiencePermissionReminder($context);
    $footer="\n\n".($reminder!==''&&!audienceHasPermissionReminder($text,$reminder,false)?$reminder."\n\n":'').$context['LIST:COMPANY']."\n".$context['LIST:ADDRESS']."\nUpdate your preferences: ".$context['UPDATE_PROFILE']."\nUnsubscribe: ".$context['UNSUB'];
    $text.=$footer;
    if($html!==null&&(!str_contains($html,htmlspecialchars($link,ENT_QUOTES,'UTF-8'))||!str_contains($html,htmlspecialchars($context['LIST:ADDRESS'],ENT_QUOTES,'UTF-8'))))$html.='<div style="padding:24px;font-size:12px;text-align:center">'.nl2br(htmlspecialchars($footer,ENT_QUOTES,'UTF-8')).'</div>';
    if(preg_match('/\*\|[^|\r\n]{1,300}\|\*/',$subject.$text.($html??'')))throw new InvalidArgumentException('Resolve all merge tags before sending this email.');
    if(trim($subject)===''||preg_match('/[\r\n]/',$subject.$draft['from_name'].$draft['from_email']))throw new InvalidArgumentException('The email subject or sender contains invalid characters.');
    if($html!==null&&!empty($snapshot['asset_key']))$html=preg_replace_callback('~data:image/(?:png|jpeg|webp|gif|svg\+xml);base64,[A-Za-z0-9+/=]+~',static fn($m)=>$base.'/audience_email_asset.php?key='.$snapshot['asset_key'].'&amp;image='.hash('sha256',$m[0]),$html);
    if(($context['__email_format']??'html')==='text')$html=null;
    if(function_exists('audienceTrackMessage')){[$tracked,$text]=audienceTrackMessage($snapshot,$html??'',$text,$token);if($html!==null)$html=$tracked;}
    // Measure the delivered bodies, after image hosting and tracking expansion.
    if(strlen($text)+strlen($html??'')>AUDIENCE_DESIGN_MAX_BYTES)throw new InvalidArgumentException('Keep the rendered email below 10 MB.');
    $toName=!empty($draft['personalize_to'])?trim((string)($context[$draft['to_merge']??'FNAME']??'')):'';
    if(preg_match('/[\r\n]/',$toName))throw new InvalidArgumentException('The personalized To name contains invalid characters.');
    return ['email'=>$context['EMAIL'],'subject'=>$subject,'text'=>$text,'html'=>$html,'from_name'=>$draft['from_name'],'from_email'=>$draft['from_email'],'to_name'=>$toName,'message_id'=>$messageId,'unsubscribe'=>$link,'reply_to'=>$snapshot['reply_to']??''];
}

function audienceDispatchLock(mysqli $db,int $audience,int $campaign):string
{
    $name='mm-dispatch-'.$audience.'-'.$campaign;
    if((int)$db->execute_query('SELECT GET_LOCK(?,5) acquired',[$name])->fetch_assoc()['acquired']!==1)throw new InvalidArgumentException('Delivery is busy. Wait a moment and try again.');
    return $name;
}

trait AudienceDispatch
{
    public function campaignDispatchAvailable():bool
    {
        return (int)$this->one("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_campaign_deliveries'")['n']===1;
    }
    private function dispatchCandidates(array $draft):array
    {
        if(empty($draft['recipients_saved']))throw new InvalidArgumentException('Save your recipients before sending.');
        [$where,$params]=$this->campaignTarget($draft);
        return $this->all("SELECT c.id,c.email,c.version,c.first_name,c.last_name,c.source,c.custom_json,c.timezone FROM marketing_contacts c WHERE $where AND NOT EXISTS(SELECT 1 FROM marketing_email_optouts o WHERE o.audience_id=c.audience_id AND o.email_hash=SHA2(LOWER(TRIM(c.email)),256)) AND NOT EXISTS(SELECT 1 FROM marketing_deleted_emails x WHERE x.audience_id=c.audience_id AND x.email_hash=SHA2(LOWER(TRIM(c.email)),256)) ORDER BY c.id LIMIT 100001",$params);
    }
    private function dispatchSnapshot(array $row,array $audience,string $base):array
    {
        $draft=$row['draft'];
        if(!empty($draft['experiment'])||!empty($draft['design_variants']))throw new InvalidArgumentException('A/B testing is not available in MessageMonkey.');
        if(!$this->deliveryRuntimeAvailable())throw new InvalidArgumentException('The delivery upgrade is being qualified. Try again after installation.');
        if($row['status']!=='draft')throw new InvalidArgumentException('This campaign has already been confirmed. View its delivery status.');
        if(!$this->sesAvailable())throw new InvalidArgumentException('Install and configure this account’s SES connection before sending.');
        if(!empty($draft['resend']['enabled'])&&in_array($draft['resend']['segment']??'',['non-openers','non-clickers'],true)&&empty($draft['tracking'][($draft['resend']['segment']??'')==='non-clickers'?'clicks':'opens']))throw new InvalidArgumentException('Enable the corresponding open or click tracking before scheduling an automatic resend.');
        if($row['campaign_type']==='plain-text'&&!empty($draft['resend']['enabled'])&&($draft['resend']['segment']??'')==='non-openers')throw new InvalidArgumentException('Plain-text emails cannot measure opens. Choose Did not click for the resend audience.');
        $winner=$draft['experiment']['winner']??'manual';if(in_array($winner,['opens','clicks'],true)&&empty($draft['tracking'][$winner==='opens'?'opens':'clicks']))throw new InvalidArgumentException('Enable the corresponding tracking setting before using an automatic test winner.');
        if(($draft['send_time']['delivery']??'')==='optimized'){
            $evidence=$this->analyticsTimingEvidence();
            $draft['send_time']['optimization_basis']='Not enough observed click history; using your selected time.';
            if($evidence['ready']){$chosen=new DateTimeImmutable(substr($draft['send_time']['utc'],0,10).' '.sprintf('%02d:00',$evidence['best_hour']),new DateTimeZone('UTC'));$local=$chosen->setTimezone(new DateTimeZone($draft['send_time']['timezone']));$draft['send_time']['local']=$local->format('Y-m-d H:i');$draft['send_time']['utc']=$chosen->format('Y-m-d H:i:s');$draft['send_time']['optimization_basis']='Selected from observed click hours for at least 100 distinct recipients; not a guarantee of engagement.';}
        }
        if(($draft['send_time']['delivery']??'')==='timewarp')audienceRecipientDue($draft['send_time'],$draft['send_time']['timezone']);
        if(empty($draft['subject'])||empty($draft['from_email'])||empty($draft['from_name'])||($row['campaign_type']==='plain-text'?empty($draft['text']):empty($draft['design'])))throw new InvalidArgumentException('Complete To, From, Subject, Send time and Content before sending.');
        audienceDispatchDue($draft['send_time']??[]);
        $settings=audienceJson($audience['settings_json']);$ready=$this->sendingReadiness($draft['from_email'],$settings);
        if(!$ready['ready'])throw new InvalidArgumentException(implode(' ',$ready['reasons']));
        $collectionLinks=[];
        if($this->collectionAvailable())foreach($this->all("SELECT public_key,kind FROM marketing_collection_resources WHERE audience_id=? AND kind IN ('survey','preferences') AND state='published' AND deleted_at IS NULL",[$this->audienceId])as$r)$collectionLinks[$r['public_key']]=$r['kind'];
        return ['reply_to'=>$this->inboxCampaignReplyTo($draft),'ses'=>audienceSesBinding($this->sesSettings()),'collection_links'=>$collectionLinks,'variants'=>audienceExperimentVariants($draft),'draft'=>$draft,'type'=>$row['campaign_type'],'identity'=>$this->designRecipientContext(0,$audience),'base'=>audienceDispatchBase($base)];
    }
    /** Proof binds the visible review to content, sender settings and exact recipient identities. */
    public function reviewCampaignDispatch(int $id,array $audience,string $base):array
    {
        $row=$this->campaign($id);$snapshot=$this->dispatchSnapshot($row,$audience,$base);$candidates=$this->dispatchCandidates($row['draft']);
        if(!$candidates||count($candidates)>100000)throw new InvalidArgumentException('Choose between 1 and 100,000 eligible subscribers.');
        // Render before confirmation to surface unresolved tags and malformed headers.
        $first=$this->designRecipientContext((int)$candidates[0]['id'],$audience);
        audienceDispatchMessage($snapshot+['asset_key'=>str_repeat('0',64)],$first,str_repeat('0',64),'<review@message-monkey.invalid>');
        $fingerprint=hash('sha256',json_encode([$snapshot,$candidates,$this->fields(),$this->tags(),$this->groups()],JSON_THROW_ON_ERROR));
        $proof=\Dnr\Security\ApplicationKey::seal(json_encode(['audience'=>$this->audienceId,'campaign'=>$id,'version'=>(int)$row['version'],'actor'=>$this->actorId,'expires'=>time()+900,'fingerprint'=>$fingerprint],JSON_THROW_ON_ERROR));
        return ['proof'=>$proof,'count'=>count($candidates),'due'=>audienceDispatchDue($snapshot['draft']['send_time']),'snapshot'=>$snapshot,'fingerprint'=>$fingerprint];
    }
    public function confirmCampaignDispatch(int $id,int $version,string $proof,array $audience,string $base):int
    {
        try{$receipt=json_decode(\Dnr\Security\ApplicationKey::open($proof),true,8,JSON_THROW_ON_ERROR);}catch(Throwable){throw new InvalidArgumentException('Review this email again before confirming delivery.');}
        if(($receipt['audience']??0)!==$this->audienceId||($receipt['campaign']??0)!==$id||($receipt['version']??0)!==$version||($receipt['actor']??0)!==$this->actorId||($receipt['expires']??0)<time())throw new InvalidArgumentException('This review expired or changed. Review this email again.');
        $lock=audienceDispatchLock($this->db,$this->audienceId,$id);
        try{return $this->transaction(function()use($id,$version,$receipt,$base){
            $row=$this->campaign($id,true);
            // A repeated confirmed request returns the same durable operation.
            $prior=$this->one('SELECT id FROM marketing_campaign_deliveries WHERE audience_id=? AND campaign_id=? AND confirmed_version=? AND flow_run_id=0',[$this->audienceId,$id,$version]);if($prior)return (int)$prior['id'];
            if((int)$row['version']!==$version)throw new InvalidArgumentException('This campaign changed. Review it again before sending.');
            $freshAudience=$this->one('SELECT * FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);
            $review=$this->reviewCampaignDispatch($id,$freshAudience,$base);
            if(!hash_equals((string)($receipt['fingerprint']??''),$review['fingerprint']))throw new InvalidArgumentException('Your recipients or sender settings changed. Review the updated email before sending.');
            $snapshot=$review['snapshot'];$snapshot['asset_key']=bin2hex(random_bytes(32));$snapshot['tracking_key']=bin2hex(random_bytes(32));$draft=$snapshot['draft'];if(!empty($draft['resend']['enabled'])&&!in_array($draft['resend']['segment'],['non-openers','non-clickers'],true)){[$resendWhere,$resendParams]=$this->campaignTarget(['recipients_saved'=>true,'include'=>[$draft['resend']['segment']],'exclude'=>[]]);$snapshot['resend_allowed_ids']=array_map('intval',array_column($this->all("SELECT c.id FROM marketing_contacts c WHERE $resendWhere",$resendParams),'id'));}$due=$review['due'];$state=$draft['send_time']['mode']==='schedule'?'scheduled':'sending';
            $this->write('INSERT INTO marketing_campaign_deliveries(audience_id,campaign_id,confirmed_version,state,due_at,timezone,snapshot_ciphertext,asset_key,recipient_count,actor_id,actor_label) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$this->audienceId,$id,$version,$state,$due,$draft['send_time']['timezone']??'America/Chicago',\Dnr\Security\ApplicationKey::seal(json_encode($snapshot,JSON_THROW_ON_ERROR)),$snapshot['asset_key'],$review['count'],$this->actorId,$this->actorLabel]);$delivery=(int)$this->db->insert_id;
            $candidates=$this->dispatchCandidates($draft);$assignments=[];$decision=$due;
            if(!empty($draft['experiment'])){$ranked=$candidates;usort($ranked,static fn($a,$b)=>strcmp(hash('sha256',$id.':'.$a['id']),hash('sha256',$id.':'.$b['id'])));$n=count($snapshot['variants']);if(count($ranked)<$n)throw new InvalidArgumentException('Choose at least one recipient for each test combination.');$testSize=max($n,(int)ceil(count($ranked)*(int)$draft['experiment']['percent']/100));foreach($ranked as$i=>$r)$assignments[$r['id']]=[$i<$testSize?(string)($i%$n):'0',$i<$testSize?'test':'hold'];$decision=gmdate('Y-m-d H:i:s',strtotime($due.' UTC')+(int)$draft['experiment']['hours']*3600);$this->write('UPDATE marketing_campaign_deliveries SET experiment_due_at=? WHERE id=?',[$decision,$delivery]);}
            if(!empty($draft['resend']['enabled'])){$zone=new DateTimeZone($draft['send_time']['timezone']??'America/Chicago');$resend=(new DateTimeImmutable($due,new DateTimeZone('UTC')))->setTimezone($zone)->modify('+'.(int)$draft['resend']['days'].' days');$resend=new DateTimeImmutable($resend->format('Y-m-d').' '.$draft['resend']['time'],$zone);$this->write('UPDATE marketing_campaign_deliveries SET resend_due_at=? WHERE id=?',[$resend->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),$delivery]);}
            $fields=$this->fields();$tagIds=array_column($this->tags(),'id');$groupIds=[];foreach($this->groups()as$g)foreach($g['options']as$o)$groupIds[]=$o['id'];
            $memberships=[];foreach($this->all('SELECT ct.contact_id,ct.tag_id FROM marketing_contact_tags ct JOIN marketing_tags t ON t.id=ct.tag_id WHERE t.audience_id=?',[$this->audienceId])as$m)$memberships[$m['contact_id']]['tag:'.$m['tag_id']]='1';
            foreach($this->all('SELECT cg.contact_id,cg.option_id FROM marketing_contact_groups cg JOIN marketing_group_options o ON o.id=cg.option_id JOIN marketing_group_categories g ON g.id=o.category_id WHERE g.audience_id=?',[$this->audienceId])as$m)$memberships[$m['contact_id']]['group:'.$m['option_id']]='1';
            foreach($candidates as$candidate){
                $person=$this->one('SELECT id,email,first_name,last_name,source,email_status,custom_json,timezone FROM marketing_contacts WHERE audience_id=? AND id=?',[$this->audienceId,$candidate['id']]);$custom=audienceJson($person['custom_json']);
                $context=['EMAIL'=>$person['email'],'FNAME'=>$person['first_name'],'LNAME'=>$person['last_name'],'SOURCE'=>$person['source'],'EMAIL_STATUS'=>$person['email_status']];
                foreach($fields as$f){$value=$custom[$f['id']]??'';if($value===''||$value===[])$value=$f['default_value'];$context[$f['merge_tag']]=is_array($value)?implode(', ',$value):(string)$value;}
                foreach($tagIds as$t)$context['tag:'.$t]=$memberships[$person['id']]['tag:'.$t]??'';foreach($groupIds as$g)$context['group:'.$g]=$memberships[$person['id']]['group:'.$g]??'';
                $context['__email_format']=$this->collectionEmailFormat((int)$person['id']);
                $token=bin2hex(random_bytes(32));$mid='<mm-'.bin2hex(random_bytes(24)).'@'.parse_url($base,PHP_URL_HOST).'>';
                [$variantKey,$phase]=$assignments[$person['id']]??['0','main'];$send=$draft['send_time'];$variant=$snapshot['variants'][(int)$variantKey]??[];if(!empty($variant['local'])){$send['local']=str_replace('T',' ',$variant['local']);$send['mode']='schedule';$send['delivery']='specific';}
                $recipientDue=audienceRecipientDue($send,$person['timezone']);if($phase==='hold')$recipientDue=$decision;
                audienceDispatchMessage($snapshot,$context,$token,$mid,$variantKey);
                $this->write('INSERT INTO marketing_campaign_recipients(audience_id,delivery_id,contact_id,email_hash,context_ciphertext,token_hash,message_id,next_attempt_at,variant_key,phase) VALUES (?,?,?,?,?,?,?,?,?,?)',[$this->audienceId,$delivery,$person['id'],audienceDispatchEmailHash($person['email']),\Dnr\Security\ApplicationKey::seal(json_encode(['context'=>$context,'token'=>$token],JSON_THROW_ON_ERROR)),hash('sha256',$token),$mid,$recipientDue,$variantKey,$phase]);
            }
            $this->write('UPDATE marketing_campaign_deliveries SET due_at=(SELECT MIN(next_attempt_at) FROM marketing_campaign_recipients WHERE delivery_id=?) WHERE id=?',[$delivery,$delivery]);
            if(!empty($draft['experiment']))$this->write("UPDATE marketing_campaign_deliveries SET experiment_due_at=DATE_ADD((SELECT MAX(next_attempt_at) FROM marketing_campaign_recipients WHERE delivery_id=? AND phase='test'),INTERVAL ? HOUR) WHERE id=?",[$delivery,(int)$draft['experiment']['hours'],$delivery]);
            $this->write('UPDATE marketing_campaigns SET status=?,version=version+1,actor_label=? WHERE audience_id=? AND id=?',[$state,$this->actorLabel,$this->audienceId,$id]);
            return $delivery;
        });}finally{$this->db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function campaignDelivery(int $campaign):?array
    {
        return $this->one('SELECT * FROM marketing_campaign_deliveries WHERE audience_id=? AND campaign_id=? AND flow_run_id=0 ORDER BY id DESC LIMIT 1',[$this->audienceId,$campaign]);
    }
    public function campaignDeliveryCounts(int $delivery):array
    {
        return array_column($this->all('SELECT state,COUNT(*) n FROM marketing_campaign_recipients WHERE audience_id=? AND delivery_id=? GROUP BY state',[$this->audienceId,$delivery]),'n','state');
    }
    public function campaignDeliveryOperation(int $id,int $version,string $operation):void
    {
        $lock=audienceDispatchLock($this->db,$this->audienceId,$id);
        try{$this->transaction(function()use($id,$version,$operation){
            $row=$this->campaign($id,true);$job=$this->campaignDelivery($id);
            if(!$job||(int)$job['version']!==$version)throw new InvalidArgumentException('Delivery changed. Reload before continuing.');
            if(!in_array($job['state'],['scheduled','sending','paused'],true))throw new InvalidArgumentException('This delivery is already finished.');
            $state=match($operation){'pause'=>'paused','resume'=>$job['due_at']>gmdate('Y-m-d H:i:s')?'scheduled':'sending','cancel'=>'cancelled',default=>throw new InvalidArgumentException('Unknown delivery operation.')};
            if($operation==='resume'&&$job['state']!=='paused')throw new InvalidArgumentException('Only paused deliveries can be resumed.');
            if($operation==='cancel')$this->write("UPDATE marketing_campaign_recipients SET state='cancelled',error_message='Cancelled by an account editor.' WHERE audience_id=? AND delivery_id=? AND state='queued'",[$this->audienceId,$job['id']]);
            $started=(int)$this->one('SELECT COUNT(*) n FROM marketing_campaign_recipients WHERE audience_id=? AND delivery_id=? AND attempts>0',[$this->audienceId,$job['id']])['n'];
            $campaignState=$state==='cancelled'?($started?'paused':'draft'):$state;
            $this->write('UPDATE marketing_campaign_deliveries SET state=?,error_message=\'\',version=version+1 WHERE audience_id=? AND id=?',[$state,$this->audienceId,$job['id']]);
            $this->write('UPDATE marketing_campaigns SET status=?,version=version+1 WHERE audience_id=? AND id=?',[$campaignState,$this->audienceId,$id]);
        });}finally{$this->db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
}
