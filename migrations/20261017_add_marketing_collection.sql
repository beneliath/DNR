-- Public collection uses the existing account audience and consent lifecycle.
-- Drafts are never served publicly; publishing makes an immutable snapshot.
CREATE TABLE marketing_collection_resources (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 kind ENUM('hosted','embedded','popup','landing','survey','preferences') NOT NULL,
 name VARCHAR(100) NOT NULL,
 public_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 draft_json JSON NOT NULL,
 published_revision INT UNSIGNED NULL,
 state ENUM('draft','published','paused') NOT NULL DEFAULT 'draft',
 version INT UNSIGNED NOT NULL DEFAULT 1,
 deleted_at DATETIME NULL,
 installed_at DATETIME NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
 UNIQUE KEY(public_key),
 INDEX(audience_id,kind,deleted_at,id),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_collection_revisions (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 resource_id INT UNSIGNED NOT NULL,
 audience_id INT UNSIGNED NOT NULL,
 document_json JSON NOT NULL,
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(resource_id,id),
 FOREIGN KEY(resource_id) REFERENCES marketing_collection_resources(id),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
CREATE TABLE marketing_collection_responses (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 audience_id INT UNSIGNED NOT NULL,
 resource_id INT UNSIGNED NOT NULL,
 revision_id INT UNSIGNED NOT NULL,
 receipt_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 visitor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 contact_id INT UNSIGNED NULL,
 payload_ciphertext MEDIUMTEXT NOT NULL,
 variant_key VARCHAR(20) NOT NULL DEFAULT 'A',
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 UNIQUE KEY(resource_id,receipt_hash),
 INDEX(audience_id,resource_id,created_at),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id),
 FOREIGN KEY(resource_id) REFERENCES marketing_collection_resources(id),
 FOREIGN KEY(revision_id) REFERENCES marketing_collection_revisions(id)
);
CREATE TABLE marketing_collection_visits (
 resource_id INT UNSIGNED NOT NULL,
 day DATE NOT NULL,
 visitor_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 variant_key VARCHAR(20) NOT NULL DEFAULT 'A',
 created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 PRIMARY KEY(resource_id,day,visitor_hash),
 FOREIGN KEY(resource_id) REFERENCES marketing_collection_resources(id)
);
CREATE TABLE marketing_collection_limits (
 audience_id INT UNSIGNED NOT NULL,
 bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 hour_start DATETIME NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 1,
 PRIMARY KEY(audience_id,bucket_hash,hour_start),
 INDEX(audience_id,hour_start),
 FOREIGN KEY(audience_id) REFERENCES marketing_audiences(id)
);
