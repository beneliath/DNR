<?php
// Included by the disposable admin-unlock suite, using its synthetic sessions.
$connectionSend=static function(callable $client,array $input):array{
    $path='audience.php?view=contact&id='.$input['id'];$page=$client($path);
    return $client($path,['csrf_token'=>audienceHttpCsrf($page)]+$input);
};
foreach(['single','bulk']as$mode){
    $personId=$store->savePerson(['email'=>'connection-delete-'.$mode.'-'.$suffix.'@example.test','email_status'=>'non-subscribed']);
    $input=['action'=>$mode==='single'?'person_action':'bulk','id'=>$personId,'version'=>1,'operation'=>'delete','confirm_text'=>'DELETE','selected'=>[$personId],'selection_scope'=>'selected'];
    $jobsBefore=(int)$store->one('SELECT COUNT(*) n FROM marketing_jobs')['n'];
    $intact=static fn():bool=>(bool)$store->one('SELECT id FROM marketing_contacts WHERE id=? AND deleted_at IS NULL',[$personId]);
    foreach([$editor,$reviewer]as$client)audienceHttpExpect($connectionSend($client,$input)['status']===403&&$intact(),$mode.' connection deletion requires an admin');
    foreach([null,time()-301]as$at){
        $unlock($at);$response=$connectionSend($admin,$input);
        audienceHttpExpect($response['status']===302&&str_contains($response['headers'],'admin_elevation.php?')&&$intact(),$mode.' direct deletion requires a current Admin Unlock');
        audienceHttpExpect((int)$store->one('SELECT COUNT(*) n FROM marketing_jobs')['n']===$jobsBefore,$mode.' locked deletion does not enqueue work');
    }
    $unlock(time());
    $invalid=$admin('audience.php',['csrf_token'=>'invalid']+$input);
    audienceHttpExpect($invalid['status']===400&&$intact(),$mode.' connection deletion still enforces CSRF');
    $invalid=$connectionSend($admin,array_replace($input,['confirm_text'=>'']));
    audienceHttpExpect(str_contains($invalid['body'],'type DELETE')&&$intact(),$mode.' unlocked deletion still requires typed confirmation');
    $response=$connectionSend($admin,$input);
    audienceHttpExpect($response['status']===302&&!str_contains($response['headers'],'admin_elevation.php'),$mode.' unlocked admin can authorize deletion');
    if($mode==='single')audienceHttpExpect(!$intact(),'Unlocked single deletion removes personal data');
    else{
        $job=$store->one('SELECT params_json FROM marketing_jobs ORDER BY id DESC LIMIT 1');
        audienceHttpExpect((int)$store->one('SELECT COUNT(*) n FROM marketing_jobs')['n']===$jobsBefore+1&&audienceJson($job['params_json'])['action']==='delete','Unlocked bulk deletion queues exactly one approved job');
    }
}
$unlock(null);
