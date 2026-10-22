<?php
declare(strict_types=1);
require_once __DIR__.'/audience_store.php';

function audienceInboxContactProof(int $version):string
{
    return \Dnr\Security\ApplicationKey::seal(json_encode(['account'=>currentAccountKey(),'version'=>$version,'issued'=>time(),'nonce'=>bin2hex(random_bytes(32))],JSON_THROW_ON_ERROR));
}
function audienceInboxSubmitContact(AudienceStore $store,array $settings,array $input,string $ip):void
{
    try{$proof=json_decode(\Dnr\Security\ApplicationKey::open(audienceText($input,'proof',12000)),true,8,JSON_THROW_ON_ERROR);}catch(Throwable){throw new InvalidArgumentException('This form expired. Reload and try again.');}
    if(($proof['account']??'')!==currentAccountKey()||($proof['version']??0)!==(int)$settings['version']||($proof['issued']??0)>time()-2||($proof['issued']??0)<time()-7200||!preg_match('/\A[a-f0-9]{64}\z/D',$proof['nonce']??''))throw new InvalidArgumentException('This form expired. Reload and try again.');
    if(audienceText($input,'website',200)!=='')throw new InvalidArgumentException('The message could not be accepted.');
    $store->collectionRateLimit('inbox-contact:'.$ip,20);
    $email=audienceInboxEmail(audienceText($input,'email',254));$text=audienceText($input,'message',20000);if($text==='')throw new InvalidArgumentException('Enter your message.');
    $store->transaction(function()use($store,$settings,$input,$email,$text,$proof){$fresh=$store->one('SELECT contact_enabled,version FROM marketing_inbox_settings WHERE audience_id=? FOR UPDATE',[$store->audienceId]);if(!$fresh['contact_enabled']||(int)$fresh['version']!==(int)$settings['version'])throw new InvalidArgumentException('This form changed. Reload and try again.');
        $store->inboxReceive('contact',0,$email,audienceText($input,'name',100),audienceText($input,'subject',250)?:'Contact form message',$text,hash('sha256','contact:'.$proof['nonce']),false,[],0,[],true);
    });
}
function audienceInboxOptout(mysqli $db,string $token,bool $apply):bool
{
    if(!preg_match('/\A[a-f0-9]{64}\z/D',$token))return false;
    $row=$db->execute_query('SELECT * FROM marketing_inbox_optout_tokens WHERE token_hash=? AND audience_id=1 AND expires_at>UTC_TIMESTAMP()',[hash('sha256',$token)])->fetch_assoc();if(!$row)return false;
    if($apply){$db->begin_transaction();try{$db->execute_query('INSERT INTO marketing_inbox_preferences(audience_id,email_hash,optout_at) VALUES (?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE optout_at=UTC_TIMESTAMP(6)',[$row['audience_id'],$row['email_hash']]);$db->execute_query('UPDATE marketing_inbox_optout_tokens SET used_at=COALESCE(used_at,UTC_TIMESTAMP()) WHERE token_hash=?',[hash('sha256',$token)]);$db->commit();}catch(Throwable $e){$db->rollback();throw $e;}}
    return true;
}
