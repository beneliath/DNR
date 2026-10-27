<?php
// Real controller requests with the disposable suite's editor session.
$publishSend=static function(array $input)use($editor):array{
    $path='audience.php?view=form&id='.$input['id'];$page=$editor($path);
    return $editor($path,['csrf_token'=>audienceHttpCsrf($page)]+$input);
};
$formId=$store->saveCollection(['kind'=>'hosted','name'=>'Publish current changes '.$suffix]);
$original=$store->collectionResource($formId);$document=$original['draft'];$document['title']='Unsaved current title';
$input=['action'=>'collection_operation','operation'=>'publish','id'=>$formId,'version'=>1,'name'=>'Renamed at publish '.$suffix,'document_json'=>json_encode($document,JSON_THROW_ON_ERROR)];
$page=$editor('audience.php?view=form&id='.$formId);
audienceHttpExpect(str_contains($page['body'],'collection-publish-pending'),'A saved form with no published version highlights Publish');
$response=$publishSend($input);$saved=$store->collectionResource($formId);$published=$store->collectionPublished($saved['public_key']);
audienceHttpExpect($response['status']===302&&str_contains($response['headers'],'Location: audience.php?view=forms'),'Completing Publish returns the editor to Forms');
audienceHttpExpect($saved['name']===$input['name']&&$saved['draft']['title']===$document['title']&&$published['document']===$saved['draft'],'Publish saves the current name and design and publishes exactly that saved document');
$page=$editor('audience.php?view=form&id='.$formId);
audienceHttpExpect(!str_contains($page['body'],'collection-publish-pending'),'The saved published version has no Publish reminder');
$document['intro']='Saved but not published yet';
$save=['action'=>'collection_save','id'=>$formId,'version'=>$saved['version'],'name'=>$saved['name'],'document_json'=>json_encode($document,JSON_THROW_ON_ERROR)];
audienceHttpExpect($publishSend($save)['status']===302,'Editor can save a newer draft without publishing');
$page=$editor('audience.php?view=form&id='.$formId);
audienceHttpExpect(str_contains($page['body'],'collection-publish-pending'),'A newer saved draft highlights Publish even while the form is Published');
$before=$store->collectionResource($formId);$stale=$publishSend($input);
audienceHttpExpect($stale['status']===200&&str_contains($stale['body'],'changed')&&$store->collectionResource($formId)===$before,'Stale publish cannot overwrite or publish someone else’s newer saved draft');
audienceHttpExpect(str_contains($stale['body'],'Unsaved current title'),'Rejected publish retains the submitted editor design');
$embedded=$store->saveCollection(['kind'=>'embedded','name'=>'Atomic publish fixture '.$suffix]);$before=$store->collectionResource($embedded);
$document=$before['draft'];$document['intro']='Keep these unsaved edits after failure';$document['origins']=[];
$failure=$publishSend(['action'=>'collection_operation','operation'=>'publish','id'=>$embedded,'version'=>1,'name'=>$before['name'],'document_json'=>json_encode($document,JSON_THROW_ON_ERROR)]);
audienceHttpExpect($failure['status']===200&&str_contains($failure['body'],'HTTPS website origin'),'Publish validation stays in the editor with an actionable error');
audienceHttpExpect($store->collectionResource($embedded)===$before,'Failed publish rolls back the draft save and version increment');
audienceHttpExpect(str_contains($failure['body'],'Keep these unsaved edits after failure'),'Failed atomic publish preserves entered changes for correction');
