-- =========================================================================
-- schema_update11.sql
--
-- Safety net for the new Employer "Danger Zone: Account Deletion" feature
-- (and for the existing admin-panel employer deletion in admin_dashboard.php,
-- which shares the same risk).
--
-- Root cause: `jobs.employer_id` currently has ON DELETE CASCADE back to
-- `users.id`. Since employer accounts can belong to a shared multi-HR
-- company (company_members), deleting ONE employer would silently wipe out
-- EVERY job that employer personally posted — even jobs the rest of their
-- team still relies on via `jobs.company_id` — along with orphaning any
-- candidate applications, interviews, and questionnaires tied to those jobs.
--
-- The application code now explicitly reassigns/removes jobs the right way
-- before deleting an employer (see company_helpers.php:
-- get_employer_deletion_impact() / process_employer_departure()), but this
-- FK change is a defensive fallback for any deletion path that doesn't go
-- through that logic (e.g. the admin panel's single/bulk employer delete):
-- worst case, the job becomes unowned ("System") and stays visible for an
-- admin to manage, instead of disappearing without a trace.
--
-- Run this once against the database (phpMyAdmin or MySQL CLI).
-- =========================================================================

ALTER TABLE `jobs` DROP FOREIGN KEY `fk_employer`;
ALTER TABLE `jobs` ADD CONSTRAINT `fk_employer` FOREIGN KEY (`employer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
