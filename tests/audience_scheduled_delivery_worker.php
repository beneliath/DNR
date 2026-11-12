<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TEST')!=='1'||getenv('DNR_INTEGRATION_TARGET')!=='disposable')exit("Disposable suite required.\n");
require_once '/var/www/html/bootstrap.php';
require_once '/var/www/html/audience_dispatch_worker.php';

$db=applicationDatabaseConnection();
if(!str_starts_with($db->query('SELECT CURRENT_USER() u')->fetch_assoc()['u'],'dnrmaildispatch@'))throw new RuntimeException('Dedicated mail role required.');
// The preceding Inbox suite leaves its fictional SES account in sandbox mode.
$db->query("UPDATE marketing_ses_settings SET production_access=1,check_state='ready',checked_at=UTC_TIMESTAMP() WHERE audience_id=1");
$sent=[];
processAudienceCampaignDelivery($db,5,static function(array $message)use(&$sent):string{$sent[]=$message['email'];return 'accepted';});
if($sent!==['dispatch5@example.test']){
    $job=$db->query("SELECT d.state,d.error_message FROM marketing_campaign_deliveries d JOIN marketing_campaigns c ON c.id=d.campaign_id WHERE c.name='Dedicated worker dispatch fixture' ORDER BY d.id DESC LIMIT 1")->fetch_assoc();
    throw new RuntimeException('Unexpected scheduled segment result: '.json_encode([$sent,$job]));
}
echo "Dedicated dispatch role selected the scheduled segment and sent it to the test transport.\n";
