<?php
// Included by the disposable Audience HTTP suite with authenticated clients.
$bulkSuffix = bin2hex(random_bytes(5));
$bulkPath = 'audience.php?view=contacts';
$bulkToken = audienceHttpCsrf($editor($bulkPath));
$bulkReviewToken = audienceHttpCsrf($reviewer($bulkPath));
$bulkActor = 0;
$bulkStore = new AudienceStore($conn, 1, $bulkActor, 'Bulk subscription fixture');
$bulkTag = $bulkStore->saveTag('Bulk subscription '.$bulkSuffix, 0, 0);
$bulkGroup = $bulkStore->saveGroup(['name'=>'Bulk subscription '.$bulkSuffix, 'display_type'=>'checkboxes', 'options'=>[['name'=>'Updates']]], 0, 0);
$bulkOption = (int)$bulkStore->one('SELECT id FROM marketing_group_options WHERE category_id=?', [$bulkGroup])['id'];
$bulkField = $bulkStore->saveField(['label'=>'Bulk subscription '.$bulkSuffix, 'merge_tag'=>'BULKPERMISSION', 'field_type'=>'text'], 0, 0);
$bulkPeople = $bulkBefore = [];
foreach (['pending','non-subscribed','unsubscribed','subscribed','cleaned','stale','protected','archived','unselected'] as $bulkState) {
    $bulkStatus = in_array($bulkState, AUDIENCE_STATUSES, true) ? $bulkState : 'non-subscribed';
    $bulkPerson = $bulkStore->savePerson([
        'email'=>'bulk-'.$bulkState.'-'.$bulkSuffix.'@example.test', 'email_status'=>$bulkStatus,
        'first_name'=>'Bulk permission '.$bulkSuffix, 'last_name'=>$bulkState, 'consent_note'=>'Original permission',
        'tag_ids'=>[$bulkTag], 'group_ids'=>[$bulkOption], 'custom'=>[$bulkField=>'Preserved value'],
        'language'=>'he', 'timezone'=>'America/Chicago',
    ]);
    if ($bulkState === 'archived') $bulkStore->mutatePerson($bulkPerson, 1, 'archive');
    $bulkPeople[$bulkState] = $bulkPerson;
    $bulkBefore[$bulkState] = $bulkStore->person($bulkPerson);
}
$bulkStore->write('INSERT INTO marketing_deleted_emails(audience_id,email_hash) VALUES (1,?)', [hash('sha256', $bulkBefore['protected']['email'])]);
$conn->execute_query('INSERT INTO marketing_audiences(name,settings_json) VALUES (?,JSON_OBJECT())', ['Bulk foreign '.$bulkSuffix]);
$bulkForeignStore = new AudienceStore($conn, (int)$conn->insert_id, $bulkActor, 'Foreign fixture');
$bulkForeignPerson = $bulkForeignStore->savePerson(['email'=>'bulk-foreign-'.$bulkSuffix.'@example.test', 'email_status'=>'pending']);
$bulkPost = [
    'csrf_token'=>$bulkToken, 'action'=>'bulk', 'operation'=>'subscribe', 'selection_scope'=>'selected', 'archived'=>'all',
    'selected'=>[...array_values(array_diff_key($bulkPeople, ['unselected'=>true])), $bulkForeignPerson],
    'consent_note'=>"  Signed permission forms on November 9.\nNewsletter signup at the event.  ",
];
$bulkJobsBefore = (int)$bulkStore->one('SELECT COUNT(*) n FROM marketing_jobs')['n'];
audienceHttpExpect($reviewer($bulkPath, array_replace($bulkPost, ['csrf_token'=>$bulkReviewToken]))['status'] === 403, 'Reviewer cannot bulk subscribe');
audienceHttpExpect($editor($bulkPath, array_replace($bulkPost, ['csrf_token'=>'invalid']))['status'] === 400, 'Bulk subscription requires valid CSRF');
foreach (['', " \n\t ", str_repeat('x', 1001), ['invalid']] as $bulkInvalidNote) {
    $bulkRejected = $editor($bulkPath, array_replace($bulkPost, ['consent_note'=>$bulkInvalidNote]));
    audienceHttpExpect($bulkRejected['status'] === 200 && str_contains($bulkRejected['body'], 'audience-notice error'), 'Invalid bulk permission is reported before queuing');
}
audienceHttpExpect((int)$bulkStore->one('SELECT COUNT(*) n FROM marketing_jobs')['n'] === $bulkJobsBefore, 'Rejected subscriptions never create jobs');
$bulkMissingPermission = $editor($bulkPath, ['csrf_token'=>$bulkToken, 'action'=>'person_action', 'operation'=>'subscribe', 'id'=>$bulkPeople['subscribed'], 'version'=>1]);
audienceHttpExpect($bulkMissingPermission['status'] === 200 && (int)$bulkStore->person($bulkPeople['subscribed'])['version'] === 1, 'Direct subscribe requires permission even for an existing subscriber');

$bulkQueued = $editor($bulkPath, $bulkPost);
preg_match('/id=(\d+)/', $bulkQueued['headers'], $bulkJobMatch);
$bulkJob = (int)($bulkJobMatch[1] ?? 0);
audienceHttpExpect($bulkQueued['status'] === 302 && $bulkJob > 0, 'Editor queues bulk subscription through Connections');
audienceHttpExpect((int)$bulkStore->job($bulkJob)['total'] === 8, 'Bulk membership includes selected local connections across archive states');
$bulkStale = $bulkBefore['stale'];
$bulkStore->saveProfileSection($bulkPeople['stale'], (int)$bulkStale['version'], 'details', ['first_name'=>'Concurrent edit', 'last_name'=>'stale']);
do { $bulkResult = $bulkStore->processJob($bulkJob, 2); } while ($bulkResult['state'] !== 'complete');
audienceHttpExpect((int)$bulkResult['updated'] === 5 && (int)$bulkResult['errors'] === 3, 'Subscription processes every chunk and reports cleaned, protected and stale records');
foreach (['pending','non-subscribed','unsubscribed','subscribed','archived'] as $bulkState) {
    $bulkPerson = $bulkStore->person($bulkPeople[$bulkState]);
    $bulkEvidence = $bulkStore->one("SELECT details_json FROM marketing_contact_events WHERE contact_id=? AND event_type='contact_updated' ORDER BY id DESC LIMIT 1", [$bulkPerson['id']]);
    audienceHttpExpect($bulkPerson['email_status'] === 'subscribed' && (int)$bulkPerson['version'] === (int)$bulkBefore[$bulkState]['version'] + 1, 'Selected '.$bulkState.' connection is subscribed once');
    audienceHttpExpect(audienceJson($bulkEvidence['details_json'])['consent_note'] === trim($bulkPost['consent_note']), 'Common permission is stored for '.$bulkState.' connection');
    foreach (['email','first_name','last_name','source','custom','tag_ids','group_ids','language','timezone','is_archived'] as $bulkKey) {
        audienceHttpExpect($bulkPerson[$bulkKey] === $bulkBefore[$bulkState][$bulkKey], 'Bulk subscription preserves '.$bulkState.' '.$bulkKey);
    }
    if ($bulkState !== 'subscribed') {
        $bulkPermission = audienceJson($bulkStore->permissionEvent((int)$bulkPerson['id'])['details_json']);
        audienceHttpExpect($bulkPermission['from'] === $bulkBefore[$bulkState]['email_status'] && $bulkPermission['to'] === 'subscribed' && $bulkPermission['consent_note'] === trim($bulkPost['consent_note']), 'New subscription retains permission and the previous status');
    }
}
foreach (['cleaned','protected','unselected'] as $bulkState) {
    audienceHttpExpect($bulkStore->person($bulkPeople[$bulkState]) === $bulkBefore[$bulkState], 'Bulk subscription preserves excluded or suppressed '.$bulkState.' connection');
}
audienceHttpExpect($bulkStore->person($bulkPeople['stale'])['first_name'] === 'Concurrent edit' && $bulkStore->person($bulkPeople['stale'])['email_status'] === 'non-subscribed', 'Stale subscription preserves the concurrent edit');
audienceHttpExpect($bulkForeignStore->person($bulkForeignPerson)['email_status'] === 'pending', 'Bulk subscription cannot change another audience');
$bulkEvents = (int)$bulkStore->one('SELECT COUNT(*) n FROM marketing_contact_events')['n'];
$bulkRepeated = $bulkStore->processJob($bulkJob);
audienceHttpExpect($bulkRepeated['processed'] === $bulkResult['processed'] && (int)$bulkStore->one('SELECT COUNT(*) n FROM marketing_contact_events')['n'] === $bulkEvents, 'Retrying a completed subscription never duplicates permission events');

$bulkAllPeople = [];
foreach ([1,2] as $bulkNumber) $bulkAllPeople[] = $bulkStore->savePerson(['email'=>'bulk-all-'.$bulkNumber.'-'.$bulkSuffix.'@example.test', 'first_name'=>'All permission '.$bulkSuffix, 'email_status'=>'pending']);
$bulkAllQueued = $editor($bulkPath, ['csrf_token'=>$bulkToken, 'action'=>'bulk', 'operation'=>'subscribe', 'selection_scope'=>'all', 'q'=>'All permission '.$bulkSuffix, 'status'=>['pending'], 'consent_note'=>'Shared filtered permission']);
preg_match('/id=(\d+)/', $bulkAllQueued['headers'], $bulkJobMatch);
$bulkAllJob = (int)($bulkJobMatch[1] ?? 0);
audienceHttpExpect($bulkAllQueued['status'] === 302 && (int)$bulkStore->job($bulkAllJob)['total'] === 2, 'Select all matching subscribes the filtered membership');
$bulkStore->processJob($bulkAllJob);
foreach ($bulkAllPeople as $bulkPerson) {
    audienceHttpExpect($bulkStore->person($bulkPerson)['email_status'] === 'subscribed' && audienceJson($bulkStore->permissionEvent($bulkPerson)['details_json'])['consent_note'] === 'Shared filtered permission', 'Filtered selection stores shared permission on each subscription');
}
