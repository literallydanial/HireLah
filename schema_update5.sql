USE hiresense;

DELIMITER $$
CREATE PROCEDURE AddVideoRequirementColumns()
BEGIN
    -- Require video introduction setting on jobs
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'require_video'
    ) THEN
        ALTER TABLE jobs ADD COLUMN require_video TINYINT(1) DEFAULT 0;
    END IF;

    -- Candidate submitted YouTube link
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'candidates' AND COLUMN_NAME = 'youtube_url'
    ) THEN
        ALTER TABLE candidates ADD COLUMN youtube_url VARCHAR(255);
    END IF;
END $$
DELIMITER ;
CALL AddVideoRequirementColumns();
DROP PROCEDURE AddVideoRequirementColumns;
