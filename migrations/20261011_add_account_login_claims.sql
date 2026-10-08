-- One unique namespace shared by published logins and prepared replacements.
-- A uniqueness constraint, rather than cross-table checks, arbitrates races.
CREATE TABLE platform_login_claims (
    username VARCHAR(50) NOT NULL PRIMARY KEY,
    account_key VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    INDEX idx_login_claim_owner (account_key, user_id)
);
INSERT INTO platform_login_claims (username, account_key, user_id)
SELECT username, account_key, user_id FROM platform_login_routes;
INSERT INTO platform_login_claims (username, account_key, user_id)
SELECT r.username, r.account_key, r.user_id FROM platform_login_reservations r
WHERE r.username IS NOT NULL AND NOT EXISTS (SELECT 1 FROM platform_login_claims c WHERE c.username=r.username);
