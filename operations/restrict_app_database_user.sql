-- Run as the database administrator after replacing the account name if the
-- deployment uses a nonstandard application user.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'dnruser'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON dnr.* TO 'dnruser'@'%';
FLUSH PRIVILEGES;
