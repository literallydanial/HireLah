<?php
// ONE-TIME MIGRATION — safe to run more than once (idempotent).
// Adds University SSM Registration Number columns:
//   - `ssm_number` on `users` table
//   - `ssm_number` on `universities` table
//
// Can be executed from CLI: php database/run_migration_add_ssm_number.php
// Or opened in a browser while logged in as an admin.

require_once __DIR__ . '/../db.php';
header('Content-Type: text/plain');

function col_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    echo "=== Running SSM Number Database Migration ===\n\n";

    if (!col_exists($pdo, 'users', 'ssm_number')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `ssm_number` VARCHAR(100) NULL AFTER `matric_number`");
        echo "OK: Added ssm_number column to users table.\n";
    } else {
        echo "SKIP: users.ssm_number already exists.\n";
    }

    if (!col_exists($pdo, 'universities', 'ssm_number')) {
        $pdo->exec("ALTER TABLE `universities` ADD COLUMN `ssm_number` VARCHAR(100) NULL AFTER `type`");
        echo "OK: Added ssm_number column to universities table.\n";
    } else {
        echo "SKIP: universities.ssm_number already exists.\n";
    }

    echo "\n=== Migration Finished Successfully ===\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
