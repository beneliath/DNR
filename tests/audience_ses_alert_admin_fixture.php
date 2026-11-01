<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')throw new RuntimeException('Disposable suite required.');
require_once '/var/www/html/bootstrap.php';
$username='ses-alert-admin-'.bin2hex(random_bytes(5));
$conn->execute_query("INSERT INTO users(username,email,email_verified_at,password,role,account_status) VALUES (?,?,UTC_TIMESTAMP(),?,'admin','active')",[$username,$username.'@example.test',password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);
echo "Verified administrator fixture prepared; no email sent.\n";
