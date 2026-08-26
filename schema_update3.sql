USE hiresense;

DELIMITER $$
CREATE PROCEDURE AddResumePathColumn()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'candidates' AND COLUMN_NAME = 'resume_path'
    ) THEN
        ALTER TABLE candidates ADD COLUMN resume_path VARCHAR(255);
    END IF;
END $$
DELIMITER ;
CALL AddResumePathColumn();
DROP PROCEDURE AddResumePathColumn;
