-- MySQL 8 / MariaDB production script.
-- Creates or updates Marina Preradovic and assigns exactly the global `admin` role.
--
-- The bcrypt value is a strong temporary password. It is intentionally not kept
-- in plaintext in this repository. After the script is run, Marina should use
-- the "Forgot password" flow to choose her own password.
--
-- Idempotency:
-- - an existing user's password is preserved;
-- - profile and billing contact details are refreshed;
-- - global role assignments are replaced with one `admin` assignment;
-- - no duplicate user, profile, address, or role assignment is created.

START TRANSACTION;

SET @marina_email = 'marina@termol.hr';
SET @marina_user_morph = CONCAT('App', CHAR(92), 'Models', CHAR(92), 'User');
SET @marina_admin_role_id = (
    SELECT `roles`.`id`
    FROM `roles`
    WHERE `roles`.`name` = 'admin'
      AND `roles`.`scope` IS NULL
      AND EXISTS (
          SELECT 1
          FROM `permissions`
          INNER JOIN `abilities`
              ON `abilities`.`id` = `permissions`.`ability_id`
          WHERE `permissions`.`entity_id` = `roles`.`id`
            AND `permissions`.`entity_type` = 'roles'
            AND `permissions`.`scope` IS NULL
            AND `permissions`.`forbidden` = 0
            AND `abilities`.`name` = 'admin.access'
            AND `abilities`.`entity_id` IS NULL
            AND `abilities`.`entity_type` IS NULL
      )
    ORDER BY `roles`.`id`
    LIMIT 1
);

INSERT INTO `users` (
    `name`,
    `email`,
    `email_verified_at`,
    `password`,
    `remember_token`,
    `created_at`,
    `updated_at`
)
SELECT
    'Marina Preradovic',
    @marina_email,
    CURRENT_TIMESTAMP,
    '$2y$12$X9/DqGGwkdVe/2kemqCCrO/YUCK7hq7xq2FQJ2gYvSPBjqYqSzKBK',
    NULL,
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @marina_admin_role_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `email_verified_at` = COALESCE(`email_verified_at`, VALUES(`email_verified_at`)),
    `updated_at` = VALUES(`updated_at`);

SET @marina_user_id = (
    SELECT `id`
    FROM `users`
    WHERE `email` = @marina_email
    LIMIT 1
);

INSERT INTO `user_profiles` (
    `user_id`,
    `first_name`,
    `last_name`,
    `phone`,
    `company`,
    `oib`,
    `newsletter_opt_in`,
    `payload`,
    `created_at`,
    `updated_at`
)
SELECT
    @marina_user_id,
    'Marina',
    'Preradovic',
    '+385 95 66 555 44',
    'Termol d.o.o.',
    '43394280046',
    0,
    JSON_OBJECT(
        'telephone', '+385 32 550 222',
        'mobile', '+385 95 66 555 44'
    ),
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @marina_admin_role_id IS NOT NULL
  AND @marina_user_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM `user_profiles`
      WHERE `user_id` = @marina_user_id
  );

UPDATE `user_profiles`
SET
    `first_name` = 'Marina',
    `last_name` = 'Preradovic',
    `phone` = '+385 95 66 555 44',
    `company` = 'Termol d.o.o.',
    `oib` = '43394280046',
    `payload` = JSON_MERGE_PATCH(
        COALESCE(`payload`, JSON_OBJECT()),
        JSON_OBJECT(
            'telephone', '+385 32 550 222',
            'mobile', '+385 95 66 555 44'
        )
    ),
    `updated_at` = CURRENT_TIMESTAMP
WHERE `user_id` = @marina_user_id
  AND @marina_admin_role_id IS NOT NULL;

INSERT INTO `user_addresses` (
    `user_id`,
    `type`,
    `first_name`,
    `last_name`,
    `company`,
    `oib`,
    `phone`,
    `address_line_1`,
    `postal_code`,
    `city`,
    `country_code`,
    `is_default`,
    `payload`,
    `created_at`,
    `updated_at`
)
SELECT
    @marina_user_id,
    'billing',
    'Marina',
    'Preradovic',
    'Termol d.o.o.',
    '43394280046',
    '+385 32 550 222',
    'Lapovačka 11A',
    '32100',
    'Vinkovci',
    'HR',
    1,
    JSON_OBJECT('mobile', '+385 95 66 555 44'),
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
WHERE @marina_admin_role_id IS NOT NULL
  AND @marina_user_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    `first_name` = VALUES(`first_name`),
    `last_name` = VALUES(`last_name`),
    `company` = VALUES(`company`),
    `oib` = VALUES(`oib`),
    `phone` = VALUES(`phone`),
    `address_line_1` = VALUES(`address_line_1`),
    `postal_code` = VALUES(`postal_code`),
    `city` = VALUES(`city`),
    `country_code` = VALUES(`country_code`),
    `is_default` = VALUES(`is_default`),
    `payload` = JSON_MERGE_PATCH(COALESCE(`payload`, JSON_OBJECT()), VALUES(`payload`)),
    `updated_at` = VALUES(`updated_at`);

DELETE FROM `assigned_roles`
WHERE `entity_id` = @marina_user_id
  AND `entity_type` = @marina_user_morph
  AND `scope` IS NULL
  AND @marina_admin_role_id IS NOT NULL;

-- All writes are guarded by the seeded `admin` role and its `admin.access`
-- permission, so missing ACL setup cannot leave a partially configured user.
INSERT INTO `assigned_roles` (
    `role_id`,
    `entity_id`,
    `entity_type`,
    `restricted_to_id`,
    `restricted_to_type`,
    `scope`
)
SELECT
    @marina_admin_role_id,
    @marina_user_id,
    @marina_user_morph,
    NULL,
    NULL,
    NULL
WHERE @marina_admin_role_id IS NOT NULL
  AND @marina_user_id IS NOT NULL;

COMMIT;

-- Verification: status should be OK and the second query should return one
-- row with role_name = admin.
SELECT CASE
    WHEN @marina_admin_role_id IS NULL
        THEN 'ERROR: configured admin role with admin.access is missing'
    WHEN @marina_user_id IS NULL
        THEN 'ERROR: Marina user was not created'
    WHEN NOT EXISTS (
        SELECT 1
        FROM `assigned_roles`
        WHERE `role_id` = @marina_admin_role_id
          AND `entity_id` = @marina_user_id
          AND `entity_type` = @marina_user_morph
          AND `scope` IS NULL
    )
        THEN 'ERROR: admin role was not assigned'
    ELSE 'OK'
END AS `status`;

SELECT
    `users`.`id` AS `user_id`,
    `users`.`name`,
    `users`.`email`,
    `users`.`email_verified_at`,
    `roles`.`name` AS `role_name`
FROM `users`
INNER JOIN `assigned_roles`
    ON `assigned_roles`.`entity_id` = `users`.`id`
   AND `assigned_roles`.`entity_type` = @marina_user_morph
   AND `assigned_roles`.`scope` IS NULL
INNER JOIN `roles`
    ON `roles`.`id` = `assigned_roles`.`role_id`
WHERE `users`.`email` = @marina_email;
