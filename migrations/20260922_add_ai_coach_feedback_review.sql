ALTER TABLE ai_coach_requests ADD COLUMN feedback_version INT UNSIGNED NOT NULL DEFAULT 0;

-- Investigation receipts contain verified evidence, not a copy of the conversation.
-- Keep them when the operational request log is cleared.
CREATE TABLE ai_coach_feedback_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NULL,
    feedback_version INT UNSIGNED NOT NULL,
    review_version INT UNSIGNED NOT NULL,
    outcome VARCHAR(24) NOT NULL,
    disposition VARCHAR(24) NOT NULL,
    summary VARCHAR(2000) NOT NULL,
    evidence_json JSON NOT NULL,
    guidance_revision CHAR(64) NOT NULL,
    reviewed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    FOREIGN KEY (request_id) REFERENCES ai_coach_requests(id) ON DELETE SET NULL,
    UNIQUE KEY idx_coach_feedback_review (request_id, feedback_version, review_version, outcome)
);
