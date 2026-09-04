-- MySQL 8 equivalent of:
-- database/migrations/2026_07_17_090000_add_german_draft_language.php
--
-- Idempotent: running this file again updates the same language record.

START TRANSACTION;

INSERT INTO `languages` (
  `code`,
  `locale`,
  `name`,
  `native_name`,
  `direction`,
  `is_default`,
  `is_active`,
  `sort_order`,
  `settings`,
  `created_at`,
  `updated_at`
) VALUES (
  'de',
  'de_DE',
  'German',
  'Deutsch',
  'ltr',
  0,
  0,
  3,
  NULL,
  NOW(),
  NOW()
)
ON DUPLICATE KEY UPDATE
  `locale` = VALUES(`locale`),
  `name` = VALUES(`name`),
  `native_name` = VALUES(`native_name`),
  `direction` = VALUES(`direction`),
  `is_default` = VALUES(`is_default`),
  `is_active` = VALUES(`is_active`),
  `sort_order` = VALUES(`sort_order`),
  `settings` = VALUES(`settings`),
  `updated_at` = VALUES(`updated_at`);

COMMIT;

-- Manual rollback, only if no German content has been entered:
-- DELETE FROM `languages`
-- WHERE `code` = 'de' AND `is_default` = 0 AND `is_active` = 0;
