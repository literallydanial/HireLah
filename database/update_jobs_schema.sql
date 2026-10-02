-- =========================================================================
-- HIRELAH DATABASE SCHEMA UPDATE - JOB PROFILE & EMPLOYER CRUD ENHANCEMENTS
-- Execute this SQL script in phpMyAdmin or MySQL CLI if updating an existing database.
-- =========================================================================

-- 1. Add Location & Salary Columns to `jobs` table
ALTER TABLE `jobs` ADD COLUMN `location` VARCHAR(255) NULL AFTER `work_mode`;
ALTER TABLE `jobs` ADD COLUMN `salary_min` INT NULL AFTER `location`;
ALTER TABLE `jobs` ADD COLUMN `salary_max` INT NULL AFTER `salary_min`;
ALTER TABLE `jobs` ADD COLUMN `salary_text` VARCHAR(255) NULL AFTER `salary_max`;

-- 2. Add Structured Content Columns to `jobs` table
ALTER TABLE `jobs` ADD COLUMN `responsibilities` TEXT NULL AFTER `description`;
ALTER TABLE `jobs` ADD COLUMN `requirements` TEXT NULL AFTER `responsibilities`;
ALTER TABLE `jobs` ADD COLUMN `perks` TEXT NULL AFTER `requirements`;

-- =========================================================================
-- FULL REFRESH / NEW INSTALLATION CREATE TABLE SCHEMA STATEMENT
-- =========================================================================

/*
CREATE TABLE IF NOT EXISTS `jobs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employer_id` INT NULL,
    `job_title` VARCHAR(255) NOT NULL,
    `department` VARCHAR(255) NULL,
    `employment_type` VARCHAR(100) DEFAULT 'Full-time',
    `work_mode` VARCHAR(100) DEFAULT 'Hybrid',
    `location` VARCHAR(255) NULL,
    `salary_min` INT NULL,
    `salary_max` INT NULL,
    `salary_text` VARCHAR(255) NULL,
    `require_video` TINYINT(1) DEFAULT 0,
    `status` VARCHAR(50) DEFAULT 'Active',
    `description` TEXT NULL,
    `responsibilities` TEXT NULL,
    `requirements` TEXT NULL,
    `perks` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`employer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/
