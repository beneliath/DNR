ALTER TABLE ai_coach_requests ADD INDEX idx_coach_request_retention (created_at, id);

ALTER TABLE short_link_stats ADD INDEX idx_short_link_stats_retention (visit_hour, link_id);
CREATE TABLE short_link_stats_archive (
    link_id BIGINT UNSIGNED NOT NULL,
    visit_hour DATETIME NOT NULL,
    visits BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (link_id, visit_hour),
    FOREIGN KEY (link_id) REFERENCES short_links(id) ON DELETE CASCADE
);
CREATE SQL SECURITY INVOKER VIEW short_link_report_stats AS
    SELECT link_id, visit_hour, browser, os, country, referrer, visits FROM short_link_stats
    UNION ALL
    SELECT link_id, visit_hour, 'Historical' AS browser, 'Historical' AS os,
           'ZZ' AS country, '' AS referrer, visits FROM short_link_stats_archive;

ALTER TABLE user_recovery_codes ADD COLUMN key_id VARCHAR(48) NULL;
ALTER TABLE contacts ADD COLUMN merged_into_id INT NULL,
    ADD CONSTRAINT fk_contact_merge_target FOREIGN KEY (merged_into_id) REFERENCES contacts(id);
ALTER TABLE organizations ADD COLUMN merged_into_id INT NULL,
    ADD CONSTRAINT fk_organization_merge_target FOREIGN KEY (merged_into_id) REFERENCES organizations(id);

CREATE TABLE document_scan_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    presentation_id INT NOT NULL,
    speaker_id INT NOT NULL,
    asset_kind ENUM('notes','slidedeck') NOT NULL,
    storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uploaded_by INT NULL,
    base_version VARCHAR(32) NULL,
    state ENUM('queued','scanning','clean','rejected','superseded') NOT NULL DEFAULT 'queued',
    claim_token CHAR(32) CHARACTER SET ascii NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    retry_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    status_detail VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_document_scan_asset (presentation_id,speaker_id,asset_kind),
    INDEX idx_document_scan_queue (state,retry_at,id),
    FOREIGN KEY (presentation_id) REFERENCES presentations(id) ON DELETE CASCADE,
    FOREIGN KEY (speaker_id) REFERENCES speakers(id) ON DELETE CASCADE,
    FOREIGN KEY (storage_key) REFERENCES stored_files(storage_key),
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);
