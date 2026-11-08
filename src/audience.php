<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/two_factor_helpers.php';
require_once __DIR__.'/audience_store.php';
require_once __DIR__ . '/message_monkey/controllers/contacts.php';
$db=applicationDatabaseConnection();startSecureSession();requireLogin();
$canManage=hasRole(['admin','editor']);$canExport=$canManage;$canDelete=hasRole(['admin']);
$workspaceRequest=\Dnr\MessageMonkey\WorkspaceRequest::fromInput($_GET,$_POST);
$view=$workspaceRequest->view;
$_GET=audienceRememberPageSizes($_GET,$view,(int)$_SESSION['user_id']);
if(!$canDelete&&$workspaceRequest->requiresDeliveryAdmin()){http_response_code(403);exit('Email delivery requires an administrator.');}
$actor=(int)$_SESSION['user_id'];$displayName=mb_substr((string)($_SESSION['profile_display_name']??$_SESSION['username']??'Account'),0,100);
// Each MOED account has one canonical audience. Older bookmarked selectors resolve here.
$audienceId=1;
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['audience'])){
    $canonical=$_GET;unset($canonical['audience']);
    header('Location: audience.php'.($canonical?'?'.http_build_query($canonical):''));exit;
}
unset($_SESSION['marketing_audience']);
$store=new AudienceStore($db,$audienceId,$actor,$displayName);
if($view==='campaigns'&&($_GET['api']??null)==='campaign_thumbnail'){
    releaseApplicationSessionLock();header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
    try{$campaign=$store->campaign(\Dnr\Http\RequestInput::positiveInt($_GET,'id')??0,false,true);}
    catch(InvalidArgumentException){http_response_code(404);exit;}
    $thumbnail=audienceCampaignListThumbnail($campaign['draft']);
    header('X-Content-Type-Options: nosniff');
    if($thumbnail!==null){header('Content-Type: image/png');echo $thumbnail;}
    else{
        // Empty/unsupported image blocks leave the normal email icon visible underneath.
        header('Content-Type: image/gif');echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }
    exit;
}
if($view==='content'&&($_GET['api']??null)==='content_image'){
    releaseApplicationSessionLock();
    header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
    try{
        $id=\Dnr\Http\RequestInput::positiveInt($_GET,'id')??0;
        $row=$store->one("SELECT JSON_UNQUOTE(JSON_EXTRACT(data_json,'$.image')) image FROM marketing_design_library WHERE audience_id=? AND id=? AND item_type='image' AND deleted_at IS NULL",[$audienceId,$id]);
        $image=audienceDesignImage($row['image']??'',1000000);
        if($image==='')throw new InvalidArgumentException();
        [$type,$encoded]=explode(';base64,',$image,2);
        header('Content-Type: '.substr($type,5));
        header("Content-Security-Policy: sandbox; default-src 'none'");header('X-Content-Type-Options: nosniff');
        echo base64_decode($encoded,true);
    }catch(InvalidArgumentException){http_response_code(404);}
    exit;
}
$audience=$db->query('SELECT * FROM marketing_audiences WHERE id=1')->fetch_assoc();
if(!$audience){http_response_code(503);exit('Audience setup is incomplete.');}
if(($_GET['api']??null)==='notifications'){
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    if(!$canManage){http_response_code(403);echo json_encode(['error'=>'Notifications require an editor or administrator.']);exit;}
    if(!in_array($_SERVER['REQUEST_METHOD'],['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');echo json_encode(['error'=>'Use GET or POST.']);exit;}
    if($_SERVER['REQUEST_METHOD']==='POST'&&!validateCsrfToken($_POST['csrf_token']??null)){http_response_code(400);echo json_encode(['error'=>'Invalid or expired request token. Reload the page.']);exit;}
    releaseApplicationSessionLock();require_once __DIR__.'/audience_notification_state.php';
    try{
        if(!audienceBellSyncAvailable($store)){http_response_code(503);echo json_encode(['error'=>'Notification synchronization is being upgraded.']);exit;}
        if($_SERVER['REQUEST_METHOD']==='POST'){
            $keys=json_decode(audienceText($_POST,'keys',40000),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($keys)||!array_is_list($keys))throw new InvalidArgumentException('Invalid notification keys.');
            audienceDismissBellItems($store,$keys);
        }
        echo json_encode(audienceBellState($store),JSON_THROW_ON_ERROR);
    }catch(InvalidArgumentException|JsonException $e){http_response_code(400);echo json_encode(['error'=>'Invalid notification keys.']);}
    catch(Throwable $e){applicationLog('error','Notification synchronization failed',['error'=>$e->getMessage()]);http_response_code(503);echo json_encode(['error'=>'Notifications could not be synchronized.']);}
    exit;
}
if(($_GET['api']??null)==='workspace_search'){
    releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');$result=['error'=>'Search requires a GET request.'];}
    else try{$result=audienceWorkspaceSearch($store,$_GET,$canManage,$canDelete);}catch(InvalidArgumentException $e){http_response_code(400);$result=['error'=>$e->getMessage()];}
    echo json_encode($result,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);exit;
}
// Apply transfer permissions before dispatching to any MessageMonkey workspace.
if(!$canExport&&$workspaceRequest->requiresTransferAccess()){http_response_code(403);exit('Import and export require an editor or administrator.');}
$id=$workspaceRequest->id;
$message=(string)($_SESSION['audience_message']??'');unset($_SESSION['audience_message']);$error='';
switch ($workspaceRequest->controller()) {
    case 'manual':
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
            http_response_code(405); header('Allow: GET, HEAD'); exit('The user manual is read-only.');
        }
        require __DIR__ . '/audience_manual_controller.php';
        renderAudienceManual($audience, $canManage, $canDelete, $displayName, $actor, $store);
        exit;
    case 'assistant':
        require __DIR__ . '/audience_ai_controller.php';
        renderAudienceAi($store, $audience, $canManage, $canDelete, $displayName, $actor);
        exit;
    case 'analytics':
        require __DIR__ . '/audience_analytics_controller.php';
        renderAudienceAnalytics($store, $audience, $view, $message, $error, $canManage, $canDelete, $displayName, $actor);
        exit;
    case 'inbox':
        require __DIR__ . '/audience_inbox_controller.php';
        renderAudienceInbox($store, $audience, $message, $error, $canManage, $canDelete, $displayName, $actor);
        exit;
    case 'flows':
        require __DIR__ . '/audience_flow_controller.php';
        renderAudienceFlowWorkspace($store, $audience, $view, $id, $message, $error, $canManage, $canDelete, $displayName, $actor);
        exit;
    case 'collection':
        require __DIR__ . '/audience_collection_controller.php';
        renderAudienceCollectionWorkspace($store, $audience, $view, $id, $message, $error, $canManage, $canDelete, $displayName, $actor);
        exit;
    case 'design':
        require __DIR__ . '/audience_design_controller.php';
        renderAudienceDesignWorkspace($store, $audience, $view, $id, $message, $error, $canManage, $canDelete, $displayName, $actor);
        exit;
    case 'campaigns':
        require __DIR__ . '/audience_campaign_controller.php';
        renderAudienceCampaignWorkspace($store, $audience, $view, $id, $message, $error, $canManage, $canDelete, $displayName, $actor);
        exit;
}
renderAudienceContactsWorkspace($store, $audience, $view, $id, $message, $error, $canManage, $canDelete, $displayName, $actor);
