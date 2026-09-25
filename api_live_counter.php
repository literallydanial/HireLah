<?php
// =========================================================================
// KERIA LIVE COUNTER API
// Provides real-time metrics for the TV Live Counter display from MySQL DB
// =========================================================================
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

session_start();

$response = [
    'success' => true,
    'db_connected' => false,
    'registered' => 0,
    'registered_new' => 0,
    'resume' => 0,
    'resume_new' => 0,
    'jobs' => 0,
    'jobs_new' => 0,
    'real_stats' => [
        'users_total' => 0,
        'users_today' => 0,
        'users_recent' => 0,
        'resumes_total' => 0,
        'resumes_today' => 0,
        'resumes_recent' => 0,
        'jobs_total' => 0,
        'jobs_today' => 0,
        'jobs_recent' => 0,
    ],
    'server_time' => date('g:i A'),
    'timestamp' => time()
];

// Load DB config if constants not defined
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'hirelah');
    define('DB_USER', 'root');
    define('DB_PASS', '');
}

$pdo = null;
try {
    $charset = 'utf8mb4';
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 2
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $response['db_connected'] = true;
} catch (\Throwable $e) {
    // Fallback to 127.0.0.1 if localhost IPv6 resolution fails on Windows
    try {
        $dsn = "mysql:host=127.0.0.1;dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $response['db_connected'] = true;
    } catch (\Throwable $e2) {
        $response['db_connected'] = false;
        $response['db_note'] = 'Database connection error: ' . $e2->getMessage();
    }
}

if ($pdo) {
    try {
        // 1. Registered Users Count
        $uTotal = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $uToday = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= CURDATE() OR created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
        $uRecent = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();
        
        $response['real_stats']['users_total'] = $uTotal;
        $response['real_stats']['users_today'] = $uToday;
        $response['real_stats']['users_recent'] = $uRecent;

        // 2. Resumes Count (candidates + resume_builds + resume_reviews + user profile resumes)
        $candTotal = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
        $candToday = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
        $candRecent = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();

        $buildsTotal = 0;
        $buildsToday = 0;
        $buildsRecent = 0;
        try {
            $buildsTotal = (int)$pdo->query("SELECT COUNT(*) FROM resume_builds")->fetchColumn();
            $buildsToday = (int)$pdo->query("SELECT COUNT(*) FROM resume_builds WHERE created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
            $buildsRecent = (int)$pdo->query("SELECT COUNT(*) FROM resume_builds WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();
        } catch (\Throwable $e) {}

        $reviewsTotal = 0;
        $reviewsToday = 0;
        $reviewsRecent = 0;
        try {
            $reviewsTotal = (int)$pdo->query("SELECT COUNT(*) FROM resume_reviews")->fetchColumn();
            $reviewsToday = (int)$pdo->query("SELECT COUNT(*) FROM resume_reviews WHERE created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
            $reviewsRecent = (int)$pdo->query("SELECT COUNT(*) FROM resume_reviews WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();
        } catch (\Throwable $e) {}

        $defaultResumesTotal = 0;
        try {
            $defaultResumesTotal = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE default_resume IS NOT NULL AND default_resume != ''")->fetchColumn();
        } catch (\Throwable $e) {}

        $rTotal = $candTotal + $buildsTotal + $reviewsTotal + $defaultResumesTotal;
        $rToday = $candToday + $buildsToday + $reviewsToday;
        $rRecent = $candRecent + $buildsRecent + $reviewsRecent;

        $response['real_stats']['resumes_total'] = $rTotal;
        $response['real_stats']['resumes_today'] = $rToday;
        $response['real_stats']['resumes_recent'] = $rRecent;

        // 3. Jobs Count
        $jTotal = (int)$pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
        $jToday = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE created_at >= CURDATE() OR created_at >= NOW() - INTERVAL 24 HOUR")->fetchColumn();
        $jRecent = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();

        $response['real_stats']['jobs_total'] = $jTotal;
        $response['real_stats']['jobs_today'] = $jToday;
        $response['real_stats']['jobs_recent'] = $jRecent;

        // Default mode is REAL DATA
        $mode = $_GET['mode'] ?? 'real';

        if ($mode === 'milestone') {
            $response['registered'] = 145392;
            $response['registered_new'] = 120;
            $response['resume'] = 92110;
            $response['resume_new'] = 51;
            $response['jobs'] = 28455;
            $response['jobs_new'] = 33;
        } elseif ($mode === 'hybrid') {
            $response['registered'] = 145392 + $uTotal;
            $response['registered_new'] = 120 + $uRecent;
            $response['resume'] = 92110 + $rTotal;
            $response['resume_new'] = 51 + $rRecent;
            $response['jobs'] = 28455 + $jTotal;
            $response['jobs_new'] = 33 + $jRecent;
        } else {
            // PURE REAL DATA
            $response['registered'] = $uTotal;
            $response['registered_new'] = $uRecent > 0 ? $uRecent : $uToday;
            $response['resume'] = $rTotal;
            $response['resume_new'] = $rRecent > 0 ? $rRecent : $rToday;
            $response['jobs'] = $jTotal;
            $response['jobs_new'] = $jRecent > 0 ? $jRecent : $jToday;
        }

    } catch (\Throwable $e) {
        $response['query_error'] = $e->getMessage();
    }
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
