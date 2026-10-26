<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('DNR_MANUAL_FIXTURE')!=='disposable'||getenv('DNR_ACCOUNT_KEY')!=='message-monkey-guide')exit(64);
require '/var/www/html/bootstrap.php';
require '/var/www/html/two_factor_helpers.php';
$db=applicationDatabaseConnection();$password=bin2hex(random_bytes(20));$secret=generateTotpSecret();
$db->execute_query("INSERT INTO users(username,password,first_name,last_name,email,email_verified_at,role,two_factor_enabled,totp_secret_encrypted,totp_confirmed_at) VALUES ('avery.guide',?,'Avery','Morgan','avery@example.test',UTC_TIMESTAMP(),'admin',1,?,UTC_TIMESTAMP())",[password_hash($password,PASSWORD_DEFAULT),encryptTwoFactorSecret($secret)]);
echo json_encode(['username'=>'avery.guide','password'=>$password,'totp_secret'=>$secret],JSON_THROW_ON_ERROR),PHP_EOL;
