<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT id, name, email, role, is_verified FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($users, JSON_PRETTY_PRINT);
