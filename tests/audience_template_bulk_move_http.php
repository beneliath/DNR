<?php
// Runs inside the disposable authenticated Audience integration suite.
$tbChecks=$checks;$tbPath='audience.php?view=templates&tab=saved';
foreach(['Bulk source','Bulk destination']as$tbFolder)$campaignStore->emailTemplateFolder('create',$tbFolder,'',$campaignStore->contentSettings()['version']);
$tbIds=[];foreach(['First','Second','Remaining']as$tbName)$tbIds[]=$campaignStore->createEmailTemplate('Bulk '.$tbName,$design,'Bulk source');
$tbSource=$tbPath.'&folder=Bulk%20source';$tbDestination=$tbPath.'&folder=Bulk%20destination';
$tbVersions=static fn(array $ids)=>array_combine($ids,array_map(static fn($id)=>$campaignStore->emailTemplate($id,false,true)['version'],$ids));
$tbRequest=static fn(array $ids,string $folder)=>['csrf_token'=>$token,'action'=>'template_bulk_move','selected'=>$ids,'versions'=>$tbVersions($ids),'folder'=>$folder];
$tbHtml=$editor($tbSource)['body'];
audienceHttpExpect(str_contains($tbHtml,'data-template-selection')&&str_contains($tbHtml,'data-template-select-page')&&str_contains($tbHtml,'form="template-selection" data-template-select')&&str_contains($tbHtml,'<option selected>Bulk source</option>'),'Editors have selection controls and the requested folder filter');
$tbSingle=['csrf_token'=>$token,'action'=>'template_operation','operation'=>'folder','id'=>$tbIds[0],'version'=>1,'folder'=>'Bulk destination'];
$tbMoved=$editor($tbSource,$tbSingle);
audienceHttpExpect($tbMoved['status']===302&&str_contains($tbMoved['headers'],'folder=Bulk+source')&&$campaignStore->emailTemplate($tbIds[0])['folder']==='Bulk destination','A single template move preserves the source folder filter');
$tbSnapshot=$campaignStore->emailTemplate($tbIds[1])['design'];$tbBulkIds=[$tbIds[1],$tbIds[2]];
$tbMoved=$editor($tbSource,$tbRequest($tbBulkIds,'Bulk destination'));
audienceHttpExpect($tbMoved['status']===302&&str_contains($tbMoved['headers'],'folder=Bulk+source'),'Bulk move preserves the source folder filter');
foreach($tbIds as$tbId)audienceHttpExpect($campaignStore->emailTemplate($tbId)['folder']==='Bulk destination'&&(int)$campaignStore->emailTemplate($tbId)['version']===2,'Only requested template versions advance once');
audienceHttpExpect($campaignStore->emailTemplate($tbIds[1])['design']===$tbSnapshot,'Moving templates preserves their designs');
audienceHttpExpect(str_contains($editor($tbSource)['body'],'<option selected>Bulk source</option>'),'An emptied source folder stays selected after moving its final templates');
audienceHttpExpect(!str_contains($reviewer($tbPath)['body'],'data-template-selection')&&!str_contains($reviewer($tbPath)['body'],'data-template-select aria-label'),'Reviewers have no bulk move controls');
$tbGood=$tbRequest($tbBulkIds,'');
audienceHttpExpect($reviewer($tbSource,array_replace($tbGood,['csrf_token'=>$reviewToken]))['status']===403,'Reviewers cannot bulk move templates');
audienceHttpExpect($editor($tbSource,array_replace($tbGood,['csrf_token'=>'invalid']))['status']===400,'Bulk move requires CSRF');
$tbArchived=$campaignStore->createEmailTemplate('Archived bulk member',$design,'Bulk destination');$campaignStore->emailTemplateOperation('archive',$tbArchived,1,[]);
$tbForeign=$otherStore->createEmailTemplate('Foreign bulk member',$design);
$tbInvalid=[
    array_replace($tbGood,['folder'=>'Missing folder']),
    array_replace($tbGood,['versions'=>[$tbIds[1]=>2,$tbIds[2]=>1]]),
    array_replace($tbGood,['versions'=>[$tbIds[1]=>2]]),
    array_replace($tbGood,['versions'=>[$tbIds[1]=>['invalid'],$tbIds[2]=>2]]),
    array_replace($tbGood,['selected'=>[$tbIds[1],$tbForeign],'versions'=>[$tbIds[1]=>2,$tbForeign=>1]]),
    array_replace($tbGood,['selected'=>[$tbIds[1],$tbArchived],'versions'=>[$tbIds[1]=>2,$tbArchived=>2]]),
    array_replace($tbGood,['selected'=>[]]),
    array_replace($tbGood,['selected'=>'invalid']),
    array_replace($tbGood,['versions'=>'invalid']),
];
foreach($tbInvalid as$tbBad){$tbRejected=$editor($tbSource,$tbBad);audienceHttpExpect($tbRejected['status']===200&&$campaignStore->emailTemplate($tbIds[1])['folder']==='Bulk destination'&&$campaignStore->emailTemplate($tbIds[2])['folder']==='Bulk destination'&&$tbVersions($tbBulkIds)===$tbGood['versions'],'Invalid, stale, archived or foreign bulk selections cannot partially move templates');}
audienceHttpExpect($otherStore->emailTemplate($tbForeign)['folder']===''&&$campaignStore->emailTemplate($tbArchived,false,true)['folder']==='Bulk destination','Rejected bulk moves preserve foreign and archived templates');
$tbMoved=$editor($tbDestination,$tbGood);
audienceHttpExpect($tbMoved['status']===302&&str_contains($tbMoved['headers'],'folder=Bulk+destination')&&$campaignStore->emailTemplate($tbIds[1])['folder']===''&&$campaignStore->emailTemplate($tbIds[2])['folder']==='','Bulk move supports Unfiled without changing the current filter');
$tbUnfiled=$editor($tbPath.'&folder=unfiled',array_replace($tbSingle,['id'=>$tbIds[1],'version'=>3]));
audienceHttpExpect($tbUnfiled['status']===302&&str_contains($tbUnfiled['headers'],'folder=unfiled')&&str_contains($editor($tbPath.'&folder=unfiled')['body'],'<option value="unfiled" selected>Unfiled</option>'),'Moving from Unfiled preserves its filter and leaves remaining unfiled templates visible');
echo 'Audience template bulk move HTTP checks passed: '.($checks-$tbChecks)."\n";
