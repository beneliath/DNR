<?php
// Authenticated, disposable-only qualification; never sends external messages.
require_once $source.'/audience_notification_runtime.php';
$noticeStart=$checks;
$noticeAudience=$campaignStore->one('SELECT * FROM marketing_audiences WHERE id=1');
$noticeStaff=$campaignStore->one('SELECT id,email,email_verified_at FROM users WHERE username=?',['audience-editor-'.$suffix]);
$noticeUser=(int)$noticeStaff['id'];$noticeEmail='notice-staff@example.test';
$conn->execute_query('UPDATE users SET email=?,email_verified_at=UTC_TIMESTAMP() WHERE id=?',[$noticeEmail,$noticeUser]);
$noticeDefaults=['csrf_token'=>audienceHttpCsrf($admin('audience.php?view=settings')),'action'=>'save_audience','name'=>$noticeAudience['name'],'from_name'=>'Community team','from_email'=>'sender@example.test','default_subject'=>'Community news *|FNAME|*','mailing_address'=>'Fixture address','permission_reminder'=>'You opted in.','notifications_present'=>'1','signup_emails'=>$noticeEmail,'unsubscribe_emails'=>$noticeEmail,'digest_emails'=>$noticeEmail,'in_app_signup'=>'1','in_app_unsubscribe'=>'1','in_app_digest'=>'1'];
$noticeVersion=static fn()=>(int)$campaignStore->one('SELECT version FROM marketing_audiences WHERE id=1')['version'];
audienceHttpExpect($reviewer('audience.php?view=settings',array_replace($noticeDefaults,['csrf_token'=>$reviewToken,'version'=>$noticeVersion()]))['status']===403,'Reviewers cannot change defaults or notification recipients');
$editorDenied=$editor('audience.php?view=settings',array_replace($noticeDefaults,['csrf_token'=>audienceHttpCsrf($editor('audience.php?view=settings')),'version'=>$noticeVersion()]));
audienceHttpExpect(in_array($editorDenied['status'],[200,403],true)&&$noticeVersion()===(int)$noticeAudience['version'],'Editor cannot change audience notification preferences');
$badNotice=$admin('audience.php?view=settings',array_replace($noticeDefaults,['version'=>$noticeVersion(),'signup_emails'=>'outside@example.test']));
audienceHttpExpect($badNotice['status']===200&&str_contains($badNotice['body'],'verified email addresses'),'Unverified or foreign staff addresses are rejected');
$noticeBefore=$noticeVersion();
audienceHttpExpect($admin('audience.php?view=settings',$noticeDefaults+['version'=>$noticeBefore])['status']===302,'Administrator can save defaults and staff notification preferences');
audienceHttpExpect($admin('audience.php?view=settings',$noticeDefaults+['version'=>$noticeBefore])['status']===200,'Stale settings do not replace newer preferences');
$noticeSaved=audienceJson($campaignStore->one('SELECT settings_json FROM marketing_audiences WHERE id=1')['settings_json']);$noticePrefs=$noticeSaved['subscriber_notifications'];
$campaignStore->saveAudienceDefaults($noticeDefaults,$noticeVersion());
$noticeUnchanged=audienceJson($campaignStore->one('SELECT settings_json FROM marketing_audiences WHERE id=1')['settings_json'])['subscriber_notifications'];
audienceHttpExpect($noticeUnchanged['generation']===$noticePrefs['generation'],'Saving unchanged preferences preserves cursor generation despite JSON key ordering');
$noticeCampaign=$campaignStore->createCampaign(['name'=>'Defaults qualification','campaign_type'=>'regular']);$noticeDraft=$campaignStore->campaign($noticeCampaign)['draft'];
audienceHttpExpect($noticeDraft['subject']==='Community news *|FNAME|*'&&$noticeDraft['from_name']==='Community team'&&$noticeDraft['from_email']==='sender@example.test','New drafts inherit subject and sender defaults');
audienceHttpExpect($campaignStore->subscriberNotifications()===[],'Enabling alerts does not replay historical changes');
$noticePerson=$campaignStore->savePerson(['email'=>'notice-person@example.test','email_status'=>'non-subscribed']);
$campaignStore->event($noticePerson,'subscription_changed',['from'=>'non-subscribed','to'=>'subscribed']);
$campaignStore->event($noticePerson,'subscription_changed',['from'=>'subscribed','to'=>'unsubscribed']);
$campaignStore->event($noticePerson,'contact_added',['status'=>'subscribed','origin'=>'Import']);
$noticeBell=$campaignStore->subscriberNotifications();
audienceHttpExpect(count($noticeBell)===2&&array_column($noticeBell,'kind')===['unsubscribe','signup'],'Bell shows real changes and excludes imported snapshots');
$noticeSes=$campaignStore->one('SELECT * FROM marketing_ses_settings WHERE audience_id=1');
$campaignStore->write('UPDATE marketing_ses_settings SET enabled=1 WHERE audience_id=1');
$noticeQueued=queueAudienceChangeAlerts($conn,500);
audienceHttpExpect($noticeQueued===2&&queueAudienceChangeAlerts($conn,500)===0,'Individual alerts queue once despite repeated worker passes');
$noticeJobs=$campaignStore->all("SELECT * FROM marketing_service_mail WHERE kind='audience-alert' AND actor_id=? ORDER BY id",[$noticeUser]);
$noticeMessage=audienceJson(\Dnr\Security\ApplicationKey::open($noticeJobs[0]['payload_ciphertext']))['message'];
audienceChangeAlertReady($conn,$noticeMessage);
audienceHttpExpect($noticeMessage['email']===$noticeEmail&&!str_contains($noticeMessage['text'],'notice-person@example.test'),'Staff alert contains a scoped profile link without copying contact details');
$campaignStore->write('UPDATE users SET email_verified_at=NULL WHERE id=?',[$noticeUser]);
$noticeRejected=false;try{audienceChangeAlertReady($conn,$noticeMessage);}catch(DomainException){$noticeRejected=true;}
audienceHttpExpect($noticeRejected,'Dispatch rechecks staff verification');
$campaignStore->write('UPDATE users SET email_verified_at=UTC_TIMESTAMP() WHERE id=?',[$noticeUser]);
$clockTomorrow=(new DateTimeImmutable(gmdate('Y-m-d').' 23:11:00',new DateTimeZone('America/Chicago')))->getTimestamp();$digest=audienceNotificationDigest($conn,1,$noticePrefs,$clockTomorrow);
audienceHttpExpect($digest===['signup'=>1,'unsubscribe'=>1],'Daily digest counts observed changes once and ignores imports');
audienceHttpExpect(queueAudienceChangeAlerts($conn,500,$clockTomorrow)===1&&queueAudienceChangeAlerts($conn,500,$clockTomorrow)===0,'One daily summary per staff recipient at the Chicago send time');
$noticeChanged=$noticeDefaults;$noticeChanged['signup_emails']='';$campaignStore->saveAudienceDefaults($noticeChanged,$noticeVersion());
$noticeRejected=false;try{audienceChangeAlertReady($conn,$noticeMessage);}catch(DomainException){$noticeRejected=true;}
audienceHttpExpect($noticeRejected,'Changing notification preferences revokes queued alerts');
$gated=$noticeSes;$gated['production_access']=0;
audienceHttpExpect(in_array('Request SES production access in the selected AWS Region.',audienceSesReasons($gated,$gated['from_email']),true),'Notification transport retains the production-access gate');
// Restore only this fixture's settings; queued alerts remain explicitly cancelled.
$campaignStore->write("UPDATE marketing_service_mail SET state='cancelled' WHERE kind='audience-alert'");
$campaignStore->write('UPDATE marketing_ses_settings SET enabled=? WHERE audience_id=1',[$noticeSes['enabled']]);
$campaignStore->write('UPDATE marketing_audiences SET settings_json=?,version=version+1 WHERE id=1',[$noticeAudience['settings_json']]);
$campaignStore->write('UPDATE users SET email=?,email_verified_at=? WHERE id=?',[$noticeStaff['email'],$noticeStaff['email_verified_at'],$noticeUser]);
echo 'Audience defaults and notifications: '.($checks-$noticeStart)." checks passed; no external mail.\n";
