<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/audience_store.php';require_once __DIR__.'/audience_ses_notifications.php';
sendApplicationSecurityHeaders();header('Cache-Control: no-store');header('Content-Type: application/json');
if(!accountSecurityAccessAllowed()){http_response_code(403);exit('{"error":"Unavailable"}');}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit('{"error":"POST required"}');}
if((int)($_SERVER['CONTENT_LENGTH']??0)>262144){http_response_code(413);exit('{"error":"Payload too large"}');}
$raw=file_get_contents('php://input',false,null,0,262145);if($raw===false||strlen($raw)>262144){http_response_code(413);exit;}
try{$store=new AudienceStore(applicationDatabaseConnection(),1,0,'Amazon SES');if(!$store->sesAvailable()){http_response_code(503);exit('{"error":"Not configured"}');}$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new InvalidArgumentException('Invalid notification.');echo json_encode(['result'=>audienceSnsReceive($store,$data)],JSON_THROW_ON_ERROR);}
catch(InvalidArgumentException|JsonException|\Aws\Sns\Exception\InvalidSnsMessageException){http_response_code(403);echo '{"error":"Invalid notification"}';}
catch(Throwable){http_response_code(503);echo '{"error":"Unable to record notification; retry later"}';}
