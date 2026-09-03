-- Provide bounded, opt-in retention for terminal queue rows and retained
-- inbound source messages. Review/pending work is never eligible.
ALTER TABLE email_outbox
    ADD INDEX idx_email_outbox_retention (status, updated_at, id);
ALTER TABLE notification_outbox
    ADD INDEX idx_notification_outbox_retention (status, updated_at, id);

DROP PROCEDURE IF EXISTS prune_operational_mail_history;
DELIMITER //
CREATE PROCEDURE prune_operational_mail_history(
    IN p_retention_days INT,
    IN p_max_rows_per_table INT
)
SQL SECURITY DEFINER
MODIFIES SQL DATA
prune: BEGIN
    DECLARE v_cutoff DATETIME;
    DECLARE v_email_count INT UNSIGNED DEFAULT 0;
    DECLARE v_notification_count INT UNSIGNED DEFAULT 0;
    DECLARE v_inbound_count INT UNSIGNED DEFAULT 0;
    DECLARE v_lock_acquired INT DEFAULT 0;
    DECLARE v_transaction_started TINYINT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_transaction_started = 1 THEN
            ROLLBACK;
        END IF;
        IF v_lock_acquired = 1 THEN
            DO RELEASE_LOCK('dnr_mail_history_prune');
        END IF;
        RESIGNAL;
    END;

    IF p_retention_days IS NULL OR p_retention_days < 30 OR p_retention_days > 36500 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Mail-history retention days must be between 30 and 36500';
    END IF;
    IF p_max_rows_per_table IS NULL
        OR p_max_rows_per_table < 1 OR p_max_rows_per_table > 10000 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Mail-history prune limit must be between 1 and 10000';
    END IF;

    SELECT GET_LOCK('dnr_mail_history_prune', 0) INTO v_lock_acquired;
    IF v_lock_acquired IS NULL OR v_lock_acquired <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Another mail-history prune is already running';
    END IF;
    SET v_cutoff = DATE_SUB(UTC_DATE(), INTERVAL p_retention_days DAY);

    START TRANSACTION;
    SET v_transaction_started = 1;
    DELETE FROM email_outbox
    WHERE status IN ('sent', 'failed') AND updated_at < v_cutoff
    ORDER BY updated_at, id
    LIMIT p_max_rows_per_table;
    SET v_email_count = ROW_COUNT();
    COMMIT;
    SET v_transaction_started = 0;

    START TRANSACTION;
    SET v_transaction_started = 1;
    DELETE FROM notification_outbox
    WHERE status IN ('sent', 'failed') AND updated_at < v_cutoff
    ORDER BY updated_at, id
    LIMIT p_max_rows_per_table;
    SET v_notification_count = ROW_COUNT();
    COMMIT;
    SET v_transaction_started = 0;

    START TRANSACTION;
    SET v_transaction_started = 1;
    DELETE FROM inbound_email_messages
    WHERE status IN ('processed', 'rejected', 'failed') AND received_at < v_cutoff
      AND NOT EXISTS (
          SELECT 1 FROM mattermost_reply_notifications
          WHERE inbound_email_message_id = inbound_email_messages.id
            AND delivered_at IS NULL
      )
    ORDER BY received_at, id
    LIMIT p_max_rows_per_table;
    SET v_inbound_count = ROW_COUNT();
    COMMIT;
    SET v_transaction_started = 0;

    DO RELEASE_LOCK('dnr_mail_history_prune');
    SET v_lock_acquired = 0;
    SELECT v_email_count AS email_outbox_count,
           v_notification_count AS notification_outbox_count,
           v_inbound_count AS inbound_message_count,
           v_cutoff AS cutoff_utc;
END//
DELIMITER ;
