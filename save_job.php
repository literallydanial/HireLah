<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'candidate') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$job_id = isset($input['job_id']) ? (int)$input['job_id'] : 0;
$action = $input['action'] ?? '';

if ($job_id <= 0 || !in_array($action, ['save', 'unsave'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

try {
    if ($action === 'save') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO saved_jobs (user_id, job_id) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $job_id]);
    } else {
        $stmt = $pdo->prepare("DELETE FROM saved_jobs WHERE user_id = ? AND job_id = ?");
        $stmt->execute([$_SESSION['user_id'], $job_id]);
    }
    echo json_encode(['success' => true, 'saved' => $action === 'save']);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
