DROP PROCEDURE IF EXISTS prune_security_audit_log;

DELIMITER //
CREATE PROCEDURE prune_security_audit_log(
    IN p_retention_days INT,
    IN p_actor_user_id INT,
    IN p_actor_username VARCHAR(50),
    IN p_request_ip VARCHAR(45)
)
SQL SECURITY DEFINER
MODIFIES SQL DATA
prune: BEGIN
    DECLARE v_cutoff DATETIME;
    DECLARE v_deleted_count BIGINT UNSIGNED DEFAULT 0;
    DECLARE v_lock_acquired INT DEFAULT 0;
    DECLARE v_transaction_started TINYINT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_transaction_started = 1 THEN
            ROLLBACK;
        END IF;
        IF v_lock_acquired = 1 THEN
            DO RELEASE_LOCK('dnr_audit_log_prune');
        END IF;
        RESIGNAL;
    END;

    IF p_retention_days IS NULL OR p_retention_days < 1 OR p_retention_days > 36500 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Audit-log retention days must be between 1 and 36500';
    END IF;

    SELECT GET_LOCK('dnr_audit_log_prune', 0) INTO v_lock_acquired;
    IF v_lock_acquired IS NULL OR v_lock_acquired <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Another audit-log prune is already running';
    END IF;

    SET v_cutoff = DATE_SUB(UTC_DATE(), INTERVAL p_retention_days DAY);
    START TRANSACTION;
    SET v_transaction_started = 1;

    DELETE FROM security_audit_log
    WHERE created_at < v_cutoff;
    SET v_deleted_count = ROW_COUNT();

    IF v_deleted_count > 0 THEN
        INSERT INTO security_audit_log (
            actor_user_id, actor_username, event_category, event_type,
            entity_type, entity_label, details, ip_address
        ) VALUES (
            p_actor_user_id,
            LEFT(p_actor_username, 50),
            'security',
            'audit_log_pruned',
            'audit_log',
            'DNR audit log',
            CONCAT(
                'Administrator retained ', p_retention_days,
                ' days; permanently deleted ', v_deleted_count,
                ' entries older than ', DATE_FORMAT(v_cutoff, '%Y-%m-%d %H:%i:%s'), ' UTC'
            ),
            LEFT(p_request_ip, 45)
        );
    END IF;

    COMMIT;
    SET v_transaction_started = 0;
    DO RELEASE_LOCK('dnr_audit_log_prune');
    SET v_lock_acquired = 0;

    SELECT v_deleted_count AS deleted_count, v_cutoff AS cutoff_utc;
END//
DELIMITER ;
