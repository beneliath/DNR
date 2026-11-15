<?php
// Included after the signed SES campaign and service-mail fixtures are ready.
(static function()use($campaignStore,$runtimeRows,$sesEvent,$signSes,$fetch,$editor,$ses):void{
    $recipient=$runtimeRows[1];$contactId=(int)$recipient['contact_id'];
    $bounce=$sesEvent($recipient,'Bounce');$email=$bounce['mail']['destination'][0];
    $diagnostic='smtp; 550 <'.$email.'> mailbox unavailable <script>fixture</script>';
    $bounce['bounce']=['bounceType'=>'Permanent','bounceSubType'=>'NoEmail','timestamp'=>gmdate('c'),
        'reportingMTA'=>'dns; mx.example.test','remoteMtaIp'=>'192.0.2.10','feedbackId'=>'diagnostic-feedback',
        'bouncedRecipients'=>[['emailAddress'=>'unrelated@example.test','diagnosticCode'=>'OTHER-RECIPIENT-PRIVATE'],
            ['emailAddress'=>$email,'action'=>'failed','status'=>'5.1.1','diagnosticCode'=>$diagnostic]]];
    $bounce['mail']['headers']=[['name'=>'X-Private','value'=>'RAW-HEADER-PRIVATE']];
    $signed=$signSes($bounce,'ses-detailed-bounce');
    audienceHttpExpect(audienceSnsReceive($campaignStore,$signed,$fetch)==='accepted'
        &&audienceSnsReceive($campaignStore,$signed,$fetch)==='duplicate','Detailed signed bounce is processed once');
    $status=$campaignStore->one("SELECT details_json FROM marketing_contact_events WHERE contact_id=? AND event_type='status' ORDER BY id DESC LIMIT 1",[$contactId]);
    $message=$campaignStore->one('SELECT details_json FROM marketing_message_events WHERE recipient_id=? AND event_key=?',[$recipient['id'],hash('sha256','ses:ses-detailed-bounce')]);
    foreach([$status,$message]as$row){
        $details=audienceJson($row['details_json']);
        audienceHttpExpect(($details['bounce']['diagnostic_code']??'')===$diagnostic&&($details['bounce']['status']??'')==='5.1.1'
            &&($details['bounce']['subtype']??'')==='NoEmail'&&($details['message_id']??'')===$bounce['mail']['messageId']
            &&!str_contains($row['details_json'],'OTHER-RECIPIENT-PRIVATE')&&!str_contains($row['details_json'],'RAW-HEADER-PRIVATE'),
            'Campaign and contact history retain only the matched bounce diagnostics');
    }
    $activity=$campaignStore->personActivity($contactId,'email_hard-bounce',0,0,1,false);
    audienceHttpExpect(count(array_filter($activity['rows'],static fn($row)=>(audienceJson($row['details_json'])['bounce']['diagnostic_code']??'')===$diagnostic))===1,
        'Filtered campaign bounce history preserves the original diagnostics');
    // Simulate the status-only records written before this feature.
    $campaignStore->write("INSERT INTO marketing_contact_events(contact_id,actor_label,actor_type,event_type,details_json) VALUES (?,'Legacy provider','provider','status',?)",
        [$contactId,json_encode(['from'=>'subscribed','to'=>'cleaned','source'=>'hard-bounce'],JSON_THROW_ON_ERROR)]);
    $profile=$editor('audience.php?view=contact&id='.$contactId);
    audienceHttpExpect($profile['status']===200&&str_contains($profile['body'],'Cleaning reason:')
        &&str_contains($profile['body'],htmlspecialchars($diagnostic,ENT_QUOTES,'UTF-8'))
        &&str_contains($profile['body'],'5.1.1')&&str_contains($profile['body'],'NoEmail')
        &&str_contains($profile['body'],'Detailed bounce diagnostics were not recorded for this event.')
        &&!str_contains($profile['body'],'<script>fixture</script>')&&!preg_match('/Warning:|Fatal error:/',$profile['body']),
        'Contact activity shows escaped cleaning reasons and an honest fallback for older history');

    // Keep the shared fixture unchanged after destructive privacy and suppression checks.
    $rollback=new RuntimeException('Roll back bounce diagnostic fixtures');
    try{$campaignStore->transaction(static function()use($campaignStore,$runtimeRows,$sesEvent,$signSes,$fetch,$ses,$contactId,$recipient,$bounce,$rollback):void{
        $softRecipient=$runtimeRows[5];$softContact=$campaignStore->person((int)$softRecipient['contact_id']);
        $soft=$sesEvent($softRecipient,'Bounce');
        $soft['bounce']=['bounceType'=>'Transient','bounceSubType'=>'MailboxFull','timestamp'=>gmdate('c'),
            'bouncedRecipients'=>[['emailAddress'=>$softContact['email'],'status'=>'4.2.2','diagnosticCode'=>'smtp; 452 mailbox full']]];
        audienceSnsReceive($campaignStore,$signSes($soft,'ses-detailed-soft-bounce'),$fetch);
        $softHistory=$campaignStore->one("SELECT details_json FROM marketing_message_events WHERE recipient_id=? AND event_type='soft-bounce' ORDER BY id DESC LIMIT 1",[$softRecipient['id']]);
        audienceHttpExpect($campaignStore->person((int)$softContact['id'])['email_status']===$softContact['email_status']
            &&(audienceJson($softHistory['details_json'])['bounce']['diagnostic_code']??'')==='smtp; 452 mailbox full',
            'Soft bounce retains diagnostics without cleaning the contact');
        foreach(['inbox','subscription-notice']as$kind){
            $personId=$campaignStore->savePerson(['email'=>'bounce-'.$kind.'-'.bin2hex(random_bytes(5)).'@example.test','email_status'=>'subscribed','consent_note'=>'Synthetic bounce fixture']);
            $person=$campaignStore->person($personId);$correlation=bin2hex(random_bytes(32));$providerId='service-bounce-'.$kind;
            $campaignStore->write("INSERT INTO marketing_service_mail(audience_id,contact_id,kind,actor_label,operation_key,payload_ciphertext,message_id,state,next_attempt_at,delivery_started_at,expires_at,ses_correlation,provider_account_id,provider_region,recipient_hash,provider_message_id) VALUES (?,?,?,'Bounce fixture',?,'',?,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),?,?,?,?,?)",
                [$campaignStore->audienceId,$personId,$kind,bin2hex(random_bytes(32)),'<'.$providerId.'@example.test>',$correlation,$ses['aws_account_id'],$ses['region'],audienceDispatchEmailHash($person['email']),$providerId]);
            $service=$bounce;$service['mail']['destination']=[$person['email']];$service['mail']['messageId']=$providerId;$service['mail']['tags']['moed-receipt']=[$correlation];
            $service['bounce']['bouncedRecipients']=[['emailAddress'=>$person['email'],'status'=>'5.1.1','diagnosticCode'=>'smtp; 550 service mailbox unavailable']];
            audienceHttpExpect(audienceSnsReceive($campaignStore,$signSes($service,'ses-service-'.$kind),$fetch)==='accepted','Signed '.$kind.' bounce is correlated');
            $history=$campaignStore->one("SELECT details_json FROM marketing_contact_events WHERE contact_id=? AND event_type='status' ORDER BY id DESC LIMIT 1",[$personId]);
            audienceHttpExpect($campaignStore->person($personId)['email_status']==='cleaned'
                &&(audienceJson($history['details_json'])['bounce']['diagnostic_code']??'')==='smtp; 550 service mailbox unavailable',
                'Service-mail cleaning retains its detailed reason in contact history');
        }
        $person=$campaignStore->person($contactId);$campaignStore->mutatePerson($contactId,(int)$person['version'],'delete');
        $retained=$campaignStore->one("SELECT COUNT(*) n FROM marketing_message_events WHERE recipient_id=? AND JSON_CONTAINS_PATH(details_json,'one','$.bounce')",[$recipient['id']]);
        $privateHistory=$campaignStore->one("SELECT COUNT(*) n FROM marketing_contact_events WHERE contact_id=? AND JSON_CONTAINS_PATH(details_json,'one','$.bounce')",[$contactId]);
        audienceHttpExpect((int)$retained['n']===0&&(int)$privateHistory['n']===0,'Deleting a contact removes bounce diagnostics from both history stores');
        audienceSnsReceive($campaignStore,$signSes($bounce,'ses-detailed-after-delete'),$fetch);
        $late=$campaignStore->one('SELECT details_json FROM marketing_message_events WHERE recipient_id=? AND event_key=?',[$recipient['id'],hash('sha256','ses:ses-detailed-after-delete')]);
        audienceHttpExpect($late&&!isset(audienceJson($late['details_json'])['bounce']),'A late authenticated receipt cannot restore deleted private diagnostics');
        throw $rollback;
    });}catch(RuntimeException $error){if($error!==$rollback)throw $error;}
})();
