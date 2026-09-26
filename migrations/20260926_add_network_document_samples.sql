ALTER TABLE network_performance_samples
    ADD COLUMN sample_type ENUM('page', 'pdf', 'ppt', 'pptx') NOT NULL DEFAULT 'page',
    ADD COLUMN response_bytes BIGINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN response_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    ADD COLUMN server_headers_ms DECIMAL(12,1) NULL DEFAULT NULL;
