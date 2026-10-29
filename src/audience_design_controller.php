<?php
require_once __DIR__.'/audience_ai.php';
require_once __DIR__.'/audience_template_transfer.php';
function renderAudienceDesignWorkspace(AudienceStore $store,array $audience,string $view,int $id,string $message,string $error,bool $canManage,bool $canDelete,string $displayName,int $actor):void
{
    $audienceId=$store->audienceId;$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $url=static fn(array $p=[])=>'audience.php'.($p?'?'.http_build_query($p):'');
    $folderFilter=$_GET['folder']??'';
    if(!is_string($folderFilter)||!mb_check_encoding($folderFilter,'UTF-8')||mb_strlen($folderFilter)>100)$folderFilter='';
    $templateListUrl=static fn(string $tab='saved')=>$url(['view'=>'templates','tab'=>$tab]+($folderFilter!==''?['folder'=>$folderFilter]:[]));
    $kind=($_GET['kind']??'')==='template'?'template':'campaign';$starters=audienceDesignStarters();
    $brand=$store->contentSettings()['settings']['brand']??[];
    foreach($starters as&$starter)$starter['design']=audienceDesignBrandDefaults($starter['design'],$brand,true);unset($starter);
    $variantRaw=$_GET['variant']??'0';if(!is_string($variantRaw)||!preg_match('/^[0-7]$/D',$variantRaw)||($kind==='template'&&$variantRaw!=='0')){http_response_code(404);exit('This content variation is unavailable.');}$variant=(int)$variantRaw;
    $jsonRequest=($_SERVER['HTTP_X_AUDIENCE_DESIGN']??'')==='1'||(is_string($_POST['action']??null)&&str_starts_with($_POST['action'],'design_'));
    $respond=static function(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode($data,JSON_THROW_ON_ERROR);exit;};
    if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['template_export'])){
        if(!$canManage){http_response_code(403);exit('Template export requires an editor or administrator.');}
        try{$template=$store->emailTemplate(\Dnr\Http\RequestInput::positiveInt($_GET,'template_export')??0,false,true);$file=audienceTemplatePackageExport($template['name'],$template['design'],currentAccountKey(),applicationVersion());}
        catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}
        header('Content-Type: application/vnd.moed.message-monkey-template+json');header('Content-Disposition: attachment; filename="message-monkey-template-'.$template['id'].'.mmtemplate"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');echo $file;exit;
    }
    if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['download'])){
        if(!$canManage){http_response_code(403);exit('Template export requires an editor or administrator.');}
        try{$template=$store->emailTemplate(\Dnr\Http\RequestInput::positiveInt($_GET,'download')??0);$document=audienceDesign($template['design']);}
        catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}
        header('Content-Type: text/html; charset=UTF-8');header('Content-Disposition: attachment; filename="email-template-'.$template['id'].'.html"');header('Cache-Control: no-store');echo audienceDesignHtml($document);exit;
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        requireValidCsrfToken();if(!$canManage){http_response_code(403);exit('You do not have permission to edit email designs.');}
        try{
            $action=audienceText($_POST,'action');if(str_starts_with($action,'design_')){$owner=$store->designOwner($kind,$id);if($kind==='campaign')$store->campaignDesign($owner,$variant);}$version=\Dnr\Http\RequestInput::positiveInt($_POST,'version')??0;$redirect=$templateListUrl();
            if(isset($_POST['audience'])&&(string)$_POST['audience']!=='1')throw new InvalidArgumentException('Reload the canonical audience before saving.');
            if($action==='design_test'){
                if(isRestrictedDemoSession()||!accountSecurityAccessAllowed())$respond(['error'=>'Sending requires an unrestricted editor or administrator.'],403);
                if(!$store->deliveryRuntimeAvailable())throw new InvalidArgumentException('The delivery upgrade is not installed yet.');
                $n=$store->queueCampaignTest($id,$version,$_POST,$audience,accountPrimaryPublicUrl(),$kind,$variant);$respond(['queued'=>$n]);
            }elseif($action==='design_save'){
                $design=audienceDesign(audienceJson(audienceDesignPayload($_POST['design']??'')));
                $version=$store->saveEmailDesign($kind,$id,$version,$design,$variant);
                $respond(['version'=>$version]);
            }elseif($action==='design_rename'){
                if($kind!=='template')throw new InvalidArgumentException('Only email templates can be renamed here.');
                $name=audienceText($_POST,'name',100);$store->emailTemplateOperation('rename',$id,$version,['name'=>$name]);
                $respond(['name'=>$name,'version'=>$version+1]);
             }elseif($action==='design_preview'||$action==='design_links'||$action==='design_source'){
                $design=audienceDesign(audienceJson(audienceDesignPayload($_POST['design']??'')));$store->validateDesignConditions($design);
                $personId=\Dnr\Http\RequestInput::positiveInt($_POST,'person_id')??0;$context=($_POST['live']??'')==='1'?$store->designRecipientContext($personId,$audience):null;
                $draft=$kind==='campaign'?($owner['draft']??[]):[];if($context!==null)$context+=['MC:SUBJECT'=>(string)($draft['subject']??''),'MC_PREVIEW_TEXT'=>(string)($draft['preview_text']??''),'CURRENT_YEAR'=>gmdate('Y'),'LIST:NAME'=>$audience['name']];
                // Resolve audience identity in ordinary preview without evaluating recipient conditions.
                $identity=$store->designRecipientContext(0,$audience);$identity+=['CURRENT_YEAR'=>gmdate('Y'),'LIST:NAME'=>$audience['name']];
                $preview=audienceDesignPreviewHtml($design,audienceText($_POST,'preview_nonce',100)?:contentSecurityPolicyNonce(),$context,($_POST['dark']??'')==='1',$identity);$plain=audienceDesignPlainText($design,$context,$identity);
                if($context===null){$htmlTags=[];$plainTags=[];foreach($identity as$key=>$value){$htmlTags['*|'.$key.'|*']=htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$plainTags['*|'.$key.'|*']=(string)$value;}$preview=strtr($preview,$htmlTags);$plain=strtr($plain,$plainTags);}
                $report=audienceDesignLinkReport($design,$context);if($context===null&&trim($identity['LIST:ADDRESS'])==='')$report['warnings'][]='The audience mailing address is missing.';$safe=$design['mode']==='code'?audienceDesignSafeCode($design['code'],$design['areas']):['warnings'=>[],'regions'=>[]];
                $respond(['html'=>$preview,'text'=>$plain,'report'=>$report,'warnings'=>$safe['warnings'],'regions'=>$safe['regions'],'source'=>$action==='design_source'?($design['mode']==='code'?audienceDesignCodeWithAreas($design['code'],$design['areas']):audienceDesignHtml($design,$context)):null]);
            }elseif($action==='design_people'){
                $search=audienceText($_POST,'search',100);$respond(['people'=>$store->all("SELECT id,email,first_name,last_name FROM marketing_contacts WHERE audience_id=? AND deleted_at IS NULL AND is_archived=0 AND (LOCATE(?,email)>0 OR LOCATE(?,CONCAT(first_name,' ',last_name))>0) ORDER BY email LIMIT 20",[$audienceId,$search,$search])]);
            }elseif($action==='design_probe_link'){$respond(audienceDesignProbeLink(audienceText($_POST,'url',2048)));}
            elseif($action==='design_history'){$respond(['revisions'=>$store->designRevisions($kind,$id,$variant)]);}
            elseif($action==='design_restore'){$respond($store->restoreDesignRevision($kind,$id,$version,\Dnr\Http\RequestInput::positiveInt($_POST,'revision_id')??0,$variant));}
            elseif($action==='design_comments'){$respond(['comments'=>$store->designComments($kind,$id)]);}
            elseif($action==='design_comment_add'){$store->addDesignComment($kind,$id,$_POST);$respond(['comments'=>$store->designComments($kind,$id)]);}
            elseif($action==='design_comment_resolve'){$store->resolveDesignComment($kind,$id,\Dnr\Http\RequestInput::positiveInt($_POST,'comment_id')??0,\Dnr\Http\RequestInput::positiveInt($_POST,'comment_version')??0,($_POST['resolved']??'')==='1');$respond(['comments'=>$store->designComments($kind,$id)]);}
            elseif($action==='design_library'){$respond($store->designLibrary(audienceText($_POST,'type'),audienceText($_POST,'search',100),\Dnr\Http\RequestInput::positiveInt($_POST,'page')??1,audiencePageSize($_POST)));}
            elseif($action==='design_library_get'){$respond(['item'=>$store->designLibraryItem(\Dnr\Http\RequestInput::positiveInt($_POST,'item_id')??0)]);}
            elseif($action==='design_library_save'){$item=$store->saveDesignLibrary(audienceText($_POST,'type'),audienceText($_POST,'name',100),audienceJson(audienceDesignPayload($_POST['data']??'')));$respond(['id'=>$item]);}
            elseif($action==='design_library_operation'){
                $operation=audienceText($_POST,'operation');
                if($operation==='delete'){
                    if(!$canDelete)$respond(['error'=>'Only administrators can move files to Trash.'],403);
                    requireRecentAdminElevation($url(['view'=>'content']));
                }
                $store->operateDesignLibrary(\Dnr\Http\RequestInput::positiveInt($_POST,'item_id')??0,\Dnr\Http\RequestInput::positiveInt($_POST,'item_version')??0,$operation,audienceText($_POST,'name',100));$respond(['saved'=>true]);
            }
            elseif($action==='design_code_validate'){$safe=audienceDesignSafeCode(audienceText($_POST,'code',1000000));$respond(['warnings'=>$safe['warnings'],'regions'=>$safe['regions']]);}
            elseif($action==='design_template'){
                // Save only the scoped, version-matched persisted snapshot, never a foreign ID.
                $row=$kind==='template'?$store->emailTemplate($id):$store->campaign($id);
                if((int)$row['version']!==$version)throw new InvalidArgumentException('This design changed. Save your current design before creating a template.');
                $design=$kind==='template'?$row['design']:$store->campaignDesign($row,$variant);
                if(!$design)throw new InvalidArgumentException('Save your design first.');
                $tid=$store->createEmailTemplate(audienceText($_POST,'name',100),$design,audienceText($_POST,'folder',100));$respond(['id'=>$tid]);
            }elseif($action==='template_from_campaign'){
                $source=$store->campaign($id);$design=$source['draft']['design']??null;if(!$design)throw new InvalidArgumentException('This campaign has no reusable design.');
                $tid=$store->createEmailTemplate($source['name'],$design);$redirect=$url(['view'=>'designer','kind'=>'template','id'=>$tid]);
            }elseif($action==='template_legacy'){
                $columns=\Dnr\Http\RequestInput::positiveInt($_POST,'columns')??1;if($columns>3)throw new InvalidArgumentException('Choose one to three columns.');
                $code='<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><table role="presentation" width="600" align="center"><tr><td colspan="'.$columns.'" mc:edit="header"><h1>Your Headline</h1></td></tr><tr>';
                for($column=1;$column<=$columns;$column++)$code.='<td valign="top" width="'.(100/$columns).'%" mc:edit="body_'.$column.'"><p>Add your content here.</p></td>';
                $code.='</tr><tr><td colspan="'.$columns.'" mc:edit="footer"><p>Stay connected with our community.</p></td></tr></table></body></html>';
                $document=$starters['blank']['design'];$document['mode']='code';$document['code']=$code;$tid=$store->createEmailTemplate(audienceText($_POST,'name',100)?:'Legacy template',$document);$redirect=$url(['view'=>'designer','kind'=>'template','id'=>$tid]);
            }elseif($action==='template_package_import'){
                $import=audienceTemplatePackageUpload($_FILES['template_package']??[],currentAccountKey());
                $tid=$store->createEmailTemplate($import['name'],$import['design']);$redirect=$url(['view'=>'designer','kind'=>'template','id'=>$tid]);
            }elseif($action==='template_import'){
                $import=isset($_FILES['template_file'])&&($_FILES['template_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE?audienceDesignImportFile($_FILES['template_file']):['code'=>audienceText($_POST,'code',1000000)];
                if(trim($import['code'])==='')throw new InvalidArgumentException('Paste HTML or choose an HTML/ZIP file.');
                $document=$starters['blank']['design'];$document['mode']='code';$document['code']=$import['code'];$tid=$store->createEmailTemplate(audienceText($_POST,'name',100)?:'Custom HTML template',$document);$redirect=$url(['view'=>'designer','kind'=>'template','id'=>$tid]);
            }elseif($action==='template_create'){
                $starter=audienceText($_POST,'starter');if(!isset($starters[$starter]))throw new InvalidArgumentException('Choose a starter template.');
                $tid=$store->createEmailTemplate(audienceText($_POST,'name',100)?:$starters[$starter]['name'],$starters[$starter]['design']);$redirect=$url(['view'=>'designer','kind'=>'template','id'=>$tid]);
            }elseif($action==='template_folder'){
                $store->emailTemplateFolder(audienceText($_POST,'operation'),audienceText($_POST,'name',100),audienceText($_POST,'new_name',100),$version);
                $message='Template folders updated.';$redirect=$templateListUrl(($_GET['tab']??'')==='archived'?'archived':'saved');
            }elseif($action==='template_bulk_move'){
                if(!is_array($_POST['selected']??null)||!is_array($_POST['versions']??null))throw new InvalidArgumentException('Choose saved templates to move.');
                $store->moveEmailTemplates($_POST['selected'],$_POST['versions'],audienceText($_POST,'folder',100));$message='Selected templates moved.';
            }elseif($action==='template_operation'){
                $op=audienceText($_POST,'operation');
                if($op==='delete'){
                    if(!$canDelete){http_response_code(403);exit('Only administrators can permanently delete archived templates.');}
                    requireRecentAdminElevation($url(['view'=>'templates','tab'=>'archived']));
                }
                $result=$store->emailTemplateOperation($op,$id,$version,$_POST);
                $message=match($op){'archive'=>'Template archived. Existing campaign designs are preserved.','restore'=>'Template restored.','delete'=>'Template permanently deleted. Existing campaign designs are preserved.',default=>'Template updated.'};
                if($op==='delete')$redirect=$templateListUrl('archived');
                if($op==='campaign')$redirect=$url(['view'=>'designer','id'=>$result]);
                elseif($op==='replicate')$redirect=$url(['view'=>'designer','kind'=>'template','id'=>$result]);
            }else throw new InvalidArgumentException('Unknown design action.');
            $_SESSION['audience_message']=in_array($action,['template_operation','template_folder','template_bulk_move'],true)?$message:'Template saved.';header('Location: '.$redirect);exit;
        }catch(InvalidArgumentException $e){if($jsonRequest)$respond(['error'=>$e->getMessage()],422);$error=$e->getMessage();}
        catch(Throwable $e){applicationLog('error','Email design operation failed',['error'=>$e->getMessage()]);if($jsonRequest)$respond(['error'=>'Unable to save. Your edits are still here. Try again.'],500);$error='Unable to save. Try again.';}
    }
    $csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
    $templateFolderContext=$store->contentSettings();$templateFolders=$store->emailTemplateFolders($templateFolderContext['settings']);
    $templateFolderFilter=$folderFilter==='unfiled'||in_array($folderFilter,$templateFolders,true)?$folderFilter:'';
    $archivedTemplates=$view==='templates'&&($_GET['tab']??'')==='archived';
    $templates=$store->all('SELECT id,name,folder,version,updated_at,actor_label,design_json,deleted_at FROM marketing_email_templates WHERE audience_id=? AND deleted_at IS '.($archivedTemplates?'NOT NULL':'NULL'),[$audienceId]);
    // Embedded photographs must not become MySQL filesort payloads.
    usort($templates,static fn($a,$b)=>[$b['updated_at'],(int)$b['id']]<=>[$a['updated_at'],(int)$a['id']]);
    if($view==='designer'){
        if(!$canManage){http_response_code(403);exit('The designer requires an editor or administrator.');}
        try{$record=$kind==='template'?$store->emailTemplate($id):$store->campaign($id);if($kind==='campaign'&&($record['status']!=='draft'||$record['campaign_type']!=='regular')){$_SESSION['audience_designer_unavailable']=$id;header('Location: '.$url(['view'=>'campaign','id'=>$id]));return;}$variantDesign=$kind==='template'?$record['design']:$store->campaignDesign($record,$variant);}
        catch(InvalidArgumentException $e){http_response_code(404);exit($h($e->getMessage()));}
        $design=audienceDesignBrandDefaults($variantDesign??$starters['blank']['design'],$brand);if($variant>0)$record['name'].=' · Variation '.($variant+1);
        $savedDesigns=[];foreach($templates as$t)$savedDesigns[]=['id'=>(int)$t['id'],'name'=>$t['name'],'design'=>audienceDesignBrandDefaults(audienceJson($t['design_json']),$brand)];
        $dynamicFields=['FNAME'=>'First Name','LNAME'=>'Last Name','EMAIL'=>'Email Address','SOURCE'=>'Signup source','EMAIL_STATUS'=>'Email status'];foreach($store->fields()as$f)$dynamicFields[$f['merge_tag']]=$f['label'];foreach($store->tags()as$t)$dynamicFields['tag:'.$t['id']]='Tag: '.$t['name'];foreach($store->groups()as$g)foreach($g['options']as$o)$dynamicFields['group:'.$o['id']]='Group: '.$g['name'].' / '.$o['name'];
        $mergeTags=['FNAME'=>'First Name','LNAME'=>'Last Name','EMAIL'=>'Email Address'];foreach($store->fields()as$f)$mergeTags[$f['merge_tag']]=$f['label'];
        $sesSettings=$store->sesAvailable()?$store->sesSettings():[];
        $testSenders=$sesSettings&&!audienceSesReasons($sesSettings,$sesSettings['from_email']??'',requireFreshCheck:false)?[['email'=>$sesSettings['from_email']]]:[];
        $brandFont=audienceBrandFont($brand);
        $brandLogos=[];foreach(['logo'=>'Primary logo','secondary_logo'=>'Secondary logo']as$key=>$label)if(!empty($brand[$key]))$brandLogos[$key]=['label'=>$label,'image'=>audienceDesignImage($brand[$key],2000000,true)];
        $designerConfig=['permissionReminder'=>audiencePermissionReminder(audienceBrandIdentity(audienceJson($audience['settings_json']),(string)$audience['name'])),'brandFont'=>$brandFont,'brandLogos'=>(object)$brandLogos,'brandUrl'=>$url(['view'=>'brand']),'nonce'=>contentSecurityPolicyNonce(),'design'=>$design,'version'=>(int)$record['version'],'endpoint'=>$url(['view'=>'designer','kind'=>$kind,'id'=>$id,'variant'=>$variant]),'exit'=>$url($kind==='template'?['view'=>'templates','tab'=>'saved']:['view'=>'campaign','id'=>$id]),'starters'=>$starters,'saved'=>$savedDesigns,'mergeTags'=>$mergeTags,'dynamicFields'=>$dynamicFields,'recoveryKey'=>hash('sha256',$themeKey.':'.$kind.':'.$id.':'.$variant),'kind'=>$kind,'canSend'=>!isRestrictedDemoSession()&&accountSecurityAccessAllowed(),'senders'=>$testSenders];
        $aiInline=true;$aiText='';$aiSubject='';$aiImages=[];$aiConfig=['mode'=>'content','endpoint'=>'audience_ai_api.php','window'=>[],'enabled'=>aiCoachEnabled(),'key'=>hash('sha256',$themeKey.':'.($_SESSION['role']??'').':'.aiCoachStorageKey())];
        releaseApplicationSessionLock();
        ?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle('Email Designer'),['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-designer.min.css','assets/css/pages/audience-ai.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-designer.min.js','assets/js/audience-ai.min.js']]);include __DIR__.'/templates/audience/designer.php';?></html><?php
        return;
    }
    $tab=in_array($_GET['tab']??'', ['built-in','saved','recent','archived'],true)?$_GET['tab']:'saved';
    $recentIds=array_column($store->all("SELECT id FROM marketing_campaigns WHERE audience_id=? AND deleted_at IS NULL AND status='sent' AND campaign_type='regular' ORDER BY sent_at DESC,id DESC LIMIT 50",[$audienceId]),'id');
    $recentById=[];$recentDesigns=[];
    if($recentIds)foreach($store->all('SELECT id,name,draft_json,updated_at FROM marketing_campaigns WHERE audience_id=? AND id IN ('.implode(',',array_fill(0,count($recentIds),'?')).')',[$audienceId,...$recentIds])as$recent)$recentById[$recent['id']]=$recent;
    foreach($recentIds as$recentId)if(isset($recentById[$recentId]))$recentDesigns[]=$recentById[$recentId];
    $canExport=$canManage;$tags=[];$groups=[];$fields=[];$catalog=[];$segments=[];$filters=audienceListFilters([]);$rules=['match'=>'all','rules'=>[]];$segment=null;$job=null;$editorRecovery=null;$pageTitle='Email Templates';
    releaseApplicationSessionLock();
    ?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle('Email Templates'),['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-designer.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-designer.min.js']]);include __DIR__.'/templates/audience/workspace.php';?></html><?php
}
