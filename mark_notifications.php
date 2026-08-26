<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['mark_all']) && $data['mark_all']) {
    require_once 'notifications_helper.php';
    $notifsData = getCandidateNotifications($pdo, $user_id);
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (user_id, notification_key) VALUES (?, ?)");
    foreach ($notifsData['items'] as $item) {
        $stmt->execute([$user_id, $item['key']]);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

if (isset($data['notification_key']) && !empty($data['notification_key'])) {
    $key = trim($data['notification_key']);
    $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (user_id, notification_key) VALUES (?, ?)");
    $stmt->execute([$user_id, $key]);
    
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
