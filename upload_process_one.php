<?php
// Processes exactly ONE resume per request. Called repeatedly (one fetch per
// file) by upload.php's JS instead of the old single request that tried to
// screen an entire batch at once -- that single-request approach could run
// long enough for a gateway/proxy in front of PHP to time out (504) even
// though the backend work itself was still completing successfully.
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';
require 'parser.php';
require 'ai.php';

header('Content-Type: application/json');

$job_id = $_POST['job_id'] ?? null;

$active_company_id = get_active_company_id($pdo);
if ($active_company_id) {
    $stmt = $pdo->prepare("SELECT id FROM jobs WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?)) AND status = 'Active'");
    $stmt->execute([$job_id, $active_company_id, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT id FROM jobs WHERE id = ? AND (employer_id = ? OR employer_id IS NULL) AND status = 'Active'");
    $stmt->execute([$job_id, $_SESSION['user_id']]);
}
if (!$stmt->fetch()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid or inaccessible job posting.']);
    exit;
}

$stmt = $pdo->prepare("SELECT description FROM jobs WHERE id = ?");
$stmt->execute([$job_id]);
$job_desc = $stmt->fetchColumn();

$api_key = get_api_key();

if (!isset($_FILES['resume']) || $_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file received or upload error.']);
    exit;
}

$tmp_name = $_FILES['resume']['tmp_name'];
$name = basename($_FILES['resume']['name']);

$hash = hash_file('sha256', $tmp_name);
if ($hash !== false) {
    $dupe_stmt = $pdo->prepare("SELECT 1 FROM candidates WHERE job_id = ? AND resume_hash = ? LIMIT 1");
    $dupe_stmt->execute([$job_id, $hash]);
    if ($dupe_stmt->fetch()) {
        echo json_encode(['success' => true, 'duplicate' => true, 'name' => $name]);
        exit;
    }
}

$upload_dir = 'uploads/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
$path = $upload_dir . uniqid() . '_' . $name;

if (!move_uploaded_file($tmp_name, $path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file.', 'name' => $name]);
    exit;
}

try {
    $text = extract_text_from_pdf($path);
    $stripped = strip_pii($text);

    $candidate_id = 'cnd_' . substr(md5(uniqid()), 0, 8);

    $ai_data = null;
    $ai_warning = null;
    if ($api_key) {
        try {
            $ai_data = generate_snapshot($api_key, $stripped, $job_desc, 'bulk_upload');
        } catch (Exception $e) {
            $ai_warning = $e->getMessage();
            error_log("AI Error: " . $e->getMessage());
        }
    }

    $stmt = $pdo->prepare("INSERT INTO candidates (
        id, job_id, name, email, filename, full_text, stripped_text,
        recommendation, overall_score, skills_match, exp_match, edu_match,
        summary, education, experience, skills, strengths, gaps,
        relevance_label, note, suggested_question, screened, resume_path, resume_hash
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmt->execute([
        $candidate_id,
        $job_id,
        $ai_data['name'] ?? 'Unknown',
        null,
        $name,
        $text,
        $stripped,
        $ai_data['relevance_label'] ?? 'Pending',
        $ai_data['overall_score'] ?? 0,
        $ai_data['skills_match'] ?? 0,
        $ai_data['exp_match'] ?? 0,
        $ai_data['edu_match'] ?? 0,
        $ai_data['summary'] ?? null,
        $ai_data['education'] ?? null,
        $ai_data['experience'] ?? null,
        json_encode($ai_data['skills'] ?? []),
        json_encode($ai_data['strengths'] ?? []),
        json_encode($ai_data['gaps'] ?? []),
        $ai_data['relevance_label'] ?? null,
        $ai_data['note'] ?? null,
        $ai_data['suggested_question'] ?? null,
        $ai_data ? 1 : 0,
        $path,
        $hash ?: null
    ]);

    echo json_encode([
        'success' => true,
        'duplicate' => false,
        'name' => $name,
        'score' => $ai_data['overall_score'] ?? null,
        'ai_warning' => $ai_warning,
    ]);
} catch (Exception $e) {
    error_log("Upload Error ($name): " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'name' => $name]);
}
