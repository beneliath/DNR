<?php
declare(strict_types=1);

function renderAudienceInbox(AudienceStore $store,array $audience,string $message,string $error,bool $canManage,bool $canDelete,string $displayName,int $actor):void
{
    if(!$canManage){http_response_code(403);exit('Inbox requires an editor or administrator.');}
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$url=static fn(array $p=[])=>'audience.php?'.http_build_query(['view'=>'inbox']+$p);
    $json=($_POST['format']??'')==='json'||isset($_GET['inbox_api']);
    $respond=static function(array $data,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);};
    $available=$store->inboxAvailable();$canSend=!isRestrictedDemoSession()&&accountSecurityAccessAllowed();$canSources=$canDelete&&$canSend;
    $threadId=max(0,(int)($_GET['thread']??$_POST['thread_id']??0));$draftId=max(0,(int)($_GET['draft']??$_POST['draft_id']??0));
    $panel=in_array($_GET['panel']??'',['sources','labels','templates','notifications','compose','drafts'],true)?$_GET['panel']:'';
    try{
        if(!$available)throw new InvalidArgumentException('Inbox requires the qualified database update.');
        if($_SERVER['REQUEST_METHOD']==='POST'){
            requireValidCsrfToken();$action=audienceText($_POST,'action',40);$id=max(0,(int)($_POST['id']??0));$version=max(0,(int)($_POST['version']??0));$redirect=$url($threadId?['thread'=>$threadId]:[]);$payload=[];
            switch($action){
                case 'inbox_sources':if(!$canSources){http_response_code(403);throw new InvalidArgumentException('Only an unrestricted administrator can configure Inbox sources.');}$store->saveInboxSettings($version,$_POST);$redirect=$url(['panel'=>'sources']);$message='Inbox sources saved.';break;
                case 'inbox_named_save':$kind=audienceText($_POST,'kind',10);$saved=$store->saveInboxNamed($kind,$_POST,$id,$version);$redirect=$url(['panel'=>$kind==='label'?'labels':'templates']);$message='Changes saved.';break;
                case 'inbox_named_remove':$kind=audienceText($_POST,'kind',10);$store->removeInboxNamed($kind,$id,$version);$redirect=$url(['panel'=>$kind==='label'?'labels':'templates']);$message='Item removed. Conversation history was kept.';break;
                case 'inbox_thread':$store->inboxThreadOperation($threadId,$version,audienceText($_POST,'operation',20),$_POST);$message='Conversation updated.';break;
                case 'inbox_read':$store->inboxMarkRead($threadId,($_POST['read']??'')==='1');$message='Read status updated.';break;
                case 'inbox_comment':$store->inboxComment($threadId,audienceText($_POST,'body_html',30000),max(0,(int)($_POST['parent_id']??0)));$message='Internal comment added.';break;
                case 'inbox_link':$t=$store->inboxThread($threadId);$p=$store->one('SELECT id FROM marketing_contacts WHERE audience_id=? AND LOWER(email)=LOWER(?) AND deleted_at IS NULL AND is_archived=0',[$store->audienceId,$t['sender_email']]);if(!$p)throw new InvalidArgumentException('Add this address as a connection with its appropriate consent before replying.');$store->write('UPDATE marketing_inbox_threads SET contact_id=?,version=version+1 WHERE audience_id=? AND id=? AND version=?',[$p['id'],$store->audienceId,$threadId,$version]);if($store->db->affected_rows!==1)throw new InvalidArgumentException('This conversation changed. Reload before linking.');$message='Connection linked. Sending still checks its email consent.';break;
                case 'inbox_draft_save':$draftId=$store->saveInboxDraft($_POST,$draftId,$version);$payload=['draft'=>$store->inboxDraft($draftId)];$redirect=$url(['panel'=>'compose','draft'=>$draftId]);$message='Draft saved.';break;
                case 'inbox_draft_discard':$store->discardInboxDraft($draftId,$version);$redirect=$url(['panel'=>'drafts']);$message='Draft discarded.';break;
                case 'inbox_attachment':$store->inboxDraftAttachment($draftId,$version,$_FILES['attachment']??[],max(0,(int)($_POST['remove']??0)));$payload=['draft'=>$store->inboxDraft($draftId)];$redirect=$url(['panel'=>'compose','draft'=>$draftId]);$message='Attachments updated.';break;
                case 'inbox_send':if(!$canSend){http_response_code(403);throw new InvalidArgumentException('Sending is unavailable in this access mode.');}$result=$store->queueInboxDraft($draftId,$version,$audience,accountPrimaryPublicUrl());$redirect=$url(['thread'=>$result['thread_id']]);$payload=['redirect'=>$redirect];$message='Message queued. Delivery status will appear in the conversation.';break;
                case 'inbox_notifications':$store->saveInboxNotificationSettings($version,$_POST);$redirect=$url(['panel'=>'notifications']);$message='Your notification settings were saved.';break;
                case 'inbox_presence':$payload=['presence'=>$store->inboxPresence($threadId,($_POST['typing']??'')==='1')];break;
                default:throw new InvalidArgumentException('Unknown Inbox action.');
            }
            if($json){$respond(['message'=>$message]+$payload);return;}
            $_SESSION['audience_message']=$message;header('Location: '.$redirect);return;
        }
        if(isset($_GET['inbox_api'])){
            $data=match($_GET['inbox_api']){
                'recipients'=>['items'=>$store->inboxRecipients(audienceText($_GET,'q',100))],
                'notifications'=>['items'=>$store->inboxNotifications(max(0,(int)($_GET['after']??0))),'settings'=>$store->inboxNotificationSettings()],
                'thread'=>['thread'=>$store->inboxThread($threadId),'messages'=>$store->inboxMessages($threadId,max(0,(int)($_GET['before']??0)))],
                default=>throw new InvalidArgumentException('Unknown Inbox request.')};
            releaseApplicationSessionLock();$respond($data);return;
        }
        if(isset($_GET['attachment'])){
            $a=$store->one('SELECT * FROM marketing_inbox_attachments WHERE audience_id=? AND id=?',[$store->audienceId,max(0,(int)$_GET['attachment'])]);if(!$a)throw new InvalidArgumentException('This attachment is unavailable.');
            if($a['message_id']){$m=$store->one('SELECT thread_id FROM marketing_inbox_messages WHERE audience_id=? AND id=?',[$store->audienceId,$a['message_id']]);$store->inboxThread((int)($m['thread_id']??0));}else{$store->inboxDraft((int)$a['draft_id']);}
            header('Content-Type: '.$a['mime_type']);header('Content-Disposition: attachment; filename="attachment"; filename*=UTF-8\'\''.rawurlencode($a['filename']));header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');header('Content-Length: '.strlen($a['content']));echo $a['content'];return;
        }
    }catch(InvalidArgumentException|DomainException $e){$error=$e->getMessage();if($json){$respond(['error'=>$error],http_response_code()===403?403:422);return;}}
    catch(Throwable $e){applicationLog('error','Inbox operation failed',['error'=>$e->getMessage()]);$error='Unable to complete this Inbox request. Reload and try again.';if($json){$respond(['error'=>$error],500);return;}}
    $settings=$available?$store->inboxSettings():[];$list=$available?$store->inboxList($_GET):['items'=>[],'counts'=>[],'sources'=>[],'total'=>0,'page'=>1,'status'=>'todo'];
    $labels=$available?$store->inboxLabels():[];$templates=$available?$store->inboxTemplates():[];$thread=null;$messages=[];$draft=null;$recipient=null;
    try{if(!$available){$threadId=0;$draftId=0;}if($threadId){$thread=$store->inboxThread($threadId);$messages=$store->inboxMessages($threadId,max(0,(int)($_GET['before']??0)));}if($draftId){$draft=$store->inboxDraft($draftId);$threadId=(int)$draft['thread_id'];if($threadId)$thread=$store->inboxThread($threadId);if($draft['contact_id'])$recipient=$store->person((int)$draft['contact_id']);}elseif($panel==='compose'&&!empty($_GET['contact'])){$recipient=$store->person((int)$_GET['contact']);}elseif($panel==='compose'&&$thread){$recipient=$thread['contact'];}}catch(InvalidArgumentException $e){$error=$e->getMessage();}
    $drafts=$available?$store->all("SELECT id,subject,updated_at FROM marketing_inbox_drafts WHERE audience_id=? AND actor_id=? AND state='draft' ORDER BY updated_at DESC LIMIT 100",[$store->audienceId,$actor]):[];
    $preferences=$available?$store->inboxNotificationSettings():['version'=>0,'email_alerts'=>0,'browser_alerts'=>0];$readiness=$available?$store->inboxSendReadiness():['ready'=>false,'reasons'=>[]];
    $csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':'.$store->audienceId;
    $config=['endpoint'=>$url(),'thread'=>$threadId,'lastMessage'=>$thread?(int)$thread['last_message_id']:0,'draft'=>$draftId,'draftVersion'=>(int)($draft['version']??0),'browserAlerts'=>(bool)$preferences['browser_alerts'],'notifications'=>$available?$store->inboxNotifications():[]];
    releaseApplicationSessionLock();?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle('Inbox'),['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-inbox.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-inbox.min.js']]);include __DIR__.'/templates/audience/inbox.php';?></html><?php
}
