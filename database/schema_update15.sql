-- =========================================================================
-- schema_update15.sql  --  Employer verification (pending / approved / rejected)
--
-- Adds a review workflow to `companies`. Existing companies are marked
-- 'approved' (default), so nobody already active is locked out. New
-- self-registered employers are set to 'pending' by the application.
-- Also adds OTP attempt limiting and a signup-attempt table for rate limiting.
-- (db.php applies the same changes automatically; this file is for manual runs.)
--
--   mysql -u [db_user] -p [db_name] < database/schema_update15.sql
-- =========================================================================
ALTER TABLE `companies`
  ADD COLUMN `verification_status` VARCHAR(20) NOT NULL DEFAULT 'approved',
  ADD COLUMN `verification_flags` VARCHAR(255) NULL,
  ADD COLUMN `submitted_at` DATETIME NULL,
  ADD COLUMN `claimed_by` INT NULL,
  ADD COLUMN `claimed_at` DATETIME NULL,
  ADD COLUMN `reviewed_by` INT NULL,
  ADD COLUMN `reviewed_at` DATETIME NULL,
  ADD COLUMN `review_note` TEXT NULL;

ALTER TABLE `users` ADD COLUMN `otp_attempts` INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS `signup_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ip_address` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
