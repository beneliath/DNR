-- Create presentations table
CREATE TABLE IF NOT EXISTS presentations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    engagement_id INT NOT NULL,
    topic_title VARCHAR(255) NOT NULL,
    presentation_date DATE NOT NULL,
    presentation_time TIME NOT NULL,
    speaker_name VARCHAR(255) NOT NULL,
    expected_attendance INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE
); 