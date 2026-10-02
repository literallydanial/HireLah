USE hiresense;

DELIMITER $$
CREATE PROCEDURE AddWorkModeColumn()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'work_mode'
    ) THEN
        ALTER TABLE jobs ADD COLUMN work_mode VARCHAR(50) DEFAULT 'On-site';
    END IF;
END $$
DELIMITER ;
CALL AddWorkModeColumn();
DROP PROCEDURE AddWorkModeColumn;
