-- Keep the existing recovery destination until a reauthenticated change is verified.
ALTER TABLE users ADD COLUMN pending_email VARCHAR(254) NULL AFTER email;
ALTER TABLE user_email_tokens
    MODIFY purpose ENUM('invitation', 'verification', 'recovery', 'security_notice') NOT NULL;
ALTER TABLE email_outbox
    MODIFY purpose ENUM('invitation', 'verification', 'recovery', 'security_notice') NOT NULL;

DELIMITER //
CREATE TRIGGER users_clear_pending_email_on_revocation BEFORE UPDATE ON users
FOR EACH ROW
BEGIN
    IF NEW.auth_version <> OLD.auth_version OR NEW.account_status <> 'active' THEN
        SET NEW.pending_email = NULL;
    END IF;
END//
DELIMITER ;
