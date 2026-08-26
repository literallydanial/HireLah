<?php
require_once 'db.php';
$stmt = $pdo->query("DESCRIBE candidates");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
