-- Saved Jobs feature: lets a candidate bookmark a job against their own
-- account instead of only in that browser's localStorage. Auto-applied by
-- db.php the same way database/university.sql is -- no manual run needed.

CREATE TABLE IF NOT EXISTS saved_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    job_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_job (user_id, job_id),
    KEY idx_saved_jobs_user (user_id),
    KEY idx_saved_jobs_job (job_id)
);
