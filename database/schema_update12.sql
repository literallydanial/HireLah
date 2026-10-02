-- =========================================================================
-- schema_update12.sql
--
-- Migration for University & Career Center features:
--   1. Create `universities` table and populate all 49 institutions
--   2. Add `university_id` and `matric_number` columns to `users` table
--   3. Add foreign key `fk_users_university` linking users -> universities
--   4. Create `internship_placements` table for student placement tracking
--
-- Safe to run in MySQL CLI, phpMyAdmin, or AWS RDS / EC2.
-- =========================================================================

-- 1. Create universities table
CREATE TABLE IF NOT EXISTS `universities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `type` enum('public','private','other') NOT NULL DEFAULT 'public',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Populate standard universities list (INSERT IGNORE prevents duplicates)
INSERT IGNORE INTO `universities` (`id`, `name`, `type`) VALUES
(1, 'Universiti Malaya (UM)', 'public'),
(2, 'Universiti Putra Malaysia (UPM)', 'public'),
(3, 'Universiti Sains Malaysia (USM)', 'public'),
(4, 'Universiti Kebangsaan Malaysia (UKM)', 'public'),
(5, 'Universiti Teknologi Malaysia (UTM)', 'public'),
(6, 'Universiti Utara Malaysia (UUM)', 'public'),
(7, 'Universiti Teknologi MARA (UiTM)', 'public'),
(8, 'International Islamic University Malaysia (IIUM)', 'public'),
(9, 'Universiti Malaysia Pahang Al-Sultan Abdullah (UMPSA)', 'public'),
(10, 'Universiti Malaysia Terengganu (UMT)', 'public'),
(11, 'Universiti Malaysia Perlis (UniMAP)', 'public'),
(12, 'Universiti Malaysia Sabah (UMS)', 'public'),
(13, 'Universiti Malaysia Sarawak (UNIMAS)', 'public'),
(14, 'Universiti Pendidikan Sultan Idris (UPSI)', 'public'),
(15, 'Universiti Tun Hussein Onn Malaysia (UTHM)', 'public'),
(16, 'Universiti Teknikal Malaysia Melaka (UTeM)', 'public'),
(17, 'Universiti Sultan Zainal Abidin (UniSZA)', 'public'),
(18, 'Universiti Sains Islam Malaysia (USIM)', 'public'),
(19, 'Universiti Malaysia Kelantan (UMK)', 'public'),
(20, 'Universiti Pertahanan Nasional Malaysia (UPNM)', 'public'),
(21, 'Universiti Tunku Abdul Rahman (UTAR)', 'private'),
(22, 'Taylor\'s University', 'private'),
(23, 'Sunway University', 'private'),
(24, 'INTI International University', 'private'),
(25, 'Multimedia University (MMU)', 'private'),
(26, 'UCSI University', 'private'),
(27, 'SEGi University', 'private'),
(28, 'Asia Pacific University of Technology & Innovation (APU)', 'private'),
(29, 'HELP University', 'private'),
(30, 'Management & Science University (MSU)', 'private'),
(31, 'Universiti Teknologi PETRONAS (UTP)', 'private'),
(32, 'Universiti Tenaga Nasional (UNITEN)', 'private'),
(33, 'Monash University Malaysia', 'private'),
(34, 'University of Nottingham Malaysia', 'private'),
(35, 'Xiamen University Malaysia', 'private'),
(36, 'Heriot-Watt University Malaysia', 'private'),
(37, 'Swinburne University of Technology Sarawak Campus', 'private'),
(38, 'Curtin University Malaysia', 'private'),
(39, 'Newcastle University Medicine Malaysia', 'private'),
(40, 'Albukhary International University', 'private'),
(41, 'Universiti Kuala Lumpur (UniKL)', 'private'),
(42, 'Universiti Selangor (UNISEL)', 'private'),
(43, 'MAHSA University', 'private'),
(44, 'Lincoln University College', 'private'),
(45, 'Limkokwing University of Creative Technology', 'private'),
(46, 'Asia e University (AeU)', 'private'),
(47, 'Open University Malaysia (OUM)', 'private'),
(48, 'Wawasan Open University', 'private'),
(49, 'Other / Not Listed', 'other');

-- 3. Add university_id to users (safely check if exists)
SET @dbname = DATABASE();
SET @tablename = 'users';
SET @columnname = 'university_id';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `university_id` int(11) DEFAULT NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 4. Add matric_number to users (safely check if exists)
SET @columnname = 'matric_number';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND COLUMN_NAME = @columnname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD COLUMN `matric_number` varchar(50) DEFAULT NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 5. Add foreign key constraint if not present
SET @constraintname = 'fk_users_university';
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE
      TABLE_SCHEMA = @dbname
      AND TABLE_NAME = @tablename
      AND CONSTRAINT_NAME = @constraintname
  ) > 0,
  'SELECT 1',
  'ALTER TABLE `users` ADD KEY `fk_users_university` (`university_id`), ADD CONSTRAINT `fk_users_university` FOREIGN KEY (`university_id`) REFERENCES `universities` (`id`) ON DELETE SET NULL;'
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 6. Create internship_placements table
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
