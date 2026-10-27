<?php
declare(strict_types=1);

function renderAudienceCollectionWorkspace(AudienceStore $store,array $audience,string $view,int $id,string $message,string $error,bool $canManage,bool $canDelete,string $displayName,int $actor): void
{
    $h=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
    $url=static fn(array $p=[]):string=>'audience.php'.($p?'?'.http_build_query($p):'');
    $audienceId=$store->audienceId; $isPost=$_SERVER['REQUEST_METHOD']==='POST';
    $available=$store->collectionAvailable(); $resource=null; $recover=null; $operationRedirect=null; $collectionEditorError=null;
    if ($isPost) {
        requireValidCsrfToken();
        $action=audienceText($_POST,'action',40);
        if (!$canManage && $action!=='collection_preview') { http_response_code(403); exit('An editor or administrator must manage forms.'); }
        try {
            if (!$available) throw new InvalidArgumentException('Forms require the qualified database update.');
            $action=audienceText($_POST,'action',40); $version=\Dnr\Http\RequestInput::positiveInt($_POST,'version')??0;
            if ($action==='collection_new_tag') {
                $store->collectionResource($id);
                $name=audienceText($_POST,'tag_name',100);
                $tagId=$store->ensureTag($name);
                releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
                echo json_encode(['id'=>$tagId,'name'=>$name],JSON_THROW_ON_ERROR);return;
            } elseif ($action==='collection_library') {
                $library=$store->designLibrary('image',audienceText($_POST,'search',100),max(1,(int)($_POST['page']??1)),audiencePageSize($_POST));
                releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode($library,JSON_THROW_ON_ERROR);return;
            } elseif ($action==='collection_library_image') {
                $image=$store->designLibraryItem((int)audienceText($_POST,'image_id',12));
                if($image['item_type']!=='image')throw new InvalidArgumentException('Choose an image from this account.');
                releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode(['image'=>$image['data']['image'],'alt'=>$image['data']['alt']??''],JSON_THROW_ON_ERROR);return;
            } elseif ($action==='collection_image') {
                if(!$canManage){http_response_code(403);exit('Only an editor can upload images.');}
                $image=audienceContentUpload(is_array($_FILES['image']??null)?$_FILES['image']:[]);
                releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');echo json_encode(['image'=>$image],JSON_THROW_ON_ERROR);return;
            } elseif ($action==='collection_preview') {
                require_once __DIR__.'/audience_collection_render.php';
                $r=$store->collectionResource($id);
                try { $doc=json_decode(audienceText($_POST,'document_json',3000000),true,32,JSON_THROW_ON_ERROR); }
                catch (JsonException) { throw new InvalidArgumentException('The preview document is invalid.'); }
                if (!is_array($doc)) throw new InvalidArgumentException('The preview document is invalid.');
                $r['document']=audienceCollectionDocument($doc,$r['kind'],$store->fields(),$store->groups(),$store->tags());
                $r['archive_url']=accountPrimaryPublicUrl().'/audience_campaign_public.php?form='.$r['public_key'];
                $screen=\Dnr\Http\RequestInput::enum($_POST,'screen',['signup','thanks','confirm','unsubscribe'],'signup');$previewSuccess='';
                if($r['kind']==='hosted'&&in_array($screen,['confirm','unsubscribe'],true)){
                    $system=$r['document']['system'];$r['kind']=$screen;
                    foreach(['title','intro','button']as$key)$r['document'][$key]=$system[$screen.'_'.$key];
                }elseif($screen==='thanks')$previewSuccess=$r['document']['thanks'];
                releaseApplicationSessionLock(); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store');
                echo json_encode(['html'=>audienceCollectionHtml($r,$store->fields(),$store->groups(),'','',[], '', $previewSuccess,null,true,'',audienceText($_POST,'preview_nonce',100))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); return;
            } elseif ($action==='collection_defaults') {
                if (!$canDelete) { http_response_code(403); exit('Only an administrator can change audience defaults.'); }
                $store->collectionDefaults($version,$_POST); $id=0; $message='Audience form defaults saved. New forms will use these defaults.';
            } elseif ($action==='collection_tag') {
                $store->tagCollectionResponse($id,(int)audienceText($_POST,'response',20),(int)audienceText($_POST,'tag_id',20));
                $_SESSION['audience_message']='Tag applied to the verified connection.'; header('Location: '.$url(['view'=>'collection-report','id'=>$id])); return;
            } elseif ($action==='collection_save') {
                $input=$_POST;
                if (isset($_POST['document_json'])) {
                    try { $input['document']=json_decode(audienceText($_POST,'document_json',3000000),true,32,JSON_THROW_ON_ERROR); }
                    catch (JsonException) { throw new InvalidArgumentException('The form design is invalid. Reload and try again.'); }
                    if (!is_array($input['document'])) throw new InvalidArgumentException('Invalid form design.');
                }
                $id=$store->saveCollection($input,$id,$version); $message='Draft saved. Publish when it’s ready.';
            } elseif ($action==='collection_operation') {
                $op=audienceText($_POST,'operation',30);
                if($op==='delete'){
                    if(!$canDelete){http_response_code(403);exit('Only administrators can permanently delete archived forms.');}
                    requireRecentAdminElevation($url(['view'=>$view,'id'=>$id]));
                    if(audienceText($_POST,'confirm',10)!=='DELETE')throw new InvalidArgumentException('Type DELETE to permanently delete this archived form and its results.');
                }
                if ($op==='remove' && audienceText($_POST,'confirm',10)!=='REMOVE') throw new InvalidArgumentException('Type REMOVE to remove this form and stop its public link.');
                if(in_array($op,['archive','remove','restore','delete'],true)){
                    $entry=$store->collectionResource($id,false,true);
                    $listView=in_array($view,['forms','form-settings','landing-pages','surveys','preferences'],true)?$view:match($entry['kind']){'survey'=>'surveys','landing'=>'landing-pages','preferences'=>'preferences',default=>'forms'};
                    $operationRedirect=['view'=>$listView,'trash'=>in_array($op,['restore','delete'],true)?'1':'0','q'=>audienceText($_GET,'q',100),'type'=>audienceText($_GET,'type',20),'per_page'=>(int)($_GET['per_page']??100),'direction'=>($_GET['direction']??'desc')==='asc'?'asc':'desc','page'=>max(1,(int)($_GET['page']??1))];
                }
                if($op==='publish'&&isset($_POST['document_json'])){
                    try{$document=json_decode(audienceText($_POST,'document_json',3000000),true,32,JSON_THROW_ON_ERROR);}
                    catch(JsonException){throw new InvalidArgumentException('The form design is invalid. Reload and try again.');}
                    if(!is_array($document))throw new InvalidArgumentException('Invalid form design.');
                    $id=$store->transaction(function()use($store,$id,$version,$document):int{
                        $store->saveCollection(['name'=>audienceText($_POST,'name',100),'document'=>$document],$id,$version);
                        return $store->collectionOperation($id,$version+1,'publish');
                    });
                }else $id=$store->collectionOperation($id,$version,$op);
                if($op==='publish'&&$view==='form'){
                    $published=$store->collectionResource($id);
                    $operationRedirect=['view'=>match($published['kind']){'survey'=>'surveys','preferences'=>'preferences',default=>'forms'}];
                }
                $message=match($op){'publish'=>'Published. The public page now uses this revision.','pause'=>'Paused. Public collection has stopped.','archive','remove'=>'Form archived. Public collection has stopped and existing results are kept.','restore'=>'Restored as a draft. Publish when it’s ready.','delete'=>'Form and its results permanently deleted. Connections and consent history are kept.',default=>'Draft copied.'};
                if(in_array($op,['archive','remove','delete'],true))$id=0;
            } else throw new InvalidArgumentException('Choose a supported form action.');
            if (str_contains($_SERVER['HTTP_ACCEPT']??'','application/json') && in_array($action,['collection_save','collection_operation'],true)) {
                $saved=$id?$store->collectionResource($id):['version'=>0,'state'=>'removed'];
                releaseApplicationSessionLock();header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');
                echo json_encode(['id'=>$id,'version'=>(int)$saved['version'],'state'=>$saved['state']]+($operationRedirect?['redirect'=>$url($operationRedirect)]:[]),JSON_THROW_ON_ERROR);return;
            }
            $_SESSION['audience_message']=$message; header('Location: '.$url($operationRedirect??($id?['view'=>'form','id'=>$id]:['view'=>'forms']))); return;
        } catch (InvalidArgumentException $e) {
            if($e instanceof AudienceCollectionOriginException)$collectionEditorError=['field'=>'origins','message'=>$e->getMessage()];
            if (in_array($action,['collection_preview','collection_image','collection_library','collection_library_image','collection_new_tag'],true) || str_contains($_SERVER['HTTP_ACCEPT']??'','application/json')) { http_response_code(422); header('Content-Type: application/json; charset=UTF-8'); header('Cache-Control: no-store'); echo json_encode(['error'=>$e->getMessage()]+($collectionEditorError?['field'=>$collectionEditorError['field']]:[]),JSON_THROW_ON_ERROR); return; }
            $error=$e->getMessage(); if ($action==='collection_save'||($action==='collection_operation'&&audienceText($_POST,'operation')==='publish'&&isset($_POST['document_json']))) $recover=$_POST;
            if($action==='collection_operation'&&in_array($view,['forms','form-settings','landing-pages','surveys','preferences'],true))$id=0;
        }
    }
    if ($id && $available) {
        try { $resource=$store->collectionResource($id,false,$view==='collection-report'); }
        catch (InvalidArgumentException $e) { http_response_code(404); exit($h($e->getMessage())); }
    }
    if (in_array($view,['form','collection-report'],true) && !$resource) { http_response_code(404); exit('This form is unavailable.'); }
    $savedCollectionDraft=$resource['draft']??null;
    if ($resource && $recover && isset($recover['document_json'])) {
        try { $draft=json_decode(audienceText($recover,'document_json',3000000),true,32,JSON_THROW_ON_ERROR); if (is_array($draft)) $resource['draft']=audienceCollectionDocument($draft,$resource['kind'],$store->fields(),$store->groups(),$store->tags()); } catch (Throwable) {}
        $resource['name']=audienceText($recover,'name',100); $resource['version']=(int)($recover['version']??0);
    }
    $tags=$store->tags(); $groups=$store->groups(); $fields=$store->fields(); $catalog=$store->catalog();
    $filters=audienceListFilters([]); $rules=['match'=>'all','rules'=>[]]; $segment=null; $job=null; $editorRecovery=null;
    $q=audienceText($_GET,'q',100); $type=audienceText($_GET,'type',20);$trash=($_GET['trash']??'')==='1';
    $creationKind=\Dnr\Http\RequestInput::enum($_GET,'kind',AUDIENCE_COLLECTION_KINDS,'hosted');
    $kinds=match($view){'surveys'=>['survey'],'landing-pages'=>['landing'],'preferences'=>['preferences'],'form-settings'=>['hosted','preferences'],default=>['hosted','embedded','popup','landing']};
    $resources=[];$blockedSignupIds=[];$collectionPerPage=audiencePageSize($_GET,'per_page',100);$collectionPage=1;$collectionPages=1;$collectionTotal=0;$collectionDirection=($_GET['direction']??'desc')==='asc'?'asc':'desc';
    $listQuery=['view'=>$view,'q'=>$q,'type'=>$type,'trash'=>$trash?'1':'0','per_page'=>$collectionPerPage,'direction'=>$collectionDirection];
    if ($available) {
        $sql='FROM marketing_collection_resources WHERE audience_id=? AND deleted_at IS '.($trash?'NOT NULL':'NULL').' AND kind IN ('.implode(',',array_fill(0,count($kinds),'?')).')'; $params=[$audienceId,...$kinds];
        if ($q!=='') { $sql.=' AND LOCATE(?,name)>0'; $params[]=$q; }
        if (in_array($type,$kinds,true)) { $sql.=' AND kind=?'; $params[]=$type; }
        $collectionTotal=(int)$store->one('SELECT COUNT(*) n '.$sql,$params)['n'];$collectionPages=max(1,(int)ceil($collectionTotal/$collectionPerPage));$collectionPage=max(1,min($collectionPages,(int)($_GET['page']??1)));
        // The list needs metadata only. Keep embedded images and fonts out of MySQL's sort buffer.
        $resources=$store->all('SELECT id,kind,name,state,version,deleted_at,updated_at '.$sql.' ORDER BY updated_at '.$collectionDirection.',id '.$collectionDirection.' LIMIT ? OFFSET ?',[...$params,$collectionPerPage,($collectionPage-1)*$collectionPerPage]);
    }
    if($resources){
        $publishedIds=array_column(array_filter($resources,static fn($r)=>!$r['deleted_at']&&$r['state']==='published'&&in_array($r['kind'],['hosted','embedded','popup','landing'],true)),'id');
        if($publishedIds){
            $ses=$store->sesSettings();
            if(audienceSesReasons($ses,$ses['from_email']??'')){
                $published=$store->all('SELECT r.id,v.document_json FROM marketing_collection_resources r JOIN marketing_collection_revisions v ON v.id=r.published_revision AND v.resource_id=r.id AND v.audience_id=r.audience_id WHERE r.audience_id=? AND r.id IN ('.implode(',',array_fill(0,count($publishedIds),'?')).')',[$audienceId,...$publishedIds]);
                foreach($published as$r)if((audienceJson($r['document_json'])['opt_in']??'')==='double')$blockedSignupIds[(int)$r['id']]=true;
            }
        }
    }
    $report=[];$reportDocuments=[];$revisionTotals=[];$questionTotals=[];$responses=[];$totalResponses=0;$visits=0;$reportStart='';$reportEnd='';$questionFilter='';$answerFilter='';$revisionFilter=0;
    if($resource&&$view==='collection-report'){
        foreach(['start','end']as$key){
            $v=audienceText($_GET,$key,10);
            if($v!==''&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||!checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)))){http_response_code(400);exit('Choose valid report dates.');}
            if($key==='start')$reportStart=$v;else$reportEnd=$v;
        }
        if($reportStart!==''&&$reportEnd!==''&&$reportStart>$reportEnd){http_response_code(400);exit('The start date must precede the end date.');}
        foreach($store->all('SELECT id,document_json,created_at FROM marketing_collection_revisions WHERE audience_id=? AND resource_id=? ORDER BY id',[$audienceId,$id])as$revision)$reportDocuments[(int)$revision['id']]=['document'=>audienceJson($revision['document_json']),'at'=>$revision['created_at']];
        $questionFilter=audienceText($_GET,'question',40);$answerFilter=audienceText($_GET,'answer',5000);$revisionFilter=\Dnr\Http\RequestInput::positiveInt($_GET,'revision')??0;
        if($questionFilter!==''&&(!$revisionFilter||!isset($reportDocuments[$revisionFilter])||!in_array($questionFilter,array_column($reportDocuments[$revisionFilter]['document']['questions'],'id'),true))){http_response_code(400);exit('Choose a published survey question.');}
        if($answerFilter!==''&&$questionFilter===''){http_response_code(400);exit('Choose a question for the response filter.');}
        $reportFilters=['start'=>$reportStart,'end'=>$reportEnd,'question'=>$questionFilter,'answer'=>$answerFilter,'revision'=>$revisionFilter];
        $responseSize=audiencePageSize($_GET);
        $reportQuery=['view'=>'collection-report','id'=>$id,'per_page'=>$responseSize,...array_filter($reportFilters,static fn($v)=>$v!==''&&$v!==0)];
        if(($_GET['export']??'')==='csv'){
            if(!$canManage){http_response_code(403);exit('An editor or administrator must export responses.');}
            releaseApplicationSessionLock();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="form-responses.csv"');header('Cache-Control: no-store');
            $out=fopen('php://output','w');fputcsv($out,['Response','Submitted UTC','Variation','Verified connection','Answers'],',','"','');
            foreach($store->collectionResponses($id,$reportFilters)as$row)fputcsv($out,array_map('audienceCsvCell',[(string)$row['id'],$row['created_at'],$row['variant_key'],(string)($row['contact_id']??''),json_encode($row['answers'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]),',','"','');
            fclose($out);return;
        }
        $responsePage=max(1,min(100000,(int)($_GET['page']??1)));
        foreach($store->collectionResponses($id,$reportFilters)as$row){
            ++$totalResponses;if($totalResponses>($responsePage-1)*$responseSize&&$totalResponses<=$responsePage*$responseSize)$responses[]=$row;
            $rev=(int)$row['revision_id'];$revisionTotals[$rev]=($revisionTotals[$rev]??0)+1;
            foreach($row['answers']as$key=>$value){if($value!==''&&$value!==null&&$value!==[])$questionTotals[$rev][$key]=($questionTotals[$rev][$key]??0)+1;foreach((array)$value as$answer)if(is_scalar($answer)){$answer=mb_substr((string)$answer,0,5000);$report[$rev][$key][$answer]=($report[$rev][$key][$answer]??0)+1;}}
        }
        if($responsePage>max(1,(int)ceil($totalResponses/$responseSize))){header('Location: '.$url($reportQuery+['page'=>max(1,(int)ceil($totalResponses/$responseSize))]));return;}
        $visitWhere='resource_id=?';$vp=[$id];
        if($reportStart!==''){$visitWhere.=' AND day>=?';$vp[]=$reportStart;}
        if($reportEnd!==''){$visitWhere.=' AND day<=?';$vp[]=$reportEnd;}
        $visits=(int)$store->one("SELECT COUNT(*) n FROM marketing_collection_visits WHERE $visitWhere",$vp)['n'];
        $conversions=$store->collectionConversions($id,$reportStart,$reportEnd,$revisionFilter);
        if(isset($_GET['chart'])){
            if(!$canManage){http_response_code(403);exit('An editor or administrator must export charts.');}
            $chart=audienceText($_GET,'chart',80);
            if(!preg_match('/^([1-9][0-9]*):([A-Za-z][A-Za-z0-9_-]{0,39})$/D',$chart,$m)||!isset($reportDocuments[(int)$m[1]])||!in_array($m[2],array_column($reportDocuments[(int)$m[1]]['document']['questions'],'id'),true)){http_response_code(404);exit('This chart is unavailable.');}
            $answers=$report[(int)$m[1]][$m[2]]??[];$height=80+count($answers)*48;$max=max(1,...array_values($answers));
            releaseApplicationSessionLock();header('Content-Type: image/svg+xml; charset=UTF-8');header('Content-Disposition: attachment; filename="survey-chart.svg"');header('Cache-Control: no-store');header("Content-Security-Policy: default-src 'none'; sandbox");
            echo '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="'.$height.'" viewBox="0 0 900 '.$height.'"><rect width="900" height="'.$height.'" fill="white"/><text x="24" y="30" font-family="Arial" font-size="18">'.$h($resource['name']).'</text>';
            $y=65;foreach($answers as$answer=>$n){echo '<text x="24" y="'.$y.'" font-family="Arial" font-size="14">'.$h(mb_substr((string)$answer,0,35)).'</text><rect x="310" y="'.($y-16).'" width="'.round(500*$n/$max).'" height="24" fill="#007c89"/><text x="830" y="'.$y.'" font-family="Arial" font-size="14">'.(int)$n.'</text>';$y+=48;}echo '</svg>';return;
        }
    }
    try { $publicBase=accountPrimaryPublicUrl(); } catch (InvalidArgumentException) { $publicBase=''; }
    $publicUrl=$resource?$publicBase.'/audience_collect_public.php?key='.$resource['public_key']:'';
    $publishedResource=$resource&&$resource['state']==='published'?$store->collectionPublished($resource['public_key']):null;
    $collectionPublishPending=$resource&&(!$publishedResource||$savedCollectionDraft!=$publishedResource['document']);
    $signupEmailBlocked=false;
    if($publishedResource&&in_array($publishedResource['kind'],['hosted','embedded','popup','landing'],true)&&$publishedResource['document']['opt_in']==='double'){
        $ses=$store->sesSettings();$signupEmailBlocked=(bool)audienceSesReasons($ses,$ses['from_email']??'');
    }
    $pageTitle=match($view){'form'=>$resource['name'],'collection-create'=>'Setup your '.match($creationKind){'landing'=>'landing page','survey'=>'survey','popup'=>'popup form','embedded'=>'embedded form','preferences'=>'preference center',default=>'subscribe form'},'form-settings'=>'Forms Settings','landing-pages'=>'Landing pages','surveys'=>'Surveys','preferences'=>'Subscriber preferences','collection-report'=>$resource['name'].' · Results',default=>'Forms'};
    $collectionBrand=$resource?($store->contentSettings()['settings']['brand']??[]):[];
    $csrf=csrfInput(); $themeKey=currentAccountKey().':'.$actor.':'.$audienceId;
    releaseApplicationSessionLock();
    ?>
    <!doctype html><html lang="en">
    <?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-collection.min.js']]); ?>
    <?php include __DIR__.'/templates/audience/workspace.php'; ?>
    </html>
    <?php
}
