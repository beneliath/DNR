<?php
declare(strict_types=1);
// A restricted account must not even claim queued campaign recipients.
function accountSecurityAccessAllowed():bool{return false;}
require_once __DIR__.'/../src/audience_dispatch_worker.php';
$db=mysqli_init();
if(!$db||processAudienceCampaignDelivery($db,10,static fn()=>throw new RuntimeException('Demo transport invoked'))!==0)throw new RuntimeException('Restricted account dispatch was not blocked.');
if(processAudienceServiceMail($db,10,static fn()=>throw new RuntimeException('Demo service transport invoked'))!==0)throw new RuntimeException('Restricted service mail was not blocked.');
echo "Restricted account campaign worker guard passed.\n";
