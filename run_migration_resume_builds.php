<?php
// ONE-TIME MIGRATION — safe to run more than once, delete this file afterward.
// Fixes: "SQLSTATE[42S02]: Base table or view not found: 1146 Table
// 'hirelah.resume_builds' doesn't exist" when saving a resume from the AI
// Resume Builder (form mode or chat mode) — the table was defined in
// hirelah.sql but never actually created in this database.
require 'db.php';
header('Content-Type: text/plain');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `resume_builds` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `target_title` varchar(255) DEFAULT NULL,
        `raw_input` longtext DEFAULT NULL,
        `generated_content` longtext DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `fk_resumebuild_user` (`user_id`),
        CONSTRAINT `fk_resumebuild_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    echo "SUCCESS: `resume_builds` table is ready.\n";
    echo "The AI Resume Builder (form mode and chat mode) can now save generated resumes.\n";
    echo "\nPlease delete this file (run_migration_resume_builds.php) now that it has run.\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
