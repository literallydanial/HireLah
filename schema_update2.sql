USE hiresense;

DELIMITER $$
CREATE PROCEDURE AddProfileColumns()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'profile_picture'
    ) THEN
        ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255);
    END IF;

    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'users' AND COLUMN_NAME = 'default_resume'
    ) THEN
        ALTER TABLE users ADD COLUMN default_resume VARCHAR(255);
    END IF;
END $$
DELIMITER ;
CALL AddProfileColumns();
DROP PROCEDURE AddProfileColumns;
