<?php
require_once 'auth.php';
require_once 'ai.php';

// AJAX endpoint used by the "Ask AI" helper button in resume_builder.php.
// Takes a candidate's rough notes for one work-experience block and returns
// 3-4 suggested, polished resume bullet points.

header('Content-Type: application/json');

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'candidate') {
    http_response_code(403);
    echo json_encode(['error' => 'Please log in as a candidate to use this.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$role = trim($_POST['role'] ?? '');
$company = trim($_POST['company'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($notes === '') {
    echo json_encode(['error' => 'Type a few rough notes first, then ask AI to help word them.']);
    exit;
}

$api_key = get_api_key();

try {
    $bullets = ai_resume_assist_bullets($api_key, $role, $company, $notes);
    echo json_encode(['bullets' => $bullets]);
} catch (Throwable $e) {
    error_log("resume_ai_assist error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Something went wrong asking AI for suggestions. Please try again.']);
}
