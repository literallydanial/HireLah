-- schema_update11.sql
-- Add resume_reviews table for the standalone "AI Resume Checker" tool
-- (general resume feedback, not tied to any specific job application)
CREATE TABLE `resume_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `full_text` longtext DEFAULT NULL,
  `stripped_text` longtext DEFAULT NULL,
  `overall_score` int(11) DEFAULT 0,
  `rating_label` varchar(50) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `strengths` text DEFAULT NULL,
  `improvements` text DEFAULT NULL,
  `formatting_notes` text DEFAULT NULL,
  `ats_tips` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_resumereview_user` (`user_id`),
  CONSTRAINT `fk_resumereview_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
