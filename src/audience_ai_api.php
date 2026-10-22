<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/audience_store.php';
require_once __DIR__.'/audience_ai.php';
startSecureSession();requireLogin();
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
function audienceAiRespond(array $value,int $status=200):never{http_response_code($status);echo json_encode($value,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);exit;}
if(!aiCoachEnabled())audienceAiRespond(['error'=>'AI assistance is not enabled for this deployment.'],503);
$actor=(int)$_SESSION['user_id'];$role=(string)$_SESSION['role'];
if(!audienceAiCanAccess($role,'analytics'))audienceAiRespond(['error'=>'AI assistance requires a signed-in user.'],403);
$db=applicationDatabaseConnection();$store=new AudienceStore($db,1,$actor,mb_substr((string)($_SESSION['profile_display_name']??$_SESSION['username']??'Account'),0,100));
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        if(($_GET['action']??'')==='history'){
            $rows=$db->execute_query("SELECT client_request_id,question,manual_topic,outcome,created_at FROM ai_coach_requests WHERE user_id=? AND user_role=? AND guided_step='audience-ai' AND manual_topic IN ('audience-ai:content','audience-ai:analytics') ORDER BY id DESC LIMIT 20",[$actor,$role])->fetch_all(MYSQLI_ASSOC);
            $rows=array_values(array_filter($rows,static fn($r)=>audienceAiCanAccess($role,$r['manual_topic']==='audience-ai:analytics'?'analytics':'content')));
            audienceAiRespond(['items'=>$rows,'can_remove_history'=>canDeleteEntries($role)]);
        }
        $uuid=audienceText($_GET,'request_id',36);$owned=audienceAiOwnedRequest($db,$actor,$role,$uuid);
        if(!$owned||!audienceAiCanAccess($role,$owned['manual_topic']==='audience-ai:analytics'?'analytics':'content'))audienceAiRespond(['error'=>'Suggestion not found.'],404);
        releaseApplicationSessionLock();$status=aiCoachRequestStatus($db,$actor,$role,$uuid)??['state'=>'interrupted'];
        if(($_GET['action']??'')==='contacts'){
            if($owned['manual_topic']!=='audience-ai:analytics'||($status['response']['purpose']??'')!=='audience-ai')throw new DomainException('Contact results not found.');
            $evidence=$status['response']['evidence']??[];$tableId=audienceText($_GET,'table_id',80);$offset=audienceText($_GET,'offset',7);
            if(!ctype_digit($offset))throw new InvalidArgumentException('Choose a valid results page.');
            $queries=new AudienceAiQueries($store,audienceAiReportWindow($evidence['window']??[]));
            audienceAiRespond(['table'=>audienceAiContactPage($queries,$evidence,$tableId,(int)$offset)]);
        }
        audienceAiRespond($status+['question'=>$owned['question']]);
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');audienceAiRespond(['error'=>'Use POST to ask for a suggestion.'],405);}
    if(!validateCsrfToken($_SERVER['HTTP_X_CSRF_TOKEN']??null))audienceAiRespond(['error'=>'Reload the page before continuing.'],403);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>24000)audienceAiRespond(['error'=>'This request is too large.'],413);
    $raw=file_get_contents('php://input',false,null,0,24001);
    if($raw===false||strlen($raw)>24000)audienceAiRespond(['error'=>'This request is too large.'],413);
    $input=json_decode($raw,true,24,JSON_THROW_ON_ERROR);if(!is_array($input))throw new InvalidArgumentException('Invalid request.');
    $action=$input['action']??'generate';
    if($action==='checks'){
        if(!audienceAiCanAccess($role,'content'))throw new DomainException('Writing assistance requires an editor or administrator.');
        audienceAiRespond(audienceAiChecks(audienceDesignString($input['text']??'',10000),audienceDesignString($input['subject']??'',250)));
    }
    if($action==='cancel'){
        $uuid=audienceText($input,'request_id',36);$owned=audienceAiOwnedRequest($db,$actor,$role,$uuid);
        if(!$owned||!audienceAiCanAccess($role,$owned['manual_topic']==='audience-ai:analytics'?'analytics':'content'))audienceAiRespond(['error'=>'Suggestion not found.'],404);
        releaseApplicationSessionLock();audienceAiRespond(['saved'=>aiCoachCancelRequest($db,$actor,$role,$uuid)]);
    }
    if($action==='save_template'){
        if(!audienceAiCanAccess($role,'content'))throw new DomainException('Saving templates requires an editor or administrator.');
        $uuid=audienceText($input,'request_id',36);$owned=audienceAiOwnedRequest($db,$actor,$role,$uuid);
        if(!$owned||$owned['manual_topic']!=='audience-ai:content')throw new DomainException('Writing suggestion not found.');
        $status=aiCoachRequestStatus($db,$actor,$role,$uuid);
        if(($status['response']['purpose']??'')!=='audience-ai'||($status['response']['reason']??'unavailable')!=='')throw new InvalidArgumentException('Generate and review a completed suggestion first.');
        $text=audienceDesignString($input['text']??'',10000);$headline=audienceDesignString($input['headline']??'',180);$name=audienceDesignString($input['name']??'',100);
        if(trim($text)===''||trim($name)==='')throw new InvalidArgumentException('Add a template name and review the email text.');
        $rowId='ai-'.substr(hash('sha256',$actor.':'.$uuid),0,28);$lock='audience-ai-save-'.substr(hash('sha256',currentAccountKey().':'.$actor.':'.$uuid),0,32);
        if(!(int)$db->execute_query('SELECT GET_LOCK(?,1)',[$lock])->fetch_row()[0])audienceAiRespond(['error'=>'This suggestion is already being saved.'],409);
        try{
            $found=$store->one("SELECT id FROM marketing_email_templates WHERE audience_id=1 AND deleted_at IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(design_json,'$.rows[0].id'))=?",[$rowId]);
            if($found)$templateId=(int)$found['id'];
            else{
                $blocks=[];if($headline!=='')$blocks[]=['id'=>$rowId.'-h','type'=>'heading','text'=>$headline];
                $blocks[]=['id'=>$rowId.'-t','type'=>'text','text'=>$text];
                $design=audienceDesign(['schema'=>1,'rows'=>[['id'=>$rowId,'name'=>'Reviewed AI content','columns'=>[$blocks]]]]);
                $settings=$store->contentSettings()['settings'];$design=$store->brandedDesign($design,$settings['brand']??[]);
                $imageId=(int)($input['image_id']??0);
                if($imageId){$item=$store->designLibraryItem($imageId);if($item['item_type']!=='image')throw new InvalidArgumentException('Choose an image from My files.');$image=$item['data']['image']??'';array_unshift($design['rows'][0]['columns'][0],['id'=>$rowId.'-image','type'=>'image','image'=>$image,'alt'=>$item['data']['alt']??$item['name'],'padding'=>0]);}
                $templateId=$store->createEmailTemplate($name,$design);
            }
        }finally{$db->execute_query('SELECT RELEASE_LOCK(?)',[$lock]);}
        audienceAiRespond(['redirect'=>'audience.php?view=designer&kind=template&id='.$templateId]);
    }
    if($action!=='generate')throw new InvalidArgumentException('Choose a supported assistant action.');
    $request=audienceAiRequest($input,$role);$request['account']=currentAccountKey();
    if($request['mode']==='analytics'){
        $window=audienceAiReportWindow(is_array($input['window']??null)?$input['window']:[]);
        $request['analysis_scope']=array_intersect_key($window,array_flip(['start','end','compare','bots','channel','messages_mode','messages','segment','dataset','source']));
    }else{
        $settings=$store->contentSettings()['settings'];
        $request['brand']=['name'=>mb_substr((string)($store->one('SELECT name FROM marketing_audiences WHERE id=1')['name']??''),0,100),'styles'=>array_intersect_key($settings['brand']['styles']??[],array_flip(['font','fontSize','text','button','body']))];
    }
    $parent=audienceText($input,'parent',36);
    if($parent!==''){
        $owned=audienceAiOwnedRequest($db,$actor,$role,$parent);
        if(!$owned||$owned['manual_topic']!=='audience-ai:'.$request['mode'])throw new InvalidArgumentException('The previous suggestion is unavailable in this context.');
        $previous=aiCoachRequestStatus($db,$actor,$role,$parent)['response']??[];
        $request['previous_reply']=array_intersect_key($previous,array_flip(['summary','text','recommendations','observations']));
        if($request['mode']==='analytics')$request['previous_context']=audienceAiPreviousContext($owned['question'],$previous);
    }
    $envelope=['role'=>$role,'page'=>'audience.php','step'=>'audience-ai','question'=>$request['prompt']?:ucfirst($request['operation']).' selected text','history'=>[],'topic'=>'audience-ai:'.$request['mode'],'request_id'=>$request['request_id'],'ui'=>['purpose'=>'audience-ai','mode'=>$request['mode'],'operation'=>$request['operation']]];
    $created=false;$historyId=aiCoachRecordRequest($db,$envelope,$actor,$created);
    if($historyId===null)audienceAiRespond(['error'=>'The request could not be saved. Try again.'],503);
    if(!$created){
        $duplicate=audienceAiOwnedRequest($db,$actor,$role,$request['request_id']);if(!$duplicate||$duplicate['manual_topic']!=='audience-ai:'.$request['mode'])throw new DomainException('Request identifier is already in use.');
        releaseApplicationSessionLock();audienceAiRespond(aiCoachRequestStatus($db,$actor,$role,$request['request_id'])??['state'=>'interrupted']);
    }
    if(microtime(true)-(float)($_SESSION['_audience_ai_last_request']??0)<2){aiCoachCompleteRequest($db,$historyId,['purpose'=>'audience-ai','reason'=>'busy','message'=>'Please wait a moment before asking again.'],0,'throttled');audienceAiRespond(['error'=>'Please wait a moment before asking again.'],429);}
    $_SESSION['_audience_ai_last_request']=microtime(true);releaseApplicationSessionLock();
    if(!aiCoachEnqueue($db,$historyId,$request)){aiCoachCompleteRequest($db,$historyId,['purpose'=>'audience-ai','reason'=>'busy','message'=>'The AI service is busy. Your content is unchanged.'],0,'busy');audienceAiRespond(['error'=>'The AI service is busy. Try again shortly.'],429);}
    audienceAiRespond(['state'=>'pending','request_id'=>$request['request_id']],202);
}catch(DomainException $error){audienceAiRespond(['error'=>$error->getMessage()],403);}
catch(InvalidArgumentException|JsonException $error){audienceAiRespond(['error'=>'Check the request and try again. '.($error instanceof InvalidArgumentException?$error->getMessage():'')],400);}
catch(Throwable $error){applicationLog('error','MessageMonkey AI request failed',['error_class'=>get_class($error)]);audienceAiRespond(['error'=>'The assistant is unavailable. Your content is unchanged.'],503);}
