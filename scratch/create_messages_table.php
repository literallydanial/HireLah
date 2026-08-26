<?php
require_once 'db.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        candidate_id VARCHAR(50) NOT NULL,
        sender_role ENUM('employer', 'candidate') NOT NULL,
        sender_id INT NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        read_at DATETIME NULL,
        INDEX (candidate_id),
        INDEX (sender_role, read_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "Messages table created successfully.";
} catch (\PDOException $e) {
    echo "Error creating messages table: " . $e->getMessage();
}
