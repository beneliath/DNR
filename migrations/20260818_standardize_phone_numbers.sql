-- Existing telephone values predate country-code entry. Treat every legacy
-- 10-digit number (or 11-digit number beginning with 1) as a U.S. number.

UPDATE organizations
SET phone = CASE
    WHEN CHAR_LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) = 10 THEN
        CONCAT(
            '+1 (', SUBSTRING(REGEXP_REPLACE(phone, '[^0-9]', ''), 1, 3), ') ',
            SUBSTRING(REGEXP_REPLACE(phone, '[^0-9]', ''), 4, 3), '-',
            SUBSTRING(REGEXP_REPLACE(phone, '[^0-9]', ''), 7, 4)
        )
    ELSE
        CONCAT(
            '+1 (', SUBSTRING(REGEXP_REPLACE(phone, '[^0-9]', ''), 2, 3), ') ',
            SUBSTRING(REGEXP_REPLACE(phone, '[^0-9]', ''), 5, 3), '-',
            SUBSTRING(REGEXP_REPLACE(phone, '[^0-9]', ''), 8, 4)
        )
    END
WHERE phone IS NOT NULL
  AND TRIM(phone) <> ''
  AND (
      CHAR_LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) = 10
      OR (
          CHAR_LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) = 11
          AND LEFT(REGEXP_REPLACE(phone, '[^0-9]', ''), 1) = '1'
      )
  );

UPDATE organizations
SET fax = CASE
    WHEN CHAR_LENGTH(REGEXP_REPLACE(fax, '[^0-9]', '')) = 10 THEN
        CONCAT(
            '+1 (', SUBSTRING(REGEXP_REPLACE(fax, '[^0-9]', ''), 1, 3), ') ',
            SUBSTRING(REGEXP_REPLACE(fax, '[^0-9]', ''), 4, 3), '-',
            SUBSTRING(REGEXP_REPLACE(fax, '[^0-9]', ''), 7, 4)
        )
    ELSE
        CONCAT(
            '+1 (', SUBSTRING(REGEXP_REPLACE(fax, '[^0-9]', ''), 2, 3), ') ',
            SUBSTRING(REGEXP_REPLACE(fax, '[^0-9]', ''), 5, 3), '-',
            SUBSTRING(REGEXP_REPLACE(fax, '[^0-9]', ''), 8, 4)
        )
    END
WHERE fax IS NOT NULL
  AND TRIM(fax) <> ''
  AND (
      CHAR_LENGTH(REGEXP_REPLACE(fax, '[^0-9]', '')) = 10
      OR (
          CHAR_LENGTH(REGEXP_REPLACE(fax, '[^0-9]', '')) = 11
          AND LEFT(REGEXP_REPLACE(fax, '[^0-9]', ''), 1) = '1'
      )
  );

UPDATE contacts
SET contact_phone = CASE
    WHEN CHAR_LENGTH(REGEXP_REPLACE(contact_phone, '[^0-9]', '')) = 10 THEN
        CONCAT(
            '+1 (', SUBSTRING(REGEXP_REPLACE(contact_phone, '[^0-9]', ''), 1, 3), ') ',
            SUBSTRING(REGEXP_REPLACE(contact_phone, '[^0-9]', ''), 4, 3), '-',
            SUBSTRING(REGEXP_REPLACE(contact_phone, '[^0-9]', ''), 7, 4)
        )
    ELSE
        CONCAT(
            '+1 (', SUBSTRING(REGEXP_REPLACE(contact_phone, '[^0-9]', ''), 2, 3), ') ',
            SUBSTRING(REGEXP_REPLACE(contact_phone, '[^0-9]', ''), 5, 3), '-',
            SUBSTRING(REGEXP_REPLACE(contact_phone, '[^0-9]', ''), 8, 4)
        )
    END
WHERE contact_phone IS NOT NULL
  AND TRIM(contact_phone) <> ''
  AND (
      CHAR_LENGTH(REGEXP_REPLACE(contact_phone, '[^0-9]', '')) = 10
      OR (
          CHAR_LENGTH(REGEXP_REPLACE(contact_phone, '[^0-9]', '')) = 11
          AND LEFT(REGEXP_REPLACE(contact_phone, '[^0-9]', ''), 1) = '1'
      )
  );
