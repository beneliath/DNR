<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Audience data management checks skipped (disposable database required).\n");
$source=getenv('DNR_TEST_SOURCE_DIR')?:__DIR__.'/../src';
require_once $source.'/bootstrap.php';require_once $source.'/audience_store.php';
$db=applicationDatabaseConnection();$checks=0;$failures=[];
$expect=static function(bool $ok,string $why)use(&$checks,&$failures):void{++$checks;if(!$ok)$failures[]=$why;};
$newStore=static function()use($db):AudienceStore{
    $db->execute_query('INSERT INTO marketing_audiences(name,settings_json) VALUES (?,JSON_OBJECT())',['Data management '.bin2hex(random_bytes(6))]);
    return new AudienceStore($db,(int)$db->insert_id,1,'Data management fixture');
};
$store=$newStore();$foreign=$newStore();
$newPerson=static fn($s,$email)=>$s->savePerson(['email'=>$email,'first_name'=>'Preserve','email_status'=>'non-subscribed']);
$aid=(int)$store->audienceId;
$target=$newPerson($store,'ann@example.test');$neighbor=$newPerson($store,'joann@example.test');
$foreignTarget=$newPerson($foreign,'ann@example.test');
$queue=static fn($s,$id)=>$s->queueSelection('export','Data fixture',['c.audience_id=? AND c.id=?',[$s->audienceId,$id]]);
$targetExport=$queue($store,$target);$neighborExport=$queue($store,$neighbor);$foreignExport=$queue($foreign,$foreignTarget);
foreach([$targetExport,$neighborExport]as$job)$store->processJob($job);$foreign->processJob($foreignExport);
$parsed=['headers'=>['Email','Name'],'rows'=>[[' ANN@EXAMPLE.TEST ','Target'],['joann@example.test','Neighbor'],['other@example.test','ann@example.test']]];
$import=$store->createImport($parsed,'Mapped pending import');
$store->configureImport($import,['mapping'=>['email','first_name']],false);
$draft=$store->createImport(['headers'=>['Email'],'rows'=>[['ann@example.test'],['joann@example.test']]],'Unmapped draft');
$paged=$store->createImport(['headers'=>['Email'],'rows'=>array_fill(0,205,['ann@example.test'])],'Paged redaction');
$before=$store->one('SELECT payload_json,result_json,state FROM marketing_job_items WHERE job_id=?',[$neighborExport]);
$store->mutatePerson($target,(int)$store->person($target)['version'],'delete');
$item=static fn($s,$job,$row=0)=>$s->one('SELECT payload_json,result_json,state FROM marketing_job_items WHERE job_id=? ORDER BY source_row LIMIT 1 OFFSET ?',[$job,$row]);
$expect($item($store,$targetExport)['state']==='skipped'&&$item($store,$targetExport)['result_json']===null,'Deleted contact export data is removed');
$expect($item($store,$neighborExport)===$before,'Deleting ann must preserve the completed export of joann');
$expect($item($store,$import)['state']==='skipped','Pending import matches a normalized complete email');
$expect($item($store,$import,1)['state']==='pending','Pending import with an email suffix collision survives');
$expect($item($store,$import,2)['state']==='pending','Mapped email identity takes precedence over a matching name cell');
$expect($item($store,$draft)['state']==='skipped'&&$item($store,$draft,1)['state']==='pending','Unmapped drafts redact complete cells without suffix collisions');
$expect($item($foreign,$foreignExport)['state']==='exported','Deletion cannot redact a different audience');
$expect((bool)$store->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=?',[$aid,hash('sha256','ann@example.test')]),'Permanent deletion retains the exact reimport suppression hash');
$expect($store->person($neighbor)['email']==='joann@example.test','Neighbor contact remains intact');
$expect((int)$store->one("SELECT COUNT(*) n FROM marketing_job_items WHERE job_id=? AND state='skipped'",[$paged])['n']===205,'Redaction visits every batch without skipping rows');
$expect($store->permissionEvent($target)===null&&$store->permissionEvent($foreignTarget)===null,'Permission history excludes deleted and foreign records');

// All erasure must roll back with the surrounding transaction.
$rollback=$newPerson($store,'rollback@example.test');$rollbackJob=$queue($store,$rollback);
try{$store->transaction(function()use($store,$rollback):void{$store->mutatePerson($rollback,1,'delete');throw new RuntimeException('Rollback fixture');});}catch(RuntimeException $e){if($e->getMessage()!=='Rollback fixture')throw $e;}
$expect($store->person($rollback)['email']==='rollback@example.test'&&$item($store,$rollbackJob)['state']==='pending','Failed deletion rolls back contact and job erasure together');
$expect(!$store->one('SELECT email_hash FROM marketing_deleted_emails WHERE audience_id=? AND email_hash=?',[$aid,hash('sha256','rollback@example.test')]),'Failed deletion does not leave a permanent suppression hash');

// A literal email may contain SQL LIKE wildcard and JSON-escaped characters.
foreach(['x_%@example.test','a/b@example.test']as$email){
    $id=$newPerson($store,$email);$job=$store->createImport(['headers'=>['Email'],'rows'=>[["\t".strtoupper($email)."\n"],['other@example.test']]],'Literal identity');
    $store->mutatePerson($id,1,'delete');
    $expect($item($store,$job)['state']==='skipped'&&$item($store,$job,1)['state']==='pending','Literal normalized identity redaction: '.$email);
}

// These are the native event shapes written by unsubscribe, SES, confirmation,
// and collection conversion paths; later signup attempts are not new consent.
$cases=[
    ['status',['from'=>'subscribed','to'=>'unsubscribed','source'=>'Campaign unsubscribe'],'unsubscribed','UNSUB_TIME'],
    ['status',['from'=>'subscribed','to'=>'cleaned','source'=>'hard-bounce'],'cleaned','CLEAN_TIME'],
    ['status',['from'=>'subscribed','to'=>'unsubscribed','source'=>'complaint'],'unsubscribed','UNSUB_TIME'],
    ['subscription-confirmed',['from'=>'unsubscribed','to'=>'subscribed','source'=>'Delivered single-use confirmation'],'subscribed','CONFIRM_TIME'],
    ['collection-converted',['method'=>'single','resource_id'=>1],'subscribed','OPTIN_TIME'],
    ['collection-converted',['method'=>'double','resource_id'=>1],'subscribed','CONFIRM_TIME'],
];
foreach($cases as$n=>[$type,$details,$status,$column]){
    $id=$newPerson($store,'history-'.$n.'@example.test');
    $store->event($id,'contact_imported',['source_metadata'=>['OPTIN_TIME'=>'2020-01-01 00:00:00','OPTIN_IP'=>'198.51.100.1','CONFIRM_TIME'=>'2020-01-02 00:00:00','CONFIRM_IP'=>'198.51.100.2','UNSUB_TIME'=>'2020-02-01 00:00:00','CLEAN_TIME'=>'2020-03-01 00:00:00','UNSUB_CAMPAIGN_ID'=>'old','CLEAN_CAMPAIGN_ID'=>'old']]);
    $store->write('UPDATE marketing_contacts SET email_status=? WHERE audience_id=? AND id=?',[$status,$aid,$id]);
    $store->event($id,$type,$details);$eventId=(int)$db->insert_id;$at='2026-10-20 12:34:56';
    $store->write('UPDATE marketing_contact_events SET created_at=? WHERE id=?',[$at,$eventId]);
    $store->event($id,'signup-permission',['opt_in'=>'single','permission'=>'Repeat submission']);
    $permission=$store->permissionEvent($id);
    $importEvent=$store->one("SELECT * FROM marketing_contact_events WHERE contact_id=? AND event_type='contact_imported' ORDER BY id DESC LIMIT 1",[$id]);
    $profile=audienceMarketingDetails($store->person($id),$permission,$importEvent);
    $expect((int)($permission['id']??0)===$eventId&&$profile['datetime']==='2026-10-20T12:34:56Z','Profile uses the actual native transition and ignores repeat signup attempts: '.$type);
    $job=$queue($store,$id);$captured=$store->job($job);
    $store->event($id,'subscription_changed',['to'=>'unsubscribed']);
    $store->processJob($job);
    $result=audienceJson($item($store,$job)['result_json']);$row=array_combine(audienceExportHeaders($captured['params'],$status),$result['row']);
    $expect($row[$column]===$at,'Native '.$type.' '.($details['source']??$details['method']??'').' exports its actual event date');
    if($status==='subscribed'){
        $expect($row['OPTIN_IP']===''&&$row['CONFIRM_IP']==='','New native consent cannot inherit old provider IP evidence: '.$type);
        if(($details['method']??'')==='single')$expect($row['CONFIRM_TIME']==='','Single opt-in cannot inherit an old confirmation date');
    }else $expect($row[($status==='cleaned'?'CLEAN':'UNSUB').'_CAMPAIGN_ID']==='','Native suppression cannot inherit an old provider campaign');
}
echo "Audience data management: $checks checks, ".count($failures)." failures.\n";
foreach($failures as$failure)echo 'FAIL: '.$failure."\n";
if($failures)exit(1);
