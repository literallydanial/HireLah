-- =========================================================================
-- FIX: questionnaire.php crashes with HTTP 500 when an employer saves a
-- questionnaire template WITHOUT assigning it to a specific job.
--
-- Root cause: the `questionnaires` table has `job_id` defined as NOT NULL,
-- but questionnaire.php intentionally allows job_id to be NULL (a
-- job-agnostic, reusable template — see the `$job_id = ... : null;` logic
-- in questionnaire.php). Saving with no job selected sends NULL into a
-- NOT NULL column, MySQL throws an integrity-constraint violation, and
-- since PDO is configured to throw exceptions (db.php) and display_errors
-- is off in production, the uncaught exception surfaces as a blank
-- "HTTP ERROR 500" page instead of a friendly message.
--
-- Run this once against the production database (phpMyAdmin or MySQL CLI).
-- =========================================================================

ALTER TABLE `questionnaires` MODIFY `job_id` INT(11) NULL;
