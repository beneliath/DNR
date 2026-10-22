<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
sendApplicationSecurityHeaders();
header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=3600');
$key=\Dnr\Http\RequestInput::string($_GET,'key');$image=\Dnr\Http\RequestInput::string($_GET,'image');
if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$image)){http_response_code(404);exit;}
$db=applicationDatabaseConnection();
$job=$db->execute_query('SELECT d.snapshot_ciphertext FROM marketing_campaign_deliveries d JOIN marketing_campaigns c ON c.id=d.campaign_id AND c.audience_id=d.audience_id WHERE d.asset_key=? AND d.audience_id=1 AND c.deleted_at IS NULL',[$key])->fetch_assoc();
if(!$job){require_once __DIR__.'/audience_mail_budget.php';if(!audienceMailPolicyAvailable($db)){http_response_code(404);exit;}$service=$db->execute_query("SELECT payload_ciphertext FROM marketing_service_mail WHERE audience_id=1 AND operation_key=? AND kind='test'",[$key])->fetch_assoc();if(!$service){http_response_code(404);exit;}$payload=json_decode(\Dnr\Security\ApplicationKey::open($service['payload_ciphertext']),true,64,JSON_THROW_ON_ERROR);$snapshot=$payload['snapshot'];}else 
$snapshot=json_decode(\Dnr\Security\ApplicationKey::open($job['snapshot_ciphertext']),true,64,JSON_THROW_ON_ERROR);
$design=json_encode([$snapshot['draft']['design']??[],$snapshot['variants']??[]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
preg_match_all('~data:(image/(?:png|jpeg|webp|gif));base64,[A-Za-z0-9+/=]+~',$design,$matches,PREG_SET_ORDER);
foreach($matches as$match)if(hash_equals($image,hash('sha256',$match[0]))){$bytes=base64_decode(substr($match[0],strpos($match[0],',')+1),true);if($bytes!==false){header('Content-Type: '.$match[1]);echo $bytes;exit;}}
http_response_code(404);
