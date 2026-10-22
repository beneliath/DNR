-- Existing tags have no recorded creation timestamp; preserve that as unknown.
ALTER TABLE marketing_tags ADD created_at TIMESTAMP(6) NULL DEFAULT NULL;
