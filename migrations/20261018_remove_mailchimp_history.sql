-- Retire vendor campaign summaries and analyses derived from that removed source.
-- The original migration stays immutable for deployed migration ledgers.
CREATE TEMPORARY TABLE removed_mailchimp_ai_requests (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO removed_mailchimp_ai_requests (id)
SELECT r.id FROM ai_coach_requests r LEFT JOIN ai_coach_jobs j ON j.request_id=r.id
WHERE r.guided_step='audience-ai' AND r.manual_topic='audience-ai:analytics'
AND (JSON_UNQUOTE(JSON_EXTRACT(r.response_json,'$.evidence.window.origin'))='mailchimp'
     OR JSON_UNQUOTE(JSON_EXTRACT(j.request_json,'$.evidence.window.origin'))='mailchimp');
DELETE FROM ai_coach_feedback_reviews WHERE request_id IN (SELECT id FROM removed_mailchimp_ai_requests);
DELETE FROM ai_coach_improvements WHERE source_request_id IN (SELECT id FROM removed_mailchimp_ai_requests);
DELETE FROM ai_coach_requests WHERE id IN (SELECT id FROM removed_mailchimp_ai_requests);
DROP TEMPORARY TABLE removed_mailchimp_ai_requests;
DROP TABLE marketing_historical_campaigns;
