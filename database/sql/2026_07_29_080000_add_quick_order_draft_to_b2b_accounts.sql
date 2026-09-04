-- MySQL 8 equivalent of:
-- database/migrations/2026_07_29_080000_add_quick_order_draft_to_b2b_accounts.php
--
-- Idempotent: the ALTER TABLE runs only when the column does not exist.

SET @quick_order_draft_exists = (
  SELECT COUNT(*)
  FROM `information_schema`.`COLUMNS`
  WHERE `TABLE_SCHEMA` = DATABASE()
    AND `TABLE_NAME` = 'b2b_accounts'
    AND `COLUMN_NAME` = 'quick_order_draft'
);

SET @quick_order_draft_sql = IF(
  @quick_order_draft_exists = 0,
  'ALTER TABLE `b2b_accounts` ADD COLUMN `quick_order_draft` JSON NULL AFTER `payload`',
  'SELECT ''Column b2b_accounts.quick_order_draft already exists'' AS `status`'
);

PREPARE quick_order_draft_statement FROM @quick_order_draft_sql;
EXECUTE quick_order_draft_statement;
DEALLOCATE PREPARE quick_order_draft_statement;

-- Manual rollback (deletes all saved quick-order drafts):
-- ALTER TABLE `b2b_accounts` DROP COLUMN `quick_order_draft`;
