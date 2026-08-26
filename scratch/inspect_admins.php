<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT id, name, email, role, is_verified FROM users WHERE role = 'admin'");
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($admins, JSON_PRETTY_PRINT);
