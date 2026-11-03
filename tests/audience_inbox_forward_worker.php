<?php
declare(strict_types=1);
if(getenv('DNR_INTEGRATION_TARGET')!=='disposable'||getenv('MYSQL_USER')!=='dnrmaildispatch')exit(1);
require '/var/www/html/bootstrap.php';
require_once '/var/www/html/audience_store.php';
require_once '/var/www/html/audience_delivery_lifecycle.php';
$address=trim((string)fgets(STDIN));
if(!preg_match('/\A127\.0\.0\.1:([0-9]+)\z/D',$address,$port))throw new RuntimeException('Expected the loopback SMTP receiver.');
putenv('DNR_SMTP_HOST=127.0.0.1');putenv('DNR_SMTP_PORT='.$port[1]);putenv('DNR_SMTP_ENCRYPTION=none');
putenv('DNR_MAIL_FROM=moed-relay@example.test');putenv('DNR_MAIL_TRANSPORT=smtp');
$db=applicationDatabaseConnection();$s=new AudienceStore($db,1,0,'SMTP worker qualification');
$jobs=$s->all("SELECT s.id,s.message_id,f.recipient_email FROM marketing_inbox_forwards f
    JOIN marketing_service_mail s ON s.id=f.service_mail_id AND s.audience_id=f.audience_id
    JOIN marketing_inbox_messages m ON m.id=f.message_id AND m.audience_id=f.audience_id
    WHERE f.audience_id=1 AND s.state='queued' AND m.email_subject='SMTP forwarding fixture' ORDER BY s.id");
if(count($jobs)!==2)throw new RuntimeException('Missing SMTP forward fixtures.');
$ids=array_column($jobs,'id');
$prior=$s->all("SELECT id,next_attempt_at FROM marketing_service_mail WHERE state='queued' AND id NOT IN (?,?)",$ids);
$enabled=$s->sesSettings()['enabled'];
try {
    $s->write('UPDATE marketing_ses_settings SET enabled=0 WHERE audience_id=1');
    $s->write("UPDATE marketing_service_mail SET next_attempt_at=IF(id IN (?,?),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 DAY)) WHERE state='queued'",$ids);
    $sesCalls=0;
    processAudienceServiceMail($db,2,static function()use(&$sesCalls){++$sesCalls;throw new RuntimeException('Unexpected SES dispatch');});
    foreach($jobs as $i=>$job){
        $state=$s->one('SELECT state,ses_correlation FROM marketing_service_mail WHERE id=?',[$job['id']]);
        if($sesCalls!==0||$state['state']!==($i===0?'accepted':'uncertain')||$state['ses_correlation']!==null)throw new RuntimeException('Wrong SMTP receipt: '.json_encode($state));
        $wire=json_decode((string)fgets(STDIN),true,32,JSON_THROW_ON_ERROR);
        if(!in_array('MAIL FROM:<moed-relay@example.test>',$wire['envelope'],true)
            ||array_values(preg_grep('/^RCPT TO:/',$wire['envelope']))!==['RCPT TO:<'.$job['recipient_email'].'>']
            ||!str_contains($wire['data'],'Message-ID: '.$job['message_id'])
            ||!str_contains($wire['data'],base64_encode('Fwd: SMTP forwarding fixture'))
            ||!str_contains($wire['data'],'Original SMTP fixture body')
            ||!str_contains($wire['data'],'filename="Small_image.png"')
            ||!str_contains(str_replace("\r\n",'',$wire['data']),'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=')
        )throw new RuntimeException('The MOED SMTP envelope, content or attachment changed.');
        if(!$s->one("SELECT id FROM marketing_relay_usage WHERE message_id=? AND lane='operational'",[$job['message_id']]))throw new RuntimeException('Forward bypassed the operational mail allowance.');
    }
    if(processAudienceServiceMail($db,1)!==0)throw new RuntimeException('Replayed an uncertain forward.');
}finally{
    $s->write('UPDATE marketing_ses_settings SET enabled=? WHERE audience_id=1',[$enabled]);
    foreach($prior as $old)$s->write('UPDATE marketing_service_mail SET next_attempt_at=? WHERE id=?',[$old['next_attempt_at'],$old['id']]);
}
echo "Inbox forwards used MOED SMTP with SES disabled. Configured sender, separate recipients, attachment, relay budget and uncertain receipt verified.\n";
