<?php
declare(strict_types=1);
// CLI-only render of the current workspace with fictional records; no database or cookies.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../src/audience_store.php';
function contentSecurityPolicyNonce():string{return 'previewNonce123456';}
function generateCsrfToken():string{return 'preview-only';}
function accountPrimaryPublicUrl():string{return 'https://community.example';}
$h=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
$campaignJson=static fn($value):string=>$h(json_encode($value,JSON_THROW_ON_ERROR));
$view='contacts';$pageTitle='Connections';$displayName='Preview account';$canManage=$canExport=$canDelete=$canSend=true;
$audienceId=1;$themeKey='fixture:1:1';$audience=['id'=>1,'name'=>'My audience','version'=>1,'settings_json'=>'{}'];
$url=static fn(array $params=[]):string=>'audience.php'.($params?'?'.http_build_query($params):'');
$filters=audienceListFilters([]);$rules=['match'=>'all','rules'=>[]];$query=$filters+['rules'=>json_encode($rules),'sort'=>'created_at','direction'=>'desc','per_page'=>25];
$segments=[['id'=>1,'name'=>'Newsletter readers','version'=>1,'created_at'=>'2026-10-16 10:00:00','updated_at'=>'2026-10-16 10:00:00','filters_json'=>'{"match":"all","rules":[]}']];$segment=null;$groups=[];$fields=[];$sources=[['source'=>'Signup form']];$counts=['total'=>3,'subscribed'=>2];$total=3;$page=1;$pageSize=25;$sort='created_at';$direction='desc';$sortColumns=['email','first_name','last_name','email_status','source','created_at','updated_at'];$message=$error='';$job=null;
$csrf='<input type="hidden" name="csrf_token" value="preview-only">';$tags=[['id'=>1,'name'=>'Newsletter','contact_count'=>3,'version'=>1],['id'=>2,'name'=>'Volunteer','contact_count'=>1,'version'=>1]];
$fields=[
 ['id'=>1,'version'=>1,'label'=>'Full Name','merge_tag'=>'FULLNAME','field_type'=>'text','position'=>1,'required'=>0,'is_public'=>1,'default_value'=>'','options_json'=>'[]'],
 ['id'=>2,'version'=>1,'label'=>'Interests','merge_tag'=>'INTERESTS','field_type'=>'checkboxes','position'=>2,'required'=>0,'is_public'=>1,'default_value'=>'','options_json'=>'["News","Events"]'],
 ['id'=>4,'version'=>1,'label'=>'Address','merge_tag'=>'ADDRESS','field_type'=>'address','position'=>4,'required'=>0,'is_public'=>1,'default_value'=>'','options_json'=>'[]'],
 ['id'=>3,'version'=>1,'label'=>'Birthday','merge_tag'=>'BIRTHDAY','field_type'=>'birthday','position'=>3,'required'=>0,'is_public'=>1,'default_value'=>'','options_json'=>'[]']
];
$groups=[['id'=>1,'name'=>'Email preferences','display_type'=>'checkboxes','version'=>1,'options'=>[['id'=>1,'name'=>'Community news','position'=>0,'contact_count'=>2],['id'=>2,'name'=>'Events','position'=>1,'contact_count'=>1]]]];
$contact=['id'=>0,'version'=>0,'email'=>'','first_name'=>'','last_name'=>'','email_status'=>'non-subscribed','source'=>'Manual entry','custom'=>[],'tag_ids'=>[],'group_ids'=>[],'language'=>'','timezone'=>'','is_archived'=>0,'consent_note'=>''];$id=0;
if(($argv[1]??'')==='new-person'){foreach(['2023 December donors','2024 CLIPBOARD PROJECT','2024 donor','2025 donor','B2ZContactsThru25FEB26','B2ZHostsThru25FEB26','devotions','donor','frequent donor','Israel tour interest','large donor','miniContact','news','note-bounce','prayer letter','prayer team','recent donor','SiMM lunch donor','SMS','Volunteer','wpforms']as$i=>$name)$tags[$i]=['id'=>$i+1,'name'=>$name,'contact_count'=>1,'version'=>1];}
if(($argv[1]??'')==='long-labels'){$tags[0]['name']=str_repeat('LongTagName',10);$groups[0]['name']=str_repeat('Long group category ',5);}
$catalog=audienceRuleCatalog($fields,$tags,$groups);$rows=[];$rowTags=[];$rowGroups=[];
foreach([['Avery','Rivera','subscribed'],['Morgan','Chen','non-subscribed'],['Jordan','Patel','subscribed']]as$index=>[$first,$last,$status]){
 $id=$index+1;$rows[]=['id'=>$id,'email'=>strtolower($first).'@example.test','first_name'=>$first,'last_name'=>$last,'email_status'=>$status,'source'=>'Signup form','created_at'=>'2026-10-16 10:00:00','updated_at'=>'2026-10-16 10:00:00','custom_json'=>'{}'];$rowTags[$id]=[$tags[0]];$rowGroups[$id]=[];
}
if(($argv[1]??'')==='many-tags'){
 $previewTags=[];foreach(['Newsletter','Volunteer','Events','Community','Prayer','News','Devotions','Updates','Welcome']as$index=>$name)$previewTags[]=['id'=>$index+1,'name'=>$name];
 $rowTags[1]=$previewTags;$rowTags[2]=array_slice($previewTags,0,6);$rowTags[3]=array_slice($previewTags,0,7);
}
if(in_array($argv[1]??'', ['profile','new-person'],true)){
    $view='contact';$pageTitle='Connection profile';$id=1;
    $contact=array_replace($rows[0],['version'=>1,'custom'=>[1=>'Avery Rivera',2=>['News']],'tag_ids'=>[1,2],'group_ids'=>[1],'language'=>'en','timezone'=>'America/Chicago','is_archived'=>0,'consent_note'=>'Fictional preview']);
    $tab=in_array($argv[2]??'', ['overview','notes','settings','insights'],true)?$argv[2]:'overview';$events=[['id'=>1,'event_type'=>'contact_added','details_json'=>'{}','actor_label'=>'Preview editor','created_at'=>'2026-10-16 10:00:00']];$notes=[['id'=>1,'version'=>1,'body'=>'Fictional note for keyboard and layout qualification.','actor_label'=>'Preview editor','created_at'=>'2026-10-16 10:00:00','updated_at'=>'2026-10-16 10:00:00']];$eventTotal=1;$eventPage=1;$eventFilter='';$days=30;
    if(($argv[1]??'')==='new-person'){$id=0;$contact['id']=0;$contact['version']=0;$contact['email']='';$contact['first_name']='';$contact['last_name']='';$contact['tag_ids']=[];$contact['email_status']='non-subscribed';$pageTitle='Add a single connection';}
    $marketingDetails=audienceMarketingDetails($contact,['id'=>1,'details_json'=>'{"status":"subscribed"}','created_at'=>'2024-01-23 09:59:00'],null);
}
if(in_array($argv[1]??'', ['tags','segments','groups','fields','settings','import'],true)){
 $view=$argv[1];$pageTitle=ucfirst($view);
 foreach($tags as &$tag)$tag['created_at']='2026-10-16 10:00:00';unset($tag);
 $segments=[['id'=>1,'name'=>'Newsletter readers','version'=>1,'created_at'=>'2026-10-16 10:00:00','updated_at'=>'2026-10-16 10:00:00','filters_json'=>json_encode(['match'=>'all','rules'=>[['field'=>'email_status','operator'=>'is','value'=>'subscribed']]])]];
}
if(($argv[1]??'')==='empty'){$rows=[];$counts=['total'=>0,'subscribed'=>0];$total=0;}
if(($argv[1]??'')==='reviewer'){$canManage=$canExport=$canDelete=false;}
if(($argv[1]??'')==='recovery'){$view='tags';$pageTitle='Tags';$editorRecovery=['dialog'=>'tag-editor','values'=>['id'=>1,'version'=>1,'name'=>'Existing name'],'error'=>'That name already exists in this audience.'];}
if(($argv[1]??'')==='job'){
 $view='job';$id=1;$pageTitle='Preview import';$job=['id'=>1,'name'=>'Preview import.csv','job_type'=>'import','actor_label'=>'Preview editor','created_at'=>'2026-10-16 10:00:00','state'=>($argv[2]??'complete'),'total'=>3,'processed'=>3,'added'=>2,'updated'=>0,'skipped'=>0,'errors'=>1,'error_message'=>''];
 $store=new class { public function all(string $sql,array $params=[]):array{return [['source_row'=>4,'error_message'=>'Enter a valid email address.']];}};
}
if(($argv[1]??'')==='import-step'){
 $view='import';$id=1;$pageTitle='Import Connections';$_GET['step']=$argv[2]??'organize';
 $job=['id'=>1,'state'=>'draft','total'=>3,'name'=>'Preview.csv','params'=>['headers'=>['Email','First Name','Tags'],'mapping'=>['email','first_name','tags'],'status'=>'non-subscribed','tag_ids'=>[1],'group_ids'=>[1]]];
 $store=new class { public function all(string $sql,array $params=[]):array{return [['payload_json'=>'["avery@example.test","Avery","Newsletter"]']];}};
}
if(in_array($argv[1]??'', ['campaigns','campaign','campaign-empty','campaign-calendar'],true)){
 $view=($argv[1]==='campaign')?'campaign':'campaigns';$pageTitle=$view==='campaign'?'Community newsletter':'All campaigns';
 $campaignFilters=audienceCampaignFilters([]);if($argv[1]==='campaign-calendar')$campaignFilters['layout']='calendar';
 $campaignUrl=static fn(array $changes=[]):string=>$url(array_replace(['view'=>'campaigns']+$campaignFilters,$changes));
 $folders=[['id'=>1,'name'=>'Newsletters','version'=>1,'campaign_count'=>2]];$campaignRecovery=null;$campaignPage=1;$campaignTotal=2;$campaignSnapshot=[1=>1,2=>1];
 $campaignRows=[];foreach(['Community newsletter','Volunteer welcome']as$index=>$name)$campaignRows[]=['id'=>$index+1,'name'=>$name,'version'=>1,'campaign_type'=>$index?'plain-text':'regular','status'=>'draft','actor_label'=>'Preview editor','updated_at'=>'2026-10-16 10:00:00','created_at'=>'2026-10-16 10:00:00','draft_json'=>'{}','folder_id'=>1,'folder_name'=>'Newsletters'];
 if($argv[1]==='campaign-empty'){$campaignRows=[];$campaignTotal=0;$campaignSnapshot=[];}
 $month=new DateTimeImmutable('2026-10-01');$calendarStart=$month->modify('monday this week');$calendarEnd=$month->modify('last day of this month')->modify('sunday this week');$calendarRows=[['id'=>1,'name'=>'Community newsletter','status'=>'draft','calendar_date'=>'2026-10-16']];
 $recentCampaigns=$campaignRows;$campaign=$campaignRows[0]??null;if($campaign)$campaign['draft']=['subject'=>'A community update','from_name'=>'MOED','from_email'=>'hello@example.test','recipients_saved'=>true,'target'=>'all'];$recipientCount=2;$targetError='';
}
if(in_array($argv[1]??'', ['campaign-advanced','campaign-review','campaign-manager','campaign-plan','campaign-manager-list','campaign-manager-agenda'],true)){
 $view=match($argv[1]){'campaign-advanced'=>'campaign','campaign-manager-list','campaign-manager-agenda'=>'campaign-manager',default=>$argv[1]};$id=1;$pageTitle=$view==='campaign'?'Community newsletter':($view==='campaign-review'?'Review community newsletter':'Campaign Manager');
 $campaignFilters=audienceCampaignFilters([]);$campaignUrl=static fn(array $changes=[]):string=>$url(array_replace(['view'=>'campaigns']+$campaignFilters,$changes));$folders=[];$recentCampaigns=[];$campaignRecovery=null;
 $month=new DateTimeImmutable('2026-10-01',new DateTimeZone('UTC'));$calendarStart=$month->modify('monday this week');$calendarEnd=$month->modify('last day of this month')->modify('sunday this week');
 $design=audienceDesignStarters()['newsletter']['design'];$campaign=['id'=>1,'version'=>1,'name'=>'Community newsletter','campaign_type'=>'regular','status'=>'draft','draft'=>['subject'=>'A community update','preview_text'=>'News and opportunities for you','from_name'=>'Community team','from_email'=>'hello@example.test','recipients_saved'=>true,'target'=>'all','personalize_to'=>true,'to_merge'=>'FNAME','design'=>$design,'tracking'=>['opens'=>true,'clicks'=>true],'social'=>['enabled'=>true,'title'=>'Community news','description'=>'The latest from our community.','image'=>''],'resend'=>['enabled'=>true,'days'=>3,'time'=>'09:00','segment'=>'non-openers','ignore_bots'=>true],'send_time'=>['mode'=>'schedule','timezone'=>'America/Chicago','delivery'=>'specific','local'=>'2026-10-20 09:00','utc'=>'2026-10-20 14:00:00'],'experiment'=>['kind'=>'ab','variables'=>['content'=>['Variation 1','Variation 2']],'combinations'=>2,'percent'=>50,'hours'=>4,'winner'=>'clicks']]];
 $recipientCount=2;$targetError='';$publication=null;$campaignMergeTags=['FNAME'=>'First Name','LNAME'=>'Last Name','EMAIL'=>'Email Address'];$campaignComments=[['id'=>1,'actor_label'=>'Preview editor','created_at'=>'2026-10-16 10:00:00','body'=>'The updated design is ready for review.','resolved'=>false]];
 $planRows=[];foreach(['Community fall update','Volunteer welcome']as$i=>$name)$planRows[]=['id'=>$i+1,'version'=>1,'name'=>$name,'data_json'=>json_encode(['goal'=>'Make an announcement','start'=>'2026-10-01','end'=>'2026-10-31','notes'=>'Coordinate our newsletter and community event.','tracking'=>['opens'=>true,'clicks'=>true]])];
 $planChoices=$planRows;$plan=$view==='campaign-plan'?$planRows[0]:null;if($plan)$plan['data']=audienceJson($plan['data_json']);$planTotal=2;$planPage=1;$planSearch='';$planSort='updated_at';$planDirection='desc';
 $plannerLayout=match($argv[1]){'campaign-manager-list'=>'list','campaign-manager-agenda'=>'agenda',default=>'calendar'};$plannerScale='month';$plannerDate='2026-10-16';$plannerEmails=[['id'=>1,'name'=>'Community newsletter']];
 $touchpoints=[['id'=>1,'version'=>1,'plan_id'=>1,'campaign_id'=>null,'touchpoint_type'=>'task','name'=>'Review newsletter','start_at'=>'2026-10-16 09:00:00','end_at'=>'2026-10-16 10:00:00','state'=>'in-progress','data_json'=>'{"notes":"Check links and content before sending.","event_type":""}','email_status'=>null,'email_deleted'=>null],['id'=>2,'version'=>1,'plan_id'=>1,'campaign_id'=>1,'touchpoint_type'=>'email','name'=>'Community newsletter','start_at'=>'2026-10-20 14:00:00','end_at'=>'2026-10-20 14:30:00','state'=>'not-started','data_json'=>'{"notes":"Share the community update.","event_type":""}','email_status'=>'draft','email_deleted'=>null]];
}
if(in_array($argv[1]??'', ['campaign','campaign-empty','campaigns','campaign-calendar'],true)){$campaignMergeTags=['FNAME'=>'First Name','LNAME'=>'Last Name','EMAIL'=>'Email Address'];$publication=null;$campaignComments=[];if($view==='campaign')$id=(int)$campaign['id'];}
if(in_array($argv[1]??'', ['designer','templates','templates-saved'],true)){
 $starters=audienceDesignStarters();$record=['name'=>'Community newsletter'];$design=$starters['newsletter']['design'];
 $templates=[['id'=>1,'name'=>'Community newsletter','folder'=>'Newsletters','version'=>1,'updated_at'=>'2026-10-16 10:00:00','actor_label'=>'Preview editor','design_json'=>json_encode($design)]];
 $tab=$argv[1]==='templates-saved'?'saved':'built-in';$recentDesigns=[];$view='templates';$pageTitle='Email Templates';
 if(($argv[2]??'')==='brand')$design['styles']['webFont']='data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/../src/assets/fonts/rubik-latin-700.woff2'));
 $designerConfig=['nonce'=>contentSecurityPolicyNonce(),'design'=>$design,'version'=>1,'endpoint'=>'preview-api.php','exit'=>'templates-saved.html','starters'=>$starters,'saved'=>[['id'=>1,'name'=>'Community newsletter','design'=>$design]],'mergeTags'=>['FNAME'=>'First Name','EMAIL'=>'Email Address'],'dynamicFields'=>['FNAME'=>'First Name','EMAIL'=>'Email Address','tag:1'=>'Tag: Newsletter'],'recoveryKey'=>'fictional-designer-fixture','kind'=>'campaign'];
}
if(in_array($argv[1]??'', ['content','content-list','brand'],true)){
 $view=$argv[1]==='brand'?'brand':'content';$pageTitle=$view==='brand'?'Brand Kit':'Content';$campaignRecovery=null;
 $image='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=';
 $contentContext=['version'=>1,'settings'=>['content_folders'=>['Newsletters','Community']]];$contentItem=null;
 $contentLibrary=['items'=>[],'total'=>3,'page'=>1,'pages'=>1,'per_page'=>25,'search'=>'','folder'=>'','type'=>'','sort'=>'updated_at','direction'=>'desc','trash'=>false,'layout'=>$argv[1]==='content-list'?'list':'grid'];
 foreach(['Community logo','Newsletter banner','Welcome section']as$i=>$name)$contentLibrary['items'][]=['id'=>$i+1,'version'=>1,'name'=>$name,'item_type'=>$i===2?'section':'image','updated_at'=>'2026-10-16 10:00:00','data'=>['image'=>$image,'alt'=>'Fictional preview','folder'=>'Newsletters','published'=>false]];
 $brand=['styles'=>audienceDesignStarters()['newsletter']['design']['styles'],'logo'=>'','secondary_logo'=>''];$brandImages=[['id'=>1,'name'=>'Community logo']];$brandTemplates=[['id'=>1,'name'=>'Community newsletter','version'=>2],['id'=>2,'name'=>'Volunteer welcome','version'=>7]];$brandPreview=audienceDesignStarters()['newsletter']['design'];
}
if(in_array($argv[1]??'', ['delivery','delivery-empty','delivery-relay','delivery-verification','delivery-authentication','delivery-limits','delivery-activity','delivery-events'],true)){
 $view='delivery';$pageTitle='Domains';$campaignRecovery=null;$canConfigureDelivery=true;$deliveryAvailable=true;$deliverySender=$deliverySenderJob=$deliveryDomain=null;$deliveryDevelopmentCode='';$senderReadiness=null;
 $deliveryProbe=['id'=>1,'state'=>'accepted','updated_at'=>gmdate('Y-m-d H:i:s'),'result_json'=>'{"transport":"smtp","from":"hello@community.example","tls":true,"ready":true}','error_message'=>''];
 $record=['selector'=>'relay1','host'=>'relay1._domainkey.community.example','type'=>'CNAME','value'=>'relay1.provider.example'];
 $domain=['id'=>1,'version'=>2,'domain'=>'community.example','records_json'=>json_encode([$record]),'checks_json'=>'{}','authentication_enabled'=>0,'authenticated_at'=>null,'checked_at'=>null,'verified_count'=>1];
 $deliveryDomains=[$domain];$deliveryAddresses=[['id'=>1,'domain_id'=>1,'email'=>'hello@community.example','verified_at'=>'2026-10-16 10:00:00','verified_transport'=>'smtp']];
 if($argv[1]==='delivery-empty'){$deliveryDomains=[];$deliveryAddresses=[];}
 $runtimeInstalled=true;$deliveryPolicy=['version'=>1,'hourly_budget'=>0,'daily_budget'=>0,'hourly_reserve'=>0,'daily_reserve'=>0,'interval_seconds'=>1,'bulk_approved'=>1,'blocked_until'=>null,'event_key_ciphertext'=>null];$mailUsage=[];$mailActivity=[['kind'=>'test','actor_label'=>'Preview editor','state'=>'queued','attempts'=>0,'created_at'=>'2026-10-16 10:00:00','error_message'=>'Waiting for configured capacity.','next_attempt_at'=>'2026-10-16 11:00:00']];$eventSummary=['n'=>0,'latest'=>null];$operationalQueues=['Account access'=>[['status'=>'sent','n'=>2,'next_attempt'=>null]],'Task notifications'=>[],'Engagement correspondence'=>[],'Reimbursement submissions'=>[]];if(in_array($argv[1],['delivery-limits','delivery-activity','delivery-events'],true))$_GET['tab']=substr($argv[1],9);
 if($argv[1]==='delivery-relay')$_GET['tab']='relay';
 if($argv[1]==='delivery-verification'){$deliverySender=['id'=>1,'domain_id'=>1,'email'=>'hello@community.example','code_hash'=>'fictional','verified_at'=>null,'verified_transport'=>''];$deliverySenderJob=['state'=>'accepted','error_message'=>''];}
 if($argv[1]==='delivery-authentication')$deliveryDomain=$domain+['records'=>[$record],'checks'=>[]];
}
if(str_starts_with($argv[1]??'','ses-')){
 $view='delivery';$pageTitle='Email delivery';$sesInstalled=true;$canConfigureDelivery=true;$campaignRecovery=null;$runtimeInstalled=true;
 $_GET['tab']=substr($argv[1],4);$mailActivity=[];
 $sesSettings=['version'=>1,'check_state'=>'unconfigured','enabled'=>0,'region'=>'us-east-1','from_email'=>'hello@community.example','configuration_set'=>'message-monkey','topic_arn'=>'arn:aws:sns:us-east-1:123456789012:message-monkey','aws_account_id'=>'123456789012','access_key_tail'=>'','checked_at'=>null,'error_message'=>'','result_json'=>'{}','production_access'=>0,'sending_enabled'=>0,'max_daily'=>0,'max_rate'=>0,'sent_last_day'=>0,'quota_checked_at'=>null,'subscription_arn'=>'','last_event_at'=>null];
 $sesInboxCounts=[];
 if(($argv[2]??'')==='configured'){
  $sesSettings=array_replace($sesSettings,['enabled'=>1,'check_state'=>'attention','access_key_tail'=>'DEMO','checked_at'=>'2026-10-17 10:00:00','error_message'=>'SES is in the sandbox. Request production access.','sending_enabled'=>1,'max_daily'=>200,'max_rate'=>1,'subscription_arn'=>$sesSettings['topic_arn'].':aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa','last_event_at'=>'2026-10-17 10:00:00','result_json'=>json_encode(['domain'=>'community.example','domain_verified'=>true,'dkim'=>['Status'=>'SUCCESS'],'dmarc'=>true,'mail_from'=>['MailFromDomain'=>'bounce.community.example']])]);
 }
}
if(in_array($argv[1]??'', ['campaign-send','campaign-send-now','campaign-send-blocked','campaign-delivery','campaign-delivery-paused','campaign-delivery-completed','campaign-delivery-cancelled'],true)){
 $view=str_starts_with($argv[1],'campaign-send')?'campaign-send':'campaign-delivery';$id=1;$pageTitle='Community newsletter';$campaignRecovery=null;$campaignComments=[];$publication=null;$campaignMergeTags=[];
 $draft=['subject'=>'A community update','preview_text'=>'News and opportunities for you','from_name'=>'Community team','from_email'=>'hello@community.example','design'=>audienceDesignStarters()['newsletter']['design'],'send_time'=>['mode'=>$argv[1]==='campaign-send-now'?'now':'schedule','local'=>'2026-10-20 09:00','utc'=>'2026-10-20 14:00:00','timezone'=>'America/Chicago','delivery'=>'specific']];
 $state=match($argv[1]){'campaign-delivery-paused'=>'paused','campaign-delivery-completed'=>'completed','campaign-delivery-cancelled'=>'cancelled',default=>'scheduled'};
 $campaign=['id'=>1,'version'=>6,'name'=>$pageTitle,'campaign_type'=>'regular','status'=>$view==='campaign-send'||$state==='cancelled'?'draft':($state==='completed'?'sent':$state),'draft'=>$draft];
 $dispatchReview=['proof'=>'fictional-proof','count'=>7203,'due'=>'2026-10-20 14:00:00'];$dispatchError='';if($argv[1]==='campaign-send-blocked'){$dispatchReview=null;$dispatchError='Verify the sending email address through the live relay. Check the provider’s DKIM and DMARC records for this domain.';}
 $delivery=['id'=>1,'version'=>2,'state'=>$state,'due_at'=>'2026-10-20 14:00:00','timezone'=>'America/Chicago','recipient_count'=>7203,'error_message'=>''];
 $runtimeInstalled=true;$deliveryReport=[['variant_key'=>'0','accepted'=>7180,'opens'=>3100,'clicks'=>320,'delivered'=>0,'bounced'=>0,'complaints'=>0,'unsubscribed'=>20,'delayed'=>0,'rejected'=>0]];$experimentReport=[];$deliverySnapshot=['draft'=>$draft];
 $deliveryCounts=$state==='completed'?['accepted'=>7180,'suppressed'=>20,'failed'=>2,'uncertain'=>1]:['queued'=>7203];$deliveryRows=[];
 if($state==='completed')foreach(['accepted','suppressed','failed','uncertain']as$i=>$status)$deliveryRows[]=['id'=>$i+1,'contact_id'=>$i+1,'state'=>$status,'attempts'=>1,'accepted_at'=>$status==='accepted'?'2026-10-20 14:00:06':null,'error_message'=>$status==='uncertain'?'SMTP acceptance could not be confirmed. This recipient will not be retried automatically.':''];
}
if(in_array($argv[1]??'',['forms','form','surveys','landing-pages','preferences','form-settings'],true)){
 $view=$argv[1];$available=true;$q=$type='';$kinds=match($view){'surveys'=>['survey'],'landing-pages'=>['landing'],'preferences'=>['preferences'],'form-settings'=>['hosted','preferences'],default=>['hosted','embedded','popup','landing']};
 $kind=($argv[2]??'hosted');if(!in_array($kind,AUDIENCE_COLLECTION_KINDS,true))$kind='hosted';
 $draft=audienceCollectionDefaults($kind,$kind==='survey'?'We’d love your feedback':'Join our community');$draft['opt_in']='single';
 $resource=['id'=>1,'kind'=>$kind,'name'=>'Newsletter signup','public_key'=>str_repeat('a',64),'draft'=>$draft,'draft_json'=>json_encode($draft),'state'=>'draft','version'=>1,'published_revision'=>null,'installed_at'=>null,'deleted_at'=>null,'updated_at'=>'2026-10-17 10:00:00'];$id=1;
 $resources=[...array_map(static fn($k,$i)=>['id'=>$i+1,'kind'=>$k,'name'=>match($k){'hosted'=>'Newsletter signup','embedded'=>'Website footer signup','popup'=>'Welcome popup','landing'=>'Newsletter landing page','survey'=>'Newsletter feedback',default=>'Subscription preferences'},'public_key'=>str_repeat('a',64),'state'=>$i%2?'published':'draft','version'=>1,'deleted_at'=>null,'updated_at'=>'2026-10-17 10:00:00'], $kinds,array_keys($kinds))];
 $chooserKinds=match($view){'surveys'=>['survey'],'landing-pages'=>['landing'],'preferences'=>['preferences'],default=>['popup','embedded','landing']};
 $publicBase='https://community.example';$publicUrl=$publicBase.'/audience_collect_public.php?key='.$resource['public_key'];
 $pageTitle=match($view){'form'=>$resource['name'],'surveys'=>'Surveys','landing-pages'=>'Landing pages','preferences'=>'Subscriber preferences','form-settings'=>'Forms Settings',default=>'Forms'};
 if($view==='form'){require_once __DIR__.'/../src/audience_collection_render.php';$r=$resource;$r['document']=$draft;$collectionPreviewHtml=audienceCollectionHtml($r,[],[],'','',[], '', '',null,true);}
}
$previewTheme=in_array($argv[3]??'',['light','dark'],true)?$argv[3]:null;
echo '<!DOCTYPE html><html lang="en"'.($previewTheme==='dark'?' class="dark-mode"':'').'><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Audience preview — fictional connections</title>'.($previewTheme===null?'<script src="theme-init.min.js"></script>':'').'<link rel="stylesheet" href="audience.min.css"><script src="audience.min.js" defer></script><link rel="stylesheet" href="audience-designer.min.css"><script src="audience-designer.min.js" defer></script><script src="audience-collection.min.js" defer></script></head>';
include __DIR__.'/../src/templates/audience/'.(($argv[1]??'')==='designer'?'designer.php':'workspace.php');
if($previewTheme!==null)echo '<script>document.addEventListener("DOMContentLoaded",function(){const sync=function(){document.documentElement.classList.toggle("dark-mode",'.json_encode($previewTheme==='dark').');};sync();window.addEventListener("pageshow",sync);});</script>';
echo '</html>';
