<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/audience_inbox_public.php';
sendApplicationSecurityHeaders();header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,0,'Contact form visitor');
if(!$store->inboxAvailable()){http_response_code(404);exit('This form is unavailable.');}
$s=$store->inboxSettings();$token=audienceText($_GET,'key',64);
if(!$s['contact_enabled']||!preg_match('/\A[a-f0-9]{64}\z/D',$token)||!hash_equals($s['contact_token'],$token)){http_response_code(404);exit('This form is unavailable.');}
$done=false;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){try{audienceInboxSubmitContact($store,$s,$_POST,requestIpAddress()??'unknown');$done=true;}catch(InvalidArgumentException $e){http_response_code(422);$error=$e->getMessage();}}
elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET, POST');exit;}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$d=$s['document'];
?><!doctype html><html lang="en"><?php renderPageHead($d['title'],['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-inbox.min.css']]); ?><body class="audience-inbox-public"><main><h1><?= $h($d['title']) ?></h1><?php if($done): ?><p role="status"><?= $h($d['success']) ?></p><?php else: ?><p><?= $h($d['intro']) ?></p><?php if($error): ?><p role="alert"><?= $h($error) ?></p><?php endif; ?><form method="post"><input type="hidden" name="proof" value="<?= $h(audienceInboxContactProof((int)$s['version'])) ?>"><label>Name<input name="name" maxlength="100" autocomplete="name" value="<?= $h($_POST['name']??'') ?>"></label><label>Email address<input name="email" type="email" required maxlength="254" autocomplete="email" value="<?= $h($_POST['email']??'') ?>"></label><label>Subject<input name="subject" maxlength="250" value="<?= $h($_POST['subject']??'') ?>"></label><label>Message<textarea name="message" rows="8" required maxlength="20000"><?= $h($_POST['message']??'') ?></textarea></label><label class="inbox-honeypot" aria-hidden="true">Website<input name="website" tabindex="-1" autocomplete="off"></label><button class="primary"><?= $h($d['button']) ?></button></form><?php endif; ?></main></body></html>
