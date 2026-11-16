-- =========================================================================
-- schema_update19.sql  --  AI Quota & Referral Reward System
--
-- Implements weekly submission quotas (3 free uses per week) and referral
-- bonus credits (+3 extra uses each) for:
--   1. AI Resume Screening (resume_check.php)
--   2. AI Resume Builder (resume_builder.php)
--
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH on Ubuntu:
--   mysql -u [db_user] -p [db_name] < database/schema_update19.sql
-- =========================================================================

SET @dbname = DATABASE();
SET @tablename = 'users';

-- 1. Add referral_code to users if not present
SET @columnname = 'referral_code';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `referral_code` VARCHAR(30) NULL DEFAULT NULL AFTER `role`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Add bonus_screening_credits to users if not present
SET @columnname = 'bonus_screening_credits';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `bonus_screening_credits` INT(11) NOT NULL DEFAULT 0 AFTER `referral_code`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 3. Add bonus_builder_credits to users if not present
SET @columnname = 'bonus_builder_credits';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `bonus_builder_credits` INT(11) NOT NULL DEFAULT 0 AFTER `bonus_screening_credits`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 4. Add referred_by_user_id to users if not present
SET @columnname = 'referred_by_user_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `referred_by_user_id` INT(11) NULL DEFAULT NULL AFTER `bonus_builder_credits`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 5. Add index on referral_code if not present
SET @indexname = 'idx_users_referral_code';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD INDEX `idx_users_referral_code` (`referral_code`);'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 6. Create user_referrals table if not exists
CREATE TABLE IF NOT EXISTS `user_referrals` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `referrer_id` int(11) NOT NULL,
    `referred_id` int(11) NOT NULL,
    `referral_code` varchar(30) NOT NULL,
    `credits_awarded` int(11) NOT NULL DEFAULT 3,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_referred_unique` (`referred_id`),
    KEY `idx_referrer` (`referrer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Populate referral_code for any existing users lacking one
UPDATE `users` 
SET `referral_code` = CONCAT('KL-', UPPER(SUBSTRING(MD5(CONCAT(id, email, 'keria_referral_salt_2026')), 1, 6)))
WHERE `referral_code` IS NULL OR `referral_code` = '';

