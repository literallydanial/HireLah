<?php
require_once 'db.php';

try {
    $pdo->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(100) NULL, ADD COLUMN reset_token_expires_at DATETIME NULL;");
    echo "Columns added successfully.";
} catch (\PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
