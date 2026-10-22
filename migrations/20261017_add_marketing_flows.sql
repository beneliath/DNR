-- Native automation drafts, immutable execution revisions and durable per-person effects.
CREATE TABLE marketing_flows (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 name VARCHAR(100) NOT NULL,
 state ENUM('draft','running','paused') NOT NULL DEFAULT 'draft',
 draft_json JSON NOT NULL,
 published_revision INT UNSIGNED NULL,
 cursor_json JSON NOT NULL,
 paused_nodes_json JSON NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 deleted_at DATETIME NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY(audience_id,id),
 INDEX(audience_id,state,deleted_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_flow_revisions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 flow_id INT UNSIGNED NOT NULL,
 document_json JSON NOT NULL,
 actor_label VARCHAR(100) NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 FOREIGN KEY(audience_id,flow_id) REFERENCES marketing_flows(audience_id,id)
);
CREATE TABLE marketing_flow_runs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 flow_id INT UNSIGNED NOT NULL,
 revision_id INT UNSIGNED NOT NULL,
 contact_id INT UNSIGNED NOT NULL,
 entry_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 node_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('active','completed','exited','failed') NOT NULL DEFAULT 'active',
 due_at DATETIME NOT NULL,
 error_message VARCHAR(300) NOT NULL DEFAULT '',
 started_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 ended_at DATETIME NULL,
 UNIQUE KEY(flow_id,contact_id,entry_key),
 INDEX(audience_id,state,due_at),
 INDEX(flow_id,contact_id,state),
 FOREIGN KEY(audience_id,flow_id) REFERENCES marketing_flows(audience_id,id),
 FOREIGN KEY(revision_id) REFERENCES marketing_flow_revisions(id)
);
CREATE TABLE marketing_flow_steps (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 run_id BIGINT UNSIGNED NOT NULL,
 node_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 state ENUM('waiting','completed','skipped','failed') NOT NULL,
 result_json JSON NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 completed_at DATETIME NULL,
 UNIQUE KEY(run_id,node_key),
 FOREIGN KEY(run_id) REFERENCES marketing_flow_runs(id)
);
-- Ordinary reviewed campaign receipts retain their original zero/empty scope.
ALTER TABLE marketing_campaign_deliveries
 ADD COLUMN flow_run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN flow_node VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
 DROP INDEX audience_id,
 ADD UNIQUE KEY uq_delivery_confirmation(audience_id,campaign_id,confirmed_version,flow_run_id,flow_node),
 ADD INDEX(flow_run_id);
