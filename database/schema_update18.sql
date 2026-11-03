-- =========================================================================
-- schema_update18.sql  --  Resume Hash Dedup (HR Resume Upload)
--
-- Ensures the candidates table has the resume_hash column used by
-- upload.php's HR-side external resume upload feature:
--   1. candidates.resume_hash  -- SHA-256 of the uploaded PDF's raw bytes,
--                                  used to silently skip re-uploading the
--                                  exact same resume twice onto one job.
--   2. idx_job_resume_hash     -- composite index on (job_id, resume_hash)
--                                  so the duplicate lookup stays fast.
--
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH on Ubuntu:
--   mysql -u [db_user] -p [db_name] < database/schema_update18.sql
-- =========================================================================

-- 1. Add resume_hash column to candidates if not already present
SET @dbname = DATABASE();
SET @tablename = 'candidates';

SET @columnname = 'resume_hash';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `candidates` ADD COLUMN `resume_hash` CHAR(64) NULL DEFAULT NULL AFTER `resume_path`;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 2. Add composite (job_id, resume_hash) index if not already present
SET @indexname = 'idx_job_resume_hash';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND INDEX_NAME = @indexname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `candidates` ADD INDEX `idx_job_resume_hash` (`job_id`, `resume_hash`);'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;
