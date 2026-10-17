<?php
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
$s3start=$checks;$s3path='audience.php?view=delivery';$s3post=static fn(array $data)=>$admin($s3path,['csrf_token'=>$csrf]+$data);
$page=$admin($s3path);audienceHttpExpect($page['status']===200&&str_contains($page['body'],'Amazon SES')&&!preg_match('/Warning:|Fatal error:/',$page['body']),'Administrator SES connection renders');
foreach(['editor'=>$editor,'reviewer'=>$reviewer]as$role=>$client){
    foreach(['','relay','domains','limits','activity','events','unknown']as$tab){
        $denied=$client($s3path.($tab!==''?'&tab='.$tab:''));
        audienceHttpExpect($denied['status']===403&&!str_contains($denied['body'],'ses-key')&&!str_contains($denied['body'],'audience_ses_events.php'),$role.' cannot read delivery tab '.$tab);
    }
    audienceHttpExpect($client($s3path.'&api=workspace_search')['status']===403,$role.' cannot bypass delivery access through an API selector');
    audienceHttpExpect($client($s3path.'&audience=1')['status']===403,$role.' cannot bypass delivery access through a legacy audience selector');
    foreach(['delivery_ses_operation','delivery_policy','delivery_request']as$action)audienceHttpExpect($client('audience.php?view=campaigns',['csrf_token'=>$role==='editor'?$token:$reviewToken,'action'=>$action,'operation'=>'disconnect'])['status']===403,$role.' cannot post '.$action.' through another view');
    foreach(['campaigns','settings']as$view){$navigation=$client('audience.php?view='.$view);audienceHttpExpect($navigation['status']===200&&!str_contains($navigation['body'],'view=delivery'),$role.' navigation omits admin-only delivery links');}
}
audienceHttpExpect(str_contains($admin('audience.php?view=campaigns')['body'],'view=delivery'),'Administrator navigation retains delivery settings');
$credentialInputs=static function(string $html):array{$document=new DOMDocument();@$document->loadHTML($html);$xpath=new DOMXPath($document);return [$xpath->query('//*[@id="ses-key"]')->item(0),$xpath->query('//*[@id="ses-secret"]')->item(0)];};
[$newKey,$newSecret]=$credentialInputs($admin($s3path)['body']);
audienceHttpExpect($newKey instanceof DOMElement&&$newSecret instanceof DOMElement&&$newKey->getAttribute('placeholder')===''&&$newSecret->getAttribute('placeholder')==='','Unconfigured credentials do not imply that keys are saved');
$sesInput=['action'=>'delivery_ses_save','version'=>1,'region'=>'us-east-1','from_email'=>'sender@community.example','configuration_set'=>'message-monkey','topic_arn'=>'arn:aws:sns:us-east-1:123456789012:message-monkey','access_key_id'=>'AKIA'.str_repeat('A',16),'secret_access_key'=>str_repeat('s',40)];
audienceHttpExpect($editor($s3path,['csrf_token'=>$token]+$sesInput)['status']===403,'Editor cannot configure account credentials');
audienceHttpExpect($admin($s3path,['csrf_token'=>'invalid']+$sesInput)['status']===400,'SES setup requires CSRF');
audienceHttpExpect(!$campaignStore->sendingReadiness('sender@community.example',[])['ready'],'Unconfigured connection blocks sending');
audienceHttpExpect($s3post($sesInput)['status']===302,'Admin saves encrypted SES credentials');$ses=$campaignStore->sesSettings();
audienceHttpExpect($ses['access_key_tail']==='AAAA'&&!str_contains(json_encode($ses),str_repeat('s',40))&&$ses['check_state']==='queued','Only masked credential metadata is readable');
$savedPage=$admin($s3path)['body'];[$savedKey,$savedSecret]=$credentialInputs($savedPage);
audienceHttpExpect($savedKey instanceof DOMElement&&$savedSecret instanceof DOMElement&&$savedKey->getAttribute('type')==='password'&&$savedSecret->getAttribute('type')==='password'&&!$savedKey->hasAttribute('value')&&!$savedSecret->hasAttribute('value')&&str_contains($savedKey->getAttribute('placeholder'),'saved')&&str_contains($savedSecret->getAttribute('placeholder'),'saved')&&!str_contains($savedPage,str_repeat('s',40))&&!str_contains($savedPage,'AKIA'.str_repeat('A',16)),'Saved credential indicators never fill submitted values or expose either stored key');
audienceHttpExpect($savedKey->getAttribute('aria-describedby')==='ses-credential-help'&&$savedSecret->getAttribute('aria-describedby')==='ses-credential-help','Both credential fields expose the saved/replacement instructions to assistive technology');
$credentialRead=false;try{$conn->query('SELECT credential_ciphertext FROM marketing_ses_credentials');}catch(mysqli_sql_exception $e){$credentialRead=in_array($e->getCode(),[1142,1143],true);}audienceHttpExpect($credentialRead,'Web database role cannot read SES credentials');
$bad=$s3post($sesInput);audienceHttpExpect($bad['status']===200&&str_contains($bad['body'],'changed')&&!str_contains($bad['body'],str_repeat('s',40))&&!str_contains($bad['body'],'AKIA'.str_repeat('A',16)),'Stale setup rejected without re-echoing secrets');
$sesInput['version']=$ses['version'];$sesInput['access_key_id']=$savedKey->getAttribute('value');$sesInput['secret_access_key']=$savedSecret->getAttribute('value');audienceHttpExpect($s3post($sesInput)['status']===302&&$campaignStore->sesSettings()['access_key_tail']==='AAAA','Unchanged masked form fields retain saved credentials');
$ses=$campaignStore->sesSettings();audienceHttpExpect($s3post(['action'=>'delivery_ses_operation','version'=>$ses['version'],'operation'=>'disconnect'])['status']===302&&!$campaignStore->sesSettings()['enabled'],'Disconnect disables this account’s marketing connection');
[$removedKey,$removedSecret]=$credentialInputs($admin($s3path)['body']);audienceHttpExpect($removedKey->getAttribute('placeholder')===''&&$removedSecret->getAttribute('placeholder')==='','Disconnect removes both saved-credential indicators');
$sesInput['version']=$campaignStore->sesSettings()['version'];audienceHttpExpect(str_contains($s3post($sesInput)['body'],'Enter this MOED account'),'Disconnected credentials cannot be reused');
$sesInput['access_key_id']='AKIA'.str_repeat('A',16);$sesInput['secret_access_key']=str_repeat('s',40);audienceHttpExpect($s3post($sesInput)['status']===302,'Reconnect saves a fresh account-bound secret');
foreach(['relay','domains','limits','activity','events']as$tab){$p=$admin($s3path.'&tab='.$tab);audienceHttpExpect($p['status']===200&&!preg_match('/Warning:|Fatal error:/',$p['body']),'SES tab '.$tab.' renders');}
audienceHttpExpect(str_contains($admin($s3path.'&tab=events')['body'],'audience_ses_events.php'),'Account HTTPS SNS receiver displayed');
audienceHttpExpect($s3post(['action'=>'delivery_probe'])['status']===200,'Retired SMTP marketing setup action cannot queue mail');
$ses=$campaignStore->sesSettings();$conn->execute_query("UPDATE marketing_ses_settings SET check_state='ready',checked_at=UTC_TIMESTAMP(),production_access=1,sending_enabled=1,max_daily=100000,max_rate=1000,subscription_arn=CONCAT(topic_arn,':aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa') WHERE audience_id=1");
audienceHttpExpect($campaignStore->sendingReadiness('sender@community.example',['mailing_address'=>'123 Fixture Road'])['ready'],'Verified SES metadata and event subscription permit review');
audienceHttpExpect(!$campaignStore->sendingReadiness('other@community.example',['mailing_address'=>'123 Fixture Road'])['ready'],'Different From address blocked');
audienceHttpExpect(!$campaignStore->sendingReadiness('sender@community.example',[])['ready'],'Postal address still required');
echo 'SES setup HTTP: '.($checks-$s3start)." checks passed.\n";
