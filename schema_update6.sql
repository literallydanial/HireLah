USE hiresense;

-- Questionnaires table created by employers for jobs
CREATE TABLE IF NOT EXISTS questionnaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    employer_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    questions_json LONGTEXT NOT NULL, -- JSON array of questions
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (employer_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Questionnaire Requests / Responses sent to candidates
CREATE TABLE IF NOT EXISTS questionnaire_requests (
    id VARCHAR(50) PRIMARY KEY, -- unique token/hash for questionnaire link
    candidate_id VARCHAR(50) NOT NULL,
    questionnaire_id INT NOT NULL,
    status VARCHAR(50) DEFAULT 'Pending', -- 'Pending', 'Submitted'
    answers_json LONGTEXT NULL, -- Candidate answers
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    submitted_at TIMESTAMP NULL,
    FOREIGN KEY (candidate_id) REFERENCES candidates(id) ON DELETE CASCADE,
    FOREIGN KEY (questionnaire_id) REFERENCES questionnaires(id) ON DELETE CASCADE
);
