-- Receiving evidence contains no sender, subject, message body or credentials.
CREATE TABLE marketing_inbox_route_receipts (
 audience_id INT UNSIGNED NOT NULL,
 inbound_id BIGINT UNSIGNED NOT NULL,
 route_id INT UNSIGNED NOT NULL,
 sender_verified TINYINT(1) NOT NULL,
 authentication_method VARCHAR(32) NOT NULL,
 received_at DATETIME NOT NULL,
 PRIMARY KEY(audience_id,inbound_id),
 INDEX(audience_id,route_id,sender_verified,received_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY(inbound_id) REFERENCES inbound_email_messages(id) ON DELETE CASCADE,
 FOREIGN KEY(route_id) REFERENCES marketing_inbox_routes(id) ON DELETE CASCADE
);
