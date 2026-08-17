-- Create presentations table
CREATE TABLE IF NOT EXISTS presentations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    topic_title VARCHAR(255) NOT NULL,
    presentation_date DATE NULL,
    presentation_time VARCHAR(8) NULL,
    speaker_name VARCHAR(255) NOT NULL,
    expected_attendance INT NULL,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE,
    CONSTRAINT fk_presentation_archiver
        FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_presentation_engagement_active_schedule (
        engagement_id, is_archived, presentation_date, presentation_time, id
    )
);
