-- Birthdays drive annual reminders, so retain only the month and day.

ALTER TABLE contacts
    MODIFY COLUMN contact_birthday VARCHAR(10) NULL;

UPDATE contacts
SET contact_birthday = DATE_FORMAT(
    STR_TO_DATE(contact_birthday, '%Y-%m-%d'),
    '%m/%d'
)
WHERE contact_birthday IS NOT NULL;

ALTER TABLE contacts
    MODIFY COLUMN contact_birthday CHAR(5) NULL;
