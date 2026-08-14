-- Create presentations table
CREATE TABLE IF NOT EXISTS presentations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    topic_title VARCHAR(255) NOT NULL,
    presentation_date DATE NULL,
    presentation_time VARCHAR(8) NULL,
    speaker_name VARCHAR(255) NOT NULL,
    expected_attendance INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE
);
