-- MySQL 8 deployment equivalent of:
--   2026_07_29_100000_create_contract_withdrawals_table.php
--   2026_07_29_101000_grant_contract_withdrawal_access_to_admin_role.php
--   2026_07_29_102000_configure_contract_withdrawal_defaults.php
--
-- Idempotent: safe to run more than once.

CREATE TABLE IF NOT EXISTS `contract_withdrawals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(32) NOT NULL,
  `submission_key` VARCHAR(64) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `order_number` VARCHAR(80) NOT NULL,
  `full_name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `phone` VARCHAR(80) NULL,
  `address_line` VARCHAR(255) NOT NULL,
  `postal_code` VARCHAR(32) NOT NULL,
  `city` VARCHAR(120) NOT NULL,
  `country_code` CHAR(2) NOT NULL DEFAULT 'HR',
  `contract_date` DATE NULL,
  `received_date` DATE NULL,
  `items` TEXT NOT NULL,
  `note` TEXT NULL,
  `declaration` TEXT NOT NULL,
  `request_snapshot` JSON NOT NULL,
  `snapshot_hash` VARCHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'received',
  `internal_note` TEXT NULL,
  `locale` VARCHAR(12) NOT NULL DEFAULT 'hr',
  `submitted_at` TIMESTAMP NOT NULL,
  `consumer_notified_at` TIMESTAMP NULL,
  `admin_notified_at` TIMESTAMP NULL,
  `notification_error` TEXT NULL,
  `handled_by` BIGINT UNSIGNED NULL,
  `handled_at` TIMESTAMP NULL,
  `completed_at` TIMESTAMP NULL,
  `ip_address` VARCHAR(64) NULL,
  `user_agent` VARCHAR(512) NULL,
  `created_at` TIMESTAMP NULL,
  `updated_at` TIMESTAMP NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contract_withdrawals_reference_unique` (`reference`),
  UNIQUE KEY `contract_withdrawals_submission_key_unique` (`submission_key`),
  KEY `contract_withdrawals_order_number_index` (`order_number`),
  KEY `contract_withdrawals_email_index` (`email`),
  KEY `contract_withdrawals_status_index` (`status`),
  KEY `contract_withdrawals_submitted_at_index` (`submitted_at`),
  KEY `contract_withdrawals_status_submitted_at_index` (`status`, `submitted_at`),
  KEY `contract_withdrawals_user_id_index` (`user_id`),
  KEY `contract_withdrawals_order_id_index` (`order_id`),
  KEY `contract_withdrawals_handled_by_index` (`handled_by`),
  CONSTRAINT `contract_withdrawals_user_id_foreign`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contract_withdrawals_order_id_foreign`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `contract_withdrawals_handled_by_foreign`
    FOREIGN KEY (`handled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

INSERT INTO `abilities`
  (`name`, `title`, `entity_id`, `entity_type`, `only_owned`, `options`, `scope`, `created_at`, `updated_at`)
SELECT
  'sales.withdrawals.view',
  'View contract withdrawals',
  NULL,
  NULL,
  0,
  JSON_OBJECT('group', 'sales.withdrawals'),
  NULL,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `abilities`
  WHERE `name` = 'sales.withdrawals.view'
    AND `entity_id` IS NULL
    AND `entity_type` IS NULL
);

UPDATE `abilities`
SET
  `title` = 'View contract withdrawals',
  `options` = JSON_OBJECT('group', 'sales.withdrawals'),
  `updated_at` = NOW()
WHERE `name` = 'sales.withdrawals.view'
  AND `entity_id` IS NULL
  AND `entity_type` IS NULL;

INSERT INTO `abilities`
  (`name`, `title`, `entity_id`, `entity_type`, `only_owned`, `options`, `scope`, `created_at`, `updated_at`)
SELECT
  'sales.withdrawals.manage',
  'Manage contract withdrawals',
  NULL,
  NULL,
  0,
  JSON_OBJECT('group', 'sales.withdrawals'),
  NULL,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `abilities`
  WHERE `name` = 'sales.withdrawals.manage'
    AND `entity_id` IS NULL
    AND `entity_type` IS NULL
);

UPDATE `abilities`
SET
  `title` = 'Manage contract withdrawals',
  `options` = JSON_OBJECT('group', 'sales.withdrawals'),
  `updated_at` = NOW()
WHERE `name` = 'sales.withdrawals.manage'
  AND `entity_id` IS NULL
  AND `entity_type` IS NULL;

SET @admin_role_id = (
  SELECT `id` FROM `roles`
  WHERE `name` = 'admin' AND `scope` IS NULL
  LIMIT 1
);
SET @withdrawals_view_ability_id = (
  SELECT `id` FROM `abilities`
  WHERE `name` = 'sales.withdrawals.view'
    AND `entity_id` IS NULL
    AND `entity_type` IS NULL
  LIMIT 1
);
SET @withdrawals_manage_ability_id = (
  SELECT `id` FROM `abilities`
  WHERE `name` = 'sales.withdrawals.manage'
    AND `entity_id` IS NULL
    AND `entity_type` IS NULL
  LIMIT 1
);

INSERT INTO `permissions`
  (`ability_id`, `entity_id`, `entity_type`, `forbidden`, `scope`)
SELECT @withdrawals_view_ability_id, @admin_role_id, 'roles', 0, NULL
WHERE @admin_role_id IS NOT NULL
  AND @withdrawals_view_ability_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `permissions`
    WHERE `ability_id` = @withdrawals_view_ability_id
      AND `entity_id` = @admin_role_id
      AND `entity_type` = 'roles'
      AND `scope` IS NULL
      AND `forbidden` = 0
  );

INSERT INTO `permissions`
  (`ability_id`, `entity_id`, `entity_type`, `forbidden`, `scope`)
SELECT @withdrawals_manage_ability_id, @admin_role_id, 'roles', 0, NULL
WHERE @admin_role_id IS NOT NULL
  AND @withdrawals_manage_ability_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `permissions`
    WHERE `ability_id` = @withdrawals_manage_ability_id
      AND `entity_id` = @admin_role_id
      AND `entity_type` = 'roles'
      AND `scope` IS NULL
      AND `forbidden` = 0
  );

SET @withdrawal_admin_email = COALESCE(
  (
    SELECT `value` FROM `system_settings`
    WHERE `key` = 'store_email_orders_to'
    LIMIT 1
  ),
  JSON_QUOTE('webshop@termol.hr')
);

INSERT INTO `system_settings` (`key`, `value`, `created_at`, `updated_at`)
VALUES
  ('store_withdrawal_admin_email', @withdrawal_admin_email, NOW(), NOW()),
  ('store_withdrawal_return_address', JSON_QUOTE('TERMOL d.o.o., Lapovačka 11A, 32100 Vinkovci, Hrvatska'), NOW(), NOW()),
  ('store_withdrawal_instructions', JSON_QUOTE(''), NOW(), NOW())
ON DUPLICATE KEY UPDATE
  `key` = VALUES(`key`);

UPDATE `system_settings`
SET
  `value` = REPLACE(
    REPLACE(
      REPLACE(
        REPLACE(
          REPLACE(
            `value`,
            'Obrazac za povrat|/forma-za-povrat-i-reklamacije',
            'Raskid ugovora|/forma-za-povrat-i-reklamacije'
          ),
          'Povrat i reklamacije|/forma-za-povrat-i-reklamacije',
          'Raskid ugovora|/forma-za-povrat-i-reklamacije'
        ),
        'Returns and claims form|/returns-and-claims',
        'Withdraw from contract|/returns-and-claims'
      ),
      'Rücksendungen und Reklamationen|/rucksendungen-und-reklamationen',
      'Vertrag widerrufen|/rucksendungen-und-reklamationen'
    ),
    'Returns & claims|/returns-and-claims',
    'Withdraw from contract|/returns-and-claims'
  ),
  `updated_at` = NOW()
WHERE `key` IN (
  'store_footer_col_1_custom_links',
  'store_footer_col_2_custom_links',
  'store_footer_col_3_custom_links',
  'store_footer_col_1_custom_links_translations',
  'store_footer_col_2_custom_links_translations',
  'store_footer_col_3_custom_links_translations'
);

COMMIT;
