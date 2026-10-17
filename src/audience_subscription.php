<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/audience_store.php';
sendApplicationSecurityHeaders();
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$db=applicationDatabaseConnection();
if(isset($_GET['test'])){require __DIR__.'/templates/audience/subscription-test.php';exit;}
if(isset($_GET['confirm'])){require __DIR__.'/templates/audience/subscription-confirm.php';exit;}
$token=\Dnr\Http\RequestInput::string($_GET,'token');
if(!preg_match('/^[a-f0-9]{64}$/D',$token)){http_response_code(404);exit('This subscription link is unavailable.');}
$recipient=$db->execute_query('SELECT r.*,d.snapshot_ciphertext FROM marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id WHERE r.token_hash=? AND r.audience_id=1',[hash('sha256',$token)])->fetch_assoc();
if(!$recipient){http_response_code(404);exit('This subscription link is unavailable.');}
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['preferences'])){
    $preferenceStore=new AudienceStore($db,1,0,'Email recipient');
    if($preferenceStore->collectionAvailable()){
        $center=$db->query("SELECT public_key FROM marketing_collection_resources WHERE audience_id=1 AND kind='preferences' AND state='published' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1")->fetch_assoc();
        if($center){header('Location: audience_collect_public.php?key='.$center['public_key'].'&recipient='.$token);exit;}
    }
}
$done=false;$preferenceMessage='';$preferenceError='';$preferenceAction=audienceText($_POST,'preference_action');$publicStore=new AudienceStore($db,1,0,'Email recipient');
if($_SERVER['REQUEST_METHOD']==='POST'&&in_array($preferenceAction,['save','confirm'],true)){
    startSecureSession();requireValidCsrfToken();
    try{$person=$publicStore->person((int)$recipient['contact_id']);if(!hash_equals($recipient['email_hash'],audienceDispatchEmailHash($person['email'])))throw new InvalidArgumentException('This preferences link no longer matches the current address.');if($preferenceAction==='save'){$publicStore->savePublicPreferences((int)$person['id'],(int)audienceText($_POST,'version',12),$_POST);$preferenceMessage='Your preferences have been saved.';}else{$publicStore->requestSubscriptionConfirmation((int)$person['id'],accountPrimaryPublicUrl());$preferenceMessage='Check your email to confirm your subscription. Your status will not change until you confirm.';}}catch(InvalidArgumentException $e){$preferenceError=$e->getMessage();}
}elseif($_SERVER['REQUEST_METHOD']==='POST'){
    if(($_POST['List-Unsubscribe']??'')!=='One-Click'&&($_POST['unsubscribe']??'')!=='1'){http_response_code(400);exit('Confirm unsubscribe to continue.');}
    $db->begin_transaction();
    try{
        $db->execute_query('INSERT IGNORE INTO marketing_email_optouts(audience_id,email_hash) VALUES (?,?)',[$recipient['audience_id'],$recipient['email_hash']]);
        $person=$db->execute_query('SELECT id,email,email_status FROM marketing_contacts WHERE id=? AND audience_id=? AND deleted_at IS NULL FOR UPDATE',[$recipient['contact_id'],$recipient['audience_id']])->fetch_assoc();
        if($person&&hash_equals($recipient['email_hash'],audienceDispatchEmailHash($person['email']))&&in_array($person['email_status'],['subscribed','pending','non-subscribed'],true)){
            $db->execute_query("UPDATE marketing_contacts SET email_status='unsubscribed',version=version+1 WHERE id=? AND audience_id=?",[$person['id'],$recipient['audience_id']]);
            $db->execute_query("INSERT INTO marketing_contact_events(contact_id,actor_user_id,actor_label,actor_type,event_type,details_json) VALUES (?,NULL,'Email recipient','recipient','status',?)",[$person['id'],json_encode(['from'=>$person['email_status'],'to'=>'unsubscribed','source'=>'Campaign unsubscribe','recipient_id'=>(int)$recipient['id'],'delivery_id'=>(int)$recipient['delivery_id']],JSON_THROW_ON_ERROR)]);
        }
        $db->commit();$done=true;
    }catch(Throwable $e){$db->rollback();throw $e;}
    if(($_POST['List-Unsubscribe']??'')==='One-Click'){header('Content-Type: text/plain; charset=UTF-8');exit('Unsubscribed');}
}elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$preferences=isset($_GET['preferences']);if($preferences)startSecureSession();
$html=null;
if(isset($_GET['email'])&&!$done){$snapshot=json_decode(\Dnr\Security\ApplicationKey::open($recipient['snapshot_ciphertext']),true,64,JSON_THROW_ON_ERROR);$sealed=json_decode(\Dnr\Security\ApplicationKey::open($recipient['context_ciphertext']),true,64,JSON_THROW_ON_ERROR);if(($recipient['phase']??'')==='resend')$snapshot['is_resend']=true;unset($snapshot['tracking_key']);$message=audienceDispatchMessage($snapshot,$sealed['context'],$token,$recipient['message_id'],(string)($recipient['variant_key']??'0'));$html=$message['html']??'<pre>'.$h($message['text']).'</pre>';}
if($html===null&&!$preferences&&($system=audienceCollectionSystem($publicStore,'unsubscribe'))){
    require_once __DIR__.'/audience_collection_render.php';$nonce=contentSecurityPolicyNonce();
    header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; img-src 'self' data: https:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    echo audienceCollectionHtml($system,[],[],'?token='.$token,'',[],'',$done?$system['done']:'');exit;
}
?><!doctype html><html lang="en">
<?php renderPageHead($done?'Unsubscribed':($html!==null?'Email':($preferences?'Email Preferences':'Unsubscribe')),['styles'=>['assets/css/pages/audience.min.css']]); ?>
<body class="audience-app campaign-public"><main>
<?php if($html!==null): ?><header><h1>Your email</h1><nav aria-label="Subscription"><a href="?token=<?= $h($token) ?>&amp;preferences=1">Email preferences</a> · <a href="?token=<?= $h($token) ?>">Unsubscribe</a></nav></header><iframe title="Your email" sandbox="" referrerpolicy="no-referrer" srcdoc="<?= $h(audienceDesignPreviewSource($html,contentSecurityPolicyNonce())) ?>"></iframe>
<?php elseif($done): ?><h1>You’re unsubscribed</h1><p>You will no longer receive emails from this audience.</p>
<?php elseif($preferences): require __DIR__.'/templates/audience/subscription-preferences.php'; ?>
<?php else: ?><h1>Unsubscribe from emails?</h1><p>You can stop emails from this audience at any time.</p><form method="post"><input type="hidden" name="unsubscribe" value="1"><button class="primary">Unsubscribe</button></form><p class="audience-help">This change applies to the address that received this email.</p><?php endif; ?>
</main></body></html>
