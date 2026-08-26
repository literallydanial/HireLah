<?php
require_once 'db.php';

$email = 'admin@hiresense.com';
$password = 'admin123';
$name = 'System Admin';

$chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$chk->execute([$email]);
$user = $chk->fetch();

if ($user) {
    $up = $pdo->prepare("UPDATE users SET role = 'admin', is_verified = 1, password_hash = ? WHERE id = ?");
    $up->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    echo "Existing account updated to Admin.";
} else {
    $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_verified, created_at) VALUES (?, ?, ?, 'admin', 1, NOW())");
    $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    echo "Admin account created successfully.";
}
