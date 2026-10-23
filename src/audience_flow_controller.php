<?php
declare(strict_types=1);
require_once __DIR__.'/audience_flow_runtime.php';
require_once __DIR__.'/audience_flow_presentation.php';

function renderAudienceFlowWorkspace(AudienceStore $store,array $audience,string $view,int $id,string $message,string $error,bool $canManage,bool $canDelete,string $displayName,int $actor): void
{
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$url=static fn(array $p=[])=>'audience.php'.($p?'?'.http_build_query($p):'');
    $audienceId=$store->audienceId;$available=$store->flowAvailable();$recover=null;
    $json=($_POST['format']??'')==='json';$respond=static function(array $data,int $status=200):void{http_response_code($status);header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($data,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);};
    if($_SERVER['REQUEST_METHOD']==='POST'){
        requireValidCsrfToken();if(!$canManage){http_response_code(403);exit('An editor or administrator must manage automations.');}
        try{
            if(!$available)throw new InvalidArgumentException('Automations require the qualified database update.');
            if(isset($_POST['audience'])&&(string)$_POST['audience']!=='1')throw new InvalidArgumentException('Reload this audience before saving.');
            $action=audienceText($_POST,'action',40);$version=(int)($_POST['version']??0);$redirectView='flow';$redirectUrl=null;
            if($action==='flow_create'){
                $template=audienceText($_POST,'template',30)?:'blank';if($template!=='blank'&&!isset(audienceFlowTemplates()[$template]))throw new InvalidArgumentException('Choose an available flow template.');
                $d=audienceFlowDefault($template);if(in_array($d['trigger']['type'],['birthday','anniversary'],true))$d['settings']['repeat']=true;
                $id=$store->saveFlow(audienceText($_POST,'name',100),$d);$message='Draft created.';
            }elseif($action==='flow_save'){
                try{$document=json_decode(audienceText($_POST,'document',6_000_000),true,48,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('This flow document is invalid.');}
                if(!is_array($document))throw new InvalidArgumentException('This flow document is invalid.');
                $id=$store->saveFlow(audienceText($_POST,'name',100),$document,$id,$version);$saved=$store->flow($id);
                if($json){$respond(['id'=>$id,'version'=>(int)$saved['version'],'document'=>$saved['document'],'errors'=>$store->flowValidate($saved['document'])]);return;}$message='Draft saved.';
            }elseif($action==='flow_operation'){
                $op=audienceText($_POST,'operation',30);
                if($op==='remove'){
                    if(!$canDelete){http_response_code(403);exit('Only administrators can remove flows.');}
                    requireRecentAdminElevation($url(['view'=>$view,'id'=>$id]));
                    if(audienceText($_POST,'confirm',10)!=='REMOVE')throw new InvalidArgumentException('Type REMOVE to stop and remove this flow. Its history is retained.');
                }
                if($op==='delete'){
                    if(!$canDelete){http_response_code(403);exit('Only administrators can permanently delete archived flows.');}
                    requireRecentAdminElevation($url(['view'=>$view,'id'=>$id]));
                    if(audienceText($_POST,'confirm',10)!=='DELETE')throw new InvalidArgumentException('Type DELETE to permanently delete this archived flow and its execution history.');
                }
                $id=$store->flowOperation($id,$version,$op,audienceText($_POST,'node',40));$message=match($op){'activate'=>'Flow turned on. New qualifying activity can enter.','pause'=>'Flow paused. Connections keep their positions.','archive','remove'=>'Flow archived. Queued actions were cancelled.','restore'=>'Flow restored as a draft.','delete'=>'Flow permanently deleted.',default=>'Flow updated.'};
                if(in_array($op,['archive','remove','restore','delete'],true)){$redirectView='automations';$id=0;$redirectUrl=$url(['view'=>'automations']+array_intersect_key($_GET,array_flip(['tab','trash','q','state','sort','page','per_page'])));}
            }elseif($action==='flow_classic'){
                try{$classic=json_decode(audienceText($_POST,'classic',100000),true,32,JSON_THROW_ON_ERROR);}catch(JsonException){throw new InvalidArgumentException('Enter a valid supported classic workflow.');}
                if(!is_array($classic))throw new InvalidArgumentException('Enter a supported classic workflow.');$d=audienceFlowClassicDocument($classic);$id=$store->saveFlow(audienceText($_POST,'name',100),$d);$message='Classic workflow converted to a private draft. Review it before turning it on.';
            }elseif($action==='flow_from_campaign'){
                $campaign=$store->campaign((int)($_POST['campaign_id']??0));$send=$campaign['draft']['send_time']??[];if(($send['mode']??'')!=='triggered')throw new InvalidArgumentException('Choose a native email configured for a tag trigger.');
                $d=audienceFlowDefault('tagged');$d['trigger']['id']=(int)($send['trigger_tag']??0);$d['nodes'][0]['campaign_id']=(int)$campaign['id'];$d['classic']=true;$id=$store->saveFlow($campaign['name'],$d);$message='Tag-triggered email converted to a flow draft. Existing email and history were kept.';
            }elseif(in_array($action,['flow_add','flow_exit','flow_retry'],true)){
                if(isRestrictedDemoSession()||!accountSecurityAccessAllowed())throw new InvalidArgumentException('Running flows requires an unrestricted editor or administrator.');
                $store->flowLock($id,function()use($store,$id,$action):void{$store->transaction(function()use($store,$id,$action):void{
                    $flow=$store->flow($id,true);
                    if($action==='flow_add'){
                        if($flow['state']!=='running')throw new InvalidArgumentException('Turn this flow on before adding connections.');$key=audienceText($_POST,'entry_key',32);if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Reload before adding a connection.');
                        $row=$store->one('SELECT document_json FROM marketing_flow_revisions WHERE audience_id=? AND flow_id=? AND id=?',[$store->audienceId,$id,$flow['published_revision']]);$person=(int)($_POST['contact_id']??0);$store->person($person);$d=audienceJson($row['document_json']);if(!audienceFlowEnroll($store,$flow,$d,$person,'manual:'.$key,time()))throw new InvalidArgumentException('This connection is already enrolled or does not meet the entry and repeat rules.');
                    }else{
                        $run=$store->one('SELECT * FROM marketing_flow_runs WHERE audience_id=? AND flow_id=? AND id=? FOR UPDATE',[$store->audienceId,$id,(int)($_POST['run_id']??0)]);if(!$run)throw new InvalidArgumentException('This execution is unavailable.');
                        if($action==='flow_exit')audienceFlowFinish($store,$run,'exited','Removed manually',time());
                        else{
                            if($run['state']!=='failed')throw new InvalidArgumentException('Only a failed step can be retried.');
                            if($store->one('SELECT id FROM marketing_campaign_deliveries WHERE audience_id=? AND flow_run_id=? AND flow_node=?',[$store->audienceId,$run['id'],$run['node_key']]))throw new InvalidArgumentException('Review this email’s existing delivery receipt. Email actions cannot be replayed from this control.');
                            $store->write("UPDATE marketing_flow_runs SET state='active',due_at=UTC_TIMESTAMP(),error_message='' WHERE audience_id=? AND id=?",[$store->audienceId,$run['id']]);
                        }
                    }
                });});$redirectView='flow-report';$message='Execution updated.';
            }else throw new InvalidArgumentException('Choose a supported flow action.');
            if($json){$respond(['redirect'=>$redirectUrl??$url(array_filter(['view'=>$redirectView,'id'=>$id]))]);return;}
            $_SESSION['audience_message']=$message;header('Location: '.($redirectUrl??$url(array_filter(['view'=>$redirectView,'id'=>$id]))));return;
        }catch(InvalidArgumentException $e){$error=$e->getMessage();if($json){$respond(['error'=>$error],422);return;}if(($_POST['action']??'')==='flow_save')$recover=$_POST;}
    }
    if($view==='automations')$id=0;
    $flow=null;if($id&&$available){try{$flow=$store->flow($id,false,$view==='flow-report');}catch(InvalidArgumentException){http_response_code(404);exit('This flow is unavailable.');}}
    if(in_array($view,['flow','flow-report'],true)&&!$flow){http_response_code(404);exit('This flow is unavailable.');}
    if($recover&&$flow){try{$flow['document']=audienceFlowDocument(json_decode($recover['document'],true,48,JSON_THROW_ON_ERROR));$flow['version']=(int)$recover['version'];$flow['name']=audienceText($recover,'name',100);}catch(Throwable){}}
    try{$listOptions=audienceFlowListOptions($view==='automations'?$_GET:[]);}catch(InvalidArgumentException $e){http_response_code(400);$error=$e->getMessage();$listOptions=audienceFlowListOptions([]);}
    $q=$view==='flow-templates'?audienceText($_GET,'q',100):$listOptions['q'];$state=$listOptions['state'];$flowSort=$listOptions['sort'];$flowOrder=match($flowSort){'updated'=>'f.updated_at DESC,f.id DESC','oldest'=>'f.created_at ASC,f.id ASC',default=>'f.created_at DESC,f.id DESC'};$classic=($_GET['tab']??'')==='classic';$trash=($_GET['trash']??'')==='1';$flows=[];$flowSize=audiencePageSize($_GET);$flowPage=max(1,(int)($_GET['page']??1));$flowTotal=0;
    if($available){$where='f.audience_id=? AND f.deleted_at IS '.($trash?'NOT NULL':'NULL');$params=[$audienceId];if($q!==''){$where.=' AND LOCATE(?,f.name)>0';$params[]=$q;}if(in_array($state,['draft','running','paused'],true)){$where.=' AND f.state=?';$params[]=$state;}$where.=" AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(f.draft_json,'$.classic')),'false')='".($classic?'true':'false')."'";$flowTotal=(int)$store->one('SELECT COUNT(*) n FROM marketing_flows f WHERE '.$where,$params)['n'];$flowPage=min($flowPage,max(1,(int)ceil($flowTotal/$flowSize)));$flows=$store->all('SELECT f.id,f.name,f.state,f.version,f.created_at,f.updated_at,f.deleted_at,f.published_revision,(SELECT COUNT(*) FROM marketing_flow_runs r WHERE r.flow_id=f.id) started,(SELECT COUNT(*) FROM marketing_flow_runs r WHERE r.flow_id=f.id AND r.state=\'active\') active,(SELECT COUNT(*) FROM marketing_flow_runs r WHERE r.flow_id=f.id AND r.state=\'completed\') completed FROM marketing_flows f WHERE '.$where.' ORDER BY '.$flowOrder.' LIMIT ? OFFSET ?',[...$params,$flowSize,($flowPage-1)*$flowSize]);}
    $tags=$store->tags();$groups=$store->groups();$fields=$store->fields();$catalog=$store->catalog();$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);$segment=null;$job=null;$editorRecovery=null;
    $campaignOptions=$store->all("SELECT id,name,campaign_type,JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.send_time.mode')) send_mode FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL AND campaign_type IN ('regular','plain-text') ORDER BY updated_at DESC LIMIT 500",[$audienceId]);
    $surveyOptions=$store->all("SELECT id,name FROM marketing_collection_resources WHERE audience_id=? AND kind='survey' AND deleted_at IS NULL ORDER BY name LIMIT 500",[$audienceId]);
    $flowOptions=$available?$store->all('SELECT id,name FROM marketing_flows WHERE audience_id=? AND deleted_at IS NULL ORDER BY name LIMIT 500',[$audienceId]):[];
    $flowSetupTasks=[];$nativeFlowTotal=0;
    if($view==='automations'&&$available&&!$classic&&!$trash){
        $nativeFlowTotal=(int)$store->one("SELECT COUNT(*) n FROM marketing_flows WHERE audience_id=? AND deleted_at IS NULL AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.classic')),'false')='false'",[$audienceId])['n'];
        $welcome=$store->one("SELECT id FROM marketing_flows WHERE audience_id=? AND deleted_at IS NULL AND (JSON_UNQUOTE(JSON_EXTRACT(draft_json,'$.trigger.type'))='signup' OR JSON_CONTAINS(JSON_EXTRACT(draft_json,'$.additional_triggers'),JSON_OBJECT('type','signup'))) AND JSON_CONTAINS(JSON_EXTRACT(draft_json,'$.nodes'),JSON_OBJECT('type','email')) LIMIT 1",[$audienceId]);
        $hosted=$store->collectionAvailable()?$store->one("SELECT id FROM marketing_collection_resources WHERE audience_id=? AND kind='hosted' AND state='published' AND deleted_at IS NULL LIMIT 1",[$audienceId]):null;
        $ready=false;
        if($store->sesAvailable()){$ses=$store->sesSettings();$readiness=$store->sendingReadiness($ses['from_email']??'',audienceJson($audience['settings_json']));$policy=$store->deliveryRuntimeAvailable()?$store->one('SELECT bulk_approved FROM marketing_delivery_policy WHERE audience_id=?',[$audienceId]):[];$ready=$readiness['ready']&&!empty($policy['bulk_approved']);}
        $flowSetupTasks=audienceFlowSetupTasks(['welcome'=>(bool)$welcome,'form'=>(bool)$hosted,'organize'=>(bool)($tags||$groups),'delivery'=>$ready],$canDelete);
    }
    $report=$flow?$store->flowReport($id,$_GET):null;$validation=$flow?$store->flowValidate($flow['document']):[];
    if($view==='flow-report'&&($_GET['download']??'')==='csv'){
        if(!$canManage){http_response_code(403);exit('Export requires an editor or administrator.');}
        releaseApplicationSessionLock();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="flow-execution.csv"');header('Cache-Control: no-store');$out=fopen('php://output','w');fputcsv($out,['Execution','Connection','State','Step','Started','Finished'],',','"','');
        for($page=1;$page<=$report['pages']&&$page<=400;$page++)foreach($store->flowReport($id,array_replace($_GET,['page'=>$page]))['runs']as$r)fputcsv($out,array_map('audienceCsvCell',[(string)$r['id'],$r['deleted_at']?'Removed connection':$r['email'],$r['state'],$r['node_key'],$r['started_at'],$r['ended_at']??'']),',','"','');fclose($out);return;
    }
    $flowPreview=$view==='flow-templates'&&isset(audienceFlowTemplates()[$_GET['preview']??''])?audienceText($_GET,'preview',30):null;
    $pageTitle=$flow?$flow['name']:($view==='flow-templates'?($flowPreview?'Flow template':'Flow templates'):'Automations');$csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
    releaseApplicationSessionLock();
    ?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-flows.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-flows.min.js']]);include __DIR__.'/templates/audience/workspace.php';?></html><?php
}
