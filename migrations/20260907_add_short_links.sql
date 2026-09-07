-- Links retain their original speaker and event attribution after presentation edits.
CREATE TABLE presentation_notes (
    presentation_id INT NOT NULL,
    speaker_id INT NOT NULL,
    pdf LONGBLOB NULL,
    filename VARCHAR(255) NULL,
    size INT UNSIGNED NULL,
    sha256 BINARY(32) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (presentation_id, speaker_id),
    FOREIGN KEY (presentation_id) REFERENCES presentations(id) ON DELETE CASCADE,
    FOREIGN KEY (speaker_id) REFERENCES speakers(id) ON DELETE RESTRICT
);

CREATE TABLE short_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    engagement_id INT NOT NULL,
    presentation_id INT NOT NULL,
    speaker_id INT NOT NULL,
    link_type VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    target_url VARCHAR(2048) NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_short_link_code (code),
    UNIQUE KEY uq_presentation_speaker_link (presentation_id, speaker_id, link_type),
    INDEX idx_short_link_event (engagement_id),
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    FOREIGN KEY (presentation_id) REFERENCES presentations(id) ON DELETE CASCADE,
    FOREIGN KEY (speaker_id) REFERENCES speakers(id) ON DELETE RESTRICT,
    CONSTRAINT chk_short_link_type CHECK (link_type IN ('website', 'bio', 'donation', 'connection', 'blog', 'books', 'notes')),
    CONSTRAINT chk_short_link_target CHECK ((link_type = 'notes' AND target_url IS NULL) OR (link_type <> 'notes' AND target_url IS NOT NULL))
);

-- Aggregate visits hourly. No raw IP addresses, visitor identifiers, or cookies.
CREATE TABLE short_link_stats (
    link_id BIGINT UNSIGNED NOT NULL,
    visit_hour DATETIME NOT NULL,
    browser VARCHAR(64) NOT NULL,
    os VARCHAR(64) NOT NULL,
    country CHAR(2) CHARACTER SET ascii NOT NULL,
    referrer VARCHAR(253) CHARACTER SET ascii NOT NULL,
    visits BIGINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (link_id, visit_hour, browser, os, country, referrer),
    FOREIGN KEY (link_id) REFERENCES short_links(id) ON DELETE CASCADE
);

-- Existing presentations get the same automatic links as newly saved ones.
INSERT INTO short_links (code, engagement_id, presentation_id, speaker_id, link_type, target_url)
SELECT LOWER(HEX(RANDOM_BYTES(8))), p.engagement_id, p.id, p.speaker_id, kinds.kind,
    CASE kinds.kind WHEN 'website' THEN s.website_url WHEN 'bio' THEN s.bio_url
        WHEN 'donation' THEN s.donation_url WHEN 'connection' THEN s.connection_url
        WHEN 'blog' THEN s.blog_url WHEN 'books' THEN s.books_url ELSE NULL END
FROM presentations p JOIN speakers s ON s.id = p.speaker_id
CROSS JOIN (SELECT 'website' AS kind UNION ALL SELECT 'bio' UNION ALL SELECT 'donation'
    UNION ALL SELECT 'connection' UNION ALL SELECT 'blog' UNION ALL SELECT 'books' UNION ALL SELECT 'notes') kinds
WHERE kinds.kind = 'notes' OR NULLIF(TRIM(CASE kinds.kind
    WHEN 'website' THEN s.website_url WHEN 'bio' THEN s.bio_url WHEN 'donation' THEN s.donation_url
    WHEN 'connection' THEN s.connection_url WHEN 'blog' THEN s.blog_url WHEN 'books' THEN s.books_url END), '') IS NOT NULL;

CREATE TRIGGER audit_short_links_after_update AFTER UPDATE ON short_links
FOR EACH ROW INSERT INTO security_audit_log
    (actor_user_id, actor_username, event_category, event_type, entity_type, entity_id, entity_label, ip_address)
VALUES (@dnr_actor_user_id, LEFT(@dnr_actor_username, 50), 'database_change', 'database_update',
    'short_links', NEW.id, NEW.link_type, LEFT(@dnr_request_ip, 45));
