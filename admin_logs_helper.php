<?php
// admin_logs_helper.php - Admin Activity Logging Utility

// Ensure admin_logs table exists in database
function ensure_admin_logs_table_exists($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            admin_name VARCHAR(100) NOT NULL,
            action VARCHAR(50) NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id INT DEFAULT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (admin_id),
            INDEX (action),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    } catch (\Throwable $e) {
        error_log("Failed to create admin_logs table: " . $e->getMessage());
    }
}

/**
 * Log a state-changing or destructive admin action.
 */
function log_admin_action($pdo, $admin_id, $admin_name, $action, $target_type = '', $target_id = null, $details = '') {
    ensure_admin_logs_table_exists($pdo);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_name, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            (int)$admin_id,
            $admin_name,
            $action,
            $target_type,
            $target_id ? (int)$target_id : null,
            $details,
            $ip
        ]);
    } catch (\Throwable $e) {
        error_log("Error inserting into admin_logs: " . $e->getMessage());
    }
}
