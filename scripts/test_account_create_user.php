<?php
/** Operator-only creation. Accepts the password on stdin; never logs/stores plaintext. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('DNR_ACCOUNT_KEY')!=='test-account' || getenv('DNR_ACCOUNT_MODE')!=='member') throw new RuntimeException('Test Account member CLI only.');
require '/var/www/html/config.php';require_once '/var/www/html/functions.php';
if (currentAccountProfile()['account_key']!=='test-account') throw new RuntimeException('Wrong database.');
$input=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);
$username=$input['username']??'TestAdmin';
$roles=['TestAdmin'=>'admin','TestEditor'=>'editor','TestReviewer'=>'reviewer'];
if (!isset($roles[$username])) throw new InvalidArgumentException('Choose an approved demo login.');
$role=$roles[$username];
$passwordHash=\Dnr\Security\PasswordPolicy::hash($input['password']);unset($input);
$conn->begin_transaction();
try {
    if($conn->execute_query('SELECT id FROM users WHERE username=?',[$username])->num_rows) throw new RuntimeException('Demo login already exists; credentials were preserved.');
    $conn->execute_query("INSERT INTO users (username,password,role,account_status,is_superadmin,platform_identity_id,two_factor_enabled,must_change_password,first_name,last_name,email,email_verified_at,activated_at,task_digest_enabled) VALUES (?, ?, ?,'active',0,NULL,0,0,'Test Account',?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),0)",[$username,$passwordHash,$role,ucfirst($role),strtolower($username).'@example.invalid']);
    $id=(int)$conn->insert_id;
    registerAccountLogin($conn,$id,$username);
    $profile=currentAccountProfile();$settings=$profile['settings'];
    if ($username==='TestAdmin') $settings['demo_password_only_user_id']=$id;
    else $settings['demo_password_only_user_ids'][$username]=$id;
    $conn->execute_query('UPDATE account_profile SET settings=?,version=version+1 WHERE id=1',[json_encode($settings,JSON_THROW_ON_ERROR)]);
    $conn->commit();flushAccountDirectory();
    echo json_encode(['id'=>$id,'username'=>$username,'role'=>$role,'is_superadmin'=>false,'platform_identity_id'=>null,'two_factor_enabled'=>false]),"\n";
}catch(Throwable $e){$conn->rollback();throw $e;}
