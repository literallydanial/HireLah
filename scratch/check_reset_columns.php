<?php
require_once 'db.php';
$stmt = $pdo->query("DESCRIBE users");
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($columns);
