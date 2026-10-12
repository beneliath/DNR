<?php
/** Operator-only creation. Accepts the password on stdin; never logs/stores plaintext. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('DNR_ACCOUNT_KEY')!=='test-account' || getenv('DNR_ACCOUNT_MODE')!=='member') throw new RuntimeException('Test Account member CLI only.');
require '/var/www/html/config.php';require_once '/var/www/html/functions.php';
if (currentAccountProfile()['account_key']!=='test-account') throw new RuntimeException('Wrong database.');
$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
$passwordHash=\Dnr\Security\PasswordPolicy::hash($input['password']);unset($input);
$conn->begin_transaction();
try {
    if($conn->query("SELECT id FROM users WHERE username='TestAdmin'")->num_rows) throw new RuntimeException('TestAdmin already exists; credentials were preserved.');
    $conn->execute_query("INSERT INTO users (username,password,role,account_status,is_superadmin,platform_identity_id,two_factor_enabled,must_change_password,first_name,last_name,email,email_verified_at,activated_at,task_digest_enabled) VALUES ('TestAdmin',?,'admin','active',0,NULL,0,0,'Test Account','Administrator','testadmin@example.invalid',UTC_TIMESTAMP(),UTC_TIMESTAMP(),0)",[$passwordHash]);
    $id=(int)$conn->insert_id;
    registerAccountLogin($conn,$id,'TestAdmin');
    $conn->execute_query("UPDATE account_profile SET settings=JSON_SET(settings,'$.demo_password_only_user_id',CAST(? AS UNSIGNED)),version=version+1 WHERE id=1",[$id]);
    $conn->commit();flushAccountDirectory();
    echo json_encode(['id'=>$id,'username'=>'TestAdmin','role'=>'admin','is_superadmin'=>false,'platform_identity_id'=>null,'two_factor_enabled'=>false]),"\n";
}catch(Throwable $e){$conn->rollback();throw $e;}
