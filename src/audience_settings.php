<?php
declare(strict_types=1);

function audienceSubscriptionChange(string $type,array $details):?string
{
    if(($details['origin']??'')==='Import')return null;
    $to=$type==='contact_added'?($details['status']??null):($details['to']??null);
    if(!in_array($type,['contact_added','subscription_changed','subscription-confirmed','status'],true)||($details['from']??null)===$to)return null;
    return match($to){'subscribed'=>'signup','unsubscribed'=>'unsubscribe',default=>null};
}

function audienceNotificationPreferences(array $input,array $users):array
{
    $allowed=array_map('strtolower',array_column($users,'email'));$p=[];
    foreach(['signup','unsubscribe','digest']as$kind){
        $raw=audienceText($input,$kind.'_emails',2000);
        $emails=array_values(array_unique(array_filter(array_map('strtolower',preg_split('/[\s,;]+/',trim($raw))))));
        if(count($emails)>5)throw new InvalidArgumentException('Choose up to five staff email addresses for each notification.');
        foreach($emails as$email)if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($email,$allowed,true))throw new InvalidArgumentException('Notification recipients must be active editors or administrators with verified email addresses in this account.');
        sort($emails,SORT_STRING);$p[$kind.'_emails']=$emails;$p['in_app_'.$kind]=($input['in_app_'.$kind]??'')==='1';
    }
    return $p;
}

/** Most recent daily email slot: 23:11 Chicago time, including daylight saving. */
function audienceDailySummaryDue(int $now):int
{
    $local=(new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('America/Chicago'));
    $due=$local->setTime(23,11);
    if($due>$local)$due=$due->modify('-1 day');
    return $due->getTimestamp();
}

trait AudienceSettings
{
    public function audienceNotificationUsers():array
    {
        return $this->all("SELECT id,email FROM users WHERE role IN ('admin','editor') AND account_status='active' AND email_verified_at IS NOT NULL AND email<>'' ORDER BY email");
    }

    public function saveAudienceDefaults(array $input,int $version):void
    {
        $name=audienceText($input,'name',100);if($name==='')throw new InvalidArgumentException('Enter an audience name.');
        $this->transaction(function()use($input,$version,$name):void{
            $row=$this->one('SELECT settings_json,version FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);
            if((int)$row['version']!==$version)throw new InvalidArgumentException('Audience settings changed. Reload before saving.');
            $s=audienceJson($row['settings_json']);
            foreach(['from_name'=>100,'from_email'=>254,'default_subject'=>200,'permission_reminder'=>1000,'mailing_address'=>1000]as$key=>$length)$s[$key]=audienceText($input,$key,$length);
            foreach(['from_name','from_email','default_subject']as$key)if(preg_match('/[\r\n]/',$s[$key]))throw new InvalidArgumentException('Sender and subject defaults must use a single line.');
            if($s['from_email']!==''&&!filter_var($s['from_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid sender email.');
            if(isset($input['notifications_present'])){
                $prefs=audienceNotificationPreferences($input,$this->audienceNotificationUsers());$old=$s['subscriber_notifications']??[];
                $oldValues=array_intersect_key($old,$prefs);$newValues=$prefs;ksort($oldValues);ksort($newValues);
                if($oldValues!==$newValues){
                    $cursor=(int)$this->one('SELECT COALESCE(MAX(e.id),0) id FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=?',[$this->audienceId])['id'];
                    // Recipient and bell edits start fresh individual alerts, but must
                    // not discard changes already observed for the daily summary.
                    $s['subscriber_notifications']=$prefs+['generation'=>bin2hex(random_bytes(16)),'start_event'=>$cursor,'start_at'=>gmdate('Y-m-d H:i:s'),'digest_start_event'=>(int)($old['digest_start_event']??$old['start_event']??$cursor)];
                }
            }
            $this->write('UPDATE marketing_audiences SET name=?,settings_json=?,version=version+1 WHERE id=?',[$name,json_encode($s,JSON_THROW_ON_ERROR),$this->audienceId]);
        });
    }

    public function subscriberNotifications():array
    {
        $s=audienceJson($this->one('SELECT settings_json FROM marketing_audiences WHERE id=?',[$this->audienceId])['settings_json']);$p=$s['subscriber_notifications']??[];
        if(empty($p['generation']))return [];
        $events=$this->all("SELECT e.id,e.contact_id,e.event_type,e.details_json,e.created_at FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=? AND c.deleted_at IS NULL AND e.id>? AND e.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) AND e.event_type IN ('contact_added','subscription_changed','subscription-confirmed','status') ORDER BY e.id DESC LIMIT 100",[$this->audienceId,$p['start_event']]);
        $result=[];foreach($events as$e){$kind=audienceSubscriptionChange($e['event_type'],audienceJson($e['details_json']));if($kind&&!empty($p['in_app_'.$kind]))$result[]=$e+['kind'=>$kind];if(count($result)>=10)break;}
        if(!empty($p['in_app_digest'])){
            $counts=audienceNotificationDigest($this->db,$this->audienceId,$p,time());
            if(array_sum($counts))array_unshift($result,['kind'=>'digest','signup'=>$counts['signup'],'unsubscribe'=>$counts['unsubscribe'],'created_at'=>gmdate('Y-m-d').' 00:00:00']);
        }
        return $result;
    }
}

/** Previous complete UTC day; no imported snapshots or fabricated historical totals. */
function audienceNotificationDigest(mysqli $db,int $audience,array $p,int $now):array
{
    $until=gmdate('Y-m-d',$now).' 00:00:00';$from=gmdate('Y-m-d',$now-86400).' 00:00:00';$counts=['signup'=>0,'unsubscribe'=>0];
    $rows=$db->execute_query("SELECT e.event_type,e.details_json,COUNT(*) n FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id WHERE c.audience_id=? AND c.deleted_at IS NULL AND e.id>? AND e.created_at>=? AND e.created_at<? AND e.event_type IN ('contact_added','subscription_changed','subscription-confirmed','status') GROUP BY e.event_type,e.details_json",[$audience,$p['digest_start_event']??$p['start_event']??0,$from,$until])->fetch_all(MYSQLI_ASSOC);
    foreach($rows as$e){$kind=audienceSubscriptionChange($e['event_type'],audienceJson($e['details_json']));if($kind)$counts[$kind]+=(int)$e['n'];}return $counts;
}
