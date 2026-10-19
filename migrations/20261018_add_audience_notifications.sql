ALTER TABLE marketing_service_mail MODIFY kind ENUM('test','confirmation','inbox','inbox-alert','audience-alert') NOT NULL;
CREATE TABLE marketing_audience_notification_state (
 audience_id INT UNSIGNED PRIMARY KEY,
 generation CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 event_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
 digest_day DATE NOT NULL,
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
