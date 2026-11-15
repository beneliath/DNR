<?php
$hardBounce=($detail['source']??'')==='hard-bounce'||$event['event_type']==='email_hard-bounce';
$softBounce=$event['event_type']==='email_soft-bounce';
$wasCleaned=($detail['to']??'')==='cleaned'&&($hardBounce||($detail['from']??'')!=='cleaned');
if($hardBounce||$softBounce||$wasCleaned):
    $bounce=is_array($detail['bounce']??null)?$detail['bounce']:[];
    $reason=$hardBounce?'The email provider reported a permanent delivery failure (hard bounce).':
        ($softBounce?'The email provider reported a temporary delivery failure (soft bounce).':'The cleaning reason was not recorded.');
    $diagnostics=[];
    foreach(['type'=>'Bounce type','subtype'=>'Bounce subtype','status'=>'SMTP status','action'=>'Delivery action',
        'reporting_mta'=>'Reporting mail server','remote_mta_ip'=>'Remote mail server IP','feedback_id'=>'SES feedback ID']as$key=>$label){
        if(is_string($bounce[$key]??null)&&$bounce[$key]!=='')$diagnostics[$label]=$bounce[$key];
    }
    if(!empty($bounce['occurred_at']))$diagnostics['Bounce time']=audienceProfileDate($bounce['occurred_at']);
    if(is_string($detail['message_id']??null)&&$detail['message_id']!=='')$diagnostics['SES message ID']=$detail['message_id'];
?>
<p><strong><?= $wasCleaned?'Cleaning reason:':'Delivery reason:' ?></strong> <?= $h($reason) ?></p>
<?php if(is_string($bounce['diagnostic_code']??null)&&$bounce['diagnostic_code']!==''): ?>
    <p class="preserve-lines"><strong>Server response:</strong> <?= $h($bounce['diagnostic_code']) ?></p>
<?php elseif($hardBounce||$softBounce): ?>
    <p class="audience-help"><?= $bounce?'The email provider did not supply a detailed server response.':'Detailed bounce diagnostics were not recorded for this event.' ?></p>
<?php endif; ?>
<?php if($diagnostics): ?><details><summary>Delivery diagnostics</summary><dl class="settings-details">
    <?php foreach($diagnostics as$label=>$value): ?><dt><?= $h($label) ?></dt><dd><?= $h($value) ?></dd><?php endforeach; ?>
</dl></details><?php endif; ?>
<?php endif; ?>
