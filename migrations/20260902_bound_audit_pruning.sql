-- Keep audit retention predictable on large installations: each transaction
-- deletes at most 1,000 rows and one invocation processes at most 50 batches.
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
    DECLARE v_batch_deleted INT UNSIGNED DEFAULT 0;
    DECLARE v_batch_count INT UNSIGNED DEFAULT 0;
    DECLARE v_more_entries TINYINT DEFAULT 0;
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
    prune_batches: LOOP
        START TRANSACTION;
        SET v_transaction_started = 1;
        DELETE FROM security_audit_log
        WHERE created_at < v_cutoff
        ORDER BY created_at, id
        LIMIT 1000;
        SET v_batch_deleted = ROW_COUNT();
        SET v_deleted_count = v_deleted_count + v_batch_deleted;
        IF v_batch_deleted > 0 THEN
            SET v_batch_count = v_batch_count + 1;
            -- Record each deletion in the same transaction. If a later batch
            -- is interrupted, every already-committed deletion remains
            -- represented in the append-only audit trail.
            INSERT INTO security_audit_log (
                actor_user_id, actor_username, event_category, event_type,
                entity_type, entity_label, details, ip_address
            ) VALUES (
                p_actor_user_id,
                LEFT(p_actor_username, 50),
                'security',
                'audit_log_prune_batch',
                'audit_log',
                'DNR audit log',
                CONCAT(
                    'Retained ', p_retention_days, ' days; permanently deleted ',
                    v_batch_deleted, ' entries older than ',
                    DATE_FORMAT(v_cutoff, '%Y-%m-%d %H:%i:%s'), ' UTC in bounded batch ',
                    v_batch_count
                ),
                LEFT(p_request_ip, 45)
            );
        END IF;
        COMMIT;
        SET v_transaction_started = 0;

        IF v_batch_deleted < 1000 OR v_batch_count >= 50 THEN
            LEAVE prune_batches;
        END IF;
    END LOOP;

    SELECT EXISTS(
        SELECT 1 FROM security_audit_log
        WHERE created_at < v_cutoff LIMIT 1
    ) INTO v_more_entries;

    IF v_deleted_count > 0 THEN
        START TRANSACTION;
        SET v_transaction_started = 1;
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
                'Retained ', p_retention_days, ' days; permanently deleted ',
                v_deleted_count, ' entries older than ',
                DATE_FORMAT(v_cutoff, '%Y-%m-%d %H:%i:%s'), ' UTC in ',
                v_batch_count, ' bounded batches',
                IF(v_more_entries = 1, '; more expired entries remain', '')
            ),
            LEFT(p_request_ip, 45)
        );
        COMMIT;
        SET v_transaction_started = 0;
    END IF;

    DO RELEASE_LOCK('dnr_audit_log_prune');
    SET v_lock_acquired = 0;
    SELECT v_deleted_count AS deleted_count, v_cutoff AS cutoff_utc,
           v_more_entries AS more_entries;
END//
DELIMITER ;
