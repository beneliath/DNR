<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/audience_store.php';
sendApplicationSecurityHeaders();header('Cache-Control: no-store');header('Content-Type: application/json');
if(!accountSecurityAccessAllowed()){http_response_code(403);exit('{"error":"Delivery events are disabled for this account"}');}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{"error":"POST required"}');}
if((int)($_SERVER['CONTENT_LENGTH']??0)>64000){http_response_code(413);exit('{"error":"Payload too large"}');}
$raw=file_get_contents('php://input',false,null,0,64001);$stamp=(string)($_SERVER['HTTP_X_MESSAGE_MONKEY_TIMESTAMP']??'');$signature=(string)($_SERVER['HTTP_X_MESSAGE_MONKEY_SIGNATURE']??'');
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Authenticated event source');if(!$store->deliveryRuntimeAvailable()){http_response_code(503);exit('{"error":"Event receiver is not configured"}');}if($store->sesAvailable()){http_response_code(410);exit('{"error":"Use the signed SES SNS event receiver"}');}$policy=$store->deliveryPolicy();
if(empty($policy['event_key_ciphertext'])){http_response_code(503);exit('{"error":"Event source is not configured"}');}
$key=\Dnr\Security\ApplicationKey::open($policy['event_key_ciphertext']);
if(!ctype_digit($stamp)||abs(time()-(int)$stamp)>300||!hash_equals(hash_hmac('sha256',$stamp.'.'.$raw,$key),$signature)){http_response_code(403);exit('{"error":"Invalid event signature"}');}
try{
 $event=json_decode($raw,true,16,JSON_THROW_ON_ERROR);$id=audienceText($event,'id',100);$type=audienceText($event,'type',30);$messageId=audienceText($event,'message_id',140);$at=audienceText($event,'event_at',30);
 if($id===''||!in_array($type,['delivered','soft-bounce','hard-bounce','complaint'],true)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$at))throw new InvalidArgumentException('Invalid event fields.');
 $time=strtotime($at);if($time===false||$time<time()-30*86400||$time>time()+300)throw new InvalidArgumentException('Event time is outside the accepted window.');
 $recipient=$store->one('SELECT id FROM marketing_campaign_recipients WHERE audience_id=1 AND message_id=? AND delivery_started_at IS NOT NULL',[$messageId]);if(!$recipient){http_response_code(404);exit('{"error":"Message not found"}');}
 $inserted=$store->recordDeliveryEvent((int)$recipient['id'],$type,hash('sha256',$id),'provider',gmdate('Y-m-d H:i:s',$time));echo json_encode(['accepted'=>true,'duplicate'=>!$inserted]);
}catch(InvalidArgumentException|JsonException $e){http_response_code(422);echo '{"error":"Invalid event payload"}';}
