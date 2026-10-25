<?php
// Called only after the shared Audience login/account/role checks.
function renderAudienceCampaignWorkspace(AudienceStore $store,array $audience,string $view,int $id,string $message,string $error,bool $canManage,bool $canDelete,string $displayName,int $actor): void
{
$dispatchInstalled=$store->campaignDispatchAvailable();$runtimeInstalled=$store->deliveryRuntimeAvailable();
$audienceId=$store->audienceId;$isPost=$_SERVER['REQUEST_METHOD']==='POST';$canExport=$canManage;
$h=static fn($value):string=>htmlspecialchars(is_scalar($value)?(string)$value:'',ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[]):string=>'audience.php'.($params?'?'.http_build_query($params):'');
$contentUrl=static fn(array $changes=[])=>$url(array_replace(['view'=>'content']+array_intersect_key($_GET,array_flip(['search','folder','type','sort','direction','layout','trash','per_page'])),$changes));
$campaignJson=static fn($value)=>$h(json_encode($value,JSON_THROW_ON_ERROR));
$view=in_array($view,['campaign','campaign-manager','campaign-plan','campaign-review','campaign-send','campaign-delivery','content','brand','delivery'],true)?$view:'campaigns';
if($view==='content'&&!$isPost&&($_GET['download']??'')==='file'){try{$file=$store->designLibraryItem($id);if($file['item_type']!=='file')throw new InvalidArgumentException('File unavailable.');audienceContentSendDocument($file);}catch(InvalidArgumentException){http_response_code(404);exit('File unavailable.');}}
$campaignFilters=audienceCampaignFilters([]);
try{$campaignFilters=audienceCampaignFilters($_GET);}catch(InvalidArgumentException $e){$error=$e->getMessage();}
$campaignUrl=static fn(array $changes=[]):string=>$url(array_replace(['view'=>'campaigns']+$campaignFilters,$changes));
$redirect=$campaignUrl();$campaignRecovery=null;
$canSend=$canManage&&!isRestrictedDemoSession()&&accountSecurityAccessAllowed();
$canConfigureDelivery=$canDelete&&!isRestrictedDemoSession()&&accountSecurityAccessAllowed();
if($isPost){
    requireValidCsrfToken();if(!$canManage){http_response_code(403);exit('You do not have permission to manage campaigns.');}
    try{
        if(isset($_POST['audience'])&&(string)$_POST['audience']!=='1')throw new InvalidArgumentException('Reload the canonical audience before saving.');
        $action=audienceText($_POST,'action');$version=\Dnr\Http\RequestInput::positiveInt($_POST,'version')??0;
        if(in_array($action,['brand_save','brand_logo_svg'],true)&&!$canDelete){http_response_code(403);exit('Only administrators can change Brand Kit settings.');}
        if($action==='brand_logo_svg'){
            releaseApplicationSessionLock();header('Content-Type: application/json');header('Cache-Control: no-store');
            try{$image=audienceSvgImage(audienceText($_POST,'image',2700000));[$width,$height]=audienceLogoDimensions($image);echo json_encode(['data'=>$image,'width'=>$width,'height'=>$height],JSON_THROW_ON_ERROR);}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}return;
        }
        if($action==='campaign_preview'){
            header('Content-Type: application/json');header('Cache-Control: no-store');
            try{
                $row=$store->campaign($id);$candidate=$store->campaignAdvancedSection($row,'to',$_POST);[$where,$params]=$store->campaignTarget($candidate);
                $count=(int)$store->one("SELECT COUNT(*) n FROM marketing_contacts c WHERE $where",$params)['n'];
                $people=$store->all("SELECT c.id,c.email,c.first_name,c.last_name FROM marketing_contacts c WHERE $where ORDER BY c.email,c.id LIMIT 100",$params);
                echo json_encode(['count'=>$count,'people'=>$people],JSON_THROW_ON_ERROR);
            }catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()],JSON_THROW_ON_ERROR);}exit;
        }
        if(str_starts_with($action,'delivery_')){
            if(!$canConfigureDelivery){http_response_code(403);exit('Email delivery setup requires an unrestricted administrator.');}
            if(!$store->deliverySetupAvailable())throw new InvalidArgumentException('Email delivery setup is not installed yet.');
        }
        if(in_array($action,['campaign_dispatch_confirm','campaign_delivery_operation','campaign_runtime_operation','campaign_test'],true)&&!$canSend){http_response_code(403);exit('Sending requires an unrestricted editor or administrator.');}
        if($store->sesAvailable()&&str_starts_with($action,'delivery_')&&!in_array($action,['delivery_ses_save','delivery_ses_operation'],true))throw new InvalidArgumentException('MessageMonkey delivery now uses this account’s SES API connection.');
        switch($action){
            case 'delivery_ses_save':$store->saveSesSettings($version,$_POST);$redirect=$url(['view'=>'delivery','tab'=>'relay']);$message='SES settings saved. A connection check is queued; no email is sent by this check.';break;
            case 'delivery_ses_operation':$store->sesOperation($version,audienceText($_POST,'operation'));$redirect=$url(['view'=>'delivery','tab'=>'relay']);$message='SES connection updated.';break;
            case 'campaign_test':
                if(!$runtimeInstalled)throw new InvalidArgumentException('The delivery upgrade is not installed yet.');
                $n=$store->queueCampaignTest($id,$version,$_POST,$audience,accountPrimaryPublicUrl());$redirect=$url(['view'=>'campaign','id'=>$id]);$message=$n.' test email(s) queued. Follow their receipts in Email delivery → Activity.';break;
            case 'campaign_runtime_operation':$store->campaignRuntimeOperation($id,$version,audienceText($_POST,'operation'),audienceText($_POST,'winner',30));$redirect=$url(['view'=>'campaign-delivery','id'=>$id]);$message='Delivery updated.';break;
            case 'delivery_policy':$store->saveDeliveryPolicy($version,$_POST);$redirect=$url(['view'=>'delivery','tab'=>'limits']);$message='Sending allocation saved.';break;
            case 'delivery_event_key':$store->rotateDeliveryEventKey($version);$redirect=$url(['view'=>'delivery','tab'=>'events']);$message='Event signing key replaced. Update the trusted event source with this key.';break;
            case 'campaign_dispatch_confirm':
                if(($_POST['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm the reviewed recipients and content before sending.');
                $store->confirmCampaignDispatch($id,$version,audienceText($_POST,'review_proof',10000),$audience,accountPrimaryPublicUrl());$redirect=$url(['view'=>'campaign-delivery','id'=>$id]);$message='Delivery confirmed. You can follow its progress here.';break;
            case 'campaign_delivery_operation':$store->campaignDeliveryOperation($id,$version,audienceText($_POST,'operation'));$redirect=$url(['view'=>'campaign-delivery','id'=>$id]);$message='Delivery updated.';break;
            case 'delivery_request':$senderId=$store->requestSenderVerification(audienceText($_POST,'email',254));$redirect=$url(['view'=>'delivery','sender'=>$senderId]);$message='Verification email queued. Check your inbox after the mail worker processes this request.';break;
            case 'delivery_verify':if(!$store->verifySendingAddress($id,audienceText($_POST,'code',40)))throw new InvalidArgumentException('That code is invalid, expired, or not ready. Check the email or request a new verification.');$redirect=$url(['view'=>'delivery','sender'=>$id]);$message='Email address verified. Domain authentication and relay readiness are checked separately.';break;
            case 'delivery_probe':$store->queueRelayCheck();$redirect=$url(['view'=>'delivery','tab'=>'relay']);$message='Connection check queued. No email will be sent by this check.';break;
            case 'delivery_records':$store->saveSendingRecords($id,$version,is_array($_POST['records']??null)?$_POST['records']:[]);$redirect=$url(['view'=>'delivery','domain'=>$id]);$message='Provider DNS requirements saved. Check the records after updating your DNS.';break;
            case 'delivery_dns':$result=$store->checkSendingDomain($id,$version);$redirect=$url(['view'=>'delivery','domain'=>$id]);$message=$result['complete']?'DKIM and DMARC records verified.':'Some DNS records are not ready. Review the results and check again after DNS updates propagate.';break;
            case 'delivery_domain_operation':$store->sendingDomainOperation($id,$version,audienceText($_POST,'operation'));$redirect=$url(['view'=>'delivery']);$message='Domain settings updated. Connections and campaign content were kept.';break;

            case 'content_rename':$store->operateDesignLibrary($id,$version,'rename',audienceText($_POST,'name',100));$redirect=$url(['view'=>'content','item'=>$id]);$message='File renamed.';break;
            case 'content_save':if(($_FILES['image_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$_POST=array_replace($_POST,audienceContentFileUpload($_FILES['image_file']));$store->saveContentItem($_POST,$id,$version);$redirect=$contentUrl();$message='File saved.';break;
            case 'content_operation':
                $operation=audienceText($_POST,'operation');
                if(in_array($operation,['delete','purge'],true)){
                    if(!$canDelete){http_response_code(403);exit('Only administrators can delete content files.');}
                    requireRecentAdminElevation($contentUrl());
                }
                if($operation==='purge'&&($_POST['confirm_delete']??'')!=='1')throw new InvalidArgumentException('Confirm permanent deletion of the selected files from Trash.');
                $store->contentOperation($operation,audienceIds($_POST['selected']??[]),is_array($_POST['versions']??null)?$_POST['versions']:[],audienceText($_POST,'folder',100));$redirect=$contentUrl($operation==='purge'?['trash'=>'1','page'=>1]:[]);$message=$operation==='purge'?'Files permanently deleted. Existing email snapshots are preserved.':'Files updated. Existing email snapshots are preserved.';break;
            case 'content_folder':$store->contentFolder(audienceText($_POST,'operation'),audienceText($_POST,'name',100),audienceText($_POST,'new_name',100),$version);$redirect=$url(['view'=>'content']);$message='Folder updated.';break;
            case 'brand_save':$store->saveBrand($_POST,$version);$redirect=$url(['view'=>'brand']);$message='Brand settings saved.';break;
            case 'brand_apply':$store->applyBrand((int)audienceText($_POST,'template_id',12),$version,($_POST['include_logo']??'')==='1');$redirect=$url(['view'=>'templates','tab'=>'saved']);$message='Brand applied. The previous template revision is retained.';break;
            case 'campaign_plan_save':$planId=$store->saveCampaignPlan($_POST,$id,$version);$redirect=$url(['view'=>'campaign-plan','id'=>$planId]);$message='Campaign plan saved.';break;
            case 'campaign_plan_operation':
                $operation=audienceText($_POST,'operation');
                $listParams=['view'=>'campaign-manager','planner_layout'=>'list','plan_trash'=>in_array($operation,['restore','delete'],true)?'1':'0'];
                foreach(['plan_search'=>100,'plan_sort'=>20,'plan_direction'=>4,'plan_size'=>3,'plan_page'=>8]as$key=>$limit){$value=audienceText($_POST,$key,$limit);if($value!=='')$listParams[$key]=$value;}
                if($operation==='delete'){
                    if(!$canDelete){http_response_code(403);exit('Deleting a campaign plan requires an administrator and Admin Unlock.');}
                    requireRecentAdminElevation($url($listParams));
                }
                $new=$store->campaignPlanOperation($id,$version,$operation);
                $redirect=$url($new?['view'=>'campaign-plan','id'=>$new]:$listParams);
                $message=match($operation){'archive'=>'Campaign plan archived. Its touchpoints and emails are preserved.','restore'=>'Campaign plan restored.','delete'=>'Campaign plan deleted. Its touchpoints and emails were kept.',default=>'Campaign plan replicated.'};
                break;
            case 'campaign_touchpoint_save':$store->saveCampaignTouchpoint($_POST,$id,$version);$planId=(int)audienceText($_POST,'plan_id',12);$redirect=$url($planId?['view'=>'campaign-plan','id'=>$planId]:['view'=>'campaign-manager']);$message='Touchpoint saved.';break;
            case 'campaign_touchpoint_operation':
                $operation=audienceText($_POST,'operation');$planId=(int)audienceText($_POST,'plan_id',12);
                $touchpointReturn=['view'=>'campaign-manager','planner_layout'=>'agenda','touchpoint_trash'=>in_array($operation,['restore','delete'],true)?'1':'0'];
                if($operation==='delete'){
                    if(!$canDelete){http_response_code(403);exit('Deleting a touchpoint requires an administrator and Admin Unlock.');}
                    requireRecentAdminElevation($url($touchpointReturn));
                }
                $store->campaignTouchpointOperation($id,$version,$operation);
                $redirect=$url($planId?['view'=>'campaign-plan','id'=>$planId]:$touchpointReturn);
                $message=match($operation){'archive'=>'Touchpoint archived.','restore'=>'Touchpoint restored.','delete'=>'Touchpoint deleted. Its email, if any, was kept.',default=>'Touchpoint replicated.'};break;
            case 'campaign_publication':$store->saveCampaignPublication($id,$version,$_POST,$audience);$redirect=$url(['view'=>'campaign','id'=>$id]);$message='Browser version settings saved.';break;
            case 'campaign_comment':$store->addDesignComment('campaign',$id,$_POST);$redirect=$url(['view'=>'campaign-review','id'=>$id]);$message='Comment added.';break;
            case 'campaign_create':$id=$store->createCampaign($_POST);$redirect=$url(['view'=>'campaign','id'=>$id]);$message='Draft created.';break;
            case 'campaign_folder_save':$store->saveCampaignFolder($_POST,$id,$version);$message='Folder saved.';break;
            case 'campaign_folder_delete':$store->deleteCampaignFolder($id,$version);$message='Folder deleted. Its campaigns are now unfiled.';$redirect=$campaignUrl(['folder'=>'']);break;
            case 'campaign_section':$store->saveCampaignSection($id,$version,audienceText($_POST,'section'),$_POST);$redirect=$url(['view'=>'campaign','id'=>$id]);$message='Changes saved.';break;
            case 'campaign_operation':
                $operation=audienceText($_POST,'operation');$selected=audienceIds($_POST['selected']??[]);$versions=is_array($_POST['versions']??null)?$_POST['versions']:[];
                if($operation==='delete'){
                    if(!$canDelete){http_response_code(403);exit('Only administrators can permanently delete archived campaigns.');}
                    requireRecentAdminElevation($campaignUrl());
                    if(audienceText($_POST,'confirm',10)!=='DELETE')throw new InvalidArgumentException('Type DELETE to permanently delete the selected archived campaigns.');
                }
                if(($_POST['selection_scope']??'')==='all'){
                    $selectionFilters=audienceCampaignFilters(audienceJson(audienceText($_POST,'selection_filters',3000)));
                    [$selectionWhere,$selectionParams]=audienceCampaignWhere($audienceId,$selectionFilters);
                    // The exact snapshot is posted, never silently expand to campaigns created since selection.
                    $snapshot=audienceJson(audienceText($_POST,'selection_snapshot',200000));$selected=audienceIds(array_keys($snapshot));$versions=$snapshot;
                    $matching=array_map('intval',array_column($store->all("SELECT c.id FROM marketing_campaigns c WHERE $selectionWhere",$selectionParams),'id'));
                    if(array_diff($selected,$matching))throw new InvalidArgumentException('The selected results changed. Reload before continuing.');
                }
                $new=$store->campaignOperation($operation,$selected,$versions,$_POST);$message=match($operation){'rename'=>'Campaign renamed.','move'=>'Campaigns moved.','archive'=>'Campaigns archived.','restore'=>'Campaigns restored. Restoring does not resume delivery.','delete'=>'Campaigns permanently deleted.',default=>'Draft replicated.'};if($new)$redirect=$url(['view'=>'campaign','id'=>$new]);break;
            default:throw new InvalidArgumentException('Unknown campaign action.');
        }
        $_SESSION['audience_message']=$message;header('Location: '.$redirect);exit;
    }catch(InvalidArgumentException $e){$error=$e->getMessage();}
    catch(Throwable $e){applicationLog('error','Campaign operation failed',['exception'=>get_class($e)]);$error=$e instanceof mysqli_sql_exception&&$e->getCode()===1062?'A folder with that name already exists.':'Unable to save. Reload and try again.';}
    unset($_POST['access_key_id'],$_POST['secret_access_key'],$_POST['session_token']);
    $campaignRecovery=$_POST;
    if($view==='campaign-plan')$id=\Dnr\Http\RequestInput::positiveInt($_GET,'id')??0;
}
$folders=$store->campaignFolders();
$recentCampaigns=$canManage?$store->all('SELECT id,name,version,status FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL ORDER BY updated_at DESC,id DESC LIMIT 3',[$audienceId]):[];
[$campaignWhere,$campaignParams]=audienceCampaignWhere($audienceId,$campaignFilters);
$campaignTotal=(int)$store->one("SELECT COUNT(*) n FROM marketing_campaigns c WHERE $campaignWhere",$campaignParams)['n'];
$campaignSize=(int)$campaignFilters['per_page'];
$campaignPage=max(1,min(max(1,(int)ceil($campaignTotal/$campaignSize)),\Dnr\Http\RequestInput::positiveInt($_GET,'page')??1));
$dispatchColumns=$dispatchInstalled?",(SELECT d.due_at FROM marketing_campaign_deliveries d WHERE d.audience_id=c.audience_id AND d.campaign_id=c.id AND d.flow_run_id=0 ORDER BY d.id DESC LIMIT 1) delivery_due,(SELECT d.recipient_count FROM marketing_campaign_deliveries d WHERE d.audience_id=c.audience_id AND d.campaign_id=c.id AND d.flow_run_id=0 ORDER BY d.id DESC LIMIT 1) delivery_count":"";
$campaignRowIds=array_column($store->all("SELECT c.id FROM marketing_campaigns c WHERE $campaignWhere ORDER BY c.{$campaignFilters['sort']} {$campaignFilters['direction']},c.id {$campaignFilters['direction']} LIMIT ? OFFSET ?",[...$campaignParams,$campaignSize,($campaignPage-1)*$campaignSize]),'id');
$campaignRowsById=[];$campaignRows=[];
if($campaignRowIds)foreach($store->all("SELECT c.* $dispatchColumns,f.name folder_name FROM marketing_campaigns c LEFT JOIN marketing_campaign_folders f ON f.id=c.folder_id AND f.audience_id=c.audience_id WHERE c.audience_id=? AND c.id IN (".implode(',',array_fill(0,count($campaignRowIds),'?')).')',[$audienceId,...$campaignRowIds])as$campaignRow)$campaignRowsById[$campaignRow['id']]=$campaignRow;
foreach($campaignRowIds as$campaignRowId)if(isset($campaignRowsById[$campaignRowId]))$campaignRows[]=$campaignRowsById[$campaignRowId];
$campaignMetrics=$runtimeInstalled?$store->analyticsCampaignMetrics($campaignRowIds):[];
$campaignSnapshot=[];
if($canManage&&$campaignTotal<=5000)foreach($store->all("SELECT c.id,c.version FROM marketing_campaigns c WHERE $campaignWhere",$campaignParams)as$row)$campaignSnapshot[$row['id']]=(int)$row['version'];
$month=new DateTimeImmutable($campaignFilters['month'].'-01',new DateTimeZone('UTC'));
$calendarStart=$month->modify('monday this week');$calendarEnd=$month->modify('last day of this month')->modify('sunday this week');
$calendarRows=[];
$calendarDate=$dispatchInstalled?"COALESCE((SELECT d.due_at FROM marketing_campaign_deliveries d WHERE d.audience_id=c.audience_id AND d.campaign_id=c.id AND d.flow_run_id=0 AND d.state IN ('scheduled','sending','paused') ORDER BY d.id DESC LIMIT 1),c.sent_at,c.updated_at)":"COALESCE(c.sent_at,c.updated_at)";
if($campaignFilters['layout']==='calendar'){
    $calendarRows=$store->all("SELECT c.id,c.name,c.status,DATE($calendarDate) calendar_date FROM marketing_campaigns c WHERE $campaignWhere AND $calendarDate>=? AND $calendarDate<? ORDER BY calendar_date,c.id",[...$campaignParams,$calendarStart->format('Y-m-d'),$calendarEnd->modify('+1 day')->format('Y-m-d')]);
}
$campaign=null;$recipientCount=null;$targetError='';
if(in_array($view,['campaign','campaign-review','campaign-send','campaign-delivery'],true)){
    try{$campaign=$store->campaign($id,false,$view==='campaign-delivery');}catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}
    if($campaign['deleted_at'])$canSend=false;
    if(!empty($campaign['draft']['recipients_saved']))try{[$targetWhere,$targetParams]=$store->campaignTarget($campaign['draft']);$recipientCount=(int)$store->one("SELECT COUNT(*) n FROM marketing_contacts c WHERE $targetWhere",$targetParams)['n'];}catch(InvalidArgumentException $e){$targetError=$e->getMessage();}
}
// Shared shell dependencies, without loading a page of unrelated Connections.
$tags=$store->tags();$groups=$store->groups();$fields=$store->fields();$catalog=$store->catalog();
$segments=$store->all('SELECT * FROM marketing_segments WHERE audience_id=? ORDER BY name,id',[$audienceId]);
$filters=audienceListFilters([]);$rules=['match'=>'all','rules'=>[]];$segment=null;$job=null;$editorRecovery=null;
$plan=null;$planRows=[];$planChoices=[];$touchpoints=[];$planTotal=0;$planPage=1;$plannerLayout=in_array($_GET['planner_layout']??'', ['calendar','agenda','list'],true)?$_GET['planner_layout']:'calendar';
$plannerScale=in_array($_GET['scale']??'', ['day','week','month'],true)?$_GET['scale']:'month';
$plannerDate=audienceText($_GET,'day')?:$month->format('Y-m-d');try{audienceCampaignDate($plannerDate);}catch(InvalidArgumentException){$plannerDate=$month->format('Y-m-d');}
if(in_array($view,['campaign-manager','campaign-plan'],true)){
    if($view==='campaign-plan'){try{$plan=$store->campaignPlan($id);}catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}}
    $planSearch=audienceText($_GET,'plan_search',100);$planSort=in_array($_GET['plan_sort']??'',['updated_at','name'],true)?$_GET['plan_sort']:'updated_at';$planDirection=($_GET['plan_direction']??'desc')==='asc'?'asc':'desc';
    $planArchived=($_GET['plan_trash']??'0')==='1';
    $planListWhere='audience_id=? AND deleted_at IS '.($planArchived?'NOT NULL':'NULL')." AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data_json,'$._permanently_deleted')),'0') NOT IN ('1','true') AND LOCATE(?,name)>0";
    $planTotal=(int)$store->one('SELECT COUNT(*) n FROM marketing_campaign_plans WHERE '.$planListWhere,[$audienceId,$planSearch])['n'];
    $planSize=audiencePageSize($_GET,'plan_size');
    $planPage=max(1,min(max(1,(int)ceil($planTotal/$planSize)),\Dnr\Http\RequestInput::positiveInt($_GET,'plan_page')??1));
    $planRows=$store->all("SELECT * FROM marketing_campaign_plans WHERE $planListWhere ORDER BY $planSort $planDirection,id $planDirection LIMIT ? OFFSET ?",[$audienceId,$planSearch,$planSize,($planPage-1)*$planSize]);
    $planChoices=$store->all('SELECT id,name FROM marketing_campaign_plans WHERE audience_id=? AND deleted_at IS NULL ORDER BY name,id',[$audienceId]);
    $planWhere=$plan?' AND t.plan_id=?':'';$planParams=$plan?[$id]:[];
    [$plannerStart,$plannerEnd]=audienceCampaignPlannerRange($plannerDate,$plannerScale);
    $touchpointArchived=$plannerLayout==='agenda'&&($_GET['touchpoint_trash']??'0')==='1';
    $touchpointStateWhere=$touchpointArchived?"t.deleted_at IS NOT NULL AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(t.data_json,'$._permanently_deleted')),'0') NOT IN ('1','true')":'t.deleted_at IS NULL AND (t.plan_id IS NULL OR p.deleted_at IS NULL)';
    $touchpoints=$store->all("SELECT t.*,c.status email_status,c.deleted_at email_deleted FROM marketing_campaign_touchpoints t LEFT JOIN marketing_campaigns c ON c.id=t.campaign_id AND c.audience_id=t.audience_id LEFT JOIN marketing_campaign_plans p ON p.id=t.plan_id AND p.audience_id=t.audience_id WHERE t.audience_id=? AND $touchpointStateWhere $planWhere AND t.start_at<? AND t.end_at>=? ORDER BY t.start_at,t.id LIMIT 1001",[$audienceId,...$planParams,$plannerEnd->modify('+1 day')->format('Y-m-d'),$plannerStart->format('Y-m-d')]);
    $plannerEmails=$store->all("SELECT id,name FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL ORDER BY name,id",[$audienceId]);
}
$deliveryPolicy=$runtimeInstalled?$store->deliveryPolicy():[];$mailActivity=[];$mailUsage=[];$operationalQueues=[];$eventSummary=[];$sesInboxCounts=[];
$deliveryAvailable=$store->deliverySetupAvailable();$deliveryDomains=[];$deliveryAddresses=[];$deliverySender=null;$deliverySenderJob=null;$deliveryDomain=null;$deliveryDevelopmentCode='';$deliveryProbe=null;$senderReadiness=null;
if($view==='delivery'){
    if($runtimeInstalled){
        if(!$store->sesAvailable()&&$canConfigureDelivery)foreach(['email_outbox'=>'Account access','notification_outbox'=>'Task notifications','engagement_email_deliveries'=>'Engagement correspondence','reimbursement_email_deliveries'=>'Reimbursement submissions']as$table=>$label)$operationalQueues[$label]=$store->all("SELECT status,COUNT(*) n,MIN(CASE WHEN status IN ('pending','retry') THEN next_attempt_at END) next_attempt FROM $table WHERE created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) GROUP BY status");
        $mailActivity=$store->serviceMailActivity();
        if(!$store->sesAvailable())$mailUsage=$store->all("SELECT lane,COUNT(*) daily,SUM(created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR)) hourly FROM marketing_relay_usage WHERE audience_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 24 HOUR) GROUP BY lane",[$audienceId]);
        $eventSummary=$store->one("SELECT COUNT(*) n,MAX(created_at) latest FROM marketing_message_events WHERE audience_id=? AND source='provider'",[$audienceId]);
        if($store->sesAvailable())$sesInboxCounts=$store->one("SELECT SUM(state IN ('queued','processing')) pending,SUM(state='failed') failed FROM marketing_ses_inbox WHERE audience_id=?",[$audienceId])??[];
    }
    if($deliveryAvailable&&!$store->sesAvailable()){
        $deliveryDomains=$store->all('SELECT d.*,(SELECT COUNT(*) FROM marketing_sending_addresses s WHERE s.domain_id=d.id AND s.audience_id=d.audience_id AND s.deleted_at IS NULL AND s.verified_at IS NOT NULL AND s.verified_transport=\'smtp\') verified_count FROM marketing_sending_domains d WHERE d.audience_id=? AND d.deleted_at IS NULL ORDER BY domain,id',[$audienceId]);
        $deliveryAddresses=$store->all('SELECT id,domain_id,email,verified_at,verified_transport FROM marketing_sending_addresses WHERE audience_id=? AND deleted_at IS NULL ORDER BY email,id',[$audienceId]);
        $senderId=($campaignRecovery['action']??'')==='delivery_verify'?$id:(\Dnr\Http\RequestInput::positiveInt($_GET,'sender')??0);
        $domainId=in_array($campaignRecovery['action']??'',['delivery_records','delivery_dns'],true)?$id:(\Dnr\Http\RequestInput::positiveInt($_GET,'domain')??0);
        try{if($senderId){$deliverySender=$store->sendingAddress($senderId);$deliverySenderJob=$store->one('SELECT * FROM marketing_delivery_setup_jobs WHERE audience_id=? AND sender_id=? ORDER BY id DESC LIMIT 1',[$audienceId,$senderId]);if($canConfigureDelivery&&($deliverySenderJob['state']??'')==='development'&&!empty($deliverySenderJob['payload_ciphertext'])&&strtotime($deliverySenderJob['expires_at'].' UTC')>time())$deliveryDevelopmentCode=audienceJson(\Dnr\Security\ApplicationKey::open($deliverySenderJob['payload_ciphertext']))['code']??'';}if($domainId)$deliveryDomain=$store->sendingDomain($domainId);}catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}
        $deliveryProbe=$store->one("SELECT * FROM marketing_delivery_setup_jobs WHERE audience_id=? AND job_type='probe' ORDER BY id DESC LIMIT 1",[$audienceId]);
    }
}
if($view==='campaign'&&$campaign&&$deliveryAvailable)$senderReadiness=$store->sendingReadiness((string)($campaign['draft']['from_email']??''),audienceJson($audience['settings_json']));
// Review is read-only. Queue creation only happens on an explicit CSRF-protected confirmation.
$dispatchReview=null;$dispatchError='';$delivery=null;$deliveryCounts=[];$deliveryRows=[];$deliveryReport=[];$experimentReport=[];$deliverySnapshot=[];
if($campaign&&$dispatchInstalled){
    $delivery=$store->campaignDelivery((int)$campaign['id']);
    if($delivery){if($runtimeInstalled){$deliveryReport=$store->deliveryReport((int)$delivery['id']);$experimentReport=$store->deliveryReport((int)$delivery['id'],true);$deliverySnapshot=audienceJson(\Dnr\Security\ApplicationKey::open($delivery['snapshot_ciphertext']));}if($campaign['status']!=='draft')$recipientCount=(int)$delivery['recipient_count'];$deliveryCounts=$store->campaignDeliveryCounts((int)$delivery['id']);$deliveryRows=$store->all('SELECT r.id,r.contact_id,r.state,r.attempts,r.accepted_at,r.next_attempt_at,r.error_message FROM marketing_campaign_recipients r WHERE r.audience_id=? AND r.delivery_id=? ORDER BY r.id DESC LIMIT 25',[$audienceId,$delivery['id']]);}
    if($view==='campaign-send'&&$canSend)try{$dispatchReview=$store->reviewCampaignDispatch($id,$audience,accountPrimaryPublicUrl());}catch(InvalidArgumentException|RuntimeException $e){$dispatchError=$e instanceof InvalidArgumentException?$e->getMessage():'Public email links are not configured. Review Email delivery setup.';}
}
if($view==='campaign-send'&&!$canSend)$dispatchError='Sending requires an unrestricted editor or administrator.';
$contentLibrary=null;$contentItem=null;$contentContext=null;$brand=[];$brandTemplates=[];
if(in_array($view,['content','brand'],true)){
    $contentContext=$store->contentSettings();$brand=$contentContext['settings']['brand']??[];
    if($view==='content'){$contentLibrary=$store->contentList($_GET);$contentId=\Dnr\Http\RequestInput::positiveInt($_GET,'item')??0;if($contentId){try{$contentItem=$store->designLibraryItem($contentId);$contentItem['usage']=$store->contentUsage($contentItem);}catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}}}
    else{$brandTemplates=$store->all('SELECT id,name,version FROM marketing_email_templates WHERE audience_id=? AND deleted_at IS NULL ORDER BY name,id',[$audienceId]);$brandPreview=$store->brandedDesign(audienceDesignStarters()['newsletter']['design'],$brand,!empty($brand['logo']));}
}
$publication=$campaign&&!$campaign['deleted_at']?$store->campaignPublication((int)$campaign['id']):null;
$campaignComments=$view==='campaign-review'?$store->designComments('campaign',$id):[];
$campaignMergeTags=$store->campaignMergeTags();
$previewIdentity=($deliverySnapshot['identity']??$store->designRecipientContext(0,$audience))+['LIST:NAME'=>$audience['name'],'CURRENT_YEAR'=>gmdate('Y'),'MC:SUBJECT'=>(string)($draft['subject']??''),'MC_PREVIEW_TEXT'=>(string)($draft['preview_text']??'')];
$sesInstalled=$store->sesAvailable();$sesSettings=$sesInstalled?$store->sesSettings():[];
$managerUrl=static fn(array $changes=[])=>$url(array_replace(['view'=>$view,'id'=>$plan['id']??0,'month'=>$month->format('Y-m'),'planner_layout'=>$plannerLayout,'scale'=>$plannerScale,'day'=>$plannerDate,'holidays'=>$campaignFilters['holidays'],'plan_search'=>$planSearch??'','plan_sort'=>$planSort??'updated_at','plan_direction'=>$planDirection??'desc','plan_size'=>$planSize??25,'plan_trash'=>!empty($planArchived)?'1':'0','touchpoint_trash'=>!empty($touchpointArchived)?'1':'0'],$changes));
$pageTitle=match($view){'campaign','campaign-review','campaign-send','campaign-delivery'=>$campaign['name'],'campaign-manager'=>'Campaign Manager','campaign-plan'=>$plan['name'],'content'=>'Content','brand'=>'Brand Kit','delivery'=>'Email delivery',default=>'Campaigns'};$csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
releaseApplicationSessionLock();
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>['assets/css/pages/audience.min.css',...($view==='campaigns'?['assets/css/pages/audience-campaign-list.min.css']:[])],'scripts'=>['assets/js/audience.min.js','assets/js/audience-designer.min.js']]); ?>
<?php include __DIR__.'/templates/audience/workspace.php'; ?>
</html>

<?php } ?>
