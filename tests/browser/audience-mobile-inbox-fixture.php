<?php
declare(strict_types=1);
// Actual Inbox templates with fictional conversations, no database or mail.
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
$_SESSION=['user_id'=>11,'username'=>'fixture-admin','role'=>'admin','_csrf_token'=>'fixture-token'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query(['view'=>'inbox']+$params);
$view='inbox';$themeKey='mobile-inbox-fixture';$displayName='Example User';
$canManage=$canDelete=$canSources=true;$canSend=false;$available=true;
$message=$error=$panel='';$csrf=csrfInput();$draftWorkspace=false;$draft=$recipient=null;
$labels=$templates=$archivedTemplates=$drafts=$draftItems=[];
$draftId=0;$draftQuery='';$draftUrl=$url;
$threadId=($argv[1]??'')==='thread'?1:0;
$items=[];
foreach(['Alexandria Montgomery','Jordan Lee']as$i=>$name)$items[]=['id'=>$i+1,'version'=>1,'sender_name'=>$name,'sender_email'=>'connection'.($i+1).'@example.test','subject'=>'A question about the community newsletter','source'=>'email','status'=>'todo','unread'=>true,'direction'=>'inbound','last_activity_at'=>'2026-10-28 14:00:00','preview'=>'Thank you for the update. Where can I find the event details?','labels'=>[],'sender_verified'=>true,'contact'=>['id'=>$i+1]];
$list=['items'=>$items,'total'=>2,'page'=>1,'per_page'=>25,'status'=>'todo','q'=>'','source'=>'','label'=>'','unread'=>0,'counts'=>['todo'=>2],'sources'=>['email'=>2]];
$thread=$threadId?$items[0]:null;
$messages=$threadId?[['id'=>1,'parent_id'=>0,'direction'=>'inbound','sender_name'=>$items[0]['sender_name'],'sender_email'=>$items[0]['sender_email'],'occurred_at'=>'2026-10-28 14:00:00','body_text'=>$items[0]['preview'],'body_html'=>'<p>'.$items[0]['preview'].'</p>','attachments'=>[],'delivery_state'=>'received','delivery_error'=>'','delivery_events'=>[]]]:[];
$messageTotal=1;$messagePage=1;$messageSize=25;$before=0;
$settings=[];$readiness=['ready'=>false,'reasons'=>['Sending is disabled in this fixture']];$replyReasons=[];
$config=['endpoint'=>$url(),'thread'=>$threadId,'draftWorkspace'=>false,'lastMessage'=>1,'draft'=>0,'draftVersion'=>0,'browserAlerts'=>false,'notifications'=>[]];
echo '<!doctype html><html lang="en">';
renderPageHead('Mobile Inbox fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-inbox.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-inbox.min.js']]);
require __DIR__.'/../../src/templates/audience/inbox.php';
echo '</html>';
