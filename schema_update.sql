USE hiresense;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'candidate', -- 'candidate', 'employer', 'admin'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add employer_id to jobs if it doesn't exist
-- We use a stored procedure to safely add the column if not exists in MySQL
DELIMITER $$
CREATE PROCEDURE AddEmployerIdToJobs()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'jobs' AND COLUMN_NAME = 'employer_id'
    ) THEN
        ALTER TABLE jobs ADD COLUMN employer_id INT;
        ALTER TABLE jobs ADD CONSTRAINT fk_employer FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
END $$
DELIMITER ;
CALL AddEmployerIdToJobs();
DROP PROCEDURE AddEmployerIdToJobs;

-- Add user_id to candidates if it doesn't exist
DELIMITER $$
CREATE PROCEDURE AddUserIdToCandidates()
BEGIN
    IF NOT EXISTS (
        SELECT * FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'hiresense' AND TABLE_NAME = 'candidates' AND COLUMN_NAME = 'user_id'
    ) THEN
        ALTER TABLE candidates ADD COLUMN user_id INT;
        ALTER TABLE candidates ADD CONSTRAINT fk_candidate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
END $$
DELIMITER ;
CALL AddUserIdToCandidates();
DROP PROCEDURE AddUserIdToCandidates;
