<?php
require_once __DIR__ . '/../db.php';
$stmt = $pdo->prepare("SELECT company_logo FROM users WHERE id = ?");
$stmt->execute([51]);
echo json_encode($stmt->fetch());
