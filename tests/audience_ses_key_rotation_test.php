<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
require_once '/var/www/html/bootstrap.php';require_once '/var/www/html/key_rotation_helpers.php';
$db=applicationDatabaseConnection();if(!str_starts_with($db->query('SELECT CURRENT_USER() u')->fetch_assoc()['u'],'dnrmaintenance@'))throw new RuntimeException('Dedicated maintenance role required.');
$ring=tempnam(sys_get_temp_dir(),'ses-keyring-');try{
 $old=base64_decode(configurationSecret('DNR_2FA_ENCRYPTION_KEY'),true);file_put_contents($ring,json_encode(['active'=>'new','legacy'=>'old','keys'=>['old'=>base64_encode($old),'new'=>base64_encode(random_bytes(32))]]));chmod($ring,0600);putenv('DNR_APPLICATION_KEYRING_FILE='.$ring);
 $inboxPlain=json_encode(['fixture'=>'SNS key rotation']);$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$inboxCipher='dnr1:old:'.base64_encode($nonce.sodium_crypto_secretbox($inboxPlain,$nonce,$old));$db->execute_query("INSERT INTO marketing_ses_inbox(audience_id,generation,payload_hash,payload_ciphertext,state) SELECT audience_id,generation,?,?,'rejected' FROM marketing_ses_settings WHERE audience_id=1",[bin2hex(random_bytes(32)),$inboxCipher]);
 $cipher=$db->query('SELECT credential_ciphertext FROM marketing_ses_credentials WHERE audience_id=1')->fetch_assoc()['credential_ciphertext'];$plain=\Dnr\Security\ApplicationKey::open($cipher);
 $dry=rotateApplicationKeyBatch($db,'marketing_ses_credentials',0,100,false);if($dry['scanned']!==1||$dry['needs_rotation']!==1||$dry['rewrapped']!==0)throw new RuntimeException('SES rotation dry-run failed.');
 if(rotateApplicationKeyBatch($db,'marketing_ses_credentials',0,100,true)['rewrapped']!==1)throw new RuntimeException('SES credentials were not rewrapped.');
 $after=$db->query('SELECT credential_ciphertext FROM marketing_ses_credentials WHERE audience_id=1')->fetch_assoc()['credential_ciphertext'];if(!str_starts_with($after,'dnr1:new:')||\Dnr\Security\ApplicationKey::open($after)!==$plain)throw new RuntimeException('SES key rotation changed the account-bound credentials.');
 if(rotateApplicationKeyBatch($db,'marketing_ses_credentials',0,100,true)['rewrapped']!==0)throw new RuntimeException('SES key rotation is not idempotent.');
 if(rotateApplicationKeyBatch($db,'marketing_ses_inbox',0,100,true)['rewrapped']!==1)throw new RuntimeException('Queued SNS envelope was not rewrapped.');
 $rotated=$db->query("SELECT payload_ciphertext FROM marketing_ses_inbox WHERE payload_ciphertext<>''")->fetch_assoc()['payload_ciphertext'];if(\Dnr\Security\ApplicationKey::open($rotated)!==$inboxPlain)throw new RuntimeException('SNS rotation changed its account envelope.');
 $collectionPlain=[];foreach($db->query('SELECT id,payload_ciphertext FROM marketing_collection_responses')->fetch_all(MYSQLI_ASSOC)as$response)$collectionPlain[(int)$response['id']]=\Dnr\Security\ApplicationKey::open($response['payload_ciphertext']);
 $collectionBatch=rotateApplicationKeyBatch($db,'marketing_collection_responses',0,500,true);if($collectionBatch['rewrapped']!==count($collectionPlain)||rotateApplicationKeyBatch($db,'marketing_collection_responses',0,500,true)['rewrapped']!==0)throw new RuntimeException('Collection response key rotation was not complete and idempotent.');
 foreach($db->query('SELECT id,payload_ciphertext FROM marketing_collection_responses')->fetch_all(MYSQLI_ASSOC)as$response)if(\Dnr\Security\ApplicationKey::open($response['payload_ciphertext'])!==$collectionPlain[(int)$response['id']])throw new RuntimeException('Collection key rotation changed a response.');
 $check=applicationKeyRetirementCheck($db,'old');if($check['live_dependencies']['marketing_ses_credentials']!==0||$check['live_dependencies']['marketing_collection_responses']!==0||$check['live_dependencies']['marketing_campaign_deliveries']===0)throw new RuntimeException('Retirement audit missed retained campaign, collection or credential ciphertext.');
 echo "SES encrypted-credential rotation, empty rows, account binding and retirement checks passed.\n";
}finally{if(is_file($ring))unlink($ring);}
