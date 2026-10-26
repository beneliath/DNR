<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/two_factor_helpers.php';
require_once __DIR__.'/audience_store.php';
$db=applicationDatabaseConnection();startSecureSession();requireLogin();
$canManage=hasRole(['admin','editor']);$canExport=$canManage;$canDelete=hasRole(['admin']);
$view=\Dnr\Http\RequestInput::enum($_GET,'view',['home','analytics','assistant','inbox','contacts','contact','tags','groups','fields','settings','segments','import','jobs','job','campaigns','campaign','campaign-manager','campaign-plan','campaign-review','campaign-send','campaign-delivery','content','brand','delivery','templates','designer','forms','form','form-settings','landing-pages','surveys','collection-report','preferences','collection-create','automations','flow','flow-templates','flow-report'],'contacts');
$_GET=audienceRememberPageSizes($_GET,$view,(int)$_SESSION['user_id']);
if(!$canDelete&&($view==='delivery'||(is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'delivery_')))){http_response_code(403);exit('Email delivery requires an administrator.');}
$h=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$actor=(int)$_SESSION['user_id'];$displayName=mb_substr((string)($_SESSION['profile_display_name']??$_SESSION['username']??'Account'),0,100);
// Each MOED account has one canonical audience. Older bookmarked selectors resolve here.
$audienceId=1;
$audience=$db->query('SELECT * FROM marketing_audiences WHERE id=1')->fetch_assoc();
if(!$audience){http_response_code(503);exit('Audience setup is incomplete.');}
if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['audience'])){
    $canonical=$_GET;unset($canonical['audience']);
    header('Location: audience.php'.($canonical?'?'.http_build_query($canonical):''));exit;
}
unset($_SESSION['marketing_audience']);
$store=new AudienceStore($db,$audienceId,$actor,$displayName);
if(($_GET['api']??null)==='workspace_search'){
    releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');$result=['error'=>'Search requires a GET request.'];}
    else try{$result=audienceWorkspaceSearch($store,$_GET,$canManage,$canDelete);}catch(InvalidArgumentException $e){http_response_code(400);$result=['error'=>$e->getMessage()];}
    echo json_encode($result,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);exit;
}
// Apply transfer permissions before dispatching to any MessageMonkey workspace.
$transferRequest=isset($_GET['download'])||isset($_GET['export'])||isset($_GET['template_export'])
    ||($view==='collection-report'&&isset($_GET['chart']))||($_GET['api']??'')==='job'
    ||in_array($view,['import','jobs','job'],true)
    ||in_array($_POST['action']??'', ['import_upload','configure_import','start_import','undo_import','queue_export','process_job','resume_job','cancel_job','template_package_import'],true);
if(!$canExport&&$transferRequest){http_response_code(403);exit('Import and export require an editor or administrator.');}
$id=\Dnr\Http\RequestInput::positiveInt($_POST+$_GET,'id')??0;
$message=(string)($_SESSION['audience_message']??'');unset($_SESSION['audience_message']);$error='';$isPost=$_SERVER['REQUEST_METHOD']==='POST';
$url=static fn(array $params=[]):string=>'audience.php'.($params?'?'.http_build_query($params):'');
$redirect=$url(['view'=>$view]);
if($view==='assistant'){require __DIR__.'/audience_ai_controller.php';renderAudienceAi($store,$audience,$canManage,$canDelete,$displayName,$actor);exit;}
if(in_array($view,['home','analytics'],true)){require __DIR__.'/audience_analytics_controller.php';renderAudienceAnalytics($store,$audience,$view,$message,$error,$canManage,$canDelete,$displayName,$actor);exit;}
if($view==='inbox'||(is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'inbox_'))){require __DIR__.'/audience_inbox_controller.php';renderAudienceInbox($store,$audience,$message,$error,$canManage,$canDelete,$displayName,$actor);exit;}
if(in_array($view,['automations','flow','flow-templates','flow-report'],true)||(is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'flow_'))){require __DIR__.'/audience_flow_controller.php';renderAudienceFlowWorkspace($store,$audience,$view,$id,$message,$error,$canManage,$canDelete,$displayName,$actor);exit;}
if(in_array($view,['forms','form','form-settings','landing-pages','surveys','collection-report','preferences','collection-create'],true)){
    require __DIR__.'/audience_collection_controller.php';
    renderAudienceCollectionWorkspace($store,$audience,$view,$id,$message,$error,$canManage,$canDelete,$displayName,$actor);
    exit;
}
if(in_array($view,['templates','designer'],true)){require __DIR__.'/audience_design_controller.php';renderAudienceDesignWorkspace($store,$audience,$view,$id,$message,$error,$canManage,$canDelete,$displayName,$actor);exit;}
if(in_array($view,['campaigns','campaign','campaign-manager','campaign-plan','campaign-review','campaign-send','campaign-delivery','content','brand','delivery'],true)||(is_string($_POST['action']??null)&&(str_starts_with($_POST['action'],'campaign_')||str_starts_with($_POST['action'],'delivery_')))){require __DIR__.'/audience_campaign_controller.php';renderAudienceCampaignWorkspace($store,$audience,$view,$id,$message,$error,$canManage,$canDelete,$displayName,$actor);exit;}
$tags=$store->tags();$groups=$store->groups();$fields=$store->fields();
$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);$segment=null;
try{
    $filters=audienceListFilters($isPost?$_POST:$_GET);
    if(!empty($_GET['tag'])){foreach($tags as$tag)if($tag['name']===audienceText($_GET,'tag'))$filters['tags'][]=(int)$tag['id'];}
    $rulesText=audienceText($isPost?$_POST:$_GET,'rules',50000);if($rulesText!=='')$rules=audienceJson($rulesText);
    $segmentId=\Dnr\Http\RequestInput::positiveInt($_GET,'segment')??0;
    if($segmentId){$segment=$store->one('SELECT * FROM marketing_segments WHERE id=? AND audience_id=?',[$segmentId,$audienceId]);if(!$segment)throw new InvalidArgumentException('That segment is unavailable.');if($rulesText==='')$rules=audienceLegacyRules(audienceJson($segment['filters_json']),$tags);}
    audienceCompileRules(audienceActiveRules($filters,$rules),$store->catalog());
}catch(InvalidArgumentException $e){$error=$e->getMessage();$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);}

if($isPost){
    requireValidCsrfToken();if(!$canManage){http_response_code(403);exit('You do not have permission to manage Audience.');}
    try{
        if(isset($_POST['audience'])&&(string)$_POST['audience']!=='1')throw new InvalidArgumentException('Audience has been consolidated. Reload this page before saving.');
        $action=audienceText($_POST,'action');$version=\Dnr\Http\RequestInput::positiveInt($_POST,'version')??0;
        $protectedViews=['delete_tag'=>'tags','delete_segment'=>'segments','delete_group'=>'groups','delete_field'=>'fields','undo_import'=>'job','delete_export_history'=>'jobs'];
        $protectedView=$protectedViews[$action]??null;
        if($action==='delete_selected_definitions'&&in_array(audienceText($_POST,'kind'),['tags','segments'],true))$protectedView=audienceText($_POST,'kind');
        if($action==='save_group'&&$id){
            $oldOptions=$store->all('SELECT o.id FROM marketing_group_options o JOIN marketing_group_categories g ON g.id=o.category_id WHERE g.id=? AND g.audience_id=?',[$id,$audienceId]);
            $keptOptions=[];foreach(is_array($_POST['options']??null)?$_POST['options']:[]as$option)if(is_array($option)&&is_string($option['name']??null)&&trim($option['name'])!=='')$keptOptions[]=(int)($option['id']??0);
            if(array_diff(array_map('intval',array_column($oldOptions,'id')),$keptOptions))$protectedView='groups';
        }
        if($protectedView!==null){
            if(!$canDelete){http_response_code(403);exit('This operation requires an administrator and Admin Unlock.');}
            $return=['view'=>$protectedView];if($action==='undo_import')$return['id']=$id;if($action==='delete_export_history')$return['kind']='export';
            requireRecentAdminElevation($url($return));
        }
        if($error!=='')throw new InvalidArgumentException($error);
        switch($action){
            case 'save_person':
                $old=$id?$store->person($id):null;$status=audienceText($_POST,'email_status');
                if(in_array($status,['pending','cleaned'],true)&&(!$old||$old['email_status']!==$status))throw new InvalidArgumentException('Pending and cleaned states are set by confirmation and delivery events.');
                $id=$store->savePerson($_POST,$id,$version);$redirect=$url(['view'=>'contact','id'=>$id]);$message='Connection saved.';break;
            case 'save_profile_section':
                $section=audienceText($_POST,'section');$store->saveProfileSection($id,$version,$section,$_POST);
                $redirect=$url(['view'=>'contact','id'=>$id,'tab'=>$section==='settings'?'settings':'overview']);$message='Changes saved.';break;
            case 'person_action':
                $operation=audienceText($_POST,'operation');if($operation==='delete'&&(!$canDelete||audienceText($_POST,'confirm_text')!=='DELETE'))throw new InvalidArgumentException('An administrator must type DELETE to permanently remove a connection.');
                $store->mutatePerson($id,$version,$operation,$_POST);$redirect=$operation==='delete'?$url():$url(['view'=>'contact','id'=>$id]);$message=$operation==='delete'?'Connection permanently removed.':'Connection updated.';break;
            case 'create_person_tag':
                $name=audienceText($_POST,'name');
                $store->transaction(function()use($store,$id,$version,$name){$store->person($id,true);$tagId=$store->ensureTag($name);$store->mutatePerson($id,$version,'tag',['tag_id'=>$tagId]);});
                $redirect=$url(['view'=>'contact','id'=>$id]);$message='Tag added.';break;
            case 'save_note':$store->saveNote($id,audienceText($_POST,'body',10000),(int)($_POST['note_id']??0),$version);$redirect=$url(['view'=>'contact','id'=>$id,'tab'=>'notes']);$message='Note saved.';break;
            case 'delete_note':
                $store->transaction(function()use($store,$id,$version){$store->person($id,true);$store->write('DELETE FROM marketing_notes WHERE id=? AND contact_id=? AND version=?',[(int)($_POST['note_id']??0),$id,$version]);if($store->db->affected_rows!==1)throw new InvalidArgumentException('This note changed. Reload before deleting it.');$store->event($id,'note_deleted');$store->write('UPDATE marketing_contacts SET version=version+1 WHERE id=?',[$id]);});$redirect=$url(['view'=>'contact','id'=>$id,'tab'=>'notes']);$message='Note deleted.';break;
            case 'delete_selected_definitions':
                $kind=audienceText($_POST,'kind');if(!is_array($_POST['selected_ids']??null)||!is_array($_POST['versions']??null))throw new InvalidArgumentException('Select items to delete.');
                $deleted=$store->deleteSelectedDefinitions($kind,$_POST['selected_ids'],$_POST['versions']);$redirect=$url(['view'=>$kind]);$message=$deleted.' '.$kind.' deleted. Connections were kept.';break;
            case 'save_tag':$store->saveTag(audienceText($_POST,'name'),$id,$version);$redirect=$url(['view'=>'tags']);$message='Tag saved.';break;
            case 'delete_tag':$store->deleteTag($id,$version);$redirect=$url(['view'=>'tags']);$message='Tag deleted. Its connections were kept.';break;
            case 'save_group':$store->saveGroup($_POST,$id,$version);$redirect=$url(['view'=>'groups']);$message='Group saved.';break;
            case 'delete_group':$store->deleteGroup($id,$version);$redirect=$url(['view'=>'groups']);$message='Group deleted. Its connections were kept.';break;
            case 'save_field':$store->saveField($_POST,$id,$version);$redirect=$url(['view'=>'fields']);$message='Audience field saved.';break;
            case 'delete_field':if(!$canDelete)throw new InvalidArgumentException('Only administrators can delete fields and their values.');$store->deleteField($id,$version);$redirect=$url(['view'=>'fields']);$message='Field and its stored values removed.';break;
            case 'save_segment':$savedSegment=$store->saveSegment(audienceText($_POST,'name'),$rules,$id,$version);$redirect=($_POST['return_to']??'')==='contacts'?$url(['segment'=>$savedSegment,'archived'=>$filters['archived']]):$url(['view'=>'segments']);$message='Segment saved.';break;
            case 'delete_segment':$store->write('DELETE FROM marketing_segments WHERE id=? AND audience_id=? AND version=?',[$id,$audienceId,$version]);if($db->affected_rows!==1)throw new InvalidArgumentException('This segment changed. Reload before deleting.');$redirect=$url(['view'=>'segments']);$message='Segment deleted.';break;
            case 'rename_segment':$original=$store->one('SELECT * FROM marketing_segments WHERE id=? AND audience_id=?',[$id,$audienceId]);if(!$original)throw new InvalidArgumentException('Segment unavailable.');$store->saveSegment(audienceText($_POST,'name'),audienceLegacyRules(audienceJson($original['filters_json']),$tags),$id,$version);$redirect=$url(['view'=>'segments']);$message='Segment renamed.';break;
            case 'duplicate_segment':$original=$store->one('SELECT * FROM marketing_segments WHERE id=? AND audience_id=?',[$id,$audienceId]);if(!$original)throw new InvalidArgumentException('Segment unavailable.');$store->saveSegment(audienceText($_POST,'name'),audienceLegacyRules(audienceJson($original['filters_json']),$tags),0,0);$redirect=$url(['view'=>'segments']);$message='Segment copied.';break;
            case 'preview_segment':
                [$where,$params]=$store->where($filters,$rules);$count=(int)$store->one("SELECT COUNT(*) n FROM marketing_contacts c WHERE $where",$params)['n'];$sample=$store->all("SELECT c.id,c.email,c.first_name,c.last_name FROM marketing_contacts c WHERE $where ORDER BY c.id DESC LIMIT 10",$params);releaseApplicationSessionLock();header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['count'=>$count,'people'=>$sample],JSON_THROW_ON_ERROR);exit;
            case 'queue_export':$id=$store->queueSelection('export','Connections export',$store->selectedWhere($_POST,$filters,$rules));$redirect=$url(['view'=>'job','id'=>$id]);break;
            case 'unsubscribe_emails':$id=$store->queueEmailUnsubscribe(audienceText($_POST,'emails',1300000));$redirect=$url(['view'=>'job','id'=>$id]);break;
            case 'bulk':
                $operation=audienceText($_POST,'operation');if(!in_array($operation,['archive','restore','unsubscribe','tag','untag','group','ungroup','delete'],true))throw new InvalidArgumentException('Choose a bulk action.');
                if($operation==='delete'&&(!$canDelete||audienceText($_POST,'confirm_text')!=='DELETE'))throw new InvalidArgumentException('An administrator must type DELETE to permanently remove selected connections.');
                $id=$store->queueSelection('bulk',ucfirst($operation).' connections',$store->selectedWhere($_POST,$filters,$rules),['action'=>$operation,'tag_id'=>(int)($_POST['tag_id']??0),'option_id'=>(int)($_POST['option_id']??0)]);$redirect=$url(['view'=>'job','id'=>$id]);break;
            case 'bulk_tag_paste':
                $emails=preg_split('/[\s,;]+/',audienceText($_POST,'emails',200000),-1,PREG_SPLIT_NO_EMPTY);if(!$emails)throw new InvalidArgumentException('Enter at least one email address.');$selected=[];foreach(array_unique(array_map('strtolower',$emails))as$email){if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid address: '.$email);$p=$store->one('SELECT id FROM marketing_contacts WHERE audience_id=? AND email=? AND deleted_at IS NULL',[$audienceId,$email]);if(!$p)throw new InvalidArgumentException('No connection found for '.$email.'. Import new connections first.');$selected[]=(int)$p['id'];}
                $id=$store->queueSelection('bulk','Bulk tag connections',$store->selectedWhere(['selected'=>$selected],audienceListFilters(['archived'=>'all']),['match'=>'all','rules'=>[]]),['action'=>audienceText($_POST,'operation')==='untag'?'untag':'tag','tag_id'=>(int)($_POST['tag_id']??0)]);$redirect=$url(['view'=>'job','id'=>$id]);break;
            case 'import_upload':
                $file=$_FILES['import_file']??null;
                if(is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){if($file['error']!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('The upload failed. Check the file size and try again.');if($file['size']>20*1024*1024)throw new InvalidArgumentException('Use a file of 20 MB or less.');$name=basename((string)$file['name']);$extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));if(!in_array($extension,['csv','txt','tsv','xlsx'],true))throw new InvalidArgumentException('Choose a CSV, TXT, TSV or XLSX file.');$parsed=$extension==='xlsx'?audienceParseXlsx($file['tmp_name']):audienceParseImport((string)file_get_contents($file['tmp_name']));}
                else{$name='Pasted connections';$parsed=audienceParseImport(audienceText($_POST,'paste',20*1024*1024));}
                $id=$store->createImport($parsed,$name);if(($_POST['suppress']??'')==='1')$store->configureImport($id,['import_status'=>'unsubscribed'],false);$redirect=$url(['view'=>'import','id'=>$id,'step'=>'organize']);break;
            case 'configure_import':$store->configureImport($id,$_POST,false);$step=audienceText($_POST,'next_step');$redirect=$url(['view'=>'import','id'=>$id,'step'=>in_array($step,['organize','tag','match','subscribe','review'],true)?$step:'review']);break;
            case 'start_import':$store->configureImport($id,$_POST,true);$redirect=$url(['view'=>'job','id'=>$id]);break;
            case 'cancel_job':$store->cancelJob($id);$redirect=$url(['view'=>'job','id'=>$id]);$message='Operation cancelled. Completed rows have been kept.';break;
            case 'undo_import':$result=$store->undoImport($id);$redirect=$url(['view'=>'job','id'=>$id]);$message=$result['undone'].' newly imported connections archived; '.$result['changed'].' subsequently changed connections kept. Existing updates were not reverted.';break;
            case 'process_job':
                $store->job($id);releaseApplicationSessionLock();ignore_user_abort(true);set_time_limit(0);header('Content-Type: application/json');header('Cache-Control: no-store');$response=json_encode(['accepted'=>true]);header('Content-Length: '.strlen($response));header('Connection: close');echo $response;while(ob_get_level()>0)ob_end_flush();flush();
                $deadline=microtime(true)+240;
                try{do{$job=$store->processJob($id);if(!in_array($job['state'],['queued','processing'],true))break;}while(microtime(true)<$deadline);}catch(Throwable $e){applicationLog('error','Audience job failed',['job_id'=>$id,'error'=>$e->getMessage()]);$store->write("UPDATE marketing_jobs SET state='failed',error_message='Processing stopped. Resume to retry the remaining rows.' WHERE id=? AND audience_id=?",[$id,$audienceId]);}exit;
            case 'delete_export_history':
                if(!$canDelete){http_response_code(403);exit('Only administrators can delete export history.');}
                $deleted=$store->deleteExportHistory(is_array($_POST['export_ids']??null)?$_POST['export_ids']:[],($_POST['delete_all']??'')==='1');$redirect=$url(['view'=>'jobs','kind'=>'export']);$message=$deleted.' export history entries and saved downloads deleted. Connections were kept.';break;
            case 'resume_job':$job=$store->job($id);if(!in_array($job['state'],['failed','processing'],true))throw new InvalidArgumentException('This job does not need to be resumed.');$store->write("UPDATE marketing_jobs SET state='queued',error_message='' WHERE id=? AND audience_id=?",[$id,$audienceId]);$redirect=$url(['view'=>'job','id'=>$id]);break;
            case 'save_audience':
                if(!$canDelete)throw new InvalidArgumentException('Only administrators can change audience defaults.');$store->saveAudienceDefaults($_POST,$version);$redirect=$url(['view'=>'settings']);$message='Audience defaults saved.';break;
            default:throw new InvalidArgumentException('Unknown Audience action.');
        }
        $_SESSION['audience_message']=$message;header('Location: '.$redirect);exit;
    }catch(InvalidArgumentException $e){$error=$e->getMessage();if(in_array($_POST['action']??'',['preview_segment','process_job'],true)){http_response_code(422);header('Content-Type: application/json');echo json_encode(['error'=>$error],JSON_THROW_ON_ERROR);exit;}}
    catch(Throwable $e){applicationLog('error','Audience operation failed',['error'=>$e->getMessage()]);$error=$e instanceof mysqli_sql_exception&&$e->getCode()===1062?'That name already exists in this audience.':'Unable to save these changes. Please reload and try again.';if(in_array($_POST['action']??'',['preview_segment','process_job'],true)){http_response_code(500);header('Content-Type: application/json');echo json_encode(['error'=>$error],JSON_THROW_ON_ERROR);exit;}}
}

if(($_GET['api']??'')==='job'){
    if(!$canManage){http_response_code(403);exit;}try{$job=$store->job($id);}catch(InvalidArgumentException){http_response_code(404);exit('Operation not found.');}releaseApplicationSessionLock();header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(array_intersect_key($job,array_flip(['id','state','total','processed','added','updated','skipped','errors','error_message','updated_at'])),JSON_THROW_ON_ERROR);exit;
}
if(isset($_GET['download'])){
    if(!$canExport){http_response_code(403);exit('Export requires an editor or administrator.');}
    try{$job=$store->job($id);}catch(InvalidArgumentException){http_response_code(404);exit('Operation not found.');}$errors=($_GET['download']==='errors');if(!$errors&&($job['job_type']!=='export'||$job['state']!=='complete')){http_response_code(409);exit('Wait for the export to finish.');}
    if(!$errors&&($job['params']['export_format']??'')==='mailchimp'){
        releaseApplicationSessionLock();
        $records=(static function()use($store,$id):Generator{
            $last=0;
            do{
                $items=$store->all("SELECT id,result_json FROM marketing_job_items WHERE job_id=? AND id>? AND state='exported' ORDER BY id LIMIT 500",[$id,$last]);
                foreach($items as$item){$last=(int)$item['id'];yield audienceJson($item['result_json']);}
            }while(count($items)===500);
        })();
        $previousAbort=ignore_user_abort(true);
        try{
            audienceWithExportArchive($job['params'],$records,static function(string $path,string $name):void{
                header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$name.'"');
                header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Content-Length: '.filesize($path));readfile($path);
            });
        }catch(Throwable $e){applicationLog('error','Audience ZIP export failed',['job_id'=>$id,'error'=>$e->getMessage()]);if(!headers_sent()){http_response_code(503);echo 'Unable to prepare this export. Please try again.';}}finally{ignore_user_abort((bool)$previousAbort);}
        exit;
    }
    releaseApplicationSessionLock();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="audience-'.($errors?'import-errors':'people').'-'.$id.'.csv"');header('Cache-Control: no-store');$output=fopen('php://output','w');$last=0;$written=false;
    do{$items=$store->all('SELECT * FROM marketing_job_items WHERE job_id=? AND id>? AND state=? ORDER BY id LIMIT 500',[$id,$last,$errors?'error':'exported']);foreach($items as$item){$row=$errors?['Row'=>$item['source_row'],'Error'=>$item['error_message'],'Original values'=>json_encode(audienceJson($item['payload_json']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]:audienceJson($item['result_json']);if(!$written){fputcsv($output,array_map('audienceCsvCell',array_keys($row)),',','"','');$written=true;}fputcsv($output,array_map(fn($v)=>audienceCsvCell((string)$v),array_values($row)),',','"','');$last=(int)$item['id'];}}while(count($items)===500);if(!$written)fputcsv($output,[$errors?'No errors':'No exported connections'],',','"','');fclose($output);exit;
}
if(($_GET['export']??'')==='person'){
    if(!$canExport){http_response_code(403);exit;}$person=$store->person($id);$data=['person'=>$store->exportPerson($person,['fields'=>$store->fields(),'tag_names'=>array_column($store->tags(),'name','id'),'group_names'=>$store->catalog()['groups']['options']]),'notes'=>$store->all('SELECT body,actor_label,created_at,updated_at FROM marketing_notes WHERE contact_id=? ORDER BY id',[$id]),'activity'=>$store->all('SELECT event_type,details_json,actor_label,created_at FROM marketing_contact_events WHERE contact_id=? ORDER BY id',[$id])];releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="audience-person-'.$id.'.json"');header('Cache-Control: no-store');echo json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);exit;
}
$tags=$store->tags();$groups=$store->groups();$fields=$store->fields();$catalog=$store->catalog();
$segments=$store->all('SELECT * FROM marketing_segments WHERE audience_id=? ORDER BY name,id',[$audienceId]);
$sources=$store->all('SELECT DISTINCT c.source,'.audienceSignupSourceExpression().' source_type FROM marketing_contacts c WHERE c.audience_id=? AND c.deleted_at IS NULL ORDER BY c.source',[$audienceId]);
$counts=$store->one("SELECT COALESCE(SUM(is_archived=0),0) records,COALESCE(SUM(is_archived=0 AND email_status IN ('subscribed','unsubscribed','non-subscribed')),0) total,COALESCE(SUM(is_archived=0 AND email_status='subscribed'),0) subscribed,COALESCE(SUM(is_archived=1),0) archived,COALESCE(SUM(is_archived=0 AND email_status='cleaned'),0) cleaned FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL",[$audienceId]);
[$where,$params]=$store->where($filters,$rules);$total=(int)$store->one("SELECT COUNT(*) n FROM marketing_contacts c WHERE $where",$params)['n'];
$pageSize=audiencePageSize($_GET);$page=max(1,min(max(1,(int)ceil($total/$pageSize)),\Dnr\Http\RequestInput::positiveInt($_GET,'page')??1));
$sort=audienceText($_GET,'sort')?:'created_at';$sortColumns=['email','first_name','last_name','email_status','source','created_at','updated_at'];foreach($fields as$field)if($field['field_type']==='date')$sortColumns[]='custom:'.(int)$field['id'];if(!in_array($sort,$sortColumns,true))$sort='created_at';$direction=($_GET['direction']??'desc')==='asc'?'asc':'desc';
$connectionOrder=audienceConnectionOrder($sort,$direction,$fields);
$rows=$store->all("SELECT c.* FROM marketing_contacts c WHERE $where ORDER BY $connectionOrder LIMIT ? OFFSET ?",[...$params,$pageSize,($page-1)*$pageSize]);$rowTags=[];$rowGroups=[];
foreach($rows as$row){$rowTags[$row['id']]=$store->all('SELECT t.id,t.name FROM marketing_tags t JOIN marketing_contact_tags ct ON ct.tag_id=t.id WHERE ct.contact_id=? AND t.audience_id=? ORDER BY t.name',[$row['id'],$audienceId]);$rowGroups[$row['id']]=$store->all('SELECT o.name,g.id category_id,g.name category FROM marketing_group_options o JOIN marketing_group_categories g ON g.id=o.category_id JOIN marketing_contact_groups cg ON cg.option_id=o.id WHERE cg.contact_id=? AND g.audience_id=? ORDER BY g.name,o.position',[$row['id'],$audienceId]);}
$query=$filters+['rules'=>json_encode($rules,JSON_THROW_ON_ERROR),'sort'=>$sort,'direction'=>$direction,'per_page'=>$pageSize];if($segment)$query['segment']=(int)$segment['id'];
$contact=['id'=>0,'version'=>0,'email'=>'','first_name'=>'','last_name'=>'','email_status'=>'non-subscribed','source'=>'Manual entry','custom'=>[],'tag_ids'=>[],'group_ids'=>[],'language'=>'','timezone'=>'','is_archived'=>0,'consent_note'=>''];
$events=[];$notes=[];$eventTotal=0;$eventSize=audiencePageSize($_GET,'activity_size');$eventPage=max(1,(int)($_GET['activity_page']??1));$tab=\Dnr\Http\RequestInput::enum($_GET,'tab',['overview','insights','notes','settings'],'overview');
if($view==='contact'&&$id){try{$contact=array_replace($contact,$store->person($id));$eventFilter=audienceText($_GET,'activity_type');$days=(int)($_GET['activity_days']??30);if(!in_array($days,[0,7,30,90,365],true))$days=30;$activityYear=(int)($_GET['activity_year']??0);$activityRange=audienceText($_GET,'activity_range');if(preg_match('/^year:(20[0-9]{2})$/D',$activityRange,$rangeMatch))$activityYear=(int)$rangeMatch[1];elseif(in_array($activityRange,['30','90','0'],true)){$days=(int)$activityRange;$activityYear=0;}if($activityYear<2000||$activityYear>(int)gmdate('Y'))$activityYear=0;$notes=$store->all('SELECT * FROM marketing_notes WHERE contact_id=? ORDER BY id DESC',[$id]);}catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}}
if($view==='contact'&&$id){
    $profileActivity=$store->personActivity($id,$eventFilter,$days,$activityYear,$eventPage,$canManage,$eventSize);$eventPage=$profileActivity['page'];$events=$profileActivity['rows'];$eventTotal=$profileActivity['total'];$activityYears=$profileActivity['years'];
    $permissionEvent=$store->one("SELECT id,event_type,details_json,created_at FROM marketing_contact_events WHERE contact_id=? AND event_type IN ('contact_added','subscription_changed') ORDER BY id DESC LIMIT 1",[$id]);
    $importEvent=$store->one("SELECT id,event_type,details_json,created_at FROM marketing_contact_events WHERE contact_id=? AND event_type IN ('imported','contact_imported') AND JSON_EXTRACT(details_json,'$.source_metadata') IS NOT NULL ORDER BY id DESC LIMIT 1",[$id]);
    $marketingDetails=audienceMarketingDetails($contact,$permissionEvent,$importEvent);
}
if($isPost&&$error!==''&&($_POST['action']??'')==='save_person'){$view='contact';$contact=array_replace($contact,array_intersect_key($_POST,$contact));}
$job=null;if(in_array($view,['job','import'],true)&&$id){try{$job=$store->job($id);}catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}}
$pageTitle=match($view){'tags'=>'Tags','groups'=>'Groups','fields'=>'Fields and merge tags','segments'=>'Segments','contact'=>$id?'Connection profile':'Add a single connection','settings'=>'Audience settings','import'=>'Import Connections','jobs'=>(($_GET['kind']??'import')==='export'?'Export history':'Import history'),'job'=>$job['name']??'Operation',default=>$filters['archived']==='1'?'Archived connections':'Connections'};
$editorRecovery=null;
if($isPost&&$error!==''){
    $recoveryDialogs=['save_tag'=>'tag-editor','save_group'=>'group-editor','save_field'=>'field-editor','save_segment'=>'segment-builder','rename_segment'=>'rename-segment','bulk_tag_paste'=>'bulk-tag','unsubscribe_emails'=>'unsubscribe-emails'];
    $failedAction=is_string($_POST['action']??null)?$_POST['action']:'';
    if(isset($recoveryDialogs[$failedAction])){
        $values=[];
        foreach(['id','version','name','display_type','label','merge_tag','field_type','position','default_value','options_text','required','is_public','tag_id','operation','emails']as$key){
            if(isset($_POST[$key])&&is_scalar($_POST[$key]))$values[$key]=mb_substr((string)$_POST[$key],0,$key==='emails'?($failedAction==='unsubscribe_emails'?1300000:200000):10000);
        }
        foreach(['required','is_public']as$key)$values[$key]=$values[$key]??'0';
        if(is_array($_POST['options']??null)){
            $values['options']=[];
            foreach(array_slice($_POST['options'],0,100)as$option)if(is_array($option))$values['options'][]=['id'=>is_scalar($option['id']??null)?(int)$option['id']:0,'name'=>is_scalar($option['name']??null)?mb_substr((string)$option['name'],0,100):''];
        }
        $editorRecovery=['dialog'=>$recoveryDialogs[$failedAction],'values'=>$values,'error'=>$error];
    }
}
$csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
releaseApplicationSessionLock();
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>array_merge(['assets/js/audience.min.js'],$view==='import'?['assets/js/audience-import.min.js']:[])]); ?>
<?php include __DIR__.'/templates/audience/workspace.php'; ?>
</html>
