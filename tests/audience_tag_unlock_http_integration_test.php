<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Audience HTTP tests skipped (disposable environment required).\n");
// Sub-suites print progress before later checks update synthetic session state.
ob_start();
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';require_once __DIR__.'/integration_auth_helpers.php';
$conn=applicationDatabaseConnection();$checks=0;
function audienceHttpExpect(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
function audienceHttpCsrf(array $response):string{preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$response['body'],$m);if(empty($m[1]))preg_match('/"csrf":"([^"]+)"/',$response['body'],$m);if(empty($m[1]))throw new RuntimeException('Missing Audience CSRF: '.$response['status']);return html_entity_decode($m[1],ENT_QUOTES);}
$clients=[];$cookies=[];$suffix=bin2hex(random_bytes(5));
try{
    foreach(['admin'=>'admin','editor'=>'editor','reviewer'=>'reviewer']as$clientKey=>$role){
        $username='audience-'.$clientKey.'-'.$suffix;$password=bin2hex(random_bytes(16));
        $conn->execute_query('INSERT INTO users(username,password,role) VALUES (?,?,?)',[$username,password_hash($password,PASSWORD_DEFAULT),$role]);
        $cookie=tempnam(sys_get_temp_dir(),'audience-cookie-');$cookies[]=$cookie;
        $client=static function(string $path,?array $post=null,bool $multipart=false)use($cookie):array{$curl=curl_init('http://127.0.0.1/'.$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_TIMEOUT=>30]);if($post!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$multipart?$post:http_build_query($post));$raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$result=['status'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];return $result;};
        $login=$client('login.php');$login=$client('login.php',['csrf_token'=>audienceHttpCsrf($login),'username'=>$username,'password'=>$password]);$login=finishIntegrationTestEnrollment($client,$login);audienceHttpExpect($login['status']===302,'Fixture login '.$clientKey);$clients[$clientKey]=$client;
    }
    $admin=$clients['admin'];$editor=$clients['editor'];$reviewer=$clients['reviewer'];
    $adminSessionId='';$adminSessionName='';
    foreach(file($cookies[0],FILE_IGNORE_NEW_LINES)as$line){$parts=explode("\t",$line);if(count($parts)===7&&($parts[5]===session_name()||str_starts_with($parts[5],'MOED_'))){$adminSessionName=$parts[5];$adminSessionId=$parts[6];}}
    audienceHttpExpect($adminSessionId!=='','Admin fixture has a session cookie');
    $store=new AudienceStore($conn,1,1,'Tag unlock qualification');$path='audience.php?view=tags';
    $unlock=static function(?int $at)use($adminSessionName,$adminSessionId):void{session_name($adminSessionName);session_id($adminSessionId);startSecureSession();unset($_SESSION['_admin_elevated_at'],$_SESSION['_admin_elevation_expires_at']);if($at!==null)$_SESSION['_admin_elevated_at']=$at;session_write_close();};
    $send=static function(callable $client,array $values)use($path):array{$page=$client($path);return $client($path,['csrf_token'=>audienceHttpCsrf($page)]+$values);};
    $person=$store->savePerson(['email'=>'tag-unlock-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
    foreach(['single','bulk']as$mode){
        $tag=$store->saveTag('Protected '.$mode.' '.$suffix,0,0);$store->write('INSERT INTO marketing_contact_tags(contact_id,tag_id) VALUES (?,?)',[$person,$tag]);
        $input=$mode==='single'?['action'=>'delete_tag','id'=>$tag,'version'=>1]:['action'=>'delete_selected_definitions','kind'=>'tags','selected_ids'=>[$tag],'versions'=>[$tag=>1]];
        foreach([$reviewer,$editor]as$client)audienceHttpExpect($send($client,$input)['status']===403,$mode.' tag deletion denies non-admin roles');
        $unlock(null);$locked=$send($admin,$input);audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'Location: admin_elevation.php?'),$mode.' direct deletion requires Admin Unlock');
        audienceHttpExpect((bool)$store->one('SELECT id FROM marketing_tags WHERE id=?',[$tag])&&$store->person($person)['tag_ids']===[$tag],$mode.' locked deletion preserves the tag and assignments');
        $unlock(time()-301);$expired=$send($admin,$input);audienceHttpExpect($expired['status']===302&&str_contains($expired['headers'],'admin_elevation.php?'),$mode.' expired unlock cannot delete');
        $unlock(time());$page=$admin($path);$invalid=$admin($path,['csrf_token'=>'invalid']+$input);audienceHttpExpect($invalid['status']===400,$mode.' deletion still enforces CSRF');
        $stale=$input;if($mode==='single')$stale['version']=99;else$stale['versions']=[$tag=>99];$changed=$send($admin,$stale);audienceHttpExpect(str_contains($changed['body'],'changed')&&(bool)$store->one('SELECT id FROM marketing_tags WHERE id=?',[$tag]),$mode.' deletion rejects stale versions');
        $deleted=$send($admin,$input);audienceHttpExpect($deleted['status']===302&&!str_contains($deleted['headers'],'admin_elevation.php')&&!$store->one('SELECT id FROM marketing_tags WHERE id=?',[$tag]),$mode.' unlocked admin can delete');
        audienceHttpExpect($store->person($person)['tag_ids']===[],$mode.' deletion keeps the connection');
    }
    $tag=$store->saveTag('Visibility '.$suffix,0,0);
    foreach(['admin'=>$admin,'editor'=>$editor,'reviewer'=>$reviewer]as$role=>$client){$page=$client($path);audienceHttpExpect($page['status']===200&&!preg_match('/(?:Warning|Fatal error):/',$page['body']),$role.' Tags page renders');audienceHttpExpect(str_contains($page['body'],'data-tag-delete-form')===($role==='admin'),$role.' sees only permitted single/bulk delete controls');}
    $path='audience.php?view=segments';
    $sendSegment=static function(callable $client,array $values)use($path):array{$page=$client($path);return $client($path,['csrf_token'=>audienceHttpCsrf($page)]+$values);};
    foreach(['single','bulk']as$mode){
        $segment=$store->saveSegment('Protected segment '.$mode.' '.$suffix,['match'=>'all','rules'=>[]],0,0);
        $input=$mode==='single'?['action'=>'delete_segment','id'=>$segment,'version'=>1]:['action'=>'delete_selected_definitions','kind'=>'segments','selected_ids'=>[$segment],'versions'=>[$segment=>1]];
        foreach([$reviewer,$editor]as$client)audienceHttpExpect($sendSegment($client,$input)['status']===403,$mode.' segment deletion denies non-admin roles');
        $unlock(null);$locked=$sendSegment($admin,$input);audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'Location: admin_elevation.php?'),$mode.' direct segment deletion requires Admin Unlock');
        audienceHttpExpect((bool)$store->one('SELECT id FROM marketing_segments WHERE id=?',[$segment])&&(bool)$store->person($person),$mode.' locked deletion preserves the segment and connection');
        $unlock(time()-301);$expired=$sendSegment($admin,$input);audienceHttpExpect($expired['status']===302&&str_contains($expired['headers'],'admin_elevation.php?'),$mode.' expired unlock cannot delete a segment');
        $unlock(time());$invalid=$admin($path,['csrf_token'=>'invalid']+$input);audienceHttpExpect($invalid['status']===400,$mode.' segment deletion still enforces CSRF');
        $stale=$input;if($mode==='single')$stale['version']=99;else$stale['versions']=[$segment=>99];$changed=$sendSegment($admin,$stale);audienceHttpExpect(str_contains($changed['body'],'changed')&&(bool)$store->one('SELECT id FROM marketing_segments WHERE id=?',[$segment]),$mode.' segment deletion rejects stale versions');
        $deleted=$sendSegment($admin,$input);audienceHttpExpect($deleted['status']===302&&!str_contains($deleted['headers'],'admin_elevation.php')&&!$store->one('SELECT id FROM marketing_segments WHERE id=?',[$segment]),$mode.' unlocked admin can delete a segment');
        audienceHttpExpect((bool)$store->person($person),$mode.' segment deletion keeps connections');
    }
    $segment=$store->saveSegment('Visibility segment '.$suffix,['match'=>'all','rules'=>[]],0,0);
    foreach(['admin'=>$admin,'editor'=>$editor,'reviewer'=>$reviewer]as$role=>$client){$page=$client($path);audienceHttpExpect($page['status']===200&&!preg_match('/(?:Warning|Fatal error):/',$page['body']),$role.' Segments page renders');audienceHttpExpect(str_contains($page['body'],'data-segment-delete-form')===($role==='admin'),$role.' sees only permitted segment delete controls');}
    $counted=$store->saveSegment('Counted segment '.$suffix,['match'=>'all','rules'=>[['field'=>'email','operator'=>'is','value'=>'tag-unlock-'.$suffix.'@example.test']]],0,0);
    $empty=$store->saveSegment('Empty counted segment '.$suffix,['match'=>'all','rules'=>[['field'=>'email','operator'=>'is','value'=>'empty-count-'.$suffix.'@example.test']]],0,0);
    $countRows=$store->all('SELECT * FROM marketing_segments WHERE audience_id=1 AND id IN (?,?)',[$counted,$empty]);
    $counts=$store->segmentConnectionCounts($countRows);audienceHttpExpect($counts[$counted]===1&&$counts[$empty]===0,'Segment connection counts match their rules including empty results');
    $store->mutatePerson($person,(int)$store->person($person)['version'],'archive');$counts=$store->segmentConnectionCounts($countRows);audienceHttpExpect($counts[$counted]===0,'Segment counts exclude archived connections');
    $store->mutatePerson($person,(int)$store->person($person)['version'],'restore');$counts=$store->segmentConnectionCounts($countRows);audienceHttpExpect($counts[$counted]===1,'Segment counts update after restoring a connection');
    audienceHttpExpect($store->segmentConnectionCounts([])===[],'Empty segment pages need no count query');
    $selectedSize=static function(array $page,string $key):int{$doc=new DOMDocument();@$doc->loadHTML($page['body']);$selected=(new DOMXPath($doc))->query('//select[@name="'.$key.'"]/option[@selected]')->item(0);return $selected?(int)$selected->getAttribute('value'):0;};
    $admin('audience.php?per_page=25');$admin('audience.php?view=segments&segment_size=10');
    audienceHttpExpect($selectedSize($admin('audience.php'),'per_page')===25&&$selectedSize($admin('audience.php?view=segments'),'segment_size')===10,'Each list restores its selected page size across visits');
    audienceHttpExpect($selectedSize($editor('audience.php?view=segments'),'segment_size')===100,'Page size choices do not leak to another user');
    $admin('audience.php?view=segments&segment_size=50');audienceHttpExpect($selectedSize($admin('audience.php?view=segments'),'segment_size')===50,'A new selection replaces only that list preference');
    $inboxPath='audience.php?view=inbox&panel=templates';
    $template=$store->saveInboxNamed('template',['name'=>'Protected Inbox reply '.$suffix,'subject'=>'Protected reply','body_html'=>'<p>Keep this reply until an administrator unlocks deletion.</p>']);
    $templateInput=['action'=>'inbox_template_operation','operation'=>'delete','id'=>$template,'version'=>1];
    $sendTemplate=static function(callable $client,array $input)use($inboxPath):array{$page=$client($inboxPath);return $client($inboxPath,['csrf_token'=>audienceHttpCsrf($page)]+$input);};
    $unlock(null);$templatePage=$admin($inboxPath);
    audienceHttpExpect(str_contains($templatePage['body'],'data-tooltip="Archive"')&&str_contains($templatePage['body'],'data-tooltip="Edit"')&&str_contains($templatePage['body'],'id="admin-unlock-dialog"'),'Active Inbox templates have shared icon actions and the Admin Unlock interface');
    $editorTemplates=$editor($inboxPath);audienceHttpExpect($editorTemplates['status']===200&&!str_contains($editorTemplates['body'],'data-inbox-template-delete-form'),'Editors can edit Inbox templates without seeing admin delete controls');
    audienceHttpExpect($sendTemplate($editor,$templateInput)['status']===403,'Direct Inbox template deletion denies editors');
    audienceHttpExpect($reviewer($inboxPath,$templateInput)['status']===403,'Direct Inbox template deletion denies reviewers');
    $legacyInput=['action'=>'inbox_named_remove','kind'=>'template','id'=>$template,'version'=>1];
    audienceHttpExpect($sendTemplate($editor,$legacyInput)['status']===403,'Legacy Inbox template deletion also denies editors');
    $unlock(time());$activeDelete=$sendTemplate($admin,$templateInput);audienceHttpExpect(str_contains($activeDelete['body'],'Archive this template')&&$store->one('SELECT deleted_at FROM marketing_inbox_templates WHERE id=?',[$template])['deleted_at']===null,'Permanent deletion requires archiving first');
    $archiveInput=['action'=>'inbox_template_operation','operation'=>'archive','id'=>$template,'version'=>1];
    audienceHttpExpect($reviewer($inboxPath,$archiveInput)['status']===403,'Reviewers cannot archive Inbox templates');
    $unlock(null);$archived=$sendTemplate($editor,$archiveInput);$archivedRow=$store->one('SELECT * FROM marketing_inbox_templates WHERE id=?',[$template]);
    audienceHttpExpect($archived['status']===302&&$archivedRow['deleted_at']!==null&&(int)$archivedRow['version']===2,'Editors can archive Inbox templates without Admin Unlock');
    audienceHttpExpect(!in_array($template,array_map('intval',array_column($store->inboxTemplates(),'id')),true)&&in_array($template,array_map('intval',array_column($store->inboxTemplates(true),'id')),true),'Archived templates are excluded from the reply picker and included in the archive');
    $archivedPath=$inboxPath.'&template_status=archived';$activePage=$admin($inboxPath);$archivePage=$admin($archivedPath);
    audienceHttpExpect(!str_contains($activePage['body'],$archivedRow['name'])&&str_contains($archivePage['body'],$archivedRow['name'])&&str_contains($archivePage['body'],'data-tooltip="Restore"')&&str_contains($archivePage['body'],'data-inbox-template-delete-form'),'Archived view exposes restore and gated delete icons separately from active templates');
    $editorArchive=$editor($archivedPath);audienceHttpExpect(str_contains($editorArchive['body'],'data-tooltip="Restore"')&&!str_contains($editorArchive['body'],'data-inbox-template-delete-form'),'Editors can restore but cannot see permanent deletion controls');
    $collision=$sendTemplate($editor,['action'=>'inbox_named_save','kind'=>'template','name'=>$archivedRow['name'],'subject'=>'Replacement','body_html'=>'<p>Do not overwrite the archive.</p>']);
    audienceHttpExpect(str_contains($collision['body'],'name is archived')&&$store->one('SELECT body_html FROM marketing_inbox_templates WHERE id=?',[$template])['body_html']===$archivedRow['body_html'],'Creating a template cannot silently overwrite an archived reply');
    $restoreInput=array_replace($archiveInput,['operation'=>'restore','version'=>2]);
    audienceHttpExpect($editor($archivedPath,['csrf_token'=>'invalid']+$restoreInput)['status']===400,'Template restoration enforces CSRF');
    $staleRestore=$sendTemplate($editor,array_replace($restoreInput,['version'=>1]));audienceHttpExpect(str_contains($staleRestore['body'],'changed'),'Template restoration rejects stale versions');
    $restored=$sendTemplate($editor,$restoreInput);$restoredRow=$store->one('SELECT * FROM marketing_inbox_templates WHERE id=?',[$template]);
    audienceHttpExpect($restored['status']===302&&$restoredRow['deleted_at']===null&&(int)$restoredRow['version']===3&&$restoredRow['body_html']===$archivedRow['body_html'],'Restore returns the original template content to active use');
    $staleArchive=$sendTemplate($editor,$archiveInput);audienceHttpExpect(str_contains($staleArchive['body'],'changed')&&$store->one('SELECT deleted_at FROM marketing_inbox_templates WHERE id=?',[$template])['deleted_at']===null,'Archiving rejects stale versions');
    $sendTemplate($editor,array_replace($archiveInput,['version'=>3]));$templateInput['version']=4;
    foreach([null,time()-301]as$elevatedAt){$unlock($elevatedAt);$locked=$sendTemplate($admin,$templateInput);audienceHttpExpect($locked['status']===302&&str_contains($locked['headers'],'admin_elevation.php?')&&(bool)$store->one('SELECT id FROM marketing_inbox_templates WHERE id=?',[$template]),'Locked or expired Admin Unlock preserves the archived Inbox template');}
    $unlock(time());$invalid=$admin($inboxPath,['csrf_token'=>'invalid']+$templateInput);audienceHttpExpect($invalid['status']===400,'Unlocked Inbox template deletion enforces CSRF');
    $stale=$sendTemplate($admin,array_replace($templateInput,['version'=>99]));audienceHttpExpect(str_contains($stale['body'],'changed')&&(bool)$store->one('SELECT id FROM marketing_inbox_templates WHERE id=?',[$template]),'Unlocked Inbox template deletion rejects stale versions');
    $deleted=$sendTemplate($admin,$templateInput);audienceHttpExpect($deleted['status']===302&&!$store->one('SELECT id FROM marketing_inbox_templates WHERE id=?',[$template]),'An unlocked administrator can permanently delete an archived Inbox template');
    $sendProtected=static function(callable $client,string $path,array $input):array{$page=$client('audience.php');return $client($path,['csrf_token'=>audienceHttpCsrf($page)]+$input);};
    $assertProtected=static function(string $path,array $input,callable $preserved)use($sendProtected,$admin,$editor,$reviewer,$unlock):void{
        foreach([$editor,$reviewer]as$client)audienceHttpExpect($sendProtected($client,$path,$input)['status']===403,$input['action'].' rejects non-admin roles');
        foreach([null,time()-301]as$at){$unlock($at);$response=$sendProtected($admin,$path,$input);audienceHttpExpect($response['status']===302&&str_contains($response['headers'],'admin_elevation.php?')&&$preserved(),$input['action'].' preserves data while locked or expired');}
        $unlock(time());audienceHttpExpect($admin($path,['csrf_token'=>'invalid']+$input)['status']===400,$input['action'].' enforces CSRF after unlock');
    };
    $contentPath='audience.php?view=content';$folderName='Protected content '.$suffix;$renamedFolder=$folderName.' renamed';$unlock(null);
    $created=$sendProtected($editor,$contentPath,['action'=>'content_folder','operation'=>'create','name'=>$folderName,'version'=>$store->contentSettings()['version']]);
    audienceHttpExpect($created['status']===302&&in_array($folderName,$store->contentSettings()['settings']['content_folders'],true),'Editors can create content folders without Admin Unlock');
    $renamed=$sendProtected($editor,$contentPath,['action'=>'content_folder','operation'=>'rename','name'=>$folderName,'new_name'=>$renamedFolder,'version'=>$store->contentSettings()['version']]);
    audienceHttpExpect($renamed['status']===302&&in_array($renamedFolder,$store->contentSettings()['settings']['content_folders'],true),'Editors can rename content folders without Admin Unlock');
    $folderSettings=$store->contentSettings();$folderInput=['action'=>'content_folder','operation'=>'delete','name'=>$renamedFolder,'version'=>$folderSettings['version']];
    $assertProtected($contentPath,$folderInput,fn()=>$store->contentSettings()===$folderSettings);
    $stale=$sendProtected($admin,$contentPath,array_replace($folderInput,['version'=>$folderSettings['version']+1]));
    audienceHttpExpect(str_contains($stale['body'],'Content settings changed')&&$store->contentSettings()===$folderSettings,'Unlocked content folder deletion rejects stale settings');
    $deleted=$sendProtected($admin,$contentPath,$folderInput);
    audienceHttpExpect($deleted['status']===302&&!str_contains($deleted['headers'],'admin_elevation.php')&&!in_array($renamedFolder,$store->contentSettings()['settings']['content_folders'],true),'An unlocked administrator can delete an empty content folder');
    $field=$store->saveField(['label'=>'Protected field '.$suffix,'merge_tag'=>'SAFE_'.strtoupper($suffix),'field_type'=>'text','is_public'=>'1'],0,0);
    $fieldInput=['action'=>'delete_field','id'=>$field,'version'=>1];
    $assertProtected('audience.php?view=fields',$fieldInput,fn()=>(bool)$store->one('SELECT id FROM marketing_fields WHERE id=?',[$field]));
    $sendProtected($admin,'audience.php?view=fields',$fieldInput);audienceHttpExpect(!$store->one('SELECT id FROM marketing_fields WHERE id=?',[$field]),'Unlocked field deletion succeeds');
    $group=$store->saveGroup(['name'=>'Protected group '.$suffix,'display_type'=>'checkboxes','options'=>[['name'=>'First'],['name'=>'Second']]],0,0);
    $options=$store->all('SELECT id,name FROM marketing_group_options WHERE category_id=? ORDER BY id',[$group]);
    $editGroup=['action'=>'save_group','id'=>$group,'version'=>1,'name'=>'Protected group '.$suffix,'display_type'=>'checkboxes','options'=>[$options[0]]];
    $assertProtected('audience.php?view=groups',$editGroup,fn()=>(int)$store->one('SELECT COUNT(*) n FROM marketing_group_options WHERE category_id=?',[$group])['n']===2);
    $sendProtected($admin,'audience.php?view=groups',$editGroup);audienceHttpExpect((int)$store->one('SELECT COUNT(*) n FROM marketing_group_options WHERE category_id=?',[$group])['n']===1,'Unlocked option removal succeeds');
    $unlock(null);$editGroup['version']=2;$editGroup['name']='Renamed group '.$suffix;
    audienceHttpExpect($sendProtected($editor,'audience.php?view=groups',$editGroup)['status']===302,'Editors can still rename a group without removing choices');
    $groupInput=['action'=>'delete_group','id'=>$group,'version'=>3];
    $assertProtected('audience.php?view=groups',$groupInput,fn()=>(bool)$store->one('SELECT id FROM marketing_group_categories WHERE id=?',[$group]));
    $sendProtected($admin,'audience.php?view=groups',$groupInput);audienceHttpExpect(!$store->one('SELECT id FROM marketing_group_categories WHERE id=?',[$group]),'Unlocked group deletion succeeds');
    $import=$store->createImport(audienceParseImport("Email\nundo-protected-".$suffix."@example.test"),'Protected import');
    $store->configureImport($import,['mapping'=>['email'],'import_status'=>'non-subscribed'],true);$store->processJob($import);
    $imported=$store->one('SELECT id FROM marketing_contacts WHERE email=?',['undo-protected-'.$suffix.'@example.test']);
    $undoInput=['action'=>'undo_import','id'=>$import];
    $assertProtected('audience.php?view=job&id='.$import,$undoInput,fn()=>(int)$store->person((int)$imported['id'])['is_archived']===0);
    $sendProtected($admin,'audience.php?view=job&id='.$import,$undoInput);audienceHttpExpect((int)$store->person((int)$imported['id'])['is_archived']===1,'Unlocked import undo archives its new connection');
    foreach(['single','selected','all']as$mode){
        $export=$store->queueSelection('export','Protected export '.$mode,$store->where(audienceListFilters([])));while($store->job($export)['state']!=='complete')$store->processJob($export);
        $exportInput=['action'=>'delete_export_history','export_ids'=>[$export],'delete_all'=>$mode==='all'?'1':'0'];
        $assertProtected('audience.php?view=jobs&kind=export',$exportInput,fn()=>empty(audienceJson($store->one('SELECT params_json FROM marketing_jobs WHERE id=?',[$export])['params_json'])['history_deleted']));
        $sendProtected($admin,'audience.php?view=jobs&kind=export',$exportInput);audienceHttpExpect($admin('audience.php?api=job&id='.$export)['status']===404,'Unlocked '.$mode.' export-history deletion succeeds');
    }
    $target=$store->savePerson(['email'=>'direct-unsubscribe-'.$suffix.'@example.test','email_status'=>'subscribed','consent_note'=>'Synthetic permission']);
    $cleaned=$store->savePerson(['email'=>'cleaned-unsubscribe-'.$suffix.'@example.test','email_status'=>'non-subscribed']);$store->write("UPDATE marketing_contacts SET email_status='cleaned' WHERE id=?",[$cleaned]);
    $countBefore=(int)$store->one('SELECT COUNT(*) n FROM marketing_contacts')['n'];
    $unsub=$sendProtected($editor,'audience.php',['action'=>'unsubscribe_emails','emails'=>"direct-unsubscribe-".$suffix."@example.test\nDIRECT-UNSUBSCRIBE-".$suffix."@example.test\ncleaned-unsubscribe-".$suffix."@example.test\nunknown-".$suffix."@example.test"]);
    audienceHttpExpect($unsub['status']===302&&preg_match('/id=(\d+)/',$unsub['headers'],$unsubMatch)===1,'Header-free unsubscribe queues directly without an import wizard');
    $unsubJob=(int)$unsubMatch[1];while($store->job($unsubJob)['state']!=='complete')$store->processJob($unsubJob);
    audienceHttpExpect($store->person($target)['email_status']==='unsubscribed'&&$store->person($cleaned)['email_status']==='cleaned','Direct unsubscribe preserves cleaned suppression');
    audienceHttpExpect((int)$store->job($unsubJob)['total']===1&&(int)$store->one('SELECT COUNT(*) n FROM marketing_contacts')['n']===$countBefore,'Duplicates and unknown addresses do not create connections or repeated work');
    $jobsBefore=(int)$store->one('SELECT COUNT(*) n FROM marketing_jobs')['n'];
    $invalid=$sendProtected($editor,'audience.php',['action'=>'unsubscribe_emails','emails'=>"valid@example.test\nbad-address"]);
    audienceHttpExpect(str_contains($invalid['body'],'line 2')&&(int)$store->one('SELECT COUNT(*) n FROM marketing_jobs')['n']===$jobsBefore,'Invalid unsubscribe input creates no job and preserves entered values');
    require __DIR__.'/audience_connection_unlock_http.php';
    require __DIR__.'/audience_collection_publish_http.php';
    $unlock(null);echo 'Audience protected actions, unsubscribe, and counts: '.$checks." checks passed.\n";
}finally{foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);}
ob_end_flush();
