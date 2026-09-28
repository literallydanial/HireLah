<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db.php';
require_once 'resume_pdf_helper.php';

// Authentication verification
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die("Please log in to view this document.");
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    die("Invalid resume build identifier.");
}

$stmt = $pdo->prepare("
    SELECT b.*, u.name as user_name, u.email as user_email
    FROM resume_builds b
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$build = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$build) {
    http_response_code(404);
    die("The requested resume builder document could not be found.");
}

$user_role = $_SESSION['user_role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_owner = ((int)$_SESSION['user_id'] === (int)$build['user_id']);
$is_employer = ($user_role === 'employer');

if (!$is_admin && !$is_owner && !$is_employer) {
    http_response_code(403);
    die("Access denied. You do not have permission to view this resume.");
}

try {
    stream_resume_builder_docx($build);
} catch (\Throwable $e) {
    error_log("Resume DOCX Generation Error (build #{$id}): " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    die("Sorry, we couldn't generate the Word document right now. Please try again in a moment.");
}
