-- Durable invalidation outbox. No foreign key: deletions must leave purge jobs.
CREATE TABLE notes_cache_purge_queue (
    code CHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    generation BIGINT UNSIGNED NOT NULL DEFAULT 1,
    pass TINYINT UNSIGNED NOT NULL DEFAULT 0,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_error VARCHAR(255) NULL,
    INDEX idx_notes_cache_purge_due (next_attempt_at)
);

CREATE TRIGGER notes_cache_after_notes_insert AFTER INSERT ON presentation_notes
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT code FROM short_links WHERE link_type = 'notes' AND (presentation_id = NEW.presentation_id AND speaker_id = NEW.speaker_id) ORDER BY code
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

CREATE TRIGGER notes_cache_after_notes_update AFTER UPDATE ON presentation_notes
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT code FROM short_links WHERE link_type = 'notes' AND ((presentation_id = OLD.presentation_id AND speaker_id = OLD.speaker_id) OR (presentation_id = NEW.presentation_id AND speaker_id = NEW.speaker_id)) ORDER BY code
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

CREATE TRIGGER notes_cache_before_notes_delete BEFORE DELETE ON presentation_notes
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT code FROM short_links WHERE link_type = 'notes' AND (presentation_id = OLD.presentation_id AND speaker_id = OLD.speaker_id) ORDER BY code
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

CREATE TRIGGER notes_cache_before_presentation_delete BEFORE DELETE ON presentations
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT code FROM short_links WHERE link_type = 'notes' AND (presentation_id = OLD.id) ORDER BY code
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

CREATE TRIGGER notes_cache_before_engagement_delete BEFORE DELETE ON engagements
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT code FROM short_links WHERE link_type = 'notes' AND (engagement_id = OLD.id) ORDER BY code
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

CREATE TRIGGER notes_cache_after_link_insert AFTER INSERT ON short_links
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT NEW.code WHERE NEW.link_type = 'notes'
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

CREATE TRIGGER notes_cache_before_link_delete BEFORE DELETE ON short_links
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT OLD.code WHERE OLD.link_type = 'notes'
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

CREATE TRIGGER notes_cache_after_link_update AFTER UPDATE ON short_links
FOR EACH ROW INSERT INTO notes_cache_purge_queue (code)
SELECT code FROM (
    SELECT OLD.code AS code WHERE OLD.link_type = 'notes'
    UNION SELECT NEW.code AS code WHERE NEW.link_type = 'notes'
) AS changed_codes ORDER BY code
ON DUPLICATE KEY UPDATE generation = generation + 1, pass = 0,
    attempts = 0, last_error = NULL, next_attempt_at = UTC_TIMESTAMP(6);

-- Prime existing links on first rollout without changing QR codes or statistics.
INSERT INTO notes_cache_purge_queue (code) SELECT code FROM short_links WHERE link_type = 'notes';
