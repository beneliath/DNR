-- Store previously formatted international telephone values in canonical
-- E.164 form. Display formatting is applied at the application boundary.

UPDATE organizations
SET phone = CONCAT('+', REGEXP_REPLACE(phone, '[^0-9]', ''))
WHERE phone IS NOT NULL
  AND TRIM(phone) REGEXP '^[+]'
  AND CHAR_LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) BETWEEN 8 AND 15;

UPDATE organizations
SET fax = CONCAT('+', REGEXP_REPLACE(fax, '[^0-9]', ''))
WHERE fax IS NOT NULL
  AND TRIM(fax) REGEXP '^[+]'
  AND CHAR_LENGTH(REGEXP_REPLACE(fax, '[^0-9]', '')) BETWEEN 8 AND 15;

UPDATE contacts
SET contact_phone = CONCAT('+', REGEXP_REPLACE(contact_phone, '[^0-9]', ''))
WHERE contact_phone IS NOT NULL
  AND TRIM(contact_phone) REGEXP '^[+]'
  AND CHAR_LENGTH(REGEXP_REPLACE(contact_phone, '[^0-9]', '')) BETWEEN 8 AND 15;

UPDATE users
SET phone = CONCAT('+', REGEXP_REPLACE(phone, '[^0-9]', ''))
WHERE phone IS NOT NULL
  AND TRIM(phone) REGEXP '^[+]'
  AND CHAR_LENGTH(REGEXP_REPLACE(phone, '[^0-9]', '')) BETWEEN 8 AND 15;
