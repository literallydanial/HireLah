<?php
// check_gemini_health.php
// Runs a real, live Gemini API health check ONLY when explicitly called (the
// admin dashboard's "Check Now" button) -- replaces the old behavior of
// silently firing this live call on every single admin dashboard page load.
require_once 'auth.php';
require_role('admin');
require_once 'ai.php';

header('Content-Type: application/json');

$api_key = get_api_key();
$model = $_SESSION['ai_model'] ?? 'gemini-3.6-flash';
$started_at = microtime(true);
$result = check_api_key_status($api_key, $model);
$latency_ms = (int) round((microtime(true) - $started_at) * 1000);

echo json_encode([
    'status' => $result['status'],
    'message' => $result['message'],
    'model' => $model,
    'latency_ms' => $latency_ms,
    'checked_at' => date('Y-m-d H:i:s'),
]);
