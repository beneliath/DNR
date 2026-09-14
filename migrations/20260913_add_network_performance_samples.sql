CREATE TABLE network_performance_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    address_family ENUM('IPv4', 'IPv6') NOT NULL,
    page_path VARCHAR(100) NOT NULL,
    cloudflare_colo CHAR(3) NULL,
    ttfb_ms DECIMAL(10,1) NOT NULL,
    dom_content_loaded_ms DECIMAL(10,1) NOT NULL,
    load_ms DECIMAL(10,1) NOT NULL,
    image_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    image_total_ms DECIMAL(12,1) NOT NULL DEFAULT 0,
    image_max_ms DECIMAL(10,1) NOT NULL DEFAULT 0,
    contact_image_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    contact_image_total_ms DECIMAL(12,1) NOT NULL DEFAULT 0,
    contact_image_max_ms DECIMAL(10,1) NOT NULL DEFAULT 0,
    recorded_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX idx_network_performance_window (recorded_at, address_family),
    INDEX idx_network_performance_page (page_path, recorded_at)
);
