<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';require_once __DIR__.'/audience_inbox_public.php';
sendApplicationSecurityHeaders();header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
$db=applicationDatabaseConnection();$token=audienceText($_GET,'token',64);$post=$_SERVER['REQUEST_METHOD']==='POST';
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
if(!audienceInboxInstalled($db)||!audienceInboxOptout($db,$token,$post)){http_response_code(404);exit('This link is unavailable or has expired.');}
?><!doctype html><html lang="en"><?php renderPageHead('One-to-one email preferences',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-inbox.min.css']]); ?><body class="audience-inbox-public"><main><h1>One-to-one email preferences</h1><?php if($post): ?><p role="status">You will no longer receive one-to-one Inbox messages unless you contact us again. Your newsletter subscription has not changed.</p><?php else: ?><p>Stop receiving one-to-one Inbox messages. Your newsletter subscription will stay the same.</p><form method="post"><button class="primary">Stop one-to-one messages</button></form><?php endif; ?></main></body></html>
