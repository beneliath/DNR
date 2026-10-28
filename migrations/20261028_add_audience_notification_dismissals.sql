CREATE TABLE marketing_notification_dismissals (
 audience_id INT UNSIGNED NOT NULL,
 user_id INT NOT NULL,
 notification_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(audience_id,user_id,notification_key),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
