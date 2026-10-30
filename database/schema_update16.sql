-- =========================================================================
-- schema_update16.sql  --  AI Logs, Resume Builds & Platform Enhancements
--
-- Ensures all newer tables and schema columns exist in the database:
--   1. gemini_api_logs (API quota, latency, and model logging)
--   2. resume_builds (AI Resume Builder storage)
--   3. admin_logs (Audit trail for system administration)
--   4. signup_attempts (Rate limiting table)
--   5. saved_jobs (Candidate saved jobs)
--
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH on Ubuntu:
--   mysql -u [db_user] -p [db_name] < database/schema_update16.sql
-- =========================================================================

-- 1. Ensure gemini_api_logs table exists
CREATE TABLE IF NOT EXISTS `gemini_api_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `source` VARCHAR(50) NOT NULL,
  `model_requested` VARCHAR(100) NOT NULL,
  `attempt_number` INT NOT NULL DEFAULT 1,
  `http_status` INT DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `latency_ms` INT DEFAULT NULL,
  `error_message` VARCHAR(500) DEFAULT NULL,
  `status_label` VARCHAR(30) DEFAULT NULL,
  `message` VARCHAR(500) DEFAULT NULL,
  `user_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`source`),
  INDEX (`created_at`),
  INDEX (`success`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Ensure resume_builds table exists
CREATE TABLE IF NOT EXISTS `resume_builds` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `target_title` VARCHAR(255) DEFAULT NULL,
  `raw_input` LONGTEXT DEFAULT NULL,
  `generated_content` LONGTEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`user_id`),
  INDEX (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Ensure admin_logs table exists
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `admin_id` INT NOT NULL,
  `admin_name` VARCHAR(100) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `target_type` VARCHAR(50) NOT NULL,
  `target_id` INT DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`admin_id`),
  INDEX (`action`),
  INDEX (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Ensure signup_attempts table exists
CREATE TABLE IF NOT EXISTS `signup_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Ensure saved_jobs table exists
CREATE TABLE IF NOT EXISTS `saved_jobs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `job_id` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user_job` (`user_id`, `job_id`),
  INDEX (`user_id`),
  INDEX (`job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Ensure resume_reviews has user_id nullable and file_path column
ALTER TABLE `resume_reviews` MODIFY COLUMN `user_id` INT(11) NULL;

