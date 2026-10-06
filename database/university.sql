-- =========================================================================
-- university.sql
-- University & Higher Education Career Intelligence System Migration
-- =========================================================================
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH:
--   mysql -u [db_user] -p [db_name] < database/university.sql
-- =========================================================================

-- 1. Create universities table
CREATE TABLE IF NOT EXISTS `universities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('public','private','other') NOT NULL DEFAULT 'public',
  `ssm_number` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Add ssm_number column to universities table if not exists (for existing tables)
SET @dbname = DATABASE();
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

-- 3. Add university_id column to users table if not exists
SET @tablename = 'users';

SET @columnname = 'university_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `university_id` int(11) DEFAULT NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 4. Add matric_number column to users table if not exists
SET @columnname = 'matric_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `matric_number` varchar(50) DEFAULT NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 5. Add ssm_number column to users table if not exists
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

-- 4. Add foreign key fk_users_university if not exists
SET @constraintname = 'fk_users_university';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND CONSTRAINT_NAME = @constraintname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD KEY `fk_users_university` (`university_id`), ADD CONSTRAINT `fk_users_university` FOREIGN KEY (`university_id`) REFERENCES `universities` (`id`) ON DELETE SET NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 5. Create internship_placements table
CREATE TABLE IF NOT EXISTS `internship_placements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `candidate_user_id` int(11) NOT NULL,
  `company` varchar(255) NOT NULL,
  `supervisor` varchar(255) DEFAULT NULL,
  `supervisor_contact` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `placement_type` enum('paid','unpaid') NOT NULL DEFAULT 'paid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_candidate` (`candidate_user_id`),
  CONSTRAINT `fk_placement_user` FOREIGN KEY (`candidate_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
