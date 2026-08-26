<?php
require_once 'db.php';

try {
    $pdo->exec("ALTER TABLE candidates 
        ADD COLUMN interview_status ENUM('None', 'Proposed', 'Confirmed', 'Declined') DEFAULT 'None',
        ADD COLUMN interview_datetime DATETIME NULL,
        ADD COLUMN interview_notes TEXT NULL,
        ADD COLUMN interview_token VARCHAR(100) NULL;");
    echo "Interview columns added successfully.";
} catch (\PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
