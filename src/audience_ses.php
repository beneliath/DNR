<?php
declare(strict_types=1);
require_once __DIR__.'/audience_mail_budget.php';

const AUDIENCE_SES_REGIONS=['us-east-1','us-east-2','us-west-1','us-west-2','ca-central-1','ca-west-1','eu-west-1','eu-west-2','eu-west-3','eu-central-1','eu-central-2','eu-north-1','eu-south-1','eu-south-2','ap-south-1','ap-south-2','ap-southeast-1','ap-southeast-2','ap-southeast-3','ap-southeast-4','ap-northeast-1','ap-northeast-2','ap-northeast-3','ap-east-1','me-south-1','me-central-1','il-central-1','af-south-1','sa-east-1'];
function audienceSesInstalled(mysqli $db):bool
{
    $table=(int)$db->query("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_ses_settings'")->fetch_assoc()['n'];
    return $table===1&&(int)($db->query('SELECT runtime_version FROM marketing_ses_settings WHERE audience_id=1')->fetch_assoc()['runtime_version']??0)===2;
}
function audienceSesInput(array $input):array
{
    $region=audienceText($input,'region',40);if(!in_array($region,AUDIENCE_SES_REGIONS,true))throw new InvalidArgumentException('Choose an AWS Region supported by this connection.');
    $from=audienceSendingAddress(audienceText($input,'from_email',254));$set=audienceText($input,'configuration_set',64);$topic=audienceText($input,'topic_arn',256);
    if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$set))throw new InvalidArgumentException('Enter the SES configuration set name.');
    if(!preg_match('/^arn:aws:sns:'.preg_quote($region,'/').':([0-9]{12}):[A-Za-z0-9_-]{1,256}$/D',$topic,$m))throw new InvalidArgumentException('Enter an SNS topic ARN from the selected Region.');
    return ['region'=>$region,'from_email'=>$from,'configuration_set'=>$set,'topic_arn'=>$topic,'aws_account_id'=>$m[1]];
}
function audienceSesBinding(array $s):array
{
    return array_intersect_key($s,array_flip(['region','from_email','configuration_set','topic_arn','aws_account_id']));
}
function audienceSesReasons(array $s,string $from):array
{
    $reasons=[];
    if(empty($s['enabled'])||empty($s['access_key_tail']))$reasons[]='Configure this account’s SES API credentials in Email delivery.';
    if(($s['check_state']??'')!=='ready'||empty($s['checked_at'])||strtotime($s['checked_at'].' UTC')<time()-900)$reasons[]='Check the SES connection, verified domain and event destination.';
    if(empty($s['production_access']))$reasons[]='Request SES production access in the selected AWS Region.';
    if(empty($s['sending_enabled'])||(float)($s['max_daily']??0)<=0||(float)($s['max_rate']??0)<=0)$reasons[]='SES sending is disabled or its sending quota is unavailable.';
    if(strtolower(trim($from))!==($s['from_email']??''))$reasons[]='Use the From address configured for this account’s SES connection.';
    if(empty($s['subscription_arn']))$reasons[]='Connect and confirm the SNS HTTPS subscription for delivery events.';
    return $reasons;
}
/** No ambient credentials, profiles, arbitrary endpoints or automatic send retries. */
function audienceSesClient(string $service,array $settings,array $credentials):object
{
    $options=['version'=>match($service){'ses'=>'2019-09-27','sns'=>'2010-03-31',default=>'2011-06-15'},'region'=>$settings['region'],'credentials'=>$credentials,'retries'=>0,'http'=>['timeout'=>20,'connect_timeout'=>5,'allow_redirects'=>false,'proxy'=>'']];
    return match($service){'ses'=>new \Aws\SesV2\SesV2Client($options),'sns'=>new \Aws\Sns\SnsClient($options),'sts'=>new \Aws\Sts\StsClient($options),default=>throw new InvalidArgumentException('Unsupported AWS service.')};
}
function audienceSesCredentials(mysqli $db,int $audience):array
{
    $row=$db->execute_query('SELECT credential_ciphertext FROM marketing_ses_credentials WHERE audience_id=?',[$audience])->fetch_assoc();
    if(!$row||$row['credential_ciphertext']==='')throw new DomainException('SES credentials are not configured.');
    $value=json_decode(\Dnr\Security\ApplicationKey::open($row['credential_ciphertext']),true,8,JSON_THROW_ON_ERROR);
    if(!hash_equals(currentAccountKey(),$value['account']??''))throw new DomainException('SES credentials belong to a different MOED account.');
    return $value['credentials'];
}
/** Read-only AWS setup check; it never sends, changes a plan, or provisions AWS resources. */
function audienceSesCheck(mysqli $db,int $audience,?callable $factory=null,?callable $resolve=null):bool
{
    $s=$db->execute_query('SELECT * FROM marketing_ses_settings WHERE audience_id=?',[$audience])->fetch_assoc();if(!$s||!$s['enabled'])return false;
    $factory??='audienceSesClient';$credentials=audienceSesCredentials($db,$audience);$ses=$factory('ses',$s,$credentials);$sts=$factory('sts',$s,$credentials);
    $caller=$sts->getCallerIdentity([]);if(($caller['Account']??'')!==$s['aws_account_id'])throw new DomainException('The AWS credentials and SNS topic belong to different AWS accounts.');
    $sample=gmdate('Y-m-d H:i:s');$account=$ses->getAccount([]);$domain=substr(strrchr($s['from_email'],'@'),1);$identity=$ses->getEmailIdentity(['EmailIdentity'=>$domain]);$set=$ses->getConfigurationSet(['ConfigurationSetName'=>$s['configuration_set']]);$destinations=$ses->getConfigurationSetEventDestinations(['ConfigurationSetName'=>$s['configuration_set']]);
    $required=['SEND','DELIVERY','BOUNCE','COMPLAINT','REJECT','DELIVERY_DELAY','RENDERING_FAILURE'];$events=[];
    foreach($destinations['EventDestinations']??[]as$d)if(!empty($d['Enabled'])&&($d['SnsDestination']['TopicArn']??'')===$s['topic_arn'])$events=array_merge($events,$d['MatchingEventTypes']??[]);
    $dns=$resolve??'audienceSendingDns';$dmarc=audienceSendingDmarc($dns('_dmarc.'.$domain,'TXT'));
    $domainReady=!empty($identity['VerifiedForSendingStatus'])&&($identity['VerificationStatus']??'')==='SUCCESS'&&!empty($identity['DkimAttributes']['SigningEnabled'])&&($identity['DkimAttributes']['Status']??'')==='SUCCESS'&&$dmarc;
    $configReady=($set['ConfigurationSetName']??'')===$s['configuration_set']&&($set['SendingOptions']['SendingEnabled']??true)&&!array_diff($required,$events);
    $mailFrom=$identity['MailFromAttributes']??[];if(!empty($mailFrom['MailFromDomain'])&&($mailFrom['MailFromDomainStatus']??'')!=='SUCCESS')$domainReady=false;
    $quota=$account['SendQuota']??[];$sending=!empty($account['SendingEnabled']);$production=!empty($account['ProductionAccessEnabled']);$ready=$sending&&$production&&$domainReady&&$configReady&&(float)($quota['Max24HourSend']??0)>0&&(float)($quota['MaxSendRate']??0)>0;
    $result=['domain'=>$domain,'domain_verified'=>$domainReady,'dmarc'=>$dmarc,'configuration_ready'=>$configReady,'event_types'=>array_values(array_unique($events)),'dkim'=>$identity['DkimAttributes']??[],'mail_from'=>$mailFrom,'pricing'=>$account['PricingAttributes']??[]];
    $reason=$ready?'':(!$production?'SES is in the sandbox. Request production access.':(!$sending?'AWS has paused SES sending.':(!$domainReady?'Verify the domain, enable DKIM and publish DMARC.':'Check the configuration set and required SNS event destination.')));
    $db->execute_query("UPDATE marketing_ses_settings SET check_state=?,checked_at=UTC_TIMESTAMP(),error_message=?,production_access=?,sending_enabled=?,max_daily=?,max_rate=?,sent_last_day=?,quota_checked_at=?,result_json=? WHERE audience_id=? AND generation=? AND enabled=1",[$ready?'ready':'attention',$reason,$production?1:0,$sending?1:0,(float)($quota['Max24HourSend']??0),(float)($quota['MaxSendRate']??0),(float)($quota['SentLast24Hours']??0),$sample,json_encode($result,JSON_THROW_ON_ERROR),$audience,$s['generation']]);return $ready&&($db->execute_query('SELECT generation FROM marketing_ses_settings WHERE audience_id=?',[$audience])->fetch_assoc()['generation']??'')===$s['generation'];
}
function processAudienceSesSetup(mysqli $db,int $limit=1,?callable $factory=null,?callable $resolve=null):int
{
    if((function_exists('accountSecurityAccessAllowed')&&!accountSecurityAccessAllowed())||!audienceSesInstalled($db))return 0;
    $db->query("UPDATE marketing_ses_settings SET check_state='attention',error_message='The connection check stopped. Check again.' WHERE check_state='checking' AND check_started_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)");
    $row=$db->query("SELECT audience_id,generation FROM marketing_ses_settings WHERE check_state='queued' AND enabled=1 ORDER BY audience_id LIMIT 1")->fetch_assoc();if(!$row)return 0;
    $db->execute_query("UPDATE marketing_ses_settings SET check_state='checking',check_started_at=UTC_TIMESTAMP() WHERE audience_id=? AND generation=? AND check_state='queued'",[$row['audience_id'],$row['generation']]);if($db->affected_rows!==1)return 0;
    try{audienceSesCheck($db,(int)$row['audience_id'],$factory,$resolve);}catch(Throwable){$db->execute_query("UPDATE marketing_ses_settings SET check_state='attention',checked_at=NULL,error_message='Unable to verify SES. Check the credentials, IAM permissions, Region, sender domain and event destination.' WHERE audience_id=? AND generation=?",[$row['audience_id'],$row['generation']]);}return 1;
}
trait AudienceSesSetup
{
    public function sesAvailable():bool{return audienceSesInstalled($this->db);}
    public function sesSettings():array{return $this->one('SELECT * FROM marketing_ses_settings WHERE audience_id=?',[$this->audienceId])??[];}
    public function saveSesSettings(int $version,array $input):void
    {
        $s=audienceSesInput($input);$key=audienceText($input,'access_key_id',128);$secret=audienceText($input,'secret_access_key',128);$token=audienceText($input,'session_token',8000);$sealed=null;
        if($key!==''||$secret!==''){
            if(!preg_match('/^(?:AKIA|ASIA)[A-Z0-9]{16}$/D',$key)||strlen($secret)<40||preg_match('/[\r\n\s]/',$secret)|| (str_starts_with($key,'ASIA')&&$token===''))throw new InvalidArgumentException('Enter a valid AWS access-key ID and secret. Temporary credentials also need their session token.');
            $credentials=['key'=>$key,'secret'=>$secret];if($token!=='')$credentials['token']=$token;$sealed=\Dnr\Security\ApplicationKey::seal(json_encode(['account'=>currentAccountKey(),'credentials'=>$credentials],JSON_THROW_ON_ERROR));
        }elseif($token!=='')throw new InvalidArgumentException('Enter all temporary credential fields together.');
        $this->transaction(function()use($version,$s,$key,$sealed){$old=$this->one('SELECT * FROM marketing_ses_settings WHERE audience_id=? FOR UPDATE',[$this->audienceId]);if(!$old||(int)$old['version']!==$version)throw new InvalidArgumentException('SES settings changed. Reload before saving.');if($sealed===null&&!$old['access_key_tail'])throw new InvalidArgumentException('Enter this MOED account’s SES API credentials.');
            $sameTopic=$old['topic_arn']===$s['topic_arn'];$this->write("UPDATE marketing_ses_settings SET enabled=1,region=?,from_email=?,configuration_set=?,topic_arn=?,aws_account_id=?,access_key_tail=?,generation=?,check_state='queued',checked_at=NULL,production_access=0,sending_enabled=0,max_daily=0,max_rate=0,sent_last_day=0,quota_checked_at=NULL,result_json=JSON_OBJECT(),subscription_arn=?,last_event_at=NULL,blocked_until=NULL,version=version+1 WHERE audience_id=?",[$s['region'],$s['from_email'],$s['configuration_set'],$s['topic_arn'],$s['aws_account_id'],$sealed!==null?substr($key,-4):$old['access_key_tail'],bin2hex(random_bytes(16)),$sameTopic?$old['subscription_arn']:'',$this->audienceId]);if($sealed!==null)$this->write('UPDATE marketing_ses_credentials SET credential_ciphertext=? WHERE audience_id=?',[$sealed,$this->audienceId]);
            if(function_exists('recordAuditEvent'))recordAuditEvent($this->db,['event_type'=>'marketing_ses_configured','actor_user_id'=>$this->actorId,'actor_username'=>$this->actorLabel,'entity_type'=>'marketing_audience','entity_id'=>$this->audienceId,'details'=>'SES connection saved; credentials are encrypted and excluded from audit details.']);
        });
    }
    public function sesOperation(int $version,string $operation):void
    {
        $this->transaction(function()use($version,$operation){$s=$this->one('SELECT * FROM marketing_ses_settings WHERE audience_id=? FOR UPDATE',[$this->audienceId]);if(!$s||(int)$s['version']!==$version)throw new InvalidArgumentException('SES settings changed. Reload before continuing.');
            if($operation==='check'){$this->write("UPDATE marketing_ses_settings SET check_state='queued',version=version+1 WHERE audience_id=? AND enabled=1 AND check_state<>'checking'",[$this->audienceId]);}
            elseif($operation==='disconnect'){$this->write("UPDATE marketing_ses_settings SET enabled=0,access_key_tail='',generation=?,check_state='unconfigured',checked_at=NULL,production_access=0,sending_enabled=0,max_daily=0,max_rate=0,sent_last_day=0,quota_checked_at=NULL,subscription_arn='',last_event_at=NULL,error_message='',result_json=JSON_OBJECT(),version=version+1 WHERE audience_id=?",[bin2hex(random_bytes(16)),$this->audienceId]);$this->write("UPDATE marketing_ses_credentials SET credential_ciphertext='' WHERE audience_id=?",[$this->audienceId]);}
            else throw new InvalidArgumentException('Unknown SES operation.');
            if(function_exists('recordAuditEvent'))recordAuditEvent($this->db,['event_type'=>'marketing_ses_'.$operation,'actor_user_id'=>$this->actorId,'actor_username'=>$this->actorLabel,'entity_type'=>'marketing_audience','entity_id'=>$this->audienceId,'details'=>'MessageMonkey delivery connection operation.']);
        });
    }
}
