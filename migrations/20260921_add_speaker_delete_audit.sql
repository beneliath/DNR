-- Application DELETE grants are installed by configure_database_privileges.sh.
-- Existing restrictive speaker foreign keys continue protecting linked history.
CREATE TRIGGER audit_speakers_after_delete AFTER DELETE ON speakers
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES
    (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_delete', 'speakers', OLD.id, LEFT(OLD.name, 255), LEFT(@dnr_request_ip, 45));
