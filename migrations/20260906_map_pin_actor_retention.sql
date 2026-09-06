-- Preserve confirmed locations if their confirming account is later deleted.
ALTER TABLE engagement_map_pins
    DROP FOREIGN KEY fk_map_pin_user,
    MODIFY confirmed_by INT NULL,
    ADD CONSTRAINT fk_map_pin_confirmer FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL;
