-- Run this once in phpMyAdmin (SQL tab, `hirelah` database) to add the
-- company_media table for the employer "Company Gallery" feature.
-- This matches exactly what's now recorded in hirelah.sql.

CREATE TABLE `company_media` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `media_type` enum('image','video') NOT NULL DEFAULT 'image',
  `file_path` varchar(255) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `company_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_companymedia_user` (`user_id`);

ALTER TABLE `company_media`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `company_media`
  ADD CONSTRAINT `fk_companymedia_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
