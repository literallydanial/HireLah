-- =========================================================================
-- schema_update14.sql
--
-- Migration for Management Role & Initial Account:
--   1. Ensures `users.role` column is VARCHAR(50) to support 'management'
--   2. Seeds the default verified management user if not already present:
--      Email: management@thekeria.com
--      Default Password: password123
--
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH on Ubuntu:
--   mysql -u [db_user] -p [db_name] < database/schema_update14.sql
-- =========================================================================

-- 1. Ensure `role` is VARCHAR(50) so 'management' role is always allowed
ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'candidate';

-- 2. Seed default management account if it does not already exist
-- Password hash below corresponds to 'password123'
INSERT INTO `users` (`name`, `email`, `password_hash`, `role`, `is_verified`, `created_at`)
SELECT 'Management Team', 'management@thekeria.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'management', 1, NOW()
WHERE NOT EXISTS (
    SELECT 1 FROM `users` WHERE `email` = 'management@thekeria.com'
);
