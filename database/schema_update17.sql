-- =========================================================================
-- schema_update17.sql  --  Student Faculty & Intake Year
--
-- Ensures the users table has the faculty/intake_year columns used by the
-- university cohort & faculty breakdown on university_dashboard.php:
--   1. users.faculty       -- fixed category label (or a free-typed "Other")
--   2. users.intake_year   -- the year a student started their programme
--
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH on Ubuntu:
--   mysql -u [db_user] -p [db_name] < database/schema_update17.sql
-- =========================================================================

-- 1. Add faculty/intake_year columns to users if not already present
SET @dbname = DATABASE();
SET @tablename = 'users';

SET @columnname = 'faculty';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `faculty` VARCHAR(100) NULL DEFAULT NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

SET @columnname = 'intake_year';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `intake_year` SMALLINT(4) NULL DEFAULT NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
