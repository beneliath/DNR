ALTER TABLE marketing_service_mail
 MODIFY kind ENUM('test','confirmation','inbox','inbox-alert','audience-alert','subscription-notice','inbox-forward') NOT NULL;

-- A forwarding action has one independently tracked delivery per selected mailbox.
CREATE TABLE marketing_inbox_forwards (
 service_mail_id BIGINT UNSIGNED PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 message_id BIGINT UNSIGNED NOT NULL,
 batch_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 recipient_user_id INT NOT NULL,
 recipient_email VARCHAR(254) NOT NULL,
 UNIQUE KEY(audience_id,batch_key,recipient_user_id),
 INDEX(audience_id,message_id),
 FOREIGN KEY(audience_id,message_id) REFERENCES marketing_inbox_messages(audience_id,id) ON DELETE CASCADE,
 FOREIGN KEY(service_mail_id) REFERENCES marketing_service_mail(id)
);

-- Retain the subject of each received email, including replies in mixed conversations.
ALTER TABLE marketing_inbox_messages ADD email_subject VARCHAR(250) NULL;
UPDATE marketing_inbox_messages m
 JOIN marketing_inbox_threads t ON t.audience_id=m.audience_id AND t.id=m.thread_id
 LEFT JOIN inbound_email_messages e ON e.deduplication_hash=UNHEX(m.source_key)
 SET m.email_subject=COALESCE(NULLIF(LEFT(TRIM(REGEXP_REPLACE(e.subject,'[[:cntrl:]]',' ')),250),''),t.subject)
 WHERE m.direction='inbound' AND (t.source IN ('email','direct') OR e.id IS NOT NULL OR m.rfc_message_id<>'');
