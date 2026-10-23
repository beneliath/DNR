<?php
declare(strict_types=1);

/** This setup uses MOED's existing worker-held SMTP configuration. */
function audienceSendingDomain(string $value):string
{
    $domain=strtolower(rtrim(trim($value),'.'));
    if(strlen($domain)>253||!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/D',$domain))throw new InvalidArgumentException('Enter a valid custom email domain.');
    return $domain;
}
function audienceSendingAddress(string $value):string
{
    $email=strtolower(trim($value));if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))throw new InvalidArgumentException('Enter a valid email address.');
    $domain=audienceSendingDomain(substr(strrchr($email,'@'),1));
    if(in_array($domain,['gmail.com','googlemail.com','yahoo.com','aol.com','hotmail.com','outlook.com','live.com','msn.com','icloud.com','me.com','mac.com','proton.me','protonmail.com'],true))throw new InvalidArgumentException('Use an email address on a custom domain that your organization controls.');
    return $email;
}
function audienceSendingRecords(string $domain,array $input):array
{
    $records=[];$seen=[];
    foreach($input as$row){
        if(!is_array($row))throw new InvalidArgumentException('Enter valid DKIM records.');
        $selector=audienceText($row,'selector',63);$value=audienceText($row,'value',4096);if($selector===''&&$value==='')continue;
        if(count($records)>=3||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}$/D',$selector)||isset($seen[strtolower($selector)]))throw new InvalidArgumentException('Use up to three unique DKIM selectors supplied by your SMTP provider.');
        $type=audienceText($row,'type');if($type==='CNAME')$value=audienceSendingDomain($value);
        elseif($type==='TXT'){
            if(preg_match('/[\r\n]/',$value)||!audienceSendingDkimKey($value))throw new InvalidArgumentException('Enter the complete DKIM public key supplied by your provider.');
        }else throw new InvalidArgumentException('Choose TXT or CNAME for the DKIM record.');
        $seen[strtolower($selector)]=true;$records[]=['selector'=>$selector,'host'=>$selector.'._domainkey.'.$domain,'type'=>$type,'value'=>$value];
    }
    if(!$records)throw new InvalidArgumentException('Enter at least one DKIM record supplied by your SMTP provider.');
    return $records;
}
function audienceSendingDkimKey(string $value):bool
{
    $tags=[];foreach(explode(';',$value)as$part){$part=trim($part);if($part==='')continue;$pair=explode('=',$part,2);if(count($pair)!==2||isset($tags[trim($pair[0])]))return false;$tags[trim($pair[0])]=trim($pair[1]);}
    if(($tags['v']??'DKIM1')!=='DKIM1'||!isset($tags['p']))return false;$bytes=base64_decode($tags['p'],true);if($bytes===false)return false;
    if(($tags['k']??'rsa')==='ed25519')return strlen($bytes)===32;
    if(($tags['k']??'rsa')!=='rsa')return false;
    $key=@openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($bytes),64,"\n")."-----END PUBLIC KEY-----\n");
    if($key===false)return false;$details=openssl_pkey_get_details($key);return $details&&$details['type']===OPENSSL_KEYTYPE_RSA&&$details['bits']>=1024;
}
function audienceSendingDmarc(array $values):bool
{
    $records=array_values(array_filter($values,static fn($v)=>is_string($v)&&preg_match('/^v=DMARC1\s*(?:;|$)/i',trim($v))));
    if(count($records)!==1)return false;$tags=[];
    foreach(explode(';',$records[0])as$part){$part=trim($part);if($part==='')continue;$pair=explode('=',$part,2);if(count($pair)!==2)return false;$name=strtolower(trim($pair[0]));if(isset($tags[$name]))return false;$tags[$name]=trim($pair[1]);}
    return ($tags['v']??'')==='DMARC1'&&in_array($tags['p']??'',['none','quarantine','reject'],true);
}
/** Resolver returns strings only; TXT chunks are joined by PHP's DNS adapter. */
function audienceSendingDns(string $host,string $type):array
{
    $rows=@dns_get_record($host,$type==='CNAME'?DNS_CNAME:DNS_TXT);$values=[];
    foreach($rows?:[]as$row)if($type==='CNAME'&&isset($row['target']))$values[]=strtolower(rtrim($row['target'],'.'));elseif($type==='TXT'&&isset($row['txt']))$values[]=$row['txt'];
    return $values;
}
function audienceSendingCheckDns(string $domain,array $records,?callable $resolve=null):array
{
    $resolve??='audienceSendingDns';$checks=[];$dkim=(bool)$records;
    foreach($records as$record){
        $actual=$resolve($record['host'],$record['type']);$matched=in_array($record['value'],$actual,true);$usable=$matched;
        if($matched&&$record['type']==='CNAME'){
            $keys=$resolve($record['value'],'TXT');$usable=false;
            foreach($keys as$key)if(audienceSendingDkimKey($key))$usable=true;
        }
        $checks[]=$record+['matched'=>$matched,'usable'=>$usable];$dkim=$dkim&&$usable;
    }
    $dmarc=audienceSendingDmarc($resolve('_dmarc.'.$domain,'TXT'));
    $spf=array_values(array_filter($resolve($domain,'TXT'),static fn($value)=>str_starts_with(strtolower(trim($value)),'v=spf1 ')));
    return ['records'=>$checks,'dkim'=>$dkim,'dmarc'=>$dmarc,'spf'=>count($spf)===1,'complete'=>$dkim&&$dmarc];
}

trait AudienceDeliverySetup
{
    public function deliverySetupAvailable():bool
    {
        return (int)$this->one("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='marketing_sending_domains'")['n']===1;
    }
    public function sendingDomain(int $id,bool $lock=false):array
    {
        $row=$this->one('SELECT * FROM marketing_sending_domains WHERE audience_id=? AND id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$this->audienceId,$id]);
        if(!$row)throw new InvalidArgumentException('This email domain is unavailable.');$row['records']=audienceJson($row['records_json']);$row['checks']=audienceJson($row['checks_json']);return $row;
    }
    public function sendingAddress(int $id,bool $lock=false):array
    {
        $row=$this->one('SELECT * FROM marketing_sending_addresses WHERE audience_id=? AND id=? AND deleted_at IS NULL'.($lock?' FOR UPDATE':''),[$this->audienceId,$id]);if(!$row)throw new InvalidArgumentException('This sending address is unavailable.');return $row;
    }
    public function requestSenderVerification(string $email):int
    {
        $email=audienceSendingAddress($email);$domain=substr(strrchr($email,'@'),1);
        return $this->transaction(function()use($email,$domain){
            $audience=$this->one('SELECT id FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);
            $count=(int)$this->one("SELECT COUNT(*) n FROM marketing_delivery_setup_jobs WHERE audience_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)",[$this->audienceId])['n'];if($count>=20)throw new InvalidArgumentException('Too many setup requests. Try again in an hour.');
            $last=$this->one('SELECT j.created_at FROM marketing_delivery_setup_jobs j JOIN marketing_sending_addresses s ON s.id=j.sender_id AND s.audience_id=j.audience_id WHERE j.audience_id=? AND s.email=? ORDER BY j.id DESC LIMIT 1',[$this->audienceId,$email]);if($last&&strtotime($last['created_at'].' UTC')>time()-60)throw new InvalidArgumentException('Wait one minute before sending another verification email.');
            $this->write("INSERT INTO marketing_sending_domains(audience_id,domain,records_json,checks_json) VALUES (?,?,JSON_ARRAY(),JSON_OBJECT()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id),version=version+(deleted_at IS NOT NULL),deleted_at=NULL",[$this->audienceId,$domain]);$domainId=(int)$this->db->insert_id;
            $existing=$this->one('SELECT id FROM marketing_sending_addresses WHERE audience_id=? AND email=? FOR UPDATE',[$this->audienceId,$email]);
            if(!$existing&&(int)$this->one('SELECT COUNT(*) n FROM marketing_sending_addresses WHERE audience_id=? AND deleted_at IS NULL',[$this->audienceId])['n']>=200)throw new InvalidArgumentException('Up to 200 sending addresses are supported.');
            $code=strtoupper(bin2hex(random_bytes(6)));$generation=bin2hex(random_bytes(16));$hash=hash('sha256',$generation.':'.$code);
            if($existing){$sender=(int)$existing['id'];$this->write("UPDATE marketing_sending_addresses SET domain_id=?,code_hash=?,generation=?,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR),attempts=0,deleted_at=NULL,version=version+1 WHERE audience_id=? AND id=?",[$domainId,$hash,$generation,$this->audienceId,$sender]);}
            else{$this->write('INSERT INTO marketing_sending_addresses(audience_id,domain_id,email,code_hash,generation,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR))',[$this->audienceId,$domainId,$email,$hash,$generation]);$sender=(int)$this->db->insert_id;}
            $this->write("UPDATE marketing_delivery_setup_jobs SET state='cancelled',payload_ciphertext='' WHERE audience_id=? AND sender_id=? AND state='queued'",[$this->audienceId,$sender]);
            $payload=\Dnr\Security\ApplicationKey::seal(json_encode(['email'=>$email,'code'=>$code],JSON_THROW_ON_ERROR));
            $this->write("INSERT INTO marketing_delivery_setup_jobs(audience_id,sender_id,job_type,generation,payload_ciphertext,expires_at,result_json,actor_id) VALUES (?,?,'verify',?,?,?,JSON_OBJECT(),?)",[$this->audienceId,$sender,$generation,$payload,gmdate('Y-m-d H:i:s',time()+86400),$this->actorId]);
            return $sender;
        });
    }
    public function verifySendingAddress(int $id,string $code):bool
    {
        // Commit failed attempts as well as success; never roll a guessed code back.
        return $this->transaction(function()use($id,$code){
            $sender=$this->sendingAddress($id,true);$job=$this->one("SELECT * FROM marketing_delivery_setup_jobs WHERE audience_id=? AND sender_id=? AND generation=? AND state IN ('accepted','development') ORDER BY id DESC LIMIT 1",[$this->audienceId,$id,$sender['generation']]);
            if(!$job||!$sender['code_hash']||$sender['attempts']>=5||strtotime($sender['expires_at'].' UTC')<=time())return false;
            $code=strtoupper(preg_replace('/[\s-]/','',trim($code)));$valid=hash_equals($sender['code_hash'],hash('sha256',$sender['generation'].':'.$code));
            $this->write('UPDATE marketing_sending_addresses SET attempts=attempts+1,version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
            if(!$valid)return false;
            $transport=$job['state']==='accepted'?'smtp':'log';$this->write('UPDATE marketing_sending_addresses SET verified_at=UTC_TIMESTAMP(),verified_transport=?,code_hash=NULL,expires_at=NULL WHERE audience_id=? AND id=?',[$transport,$this->audienceId,$id]);
            $this->write("UPDATE marketing_delivery_setup_jobs SET payload_ciphertext='' WHERE audience_id=? AND sender_id=?",[$this->audienceId,$id]);return true;
        });
    }
    public function queueRelayCheck():int
    {
        return $this->transaction(function(){
            $this->one('SELECT id FROM marketing_audiences WHERE id=? FOR UPDATE',[$this->audienceId]);
            if($this->one("SELECT id FROM marketing_delivery_setup_jobs WHERE audience_id=? AND job_type='probe' AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 60 SECOND)",[$this->audienceId]))throw new InvalidArgumentException('A connection check was recently requested. Wait one minute and refresh.');
            $this->write("INSERT INTO marketing_delivery_setup_jobs(audience_id,job_type,payload_ciphertext,expires_at,result_json,actor_id) VALUES (?,'probe','',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),JSON_OBJECT(),?)",[$this->audienceId,$this->actorId]);return (int)$this->db->insert_id;
        });
    }
    public function saveSendingRecords(int $id,int $version,array $records):void
    {
        $this->transaction(function()use($id,$version,$records){$row=$this->sendingDomain($id,true);if((int)$row['version']!==$version)throw new InvalidArgumentException('This domain changed. Reload before saving.');$records=audienceSendingRecords($row['domain'],$records);$this->write('UPDATE marketing_sending_domains SET records_json=?,checks_json=JSON_OBJECT(),authenticated_at=NULL,authentication_enabled=0,version=version+1 WHERE audience_id=? AND id=?',[json_encode($records,JSON_THROW_ON_ERROR),$this->audienceId,$id]);});
    }
    public function checkSendingDomain(int $id,int $version,?callable $resolver=null):array
    {
        $row=$this->sendingDomain($id);if((int)$row['version']!==$version)throw new InvalidArgumentException('This domain changed. Reload before checking.');
        if($row['checked_at']&&strtotime($row['checked_at'].' UTC')>time()-30)throw new InvalidArgumentException('Wait 30 seconds before checking DNS again.');
        $verified=$this->one("SELECT id FROM marketing_sending_addresses WHERE audience_id=? AND domain_id=? AND verified_at IS NOT NULL AND verified_transport='smtp' AND deleted_at IS NULL",[$this->audienceId,$id]);if(!$verified)throw new InvalidArgumentException('Verify an email address at this domain first.');
        if(!$row['records'])throw new InvalidArgumentException('Add the DKIM records from your SMTP provider first.');$checks=audienceSendingCheckDns($row['domain'],$row['records'],$resolver);
        $this->write('UPDATE marketing_sending_domains SET checks_json=?,checked_at=UTC_TIMESTAMP(),authenticated_at=IF(?,UTC_TIMESTAMP(),NULL),authentication_enabled=?,version=version+1 WHERE audience_id=? AND id=? AND version=?',[json_encode($checks,JSON_THROW_ON_ERROR),(int)$checks['complete'],(int)$checks['complete'],$this->audienceId,$id,$version]);if($this->db->affected_rows!==1)throw new InvalidArgumentException('This domain changed during the check. Reload and try again.');return $checks;
    }
    public function sendingDomainOperation(int $id,int $version,string $operation):void
    {
        $this->transaction(function()use($id,$version,$operation){$row=$this->sendingDomain($id,true);if((int)$row['version']!==$version||!in_array($operation,['disable','remove'],true))throw new InvalidArgumentException('This domain changed. Reload before continuing.');
            $this->write('UPDATE marketing_sending_domains SET authentication_enabled=0,authenticated_at=NULL,deleted_at='.($operation==='remove'?'UTC_TIMESTAMP(6)':'NULL').',version=version+1 WHERE audience_id=? AND id=?',[$this->audienceId,$id]);
            if($operation==='remove'){$this->write('UPDATE marketing_sending_addresses SET deleted_at=UTC_TIMESTAMP(6),verified_at=NULL,verified_transport=\'\',code_hash=NULL,generation=NULL,version=version+1 WHERE audience_id=? AND domain_id=?',[$this->audienceId,$id]);$this->write("UPDATE marketing_delivery_setup_jobs j JOIN marketing_sending_addresses s ON s.id=j.sender_id AND s.audience_id=j.audience_id SET j.state='cancelled',j.payload_ciphertext='' WHERE j.audience_id=? AND s.domain_id=? AND j.state='queued'",[$this->audienceId,$id]);}
        });
    }
    public function sendingReadiness(string $email,array $settings,bool $testOnly=false):array
    {
        if($this->sesAvailable()){$ses=$this->sesSettings();$reasons=audienceSesReasons($ses,$email,requireFreshCheck:!$testOnly);if(trim(audienceBrandIdentity($settings)['LIST:ADDRESS'])==='')$reasons[]='Add your organization’s mailing address in Brand Kit.';return ['ready'=>!$reasons,'reasons'=>$reasons,'sender'=>null,'probe'=>null,'relay'=>[],'ses'=>$ses];}
        $reasons=[];$sender=$this->one('SELECT s.*,d.authentication_enabled,d.authenticated_at,d.deleted_at domain_deleted FROM marketing_sending_addresses s JOIN marketing_sending_domains d ON d.id=s.domain_id AND d.audience_id=s.audience_id WHERE s.audience_id=? AND s.email=? AND s.deleted_at IS NULL',[$this->audienceId,strtolower(trim($email))]);
        if(!$sender||!$sender['verified_at']||$sender['verified_transport']!=='smtp')$reasons[]='Verify the sending email address through the live relay.';
        if(!$sender||$sender['domain_deleted']||!$sender['authentication_enabled']||!$sender['authenticated_at']||strtotime($sender['authenticated_at'].' UTC')<time()-86400)$reasons[]='Check the provider’s DKIM and DMARC records for this domain.';
        $probe=$this->one("SELECT * FROM marketing_delivery_setup_jobs WHERE audience_id=? AND job_type='probe' ORDER BY id DESC LIMIT 1",[$this->audienceId]);$relay=$probe?audienceJson($probe['result_json']):[];
        if(!$probe||$probe['state']!=='accepted'||empty($relay['ready'])||($relay['transport']??'')!=='smtp'||strtotime($probe['updated_at'].' UTC')<time()-3600)$reasons[]='Run a successful connection check through the live SMTP relay.';
        if(!empty($relay['from'])&&strtolower($relay['from'])!==strtolower(trim($email)))$reasons[]='Use the sender address authorized by the configured SMTP relay.';
        if(trim(audienceBrandIdentity($settings)['LIST:ADDRESS'])==='')$reasons[]='Add your organization’s mailing address in Brand Kit.';
        return ['ready'=>!$reasons,'reasons'=>$reasons,'sender'=>$sender,'probe'=>$probe,'relay'=>$relay];
    }
}
