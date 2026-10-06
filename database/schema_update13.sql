-- =========================================================================
-- schema_update13.sql
--
-- Migration for University SSM Registration Number:
--   1. Add `ssm_number` column to `users` table
--   2. Add `ssm_number` column to `universities` table
--
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH:
--   mysql -u [db_user] -p [db_name] < database/schema_update13.sql
-- =========================================================================

SET @dbname = DATABASE();

-- 1. Add ssm_number column to users table if not exists
SET @tablename = 'users';
SET @columnname = 'ssm_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `ssm_number` varchar(100) DEFAULT NULL AFTER `matric_number`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Add ssm_number column to universities table if not exists
SET @tablename = 'universities';
SET @columnname = 'ssm_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `universities` ADD COLUMN `ssm_number` varchar(100) DEFAULT NULL AFTER `type`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
