<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
require __DIR__.'/../../src/audience_flow_presentation.php';
function audienceInputs(array $values): void { foreach($values as$key=>$value)echo '<input type="hidden" name="'.htmlspecialchars($key,ENT_QUOTES,'UTF-8').'" value="'.htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').'">'; }
$kind=$argv[1]??'catalog';$dark=($argv[2]??'')==='dark';$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$url=static fn(array $p=[])=>'audience.php?'.http_build_query($p);$q='';$canDelete=true;
ob_start();renderPageHead('Automation qualification',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-flows.min.css']]);$head=preg_replace('~<script\b[^>]*>.*?</script>~s','',ob_get_clean());
echo '<!doctype html><html'.($dark?' class="dark-mode"':'').'>'.$head.'<body class="audience-app audience-flow-workspace" data-audience-key="synthetic-user-account"><main class="audience-content"><div class="audience-page-body">';
if($kind==='catalog')require __DIR__.'/../../src/templates/audience/flow-catalog.php';
elseif($kind==='builder'){
    $view='flow';$available=true;$id=1;$csrf='';$canManage=false;$themeKey='synthetic';$tags=[];$groups=[];$fields=[];$campaignOptions=[];$surveyOptions=[];$flowOptions=[];$validation=[];$report=['nodes'=>[]];
    $catalog=['email'=>['kind'=>'text','label'=>'Email'],'email_status'=>['kind'=>'choice','label'=>'Email status','options'=>array_combine(AUDIENCE_STATUSES,AUDIENCE_STATUSES)]];
    $flow=['id'=>1,'version'=>1,'name'=>'Cleanup fixture','state'=>'draft','document'=>audienceFlowDocument(audienceFlowDefault('cleanup')),'paused_nodes'=>[]];
    require __DIR__.'/../../src/templates/audience/flows.php';
}
elseif(str_starts_with($kind,'overview-')){
    $view='automations';$available=true;$id=0;$csrf='';$canManage=true;$themeKey='synthetic';$classic=$kind==='overview-classic';$trash=$kind==='overview-trash';$flowSort='created';$state='';$q=$kind==='overview-filtered'?'missing':'';$flowPage=1;$flow=null;$campaignOptions=[];
    $empty=in_array($kind,['overview-empty','overview-filtered'],true);$nativeFlowTotal=$kind==='overview-empty'?0:2;$flowTotal=$empty?0:2;
    $flows=$empty?[]:[['id'=>1,'version'=>7,'state'=>'draft','name'=>'Welcome & community','created_at'=>'2026-10-01 10:00:00','updated_at'=>'2026-10-19 09:00:00','published_revision'=>null,'started'=>0,'active'=>0,'completed'=>0],['id'=>2,'version'=>3,'state'=>'paused','name'=>'Community follow-up','created_at'=>'2026-09-01 10:00:00','updated_at'=>'2026-10-18 09:00:00','published_revision'=>2,'started'=>24,'active'=>3,'completed'=>14]];
    $flowSetupTasks=$classic||$trash?[]:audienceFlowSetupTasks(['welcome'=>false,'delivery'=>false,'form'=>false,'organize'=>true],true);$audience=['name'=>'Example community'];
    require __DIR__.'/../../src/templates/audience/flows.php';
}else{$flowPreview=$kind;require __DIR__.'/../../src/templates/audience/flow-template-preview.php';}
echo '</div></main><script src="/assets/js/audience.min.js"></script><script src="/assets/js/audience-flows.min.js"></script></body></html>';
