<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/audience_store.php';
sendApplicationSecurityHeaders();header('Cache-Control: no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
$token=\Dnr\Http\RequestInput::string($_GET,'token');$signature=\Dnr\Http\RequestInput::string($_GET,'sig');$encoded=\Dnr\Http\RequestInput::string($_GET,'url');$open=isset($_GET['open']);
if(!preg_match('/^[a-f0-9]{64}$/D',$token)||!preg_match('/^[a-f0-9]{64}$/D',$signature)||strlen($encoded)>3000){http_response_code(404);exit;}
$db=applicationDatabaseConnection();$recipient=$db->execute_query("SELECT r.*,d.snapshot_ciphertext FROM marketing_campaign_recipients r JOIN marketing_campaign_deliveries d ON d.id=r.delivery_id AND d.audience_id=r.audience_id WHERE r.audience_id=1 AND r.token_hash=? AND r.delivery_started_at IS NOT NULL AND r.state IN ('accepted','uncertain')",[hash('sha256',$token)])->fetch_assoc();if(!$recipient){http_response_code(404);exit;}
$snapshot=json_decode(\Dnr\Security\ApplicationKey::open($recipient['snapshot_ciphertext']),true,64,JSON_THROW_ON_ERROR);$expected=hash_hmac('sha256',$open?'open':$encoded,(string)($snapshot['tracking_key']??''));if(empty($snapshot['tracking_key'])||!hash_equals($expected,$signature)){http_response_code(404);exit;}
$url='';if(!$open){$url=base64_decode(strtr($encoded,'-_','+/'),true);if($url===false||!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('~^https?://~i',$url)||preg_match('/[\r\n]/',$url)){http_response_code(400);exit;}}
$agent=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,2048);
$bot=preg_match('/bot|crawler|spider|scanner|headless|preview|curl|python|wget/i',$agent)===1;
$recipientId=(int)$recipient['id'];
$linkHash=$open?'':hash('sha256',$url);
$now=time();
// Keep scanner and reader observations distinct while bounding writes for a shared tracking URL.
$key=hash('sha256',implode(':',['browser',$recipientId,$linkHash,gmdate('Y-m-d H:i',$now),(int)$bot]));
try {
    $store=new AudienceStore($db,1,0,'Email Link');
    if (!$store->one('SELECT 1 FROM marketing_message_events WHERE audience_id=? AND event_key=?',[$store->audienceId,$key])) {
        $interactionLocation=audienceInteractionLocation($_SERVER);
        $details=['bot'=>$bot,'link_hash'=>$linkHash]+audienceAnalyticsClientEvidence($agent,$interactionLocation['country_code']??null,$url);
        if($interactionLocation)$details['interaction_location']=$interactionLocation;
        $store->recordDeliveryEvent($recipientId,$open?'open':'click',$key,'browser',gmdate('Y-m-d H:i:s',$now),$details);
    }
} catch (Throwable) {
    applicationLog('warning','Unable to record email link activity',['recipient_id'=>$recipientId]);
}
if($open){header('Content-Type: image/gif');echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');}else{header('Location: '.$url,true,302);}
