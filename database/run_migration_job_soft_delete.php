<?php
// ONE-TIME MIGRATION — safe to run multiple times (idempotent)
// Adds `deleted_at` column to `jobs` table for soft delete / archiving.
require_once __DIR__ . '/../db.php';
header('Content-Type: text/plain');

function column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    echo "=== Running Job Soft Delete Migration ===\n\n";

    if (!column_exists($pdo, 'jobs', 'deleted_at')) {
        $pdo->exec("ALTER TABLE `jobs` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`");
        echo "OK: Added `deleted_at` column to `jobs` table.\n";
    } else {
        echo "SKIP: `jobs`.`deleted_at` already exists.\n";
    }

    echo "\n=== Migration Completed Successfully ===\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
