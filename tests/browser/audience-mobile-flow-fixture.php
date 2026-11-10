<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
require __DIR__.'/../../src/audience_flow_presentation.php';
$_SESSION=['user_id'=>11,'username'=>'fixture-admin','role'=>'admin','_csrf_token'=>'fixture-token'];
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$fixtureKind=$argv[1]??'';
$view=in_array($fixtureKind,['editor','targets-active','targets-edit'],true)?'flow':'automations';$themeKey='mobile-flow-fixture';$displayName='Example User';$pageTitle=$view==='flow'?'Welcome flow':'Automations';
$canManage=$canDelete=$available=true;$canSend=false;$message=$error='';$csrf=csrfInput();$audienceId=1;$id=1;
$audience=['name'=>'Example audience','settings_json'=>'{}','version'=>1];
$tags=$groups=$fields=$catalog=$query=$recentDesigns=$campaignOptions=$surveyOptions=$flowOptions=$validation=[];
$rules=['match'=>'all','rules'=>[]];$filters=['q'=>'','status'=>[],'source'=>[],'tags'=>[],'archived'=>'0'];$segment=$job=null;
$classic=$trash=false;$q=$state='';$flowSort='created';$flowSize=25;$flowPage=1;$nativeFlowTotal=$flowTotal=3;$flowSetupTasks=[];$flowPreview='';
$flow=$view==='flow'?['id'=>1,'version'=>1,'name'=>'Welcome flow','state'=>'draft','document'=>audienceFlowDefault('blank'),'paused_nodes'=>[],'deleted_at'=>null]:null;
if(in_array($fixtureKind,['targets-active','targets-edit'],true)){
    $tags=[['id'=>7,'name'=>'Interest in Israel Service Trip'],['id'=>8,'name'=>'Volunteer & Prayer Support']];
    $groups=[['id'=>3,'name'=>'Self-Subscribed Contact Preferences','options'=>[['id'=>31,'name'=>'Israel Service Trip'],['id'=>32,'name'=>'Prayer Updates']]]];
    $document=audienceFlowDefault('blank');
    $document['trigger']=['type'=>'group','id'=>31];
    $document['additional_triggers']=[['type'=>'tag','id'=>8],['type'=>'ungroup','id'=>32]];
    $document['start']='tagStep';
    $document['nodes']=[
        ['id'=>'tagStep','type'=>'tag','tag_id'=>7,'next'=>'groupStep'],
        ['id'=>'groupStep','type'=>'group','option_id'=>32,'remove'=>true,'next'=>'waitStep'],
        ['id'=>'waitStep','type'=>'wait','trigger'=>['type'=>'tag','id'=>8],'timeout'=>86400,'yes'=>'exitStep','no'=>'exitStep'],
        ['id'=>'exitStep','type'=>'exit'],
    ];
    $flow['document']=audienceFlowDocument($document);
    $flow['state']=$fixtureKind==='targets-active'?'running':'paused';
}
$report=['nodes'=>[]];
$flows=[];foreach(['draft','running','paused']as$i=>$status)$flows[]=['id'=>$i+1,'name'=>['Welcome new connections','Community follow-up','Monthly announcement'][$i],'state'=>$status,'created_at'=>'2026-10-21 09:00:00','updated_at'=>'2026-10-25 09:00:00','published_revision'=>$i,'started'=>$i*3,'active'=>$i,'completed'=>$i*2,'version'=>1];
if (in_array($fixtureKind, ['outcomes','report-outcomes'], true)) {
    $flows[1] = array_replace($flows[1], ['name'=>'Tag Interest in Israel Service Trip on self-select preference',
        'started'=>5,'completed'=>5,'exited'=>0,'active'=>0,'failed'=>0]);
    $flows[2] = array_replace($flows[2], ['name'=>'Connection follow-up with mixed outcomes',
        'started'=>20,'completed'=>12,'exited'=>3,'active'=>4,'failed'=>1]);
}
if ($fixtureKind === 'report-outcomes') {
    $view = 'flow-report';
    $flow = ['id'=>1,'version'=>1,'name'=>'Outcome report','state'=>'paused',
        'document'=>audienceFlowDocument(audienceFlowDefault('blank')),'paused_nodes'=>[],'deleted_at'=>null];
    $report = ['totals'=>[],'nodes'=>[],'runs'=>[],'total'=>4,'page'=>1,'per_page'=>25];
    foreach (AUDIENCE_FLOW_RUN_LABELS as $outcome=>$label) {
        $report['totals'][] = ['state'=>$outcome,'n'=>1];
        $report['runs'][] = ['id'=>count($report['runs'])+1,'revision_id'=>1,'contact_id'=>1,
            'first_name'=>'Example','last_name'=>$label,'email'=>'example@example.test','deleted_at'=>null,
            'state'=>$outcome==='completed'?'exited':$outcome,
            'error_message'=>match($outcome){'completed'=>'Exit step','exited'=>'Removed manually','failed'=>'Action failed',default=>''},
            'started_at'=>'2026-10-25 09:00:00','steps'=>[]];
    }
}
echo '<!doctype html><html lang="en">';
renderPageHead('Mobile automation fixture',['styles'=>['assets/css/pages/audience.min.css','assets/css/pages/audience-flows.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-flows.min.js']]);
require __DIR__.'/../../src/templates/audience/workspace.php';echo '</html>';
