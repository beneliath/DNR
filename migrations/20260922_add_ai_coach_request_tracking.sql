-- Correlate a browser question with its server result across page navigation.
ALTER TABLE ai_coach_requests
    ADD COLUMN client_request_id CHAR(36) NULL,
    ADD UNIQUE KEY uniq_coach_client_request (user_id, client_request_id);
