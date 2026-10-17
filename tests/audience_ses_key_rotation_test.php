<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
require_once '/var/www/html/bootstrap.php';require_once '/var/www/html/key_rotation_helpers.php';
$db=applicationDatabaseConnection();if(!str_starts_with($db->query('SELECT CURRENT_USER() u')->fetch_assoc()['u'],'dnrmaintenance@'))throw new RuntimeException('Dedicated maintenance role required.');
$ring=tempnam(sys_get_temp_dir(),'ses-keyring-');try{
 $old=base64_decode(configurationSecret('DNR_2FA_ENCRYPTION_KEY'),true);file_put_contents($ring,json_encode(['active'=>'new','legacy'=>'old','keys'=>['old'=>base64_encode($old),'new'=>base64_encode(random_bytes(32))]]));chmod($ring,0600);putenv('DNR_APPLICATION_KEYRING_FILE='.$ring);
 $cipher=$db->query('SELECT credential_ciphertext FROM marketing_ses_credentials WHERE audience_id=1')->fetch_assoc()['credential_ciphertext'];$plain=\Dnr\Security\ApplicationKey::open($cipher);
 $dry=rotateApplicationKeyBatch($db,'marketing_ses_credentials',0,100,false);if($dry['scanned']!==1||$dry['needs_rotation']!==1||$dry['rewrapped']!==0)throw new RuntimeException('SES rotation dry-run failed.');
 if(rotateApplicationKeyBatch($db,'marketing_ses_credentials',0,100,true)['rewrapped']!==1)throw new RuntimeException('SES credentials were not rewrapped.');
 $after=$db->query('SELECT credential_ciphertext FROM marketing_ses_credentials WHERE audience_id=1')->fetch_assoc()['credential_ciphertext'];if(!str_starts_with($after,'dnr1:new:')||\Dnr\Security\ApplicationKey::open($after)!==$plain)throw new RuntimeException('SES key rotation changed the account-bound credentials.');
 if(rotateApplicationKeyBatch($db,'marketing_ses_credentials',0,100,true)['rewrapped']!==0)throw new RuntimeException('SES key rotation is not idempotent.');
 $check=applicationKeyRetirementCheck($db,'old');if($check['live_dependencies']['marketing_ses_credentials']!==0||$check['live_dependencies']['marketing_campaign_deliveries']===0)throw new RuntimeException('Retirement audit missed retained campaign or credential ciphertext.');
 echo "SES encrypted-credential rotation, empty rows, account binding and retirement checks passed.\n";
}finally{if(is_file($ring))unlink($ring);}
