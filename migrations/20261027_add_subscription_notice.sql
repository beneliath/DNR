ALTER TABLE marketing_service_mail
 MODIFY kind ENUM('test','confirmation','inbox','inbox-alert','audience-alert','subscription-notice') NOT NULL,
 ADD INDEX subscription_notice_recent (audience_id,contact_id,kind,created_at);
