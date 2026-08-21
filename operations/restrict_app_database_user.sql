-- Run as the database administrator after replacing the account name if the
-- deployment uses a nonstandard application user.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'dnruser'@'%';
GRANT SELECT ON dnr.schema_migrations TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.users TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.user_recovery_codes TO 'dnruser'@'%';
GRANT SELECT, INSERT ON dnr.security_audit_log TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.authentication_rate_limits TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.calendar_subscriptions TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.organizations TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.engagements TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.engagement_map_geocodes TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.engagement_map_geocode_queue TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.engagement_chron_entries TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.presentations TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.contacts TO 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.follow_up_tasks TO 'dnruser'@'%';
FLUSH PRIVILEGES;
