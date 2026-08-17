CREATE TABLE authentication_rate_limits (
    key_hash BINARY(32) PRIMARY KEY,
    scope ENUM('ip', 'global') NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_auth_rate_limits_updated (updated_at)
);

UPDATE engagements
SET event_type_other = event_type,
    event_type = 'other'
WHERE event_type NOT IN ('conference', 'service', 'study or teaching', 'Passover Seder', 'other')
  AND TRIM(COALESCE(event_type, '')) <> '';

UPDATE engagements
SET event_end_date = event_start_date
WHERE event_end_date IS NULL;

UPDATE organizations
SET mailing_address_line_1 = physical_address_line_1,
    mailing_address_line_2 = physical_address_line_2,
    mailing_city = physical_city,
    mailing_state = physical_state,
    mailing_zipcode = physical_zipcode,
    mailing_country = physical_country
WHERE TRIM(COALESCE(mailing_address_line_1, '')) = ''
  AND TRIM(COALESCE(physical_address_line_1, '')) <> '';

UPDATE organizations
SET website_url = NULL
WHERE TRIM(COALESCE(website_url, '')) <> ''
  AND LOWER(SUBSTRING_INDEX(website_url, ':', 1)) NOT IN ('http', 'https');

UPDATE presentations SET presentation_time = NULL
WHERE TRIM(COALESCE(presentation_time, '')) = '';

ALTER TABLE engagements
    MODIFY event_end_date DATE NOT NULL,
    ADD CONSTRAINT chk_engagement_date_range CHECK (event_end_date >= event_start_date),
    ADD CONSTRAINT chk_engagement_travel_amount CHECK (travel_amount IS NULL OR travel_amount >= 0),
    ADD CONSTRAINT chk_engagement_housing_amount CHECK (housing_amount IS NULL OR housing_amount >= 0),
    ADD CONSTRAINT chk_engagement_other_type CHECK (
        event_type <> 'other' OR TRIM(COALESCE(event_type_other, '')) <> ''
    );

ALTER TABLE presentations
    ADD CONSTRAINT chk_presentation_attendance CHECK (
        expected_attendance IS NULL OR expected_attendance >= 1
    ),
    ADD CONSTRAINT chk_presentation_time_format CHECK (
        presentation_time IS NULL
        OR presentation_time REGEXP '^(0[1-9]|1[0-2]):[0-5][0-9] (AM|PM)$'
    );

CREATE INDEX idx_security_audit_login_ip_created
    ON security_audit_log (event_category, event_type, ip_address, created_at);
