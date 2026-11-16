-- A sort override keeps compound surnames and suffixes independent of display names.
-- NULL preserves existing profiles and the approved Test Account baseline.
ALTER TABLE speakers ADD COLUMN sort_name VARCHAR(255) NULL DEFAULT NULL AFTER name;
