-- MySQL 8 equivalent of:
-- database/migrations/2026_07_17_091000_fix_bali_legal_and_footer_links.php
--
-- Idempotent: replacements and JSON upserts are safe to run more than once.
-- Existing translation values are preserved; only missing HR/EN defaults are added.

START TRANSACTION;

-- Replace obsolete cookie-policy links on every information page.
UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'https://www.balidoo.hr/pravila-zastite-podataka-i-privatnosti',
    '/page/pravila-zastite-podataka-i-privatnosti'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%https://www.balidoo.hr/pravila-zastite-podataka-i-privatnosti%';

UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'http://www.balidoo.hr/pravila-zastite-podataka-i-privatnosti',
    '/page/pravila-zastite-podataka-i-privatnosti'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%http://www.balidoo.hr/pravila-zastite-podataka-i-privatnosti%';

-- Replace obsolete cancellation-form links with the local PDF.
UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'https://www.kozo-underwear.hr/image/catalog/PDF/obrazac raskid ugovora novo.pdf',
    '/documents/obrazac-za-jednostrani-raskid-ugovora.pdf'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%https://www.kozo-underwear.hr/image/catalog/PDF/obrazac raskid ugovora novo.pdf%';

UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'https://www.kozo-underwear.hr/image/catalog/PDF/obrazac%20raskid%20ugovora%20novo.pdf',
    '/documents/obrazac-za-jednostrani-raskid-ugovora.pdf'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%https://www.kozo-underwear.hr/image/catalog/PDF/obrazac%20raskid%20ugovora%20novo.pdf%';

UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'https://www.bali.hr/image/catalog/PDF/obrazac raskid ugovora novo.pdf',
    '/documents/obrazac-za-jednostrani-raskid-ugovora.pdf'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%https://www.bali.hr/image/catalog/PDF/obrazac raskid ugovora novo.pdf%';

UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'https://www.bali.hr/image/catalog/PDF/obrazac%20raskid%20ugovora%20novo.pdf',
    '/documents/obrazac-za-jednostrani-raskid-ugovora.pdf'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%https://www.bali.hr/image/catalog/PDF/obrazac%20raskid%20ugovora%20novo.pdf%';

UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'http://www.balidoo.hr/image/catalog/pdf/Raskid ugovora Balidoo.pdf',
    '/documents/obrazac-za-jednostrani-raskid-ugovora.pdf'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%http://www.balidoo.hr/image/catalog/pdf/Raskid ugovora Balidoo.pdf%';

UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'https://www.balidoo.hr/image/catalog/pdf/Raskid ugovora Balidoo.pdf',
    '/documents/obrazac-za-jednostrani-raskid-ugovora.pdf'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%https://www.balidoo.hr/image/catalog/pdf/Raskid ugovora Balidoo.pdf%';

-- Replace the obsolete return PDF with the online return form.
UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'http://www.balidoo.hr/image/catalog/pdf/povrat.pdf',
    '/forma-za-povrat-i-reklamacije'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%http://www.balidoo.hr/image/catalog/pdf/povrat.pdf%';

UPDATE `content_info_page_translations`
SET
  `body_html` = REPLACE(
    `body_html`,
    'https://www.balidoo.hr/image/catalog/pdf/povrat.pdf',
    '/forma-za-povrat-i-reklamacije'
  ),
  `updated_at` = NOW()
WHERE `body_html` LIKE '%https://www.balidoo.hr/image/catalog/pdf/povrat.pdf%';

-- Read the current footer links. System-setting strings are stored as JSON strings.
SET @footer_links_raw = (
  SELECT `value`
  FROM `system_settings`
  WHERE `key` = 'store_footer_col_2_custom_links'
  LIMIT 1
);

SET @footer_links = CASE
  WHEN @footer_links_raw IS NULL OR @footer_links_raw = '' THEN ''
  WHEN JSON_VALID(@footer_links_raw) THEN JSON_UNQUOTE(JSON_EXTRACT(@footer_links_raw, '$'))
  ELSE @footer_links_raw
END;

SET @footer_links = REPLACE(COALESCE(@footer_links, ''), '|/kontakt', '|/contact');

SET @footer_links = CASE
  WHEN LOCATE('/forma-za-povrat-i-reklamacije', @footer_links) > 0 THEN @footer_links
  ELSE CONCAT_WS(
    CHAR(10),
    NULLIF(TRIM(@footer_links), ''),
    'Obrazac za povrat|/forma-za-povrat-i-reklamacije'
  )
END;

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES (
  'store_footer_col_2_custom_links',
  JSON_QUOTE(@footer_links),
  NOW(),
  NOW()
)
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = VALUES(`updated_at`);

SET @footer_links_en = REPLACE(
  REPLACE(
    @footer_links,
    'Kontakt|/contact',
    'Contact|/contact'
  ),
  'Obrazac za povrat|/forma-za-povrat-i-reklamacije',
  'Returns and claims form|/returns-and-claims'
);

SET @footer_translations_raw = (
  SELECT `value`
  FROM `system_settings`
  WHERE `key` = 'store_footer_col_2_custom_links_translations'
  LIMIT 1
);

SET @footer_translations = CASE
  WHEN @footer_translations_raw IS NOT NULL
    AND JSON_VALID(@footer_translations_raw)
    AND JSON_TYPE(JSON_EXTRACT(@footer_translations_raw, '$')) = 'OBJECT'
  THEN @footer_translations_raw
  ELSE JSON_OBJECT()
END;

SET @footer_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_translations, 'one', '$.hr') = 1 THEN @footer_translations
  ELSE JSON_SET(@footer_translations, '$.hr', @footer_links)
END;

SET @footer_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_translations, 'one', '$.en') = 1 THEN @footer_translations
  ELSE JSON_SET(@footer_translations, '$.en', @footer_links_en)
END;

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES (
  'store_footer_col_2_custom_links_translations',
  @footer_translations,
  NOW(),
  NOW()
)
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = VALUES(`updated_at`);

-- Add missing HR/EN translations for each footer-column title.
SET @footer_title_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_footer_col_1_title' LIMIT 1
);
SET @footer_title_hr = CASE
  WHEN @footer_title_raw IS NULL OR @footer_title_raw = '' THEN 'Shop'
  WHEN JSON_VALID(@footer_title_raw) THEN JSON_UNQUOTE(JSON_EXTRACT(@footer_title_raw, '$'))
  ELSE @footer_title_raw
END;
SET @footer_title_hr = COALESCE(NULLIF(TRIM(@footer_title_hr), ''), 'Shop');
SET @footer_title_translations_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_footer_col_1_title_translations' LIMIT 1
);
SET @footer_title_translations = CASE
  WHEN @footer_title_translations_raw IS NOT NULL
    AND JSON_VALID(@footer_title_translations_raw)
    AND JSON_TYPE(JSON_EXTRACT(@footer_title_translations_raw, '$')) = 'OBJECT'
  THEN @footer_title_translations_raw
  ELSE JSON_OBJECT()
END;
SET @footer_title_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_title_translations, 'one', '$.hr') = 1 THEN @footer_title_translations
  ELSE JSON_SET(@footer_title_translations, '$.hr', @footer_title_hr)
END;
SET @footer_title_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_title_translations, 'one', '$.en') = 1 THEN @footer_title_translations
  ELSE JSON_SET(@footer_title_translations, '$.en', 'Shop')
END;
INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('store_footer_col_1_title_translations', @footer_title_translations, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = VALUES(`updated_at`);

SET @footer_title_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_footer_col_2_title' LIMIT 1
);
SET @footer_title_hr = CASE
  WHEN @footer_title_raw IS NULL OR @footer_title_raw = '' THEN 'Pomoć'
  WHEN JSON_VALID(@footer_title_raw) THEN JSON_UNQUOTE(JSON_EXTRACT(@footer_title_raw, '$'))
  ELSE @footer_title_raw
END;
SET @footer_title_hr = COALESCE(NULLIF(TRIM(@footer_title_hr), ''), 'Pomoć');
SET @footer_title_translations_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_footer_col_2_title_translations' LIMIT 1
);
SET @footer_title_translations = CASE
  WHEN @footer_title_translations_raw IS NOT NULL
    AND JSON_VALID(@footer_title_translations_raw)
    AND JSON_TYPE(JSON_EXTRACT(@footer_title_translations_raw, '$')) = 'OBJECT'
  THEN @footer_title_translations_raw
  ELSE JSON_OBJECT()
END;
SET @footer_title_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_title_translations, 'one', '$.hr') = 1 THEN @footer_title_translations
  ELSE JSON_SET(@footer_title_translations, '$.hr', @footer_title_hr)
END;
SET @footer_title_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_title_translations, 'one', '$.en') = 1 THEN @footer_title_translations
  ELSE JSON_SET(@footer_title_translations, '$.en', 'Help')
END;
INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('store_footer_col_2_title_translations', @footer_title_translations, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = VALUES(`updated_at`);

SET @footer_title_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_footer_col_3_title' LIMIT 1
);
SET @footer_title_hr = CASE
  WHEN @footer_title_raw IS NULL OR @footer_title_raw = '' THEN 'Informacije'
  WHEN JSON_VALID(@footer_title_raw) THEN JSON_UNQUOTE(JSON_EXTRACT(@footer_title_raw, '$'))
  ELSE @footer_title_raw
END;
SET @footer_title_hr = COALESCE(NULLIF(TRIM(@footer_title_hr), ''), 'Informacije');
SET @footer_title_translations_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_footer_col_3_title_translations' LIMIT 1
);
SET @footer_title_translations = CASE
  WHEN @footer_title_translations_raw IS NOT NULL
    AND JSON_VALID(@footer_title_translations_raw)
    AND JSON_TYPE(JSON_EXTRACT(@footer_title_translations_raw, '$')) = 'OBJECT'
  THEN @footer_title_translations_raw
  ELSE JSON_OBJECT()
END;
SET @footer_title_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_title_translations, 'one', '$.hr') = 1 THEN @footer_title_translations
  ELSE JSON_SET(@footer_title_translations, '$.hr', @footer_title_hr)
END;
SET @footer_title_translations = CASE
  WHEN JSON_CONTAINS_PATH(@footer_title_translations, 'one', '$.en') = 1 THEN @footer_title_translations
  ELSE JSON_SET(@footer_title_translations, '$.en', 'Information')
END;
INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('store_footer_col_3_title_translations', @footer_title_translations, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = VALUES(`updated_at`);

-- Set the cookie-policy URL only when the base value is empty.
SET @cookie_policy_url = '/page/pravila-zastite-podataka-i-privatnosti';
SET @cookie_policy_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_cookie_consent_policy_url' LIMIT 1
);
SET @cookie_policy_current = CASE
  WHEN @cookie_policy_raw IS NULL OR @cookie_policy_raw = '' THEN ''
  WHEN JSON_VALID(@cookie_policy_raw) THEN JSON_UNQUOTE(JSON_EXTRACT(@cookie_policy_raw, '$'))
  ELSE @cookie_policy_raw
END;

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES (
  'store_cookie_consent_policy_url',
  JSON_QUOTE(@cookie_policy_url),
  NOW(),
  NOW()
)
ON DUPLICATE KEY UPDATE
  `value` = CASE
    WHEN TRIM(COALESCE(@cookie_policy_current, '')) = '' THEN VALUES(`value`)
    ELSE `value`
  END,
  `updated_at` = NOW();

SET @cookie_translations_raw = (
  SELECT `value` FROM `system_settings`
  WHERE `key` = 'store_cookie_consent_policy_url_translations' LIMIT 1
);
SET @cookie_translations = CASE
  WHEN @cookie_translations_raw IS NOT NULL
    AND JSON_VALID(@cookie_translations_raw)
    AND JSON_TYPE(JSON_EXTRACT(@cookie_translations_raw, '$')) = 'OBJECT'
  THEN @cookie_translations_raw
  ELSE JSON_OBJECT()
END;
SET @cookie_translations = CASE
  WHEN JSON_CONTAINS_PATH(@cookie_translations, 'one', '$.hr') = 1 THEN @cookie_translations
  ELSE JSON_SET(@cookie_translations, '$.hr', @cookie_policy_url)
END;
SET @cookie_translations = CASE
  WHEN JSON_CONTAINS_PATH(@cookie_translations, 'one', '$.en') = 1 THEN @cookie_translations
  ELSE JSON_SET(@cookie_translations, '$.en', @cookie_policy_url)
END;
INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES ('store_cookie_consent_policy_url_translations', @cookie_translations, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `updated_at` = VALUES(`updated_at`);

COMMIT;

-- Application cache is outside the scope of SQL. After importing, run:
-- php artisan cache:forget settings.system.map
--
-- This migration intentionally has no automatic rollback because it repairs
-- links that returned HTTP 404 and preserves any later administrator edits.
