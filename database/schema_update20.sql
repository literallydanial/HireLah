-- =========================================================================
-- schema_update20.sql  --  Strict Internal Team Employer Verification & Generic Placeholders
--
-- Ensures company verification is strictly controlled by internal team review:
--   1. Changes default verification_status in `companies` to 'pending'.
--   2. Resets any unreviewed companies (reviewed_by IS NULL or reviewed_at IS NULL)
--      from 'approved' to 'pending' so they must be reviewed by an internal team admin.
--   3. Replaces market/sample company names and SSM numbers with generic placeholders.
--
-- Safe and idempotent: can be executed repeatedly in MySQL CLI, PuTTY,
-- phpMyAdmin, or auto-run by the system.
--
-- Command to run manually via PuTTY / SSH:
--   mysql -u [db_user] -p [db_name] < database/schema_update20.sql
-- =========================================================================

-- 1. Modify companies.verification_status default to 'pending'
ALTER TABLE `companies` MODIFY COLUMN `verification_status` VARCHAR(20) NOT NULL DEFAULT 'pending';

-- 2. Reset unreviewed companies to 'pending' so they appear in admin verification queue
UPDATE `companies`
SET `verification_status` = 'pending',
    `submitted_at` = COALESCE(`submitted_at`, NOW())
WHERE (`reviewed_by` IS NULL OR `reviewed_at` IS NULL)
  AND `verification_status` = 'approved';

-- 3. Replace legacy sample company names or sample SSM numbers with generic placeholders
UPDATE `companies`
SET `name` = 'Company Abc Sdn Bhd'
WHERE `name` IN ('ABC Technology Sdn. Bhd.', 'Acme Corporation Sdn Bhd');

UPDATE `companies`
SET `ssm_number` = 'xxxxxxx12345 (xxxx12-x)'
WHERE `ssm_number` IN ('202301012345 (1234567-X)', '201201012345 (1012345-X)', '202301012345', '201201012345');

UPDATE `users`
SET `company_name` = 'Company Abc Sdn Bhd'
WHERE `company_name` IN ('ABC Technology Sdn. Bhd.', 'Acme Corporation Sdn Bhd');

UPDATE `users`
SET `ssm_number` = 'xxxxxxx12345 (xxxx12-x)'
WHERE `ssm_number` IN ('202301012345 (1234567-X)', '201201012345 (1012345-X)', '202301012345', '201201012345');
