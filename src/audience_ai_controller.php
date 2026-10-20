<?php
declare(strict_types=1);
require_once __DIR__.'/audience_ai.php';

function renderAudienceAi(AudienceStore $store,array $audience,bool $canManage,bool $canDelete,string $displayName,int $actor):void
{
    $view='assistant';$section='';$aiMode=($_GET['mode']??'content')==='analytics'?'analytics':'content';
    if(!$canManage||($aiMode==='analytics'&&!$canDelete)){http_response_code(403);exit('This assistant requires '.($aiMode==='analytics'?'an administrator.':'an editor or administrator.'));}
    if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit('Use the assistant controls to request a suggestion.');}
    $audienceId=1;$id=0;$canExport=$canManage;$pageTitle=$aiMode==='analytics'?'Analytics AI':'Write with AI';$message='';$error='';
    $h=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$url=static fn(array $p=[]):string=>'audience.php'.($p?'?'.http_build_query($p):'');
    $catalog=$store->catalog();$rules=['match'=>'all','rules'=>[]];$filters=audienceListFilters([]);$segment=null;$job=null;$csrf=csrfInput();$themeKey=currentAccountKey().':'.$actor.':1';
    $aiText='';$aiSubject='';$aiSourceName='';$aiImages=[];$aiWindow=[];$aiPrompt='';
    if($aiMode==='analytics'){
        try{$aiWindow=audienceAiReportWindow($_GET);$aiPrompt=audienceText($_GET,'prompt',1500);}catch(InvalidArgumentException $e){http_response_code(400);$error=$e->getMessage();$aiWindow=audienceAiReportWindow([]);}
        $aiWindow=array_intersect_key($aiWindow,array_flip(['start','end','compare','bots','channel','messages_mode','messages','segment','dataset','source']));
    }else{
        $sourceId=\Dnr\Http\RequestInput::positiveInt($_GET,'source_id')??0;
        if($sourceId){
            try{
                $kind=($_GET['source_kind']??'')==='template'?'template':'campaign';$owner=$store->designOwner($kind,$sourceId);$design=$kind==='template'?$owner['design']:($owner['draft']['design']??null);$aiSourceName=$owner['name'];$aiSubject=$owner['draft']['subject']??'';
                if($design){$parts=[];foreach($design['rows']??[]as$row)foreach($row['columns']as$column)foreach($column as$block)if(in_array($block['type'],['text','heading','button'],true))$parts[]=html_entity_decode(strip_tags(($block['html']??'')?:($block['text']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');$aiText=mb_substr(implode("\n\n",$parts),0,10000);}
            }catch(InvalidArgumentException $e){http_response_code(404);$error='The selected email is unavailable.';}
        }
        $aiImages=$store->all("SELECT id,name FROM marketing_design_library WHERE audience_id=1 AND item_type='image' AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 60");
    }
    $aiInline=false;$aiConfig=['mode'=>$aiMode,'endpoint'=>'audience_ai_api.php','window'=>$aiWindow,'enabled'=>aiCoachEnabled(),'key'=>hash('sha256',$themeKey.':'.($_SESSION['role']??'').':'.aiCoachStorageKey())];
    releaseApplicationSessionLock();
    ?><!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle($pageTitle),['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-ai.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-ai.min.js']]);include __DIR__.'/templates/audience/workspace.php';?></html><?php
}
