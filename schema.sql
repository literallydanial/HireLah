CREATE DATABASE IF NOT EXISTS hiresense;
USE hiresense;

CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_title VARCHAR(255) NOT NULL,
    department VARCHAR(255),
    employment_type VARCHAR(100),
    status VARCHAR(50) DEFAULT 'Active',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidates (
    id VARCHAR(50) PRIMARY KEY, -- e.g., candidate_12345678
    job_id INT,
    name VARCHAR(255) DEFAULT 'Unknown',
    email VARCHAR(255),
    phone VARCHAR(255),
    filename VARCHAR(255),
    full_text LONGTEXT,
    stripped_text LONGTEXT,
    status VARCHAR(50) DEFAULT 'Review', -- Shortlisted, Review, Rejected, etc.
    recommendation VARCHAR(50) DEFAULT 'Pending',
    overall_score INT DEFAULT 0,
    skills_match INT DEFAULT 0,
    exp_match INT DEFAULT 0,
    edu_match INT DEFAULT 0,
    summary TEXT,
    education TEXT,
    experience TEXT,
    skills TEXT, -- JSON array
    strengths TEXT, -- JSON array
    gaps TEXT, -- JSON array
    relevance_label VARCHAR(100),
    note TEXT,
    suggested_question TEXT,
    screened TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL
);

-- Insert a default job posting for testing, mimicking the React default
INSERT INTO jobs (job_title, department, employment_type, description) 
VALUES ('Python Developer', 'Engineering', 'Full-time', 
'Requirements:
- 5+ years Python development
- FastAPI or Django REST Framework
- PostgreSQL, Redis, Kafka/RabbitMQ
- AWS or GCP, Docker, Kubernetes
- CI/CD pipelines

Responsibilities:
- Design scalable backend services
- Lead architecture decisions
- Mentor junior developers');
