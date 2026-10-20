<?php
// ONE-TIME MIGRATION — safe to run more than once (idempotent).
// Migration for Management Role & Initial Account
//
// Can be executed from CLI on Ubuntu: php database/run_migration_management_role.php
// Or opened in a browser while logged in as an admin.

require_once __DIR__ . '/../db.php';
header('Content-Type: text/plain');

try {
    echo "=== Running Management Role Database Migration ===\n\n";

    // 1. Ensure `role` column in `users` is VARCHAR(50)
    $col = $pdo->query("SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetch();
    if ($col && strtolower($col['DATA_TYPE']) === 'enum') {
        $pdo->exec("ALTER TABLE `users` MODIFY COLUMN `role` VARCHAR(50) NOT NULL DEFAULT 'candidate'");
        echo "OK: Modified users.role to VARCHAR(50) to support 'management' role.\n";
    } else {
        echo "SKIP: users.role is already " . ($col['COLUMN_TYPE'] ?? 'VARCHAR(50)') . ".\n";
    }

    // 2. Ensure initial management user exists
    $chk = $pdo->prepare("SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1");
    $chk->execute(['management@thekeria.com']);
    $existing = $chk->fetch();

    if (!$existing) {
        $name = 'Management Team';
        $email = 'management@thekeria.com';
        $password = 'password123';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'management';
        $is_verified = 1;

        $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_verified, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $ins->execute([$name, $email, $hash, $role, $is_verified]);
        $new_id = $pdo->lastInsertId();
        echo "OK: Created default management user account (#$new_id: $email / password: $password).\n";
    } else {
        // Ensure its role is management and verified
        if ($existing['role'] !== 'management') {
            $pdo->prepare("UPDATE users SET role = 'management', is_verified = 1 WHERE email = 'management@thekeria.com'")->execute();
            echo "OK: Updated existing account #{$existing['id']} role to 'management'.\n";
        } else {
            echo "SKIP: Default management account already exists (#{$existing['id']}: {$existing['email']}).\n";
        }
    }

    echo "\n=== Migration Finished Successfully ===\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
