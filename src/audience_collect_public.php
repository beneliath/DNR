<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/audience_store.php';
require_once __DIR__.'/audience_collection_render.php';
sendApplicationSecurityHeaders();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow');
$db=applicationDatabaseConnection(); $store=new AudienceStore($db,1,0,'Public form');
if (!$store->collectionAvailable()) { http_response_code(404); exit('This form is unavailable.'); }
$key=is_string($_GET['key']??null)?$_GET['key']:''; $r=$store->collectionPublished($key);
if (!$r) { http_response_code(404); exit('This form is unavailable or paused.'); }
if (!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
$d=$r['document']; $publicUrl=accountPrimaryPublicUrl().'/audience_collect_public.php?key='.$key;
if (isset($_GET['embed'])) {
    header('Content-Type: application/javascript; charset=UTF-8');
    if($r['kind']==='popup'&&!empty($d['popup']['countries'])&&!in_array(audienceCollectionCountry($_SERVER),$d['popup']['countries'],true)){echo '/* This popup is not eligible for this location. */';exit;}
    $config=['url'=>$publicUrl,'key'=>$key,'kind'=>$r['kind'],'origins'=>$d['origins'],'popup'=>$d['popup']];
    echo '(function(){"use strict";const config='.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';';
    readfile(__DIR__.'/assets/js/audience-collection-embed.js'); echo '})();'; exit;
}
$nonce=contentSecurityPolicyNonce(); $ancestors="'none'";
if (in_array($r['kind'],['embedded','popup'],true)) { $ancestors="'self' ".implode(' ',$d['origins']); header_remove('X-Frame-Options'); }
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-{$nonce}'; img-src 'self' data: https:; form-action 'self'; frame-ancestors {$ancestors}; base-uri 'none'; object-src 'none'");
$visitorCookie='dnr_collection_'.substr($key,0,12);
$visitor=is_string($_GET['visitor']??null)?$_GET['visitor']:(is_string($_COOKIE[$visitorCookie]??null)?$_COOKIE[$visitorCookie]:'');
if (!preg_match('/^[a-f0-9]{64}$/D',$visitor)) $visitor=bin2hex(random_bytes(32));
setcookie($visitorCookie,$visitor,['expires'=>time()+86400*365,'path'=>rtrim(parse_url(accountPrimaryPublicUrl(),PHP_URL_PATH)?:'/','/').'/','secure'=>requestUsesHttps(),'httponly'=>true,'samesite'=>'Lax']);
$variant=chr(ord('A')+(hexdec(substr(hash('sha256',$r['public_key'].$visitor),0,6))%(count($d['variants'])+1))); $r['variant']=$variant;
$recipient=is_string($_GET['recipient']??null)?$_GET['recipient']:'';if(!preg_match('/^[a-f0-9]{64}$/D',$recipient))$recipient=''; $person=$r['kind']==='preferences'?$store->collectionRecipient($recipient):null;
if ($r['kind']==='preferences') {
    if (!$person) { http_response_code(404); exit('Use the private preferences link in your email.'); }
    $person=$store->person((int)$person['id']);
}
$error=''; $success=''; $values=[]; $proof='';
if($r['kind']==='survey' && isset($_GET['choice']) && count($d['questions'])===1){$q=$d['questions'][0];$choice=\Dnr\Http\RequestInput::positiveInt($_GET,'choice')??0;if($q['type']==='radio'&&$choice>0&&isset($q['options'][$choice-1]))$values['answers'][$q['id']]=$q['options'][$choice-1];}
if(isset($_GET['preview_poll'])){
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit('Preview submissions are disabled.');}
    echo audienceCollectionHtml($r,$store->fields(),$store->groups(),'','',$values,'','',null,true);exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $values=$_POST;
    try {
        $result=$store->submitCollection($r,$_POST,requestIpAddress()??'unknown',accountPrimaryPublicUrl());
        $success=$result['confirmed']?$d['confirmation']:$d['thanks'];
        if ($d['quiz']) foreach($d['quiz']as$q)if($q['label']===audienceText($_POST,'quiz',200))$success.="\n\n".$q['outcome'];
    } catch (InvalidArgumentException $e) { http_response_code(422); $error=$e->getMessage(); }
    catch (Throwable $e) { http_response_code(503); $error='This form is temporarily unavailable. Please try again later.'; }
}
if ($success==='') $proof=$store->collectionChallenge($r,$visitor,$variant,$recipient);
if ($_SERVER['REQUEST_METHOD']==='GET') {
    $db->execute_query('INSERT IGNORE INTO marketing_collection_visits(resource_id,day,visitor_hash,variant_key) VALUES (?,UTC_DATE(),?,?)',[$r['id'],hash('sha256',$key.$visitor),$variant]);
    $ref=parse_url((string)($_SERVER['HTTP_REFERER']??''));
    if (is_array($ref) && isset($ref['scheme'],$ref['host'])) {
        $origin=$ref['scheme'].'://'.$ref['host'].(isset($ref['port'])?':'.$ref['port']:'');
        if (in_array($origin,$d['origins'],true)) $db->execute_query('UPDATE marketing_collection_resources SET installed_at=UTC_TIMESTAMP() WHERE audience_id=1 AND id=?',[$r['id']]);
    }
}
echo audienceCollectionHtml($r,$store->fields(),$store->groups(),$publicUrl.($recipient!==''?'&recipient='.rawurlencode($recipient):''),$proof,$values,$error,$success,$person);
