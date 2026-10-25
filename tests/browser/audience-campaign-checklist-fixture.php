<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
require __DIR__.'/../../src/audience_store.php';
function audienceNavIcon(string $name): void { echo '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5"/></svg>'; }
function audienceInputs(array $values): void { foreach($values as$key=>$value)echo '<input type="hidden" name="'.htmlspecialchars($key,ENT_QUOTES,'UTF-8').'" value="'.htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').'">'; }
$_SESSION=['user_id'=>11,'username'=>'qa-editor','role'=>'editor','_csrf_token'=>'fixture-token'];
$csrf=csrfInput();
$h=static fn($value)=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$url=static fn(array $params=[])=>'audience.php?'.http_build_query($params);
$campaignForm=static function(string $action,array $extra=[])use($h):void {
    echo csrfInput();
    foreach(['action'=>$action]+$extra as$key=>$value)echo '<input type="hidden" name="'.$h($key).'" value="'.$h($value).'">';
};
$id=13;$canManage=true;$canDelete=true;$canSend=false;$campaignRecovery=[];$error='';
$recipientCount=null;$targetError='';$senderReadiness=[];$publication=[];
$campaign=['id'=>$id,'version'=>1,'name'=>'Campaign checklist fixture','status'=>'draft','campaign_type'=>'regular','draft'=>['include'=>['segment:1'],'exclude'=>['tag:2'],'from_name'=>'Fixture sender','from_email'=>'sender@example.test']];
$tags=[['id'=>1,'name'=>'Members & friends'],['id'=>2,'name'=>'Staff <internal>']];
for($i=3;$i<=12;$i++)$tags[]=['id'=>$i,'name'=>'Fixture tag '.$i];
$segments=[['id'=>1,'name'=>'testSegment'],['id'=>2,'name'=>str_repeat('A long segment name ',15)]];
$campaignMergeTags=['FNAME'=>'First Name','LNAME'=>'Last Name','EMAIL'=>'Email Address','FULLNAME'=>'Full Name','ENTRYDATE'=>'Date of Entry','FUND'=>'Fund'];
$fixtureMode=$argv[1]??'';
if(in_array($fixtureMode,['sent','sent-notice'],true)){$campaign['status']='sent';$campaign['draft']['design']=audienceDesignStarters()['blank']['design'];$previewIdentity=[];}
if($fixtureMode==='plain-notice'){$campaign['campaign_type']='plain-text';$campaign['draft']['text']='Plain-text fixture';}
if(in_array($fixtureMode,['sent-notice','plain-notice'],true))$reopenDesignerUnavailable=true;
if($fixtureMode==='enabled'){$campaign['draft']['resend']=['enabled'=>true];$publication=['enabled'=>true,'archive_listed'=>true,'public_key'=>'fixture-key'];}
if($fixtureMode==='archive-only')$publication=['enabled'=>false,'archive_listed'=>true];
if(str_starts_with($fixtureMode,'send-')){
    $view='campaign-send';$dispatchReview=null;$dispatchError='';$audience=['name'=>'Fixture audience'];$campaign['deleted_at']=null;$previewIdentity=[];
    if($fixtureMode==='send-html')$campaign['draft']['design']=audienceDesignStarters()['newsletter']['design'];
    else{$campaign['campaign_type']='plain-text';$campaign['draft']['text']="Hello recipient,\n\n".str_repeat('Long preview text ',40)."\n<script>literal text</script>";}
}
echo '<!doctype html><html lang="en">';
renderPageHead('Campaign checklist fixture',['styles'=>['assets/css/pages/audience.min.css'],'scripts'=>['assets/js/audience.min.js','assets/js/audience-designer.min.js']]);
echo '<body class="audience-app"><main style="padding:24px">';
require __DIR__.'/../../src/templates/audience/'.(str_starts_with($fixtureMode,'send-')?'campaign-delivery.php':'campaign-checklist.php');
echo '</main><script id="audience-config" type="application/json">{"url":"audience.php"}</script></body></html>';
