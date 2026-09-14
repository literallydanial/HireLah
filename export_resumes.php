<?php
session_start();
require_once 'db.php';
require_once 'admin_logs_helper.php';

// Enforce admin privileges
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    $_SESSION['error'] = "Access denied. Admin privileges required.";
    header("Location: login.php");
    exit;
}

$admin_id = (int)$_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// Collect filter parameters
$source_filter = trim($_GET['source'] ?? $_POST['source'] ?? 'all');
$date_mode = trim($_GET['date_mode'] ?? $_POST['date_mode'] ?? 'all');
$exact_date = trim($_GET['exact_date'] ?? $_POST['exact_date'] ?? '');
$start_date = trim($_GET['start_date'] ?? $_POST['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? $_POST['end_date'] ?? '');
$job_id = trim($_GET['job_id'] ?? $_POST['job_id'] ?? 'all');

$filter_desc_parts = [];

// Validate date inputs
if ($date_mode === 'exact') {
    if (empty($exact_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exact_date)) {
        $_SESSION['error'] = "Please provide a valid date (YYYY-MM-DD) for exact date export.";
        header("Location: admin_resumes.php");
        exit;
    }
    $filter_desc_parts[] = "Date: " . $exact_date;
} elseif ($date_mode === 'range') {
    if (empty($start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ||
        empty($end_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
        $_SESSION['error'] = "Please provide valid Start and End dates (YYYY-MM-DD) for range export.";
        header("Location: admin_resumes.php");
        exit;
    }
    if ($start_date > $end_date) {
        $temp = $start_date;
        $start_date = $end_date;
        $end_date = $temp;
    }
    $filter_desc_parts[] = "Range: {$start_date} to {$end_date}";
} else {
    $date_mode = 'all';
    $filter_desc_parts[] = "All dates";
}

$filter_desc_parts[] = "Source: " . ucfirst(str_replace('_', ' ', $source_filter));

// ----------------------------------------------------
// 1. QUERY EACH SOURCE BASED ON FILTERS
// ----------------------------------------------------
$candidates = [];
$builder_records = [];
$checker_records = [];

// A. Job Applications (candidates table)
if ($source_filter === 'all' || $source_filter === 'job_apply') {
    $sql = "SELECT c.id, c.name, c.email, c.phone, c.filename, c.resume_path, c.created_at,
                   c.overall_score, c.skills_match, c.exp_match, c.edu_match, c.status, c.recommendation,
                   j.id as job_id, j.job_title, j.department,
                   COALESCE(NULLIF(u.company_name, ''), u.name, 'Direct') as employer_name
            FROM candidates c
            LEFT JOIN jobs j ON c.job_id = j.id
            LEFT JOIN users u ON j.employer_id = u.id
            WHERE c.resume_path IS NOT NULL AND c.resume_path != ''";
    $params = [];

    if ($date_mode === 'exact') {
        $sql .= " AND DATE(c.created_at) = ?";
        $params[] = $exact_date;
    } elseif ($date_mode === 'range') {
        $sql .= " AND DATE(c.created_at) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }

    if (!empty($job_id) && $job_id !== 'all') {
        $sql .= " AND c.job_id = ?";
        $params[] = (int)$job_id;
    }

    $sql .= " ORDER BY c.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// B. Resume Builder (resume_builds table)
if ($source_filter === 'all' || $source_filter === 'resume_builder') {
    try {
        $sql = "SELECT b.id, b.user_id, b.target_title, b.raw_input, b.generated_content, b.created_at,
                       u.name as user_name, u.email as user_email
                FROM resume_builds b
                LEFT JOIN users u ON b.user_id = u.id
                WHERE 1=1";
        $params = [];

        if ($date_mode === 'exact') {
            $sql .= " AND DATE(b.created_at) = ?";
            $params[] = $exact_date;
        } elseif ($date_mode === 'range') {
            $sql .= " AND DATE(b.created_at) BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }

        $sql .= " ORDER BY b.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $builder_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

// C. Resume Checker (resume_reviews table)
if ($source_filter === 'all' || $source_filter === 'resume_checker') {
    try {
        $sql = "SELECT r.id, r.user_id, r.filename, r.full_text, r.stripped_text, r.overall_score, 
                       r.rating_label, r.summary, r.strengths, r.improvements, r.formatting_notes, r.ats_tips, r.created_at,
                       u.name as user_name, u.email as user_email
                FROM resume_reviews r
                LEFT JOIN users u ON r.user_id = u.id
                WHERE 1=1";
        $params = [];

        if ($date_mode === 'exact') {
            $sql .= " AND DATE(r.created_at) = ?";
            $params[] = $exact_date;
        } elseif ($date_mode === 'range') {
            $sql .= " AND DATE(r.created_at) BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }

        $sql .= " ORDER BY r.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $checker_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

$total_found = count($candidates) + count($builder_records) + count($checker_records);

if ($total_found === 0) {
    $_SESSION['error'] = "No resume documents found matching: " . implode(', ', $filter_desc_parts) . ".";
    header("Location: admin_resumes.php");
    exit;
}

if (!class_exists('ZipArchive')) {
    $_SESSION['error'] = "ZipArchive PHP extension is not enabled on this server.";
    header("Location: admin_resumes.php");
    exit;
}

// Create temporary zip archive
$temp_dir = sys_get_temp_dir();
$temp_zip = tempnam($temp_dir, 'hirelah_all_resumes_');
if (!$temp_zip) {
    $temp_zip = __DIR__ . '/scratch/export_' . uniqid() . '.zip';
    if (!is_dir(__DIR__ . '/scratch')) @mkdir(__DIR__ . '/scratch', 0777, true);
}

$zip = new ZipArchive();
if ($zip->open($temp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    $_SESSION['error'] = "Unable to initialize temporary ZIP archive file.";
    header("Location: admin_resumes.php");
    exit;
}

// Helper sanitize string
function sanitize_zip_name($name) {
    $clean = preg_replace('/[^\w\-\. ]+/u', '_', (string)$name);
    return trim(preg_replace('/\s+/', ' ', $clean));
}

// Create CSV Manifest
$csv_handle = fopen('php://temp', 'r+');
fputcsv($csv_handle, [
    'Source Pipeline',
    'Record ID',
    'Candidate / User Name',
    'Email Address',
    'Phone',
    'Job / Target Title',
    'Department / Context',
    'Employer / Submitter',
    'Date Submitted',
    'Overall Match / ATS Score',
    'Status / Verdict',
    'Original File / Ref',
    'Archive Zip Path'
]);

$added_count = 0;
$used_filenames = [];

// ----------------------------------------------------
// PACK 1: JOB APPLICATIONS (PDF Files)
// ----------------------------------------------------
foreach ($candidates as $c) {
    $raw_path = $c['resume_path'];
    $candidate_file_path = null;
    $possible_paths = [
        $raw_path,
        __DIR__ . '/' . ltrim($raw_path, '/\\'),
        __DIR__ . '/uploads/' . basename($raw_path),
        __DIR__ . '/uploads/resumes/' . basename($raw_path)
    ];

    foreach ($possible_paths as $p) {
        if (file_exists($p) && is_file($p)) {
            $candidate_file_path = $p;
            break;
        }
    }

    $app_date = date('Y-m-d', strtotime($c['created_at']));
    $clean_job = sanitize_zip_name($c['job_title'] ?: 'General');
    $clean_name = sanitize_zip_name($c['name'] ?: 'Candidate');
    $orig_name = sanitize_zip_name($c['filename'] ?: basename($raw_path));

    if (!$candidate_file_path) {
        fputcsv($csv_handle, [
            'Job Application',
            $c['id'],
            $c['name'] ?: 'Unknown',
            $c['email'] ?: 'N/A',
            $c['phone'] ?: 'N/A',
            $c['job_title'] ?: 'General',
            $c['department'] ?: 'N/A',
            $c['employer_name'] ?: 'Direct',
            $c['created_at'],
            $c['overall_score'] . '%',
            $c['status'] ?: 'Review',
            $c['filename'] ?: basename($raw_path),
            '[FILE NOT FOUND ON SERVER DISK]'
        ]);
        continue;
    }

    $ext = pathinfo($candidate_file_path, PATHINFO_EXTENSION) ?: 'pdf';
    $base_zip_entry = "job_applications/[{$app_date}] [{$clean_job}] {$clean_name} - {$orig_name}";
    if (!str_ends_with(strtolower($base_zip_entry), '.' . strtolower($ext))) {
        $base_zip_entry .= '.' . $ext;
    }

    $zip_entry_name = $base_zip_entry;
    $counter = 1;
    while (isset($used_filenames[$zip_entry_name])) {
        $name_part = pathinfo($base_zip_entry, PATHINFO_FILENAME);
        $zip_entry_name = "job_applications/{$name_part}_({$counter}).{$ext}";
        $counter++;
    }
    $used_filenames[$zip_entry_name] = true;

    $zip->addFile($candidate_file_path, 'resumes/' . $zip_entry_name);
    $added_count++;

    fputcsv($csv_handle, [
        'Job Application',
        $c['id'],
        $c['name'] ?: 'Unknown',
        $c['email'] ?: 'N/A',
        $c['phone'] ?: 'N/A',
        $c['job_title'] ?: 'General',
        $c['department'] ?: 'N/A',
        $c['employer_name'] ?: 'Direct',
        $c['created_at'],
        $c['overall_score'] . '%',
        $c['status'] ?: 'Review',
        $c['filename'] ?: basename($raw_path),
        'resumes/' . $zip_entry_name
    ]);
}

// ----------------------------------------------------
// PACK 2: RESUME BUILDER (Printable HTML / Text Resume Docs)
// ----------------------------------------------------
foreach ($builder_records as $b) {
    $build_date = date('Y-m-d', strtotime($b['created_at']));
    $clean_name = sanitize_zip_name($b['user_name'] ?: 'Candidate');
    $clean_title = sanitize_zip_name($b['target_title'] ?: 'Resume');

    $html_content = "<!DOCTYPE html><html><head><meta charset='utf-8'><title>" . htmlspecialchars($clean_name . ' - ' . $clean_title) . "</title>";
    $html_content .= "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:30px auto;padding:20px;color:#111;line-height:1.6;} h1{margin-bottom:4px;color:#222;} h2{border-bottom:2px solid #333;padding-bottom:4px;margin-top:24px;font-size:16px;text-transform:uppercase;color:#444;} .contact{font-size:13px;color:#666;margin-bottom:20px;} .item{margin-bottom:14px;} .item-title{font-weight:bold;} .item-meta{font-size:12px;color:#666;}</style></head><body>";
    $html_content .= "<h1>" . htmlspecialchars($b['user_name'] ?: 'Candidate Resume') . "</h1>";
    $html_content .= "<div class='contact'>" . htmlspecialchars($b['user_email'] ?: '') . " • Target Role: " . htmlspecialchars($b['target_title'] ?: 'Professional') . " • Created: " . htmlspecialchars($b['created_at']) . "</div>";

    $gen_data = json_decode($b['generated_content'], true);
    if (is_array($gen_data)) {
        if (!empty($gen_data['summary'])) {
            $html_content .= "<h2>Professional Summary</h2><p>" . nl2br(htmlspecialchars($gen_data['summary'])) . "</p>";
        }
        if (!empty($gen_data['skills'])) {
            $skills_str = is_array($gen_data['skills']) ? implode(', ', $gen_data['skills']) : $gen_data['skills'];
            $html_content .= "<h2>Core Competencies & Skills</h2><p>" . htmlspecialchars($skills_str) . "</p>";
        }
        if (!empty($gen_data['experience']) && is_array($gen_data['experience'])) {
            $html_content .= "<h2>Professional Experience</h2>";
            foreach ($gen_data['experience'] as $exp) {
                $html_content .= "<div class='item'>";
                $html_content .= "<div class='item-title'>" . htmlspecialchars($exp['role'] ?? '') . " — " . htmlspecialchars($exp['company'] ?? '') . "</div>";
                $html_content .= "<div class='item-meta'>" . htmlspecialchars($exp['duration'] ?? '') . "</div>";
                if (!empty($exp['bullets']) && is_array($exp['bullets'])) {
                    $html_content .= "<ul>";
                    foreach ($exp['bullets'] as $bullet) $html_content .= "<li>" . htmlspecialchars($bullet) . "</li>";
                    $html_content .= "</ul>";
                } elseif (!empty($exp['notes'])) {
                    $html_content .= "<p>" . nl2br(htmlspecialchars($exp['notes'])) . "</p>";
                }
                $html_content .= "</div>";
            }
        }
        if (!empty($gen_data['education']) && is_array($gen_data['education'])) {
            $html_content .= "<h2>Education & Credentials</h2>";
            foreach ($gen_data['education'] as $edu) {
                $html_content .= "<div class='item'><div class='item-title'>" . htmlspecialchars($edu['degree'] ?? '') . " — " . htmlspecialchars($edu['school'] ?? '') . "</div><div class='item-meta'>" . htmlspecialchars($edu['year'] ?? '') . "</div></div>";
            }
        }
    } else {
        $html_content .= "<h2>Raw Content</h2><pre>" . htmlspecialchars($b['raw_input'] ?: $b['generated_content']) . "</pre>";
    }
    $html_content .= "</body></html>";

    $zip_entry_name = "resume_builder/[{$build_date}] [Builder] {$clean_name} - {$clean_title}.html";
    $counter = 1;
    while (isset($used_filenames[$zip_entry_name])) {
        $zip_entry_name = "resume_builder/[{$build_date}] [Builder] {$clean_name} - {$clean_title}_({$counter}).html";
        $counter++;
    }
    $used_filenames[$zip_entry_name] = true;

    $zip->addFromString('resumes/' . $zip_entry_name, $html_content);
    $added_count++;

    fputcsv($csv_handle, [
        'Resume Builder',
        'build_' . $b['id'],
        $b['user_name'] ?: 'Candidate',
        $b['user_email'] ?: 'N/A',
        'N/A',
        $b['target_title'] ?: 'Custom Resume',
        'AI Generated Document',
        'AI Resume Builder',
        $b['created_at'],
        '100%',
        'Generated & Polished',
        'Built Resume',
        'resumes/' . $zip_entry_name
    ]);
}

// ----------------------------------------------------
// PACK 3: RESUME CHECKER (Uploaded PDFs / Audit Reports)
// ----------------------------------------------------
foreach ($checker_records as $r) {
    $check_date = date('Y-m-d', strtotime($r['created_at']));
    $clean_name = sanitize_zip_name($r['user_name'] ?: 'Candidate');
    $orig_filename = sanitize_zip_name($r['filename'] ?: 'resume.pdf');

    // Check if original file is on disk in uploads/
    $checker_file_path = null;
    $possible_matches = glob(__DIR__ . '/uploads/*' . basename($r['filename']));
    if (!empty($possible_matches) && is_file($possible_matches[0])) {
        $checker_file_path = $possible_matches[0];
    }

    if ($checker_file_path) {
        $ext = pathinfo($checker_file_path, PATHINFO_EXTENSION) ?: 'pdf';
        $zip_entry_name = "resume_checker/[{$check_date}] [Checker {$r['overall_score']}pct] {$clean_name} - {$orig_filename}";
        if (!str_ends_with(strtolower($zip_entry_name), '.' . strtolower($ext))) {
            $zip_entry_name .= '.' . $ext;
        }

        $counter = 1;
        while (isset($used_filenames[$zip_entry_name])) {
            $name_part = pathinfo($zip_entry_name, PATHINFO_FILENAME);
            $zip_entry_name = "resume_checker/{$name_part}_({$counter}).{$ext}";
            $counter++;
        }
        $used_filenames[$zip_entry_name] = true;

        $zip->addFile($checker_file_path, 'resumes/' . $zip_entry_name);
        $added_count++;
    } else {
        // Create an audit report text document if PDF was temporary
        $report = "AI RESUME CHECKER AUDIT REPORT\r\n";
        $report .= "Candidate: " . ($r['user_name'] ?: 'Candidate') . " (" . ($r['user_email'] ?: 'Direct') . ")\r\n";
        $report .= "Date Checked: " . $r['created_at'] . "\r\n";
        $report .= "ATS Score: " . $r['overall_score'] . "% (" . ($r['rating_label'] ?: 'Evaluated') . ")\r\n";
        $report .= "Original Filename: " . $r['filename'] . "\r\n\r\n";
        $report .= "SUMMARY:\r\n" . ($r['summary'] ?: 'No summary') . "\r\n\r\n";
        $report .= "EXTRACTED TEXT:\r\n" . ($r['full_text'] ?: $r['stripped_text']) . "\r\n";

        $zip_entry_name = "resume_checker/[{$check_date}] [Checker Report] {$clean_name} - {$orig_filename}.txt";
        $counter = 1;
        while (isset($used_filenames[$zip_entry_name])) {
            $zip_entry_name = "resume_checker/[{$check_date}] [Checker Report] {$clean_name} - {$orig_filename}_({$counter}).txt";
            $counter++;
        }
        $used_filenames[$zip_entry_name] = true;

        $zip->addFromString('resumes/' . $zip_entry_name, $report);
        $added_count++;
    }

    fputcsv($csv_handle, [
        'Resume Checker',
        'check_' . $r['id'],
        $r['user_name'] ?: 'Candidate',
        $r['user_email'] ?: 'N/A',
        'N/A',
        'Resume Quality Review',
        'ATS Screening Feedback',
        'AI Resume Checker',
        $r['created_at'],
        $r['overall_score'] . '%',
        $r['rating_label'] ?: 'Audited',
        $r['filename'] ?: 'resume.pdf',
        'resumes/' . $zip_entry_name
    ]);
}

// Rewind and add manifest CSV
rewind($csv_handle);
$csv_content = stream_get_contents($csv_handle);
fclose($csv_handle);

$zip->addFromString('resume_export_manifest.csv', "\xEF\xBB\xBF" . $csv_content);

// Add README text file
$readme = "HireLah Centralized Resume Export Archive\r\n";
$readme .= "Generated On: " . date('Y-m-d H:i:s') . "\r\n";
$readme .= "Exported By Admin: " . $admin_name . " (ID #" . $admin_id . ")\r\n";
$readme .= "Filter Criteria: " . implode(', ', $filter_desc_parts) . "\r\n";
$readme .= "Total Documents In Archive: " . $added_count . "\r\n\r\n";
$readme .= "Archive Structure:\r\n";
$readme .= " - resumes/job_applications/ : Resumes submitted by candidates for job postings\r\n";
$readme .= " - resumes/resume_builder/   : Resumes crafted using the AI Resume Builder (HTML format)\r\n";
$readme .= " - resumes/resume_checker/   : Resumes audited by the AI Resume Quality Checker\r\n";
$readme .= " - resume_export_manifest.csv: Complete spreadsheet with candidate details, scores, and file paths\r\n";

$zip->addFromString('README.txt', $readme);
$zip->close();

if ($added_count === 0) {
    @unlink($temp_zip);
    $_SESSION['error'] = "No valid resume files were available to package into the ZIP archive.";
    header("Location: admin_resumes.php");
    exit;
}

// Log admin action
$log_desc = "Exported {$added_count} resume documents (" . implode(', ', $filter_desc_parts) . ")";
log_admin_action($pdo, $admin_id, $admin_name, 'export_resumes', 'resumes', null, $log_desc);

// Generate filename for download
$timestamp_slug = date('Ymd_His');
$source_slug = str_replace('_', '-', $source_filter);
if ($date_mode === 'exact') {
    $zip_filename = "HireLah_Resumes_{$source_slug}_{$exact_date}_{$timestamp_slug}.zip";
} elseif ($date_mode === 'range') {
    $zip_filename = "HireLah_Resumes_{$source_slug}_{$start_date}_to_{$end_date}_{$timestamp_slug}.zip";
} else {
    $zip_filename = "HireLah_Resumes_{$source_slug}_All_{$timestamp_slug}.zip";
}

if (ob_get_level()) ob_end_clean();

header('Content-Description: File Transfer');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($temp_zip));

readfile($temp_zip);
@unlink($temp_zip);
exit;
