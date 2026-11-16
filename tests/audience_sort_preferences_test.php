<?php
declare(strict_types=1);
require_once __DIR__.'/../src/functions.php';
require_once __DIR__.'/../src/audience_helpers.php';

$checks=0;
function sortPreferenceExpect(bool $ok,string $message):void
{
    global $checks;
    if(!$ok)throw new RuntimeException($message);
    $checks++;
}
$columns=['email','first_name','last_name','email_status','source','created_at','updated_at','custom:12'];
$default=['sort'=>'created_at','direction'=>'desc'];
$selected=['sort'=>'updated_at','direction'=>'desc'];
$account=getenv('DNR_ACCOUNT_KEY');
try{
    putenv('DNR_ACCOUNT_KEY=sort-preference-one');
    $_COOKIE=[];
    sortPreferenceExpect(audienceConnectionSortPreference([],$columns,31)===$default&&$_COOKIE===[],'An unchosen default is not saved');
    sortPreferenceExpect(audienceConnectionSortPreference($selected,$columns,31)===$selected,'The requested sort is applied');
    $cookieName=array_key_first($_COOKIE);$savedCookie=$_COOKIE[$cookieName];
    foreach([[],['view'=>'contacts'],['archived'=>'1'],['q'=>'example'],['status'=>['pending']],['tags'=>[7]],['segment'=>3],['page'=>2,'per_page'=>50]]as$request){
        sortPreferenceExpect(audienceConnectionSortPreference($request,$columns,31)===$selected,'Navigation and filtering restore the selected column and direction');
    }
    foreach([['sort'=>[]],['sort'=>'unavailable'],['sort'=>'custom:99'],['sort'=>'email','direction'=>[]],['sort'=>'email','direction'=>'invalid']]as$request){
        sortPreferenceExpect(audienceConnectionSortPreference($request,$columns,31)===$selected&&$_COOKIE[$cookieName]===$savedCookie,'Invalid requests cannot replace the saved sort');
    }
    sortPreferenceExpect(audienceConnectionSortPreference([],$columns,32)===$default,'Another user does not inherit the preference');
    putenv('DNR_ACCOUNT_KEY=sort-preference-two');
    sortPreferenceExpect(audienceConnectionSortPreference([],$columns,31)===$default,'Another account does not inherit the preference');
    audienceConnectionSortPreference(['sort'=>'email','direction'=>'asc'],$columns,31);
    putenv('DNR_ACCOUNT_KEY=sort-preference-one');
    sortPreferenceExpect(audienceConnectionSortPreference([],$columns,31)===$selected,'The first account retains its own preference');
    foreach(['asc','desc']as$direction){
        $choice=['sort'=>'last_name','direction'=>$direction];
        audienceConnectionSortPreference($choice,$columns,31);
        sortPreferenceExpect(audienceConnectionSortPreference([],$columns,31)===$choice,'New column selections and direction toggles are remembered');
    }
    $custom=['sort'=>'custom:12','direction'=>'asc'];
    audienceConnectionSortPreference($custom,$columns,31);
    sortPreferenceExpect(audienceConnectionSortPreference([],$columns,31)===$custom,'Available custom date columns are remembered');
    sortPreferenceExpect(audienceConnectionSortPreference([],array_diff($columns,['custom:12']),31)===$default,'A removed custom field falls back to the default sort');
    foreach([[], 'broken JSON', 'null', '12', '[]', '{"sort":[],"direction":"asc"}', '{"sort":"email","direction":"invalid"}']as$invalid){
        $_COOKIE[$cookieName]=$invalid;
        sortPreferenceExpect(audienceConnectionSortPreference([],$columns,31)===$default,'Malformed cookies fall back safely');
    }
    $_COOKIE=[];
    sortPreferenceExpect(audienceConnectionSortPreference($selected,$columns,0)===$selected&&$_COOKIE===[],'Non-Connections views do not save a preference');
    sortPreferenceExpect(audienceConnectionSortPreference(['sort'=>'email'],$columns,31)===['sort'=>'email','direction'=>'desc'],'Legacy links without a direction retain the default direction');
}finally{
    putenv($account===false?'DNR_ACCOUNT_KEY':'DNR_ACCOUNT_KEY='.$account);
}
echo "Connections sort preferences: $checks checks passed.\n";
