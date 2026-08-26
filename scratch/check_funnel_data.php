<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT status, COUNT(*) as count FROM candidates GROUP BY status");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $pdo->query("SELECT j.job_title, c.status, COUNT(*) as count FROM candidates c JOIN jobs j ON c.job_id = j.id GROUP BY j.id, c.status");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
