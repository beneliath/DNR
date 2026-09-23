ALTER TABLE ai_coach_requests
    ADD COLUMN ui_state_json JSON NULL,
    ADD COLUMN user_feedback VARCHAR(24) NULL,
    ADD COLUMN feedback_context_json JSON NULL;

CREATE TABLE ai_coach_jobs (
    request_id BIGINT UNSIGNED PRIMARY KEY,
    request_json JSON NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'queued',
    deadline_at DATETIME(6) NOT NULL,
    claimed_at DATETIME(6) NULL,
    claim_token CHAR(32) NULL,
    FOREIGN KEY (request_id) REFERENCES ai_coach_requests(id) ON DELETE CASCADE,
    INDEX idx_coach_jobs_ready (state, request_id),
    INDEX idx_coach_jobs_deadline (state, deadline_at)
);

-- Approved, redacted regression cases survive deletion of the operational log.
CREATE TABLE ai_coach_improvements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_request_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    category VARCHAR(32) NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    question TEXT NOT NULL,
    user_role VARCHAR(16) NOT NULL,
    page_path VARCHAR(80) NOT NULL,
    guided_step VARCHAR(80) NOT NULL DEFAULT '',
    expected_guidance TEXT NOT NULL,
    expected_workflow VARCHAR(80) NOT NULL DEFAULT '',
    topic_ids JSON NOT NULL,
    required_terms JSON NOT NULL,
    forbidden_terms JSON NOT NULL,
    approved_by INT NULL,
    guidance_revision CHAR(64) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (source_request_id) REFERENCES ai_coach_requests(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_coach_improvements_status (status, id)
);
