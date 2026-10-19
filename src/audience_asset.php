<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/audience_store.php';
sendApplicationSecurityHeaders();header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$key=\Dnr\Http\RequestInput::string($_GET,'key');if(!preg_match('/^[a-f0-9]{64}$/D',$key)){http_response_code(404);exit;}
$conn=applicationDatabaseConnection();$row=$conn->execute_query("SELECT item_type,name,data_json FROM marketing_design_library WHERE audience_id=1 AND item_type IN ('image','file') AND deleted_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.public_key'))=? AND JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.published')) IN ('1','true') LIMIT 1",[$key])->fetch_assoc();
if(!$row){http_response_code(404);exit;}$data=audienceJson($row['data_json']);if($row['item_type']==='file')audienceContentSendDocument(['name'=>$row['name'],'data'=>$data]);$image=audienceDesignImage($data['image']??'');if(!preg_match('~^data:(image/(?:png|jpeg|webp|gif));base64,([a-zA-Z0-9+/=]+)$~D',$image,$match)){http_response_code(404);exit;}
header('Content-Type: '.$match[1]);echo base64_decode($match[2],true);
