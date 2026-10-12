<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Dnr\Security\DemoAuthenticationPolicy;
$user=['id'=>2,'username'=>'TestAdmin','role'=>'admin','account_status'=>'active','is_superadmin'=>0,'platform_identity_id'=>null,'two_factor_enabled'=>0];
$profile=['account_key'=>'test-account','settings'=>['demo_password_only_user_id'=>2]];
$allows=static fn($u,$p=null,$key='test-account',$mode='member',$enabled=true)=>DemoAuthenticationPolicy::allows($u,$p ?? $profile,$key,$mode,$enabled);
if (!$allows($user)) throw new RuntimeException('Pinned ordinary demo Admin must be allowed.');
foreach (['id'=>3,'username'=>'testadmin','role'=>'editor','account_status'=>'inactive','is_superadmin'=>1,'platform_identity_id'=>4,'two_factor_enabled'=>1] as $field=>$value) {
    if ($allows(array_replace($user,[$field=>$value]))) throw new RuntimeException('Exception widened through '.$field);
}
foreach (['is_superadmin','platform_identity_id','two_factor_enabled','id'] as $field) {$u=$user;unset($u[$field]);if($allows($u))throw new RuntimeException('Missing field must fail closed: '.$field);}
foreach ([[],['account_key'=>'test-account','settings'=>[]],['account_key'=>'shalom-in-messiah','settings'=>['demo_password_only_user_id'=>2]],['account_key'=>'test-account','settings'=>['demo_password_only_user_id'=>'2']]] as $p) if($allows($user,$p))throw new RuntimeException('Unpinned/wrong profile must fail closed.');
if ($allows($user,$profile,'shalom-in-messiah') || $allows($user,$profile,'test-account','primary') || $allows($user,$profile,'test-account','member',false)) throw new RuntimeException('Other deployments must require 2FA.');
foreach (['TestEditor'=>'editor','TestReviewer'=>'reviewer'] as $name=>$role) {
    $u=array_replace($user,['id'=>4,'username'=>$name,'role'=>$role]);
    $p=$profile;$p['settings']['demo_password_only_user_ids']=[$name=>4];
    if (!$allows($u,$p) || $allows($u,$profile) || $allows(array_replace($u,['role'=>'admin']),$p)
        || $allows(array_replace($u,['is_superadmin'=>1]),$p)
        || $allows(array_replace($u,['two_factor_enabled'=>1]),$p)) throw new RuntimeException('Other demo role exception is not correctly pinned.');
}
foreach ([['two_factor_enabled'=>1],['username'=>'Changed'],['role'=>'editor']] as $change) {
    if (!DemoAuthenticationPolicy::isRestrictedUser(array_replace($user,$change),$profile,'test-account','member',true)) throw new RuntimeException('Mutable fields must not remove identity restrictions.');
}
if (DemoAuthenticationPolicy::isRestrictedUser(['id'=>3],$profile,'test-account','member',true)
    || DemoAuthenticationPolicy::isRestrictedUser($user,$profile,'test-account','primary',true)) throw new RuntimeException('Restrictions widened beyond TestAdmin.');
echo "PASS: demo exception is pinned by account, deployment mode, user ID, exact username, ordinary Admin role, lifecycle, and MFA state.\n";
