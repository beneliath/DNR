-- Store contact birthdays and invalidate calendar subscriptions when they change.

ALTER TABLE contacts
    ADD COLUMN contact_birthday DATE NULL AFTER contact_phone;

CREATE TRIGGER calendar_contacts_after_insert AFTER INSERT ON contacts
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_contacts_after_update AFTER UPDATE ON contacts
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
CREATE TRIGGER calendar_contacts_after_delete AFTER DELETE ON contacts
FOR EACH ROW UPDATE calendar_feed_revision SET revision = revision + 1 WHERE id = 1;
