-- Historical vendor summaries never participate in campaign dispatch or contact consent.
CREATE TABLE marketing_historical_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    audience_id INT UNSIGNED NOT NULL,
    source_key VARCHAR(112) NOT NULL,
    name VARCHAR(250) NOT NULL,
    subject VARCHAR(998) NOT NULL,
    sent_at DATETIME NOT NULL,
    metrics_json JSON NOT NULL,
    provenance_json JSON NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    bundle_sha256 CHAR(64) NOT NULL,
    source_filename VARCHAR(250) NOT NULL,
    imported_by INT NULL,
    imported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_marketing_history_source(audience_id,source_key),
    INDEX idx_marketing_history_sent(audience_id,sent_at),
    FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id),
    FOREIGN KEY(imported_by) REFERENCES users(id) ON DELETE SET NULL
);
