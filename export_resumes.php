<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
@set_time_limit(300);
@ini_set('memory_limit', '512M');

require_once 'db.php';
require_once 'admin_logs_helper.php';
require_once 'resume_pdf_helper.php';

/**
 * Standalone pure-PHP ZIP archive generator.
 * Zero external dependencies — works even when php-zip / ZipArchive is missing on server.
 */
class SimpleZipWriter {
    private $entries = [];
    private $fileHandle = null;
    private $filePath = null;
    private $offset = 0;

    public function __construct($filePath) {
        $this->filePath = $filePath;
        $this->fileHandle = fopen($filePath, 'wb+');
        if (!$this->fileHandle) {
            throw new \RuntimeException("Cannot open file for writing: " . $filePath);
        }
    }

    public function addFile($diskPath, $zipPath) {
        if (!is_file($diskPath) || !is_readable($diskPath)) {
            return false;
        }
        $data = file_get_contents($diskPath);
        return $this->addFromString($zipPath, $data);
    }

    public function addFromString($zipPath, $data) {
        $zipPath = str_replace('\\', '/', $zipPath);
        $zipPath = ltrim($zipPath, '/');
        
        $uncompressedSize = strlen($data);
        $crc32 = crc32($data);

        // Try deflate compression if zlib is available
        $compressedData = function_exists('gzdeflate') ? gzdeflate($data) : false;
        if ($compressedData !== false && strlen($compressedData) < $uncompressedSize) {
            $compressionMethod = 8; // DEFLATE
            $writePayload = $compressedData;
            $compressedSize = strlen($compressedData);
        } else {
            $compressionMethod = 0; // STORE
            $writePayload = $data;
            $compressedSize = $uncompressedSize;
        }

        $modTime = time();
        $dosTime = $this->unixToDosTime($modTime);

        $localHeaderOffset = $this->offset;

        // Local file header: 30 bytes + name length
        $localHeader = pack('VvvvVVVVvv',
            0x04034b50,        // Local file header signature (V)
            20,                // Version needed to extract (v)
            0,                 // General purpose bit flag (v)
            $compressionMethod,// Compression method (v)
            $dosTime,          // Last mod file time/date (V)
            $crc32,            // CRC-32 (V)
            $compressedSize,   // Compressed size (V)
            $uncompressedSize, // Uncompressed size (V)
            strlen($zipPath),  // File name length (v)
            0                  // Extra field length (v)
        ) . $zipPath;

        fwrite($this->fileHandle, $localHeader);
        fwrite($this->fileHandle, $writePayload);

        $this->offset += strlen($localHeader) + strlen($writePayload);

        $this->entries[] = [
            'name' => $zipPath,
            'compression' => $compressionMethod,
            'dosTime' => $dosTime,
            'crc32' => $crc32,
            'compressedSize' => $compressedSize,
            'uncompressedSize' => $uncompressedSize,
            'offset' => $localHeaderOffset
        ];

        return true;
    }

    public function close() {
        if (!$this->fileHandle) return true;

        $cdStartOffset = $this->offset;
        $cdSize = 0;

        // Write Central Directory headers
        foreach ($this->entries as $e) {
            $cdHeader = pack('VvvvvVVVVvvvvvVV',
                0x02014b50,         // Central directory signature (V)
                20,                 // Version made by (v)
                20,                 // Version needed (v)
                0,                  // Flags (v)
                $e['compression'],  // Compression method (v)
                $e['dosTime'],      // Time/date (V)
                $e['crc32'],        // CRC-32 (V)
                $e['compressedSize'], // (V)
                $e['uncompressedSize'], // (V)
                strlen($e['name']), // File name length (v)
                0,                  // Extra field length (v)
                0,                  // Comment length (v)
                0,                  // Disk number start (v)
                0,                  // Internal attributes (v)
                0,                  // External attributes (V)
                $e['offset']        // Relative offset of local header (V)
            ) . $e['name'];

            fwrite($this->fileHandle, $cdHeader);
            $cdSize += strlen($cdHeader);
            $this->offset += strlen($cdHeader);
        }

        $totalEntries = count($this->entries);

        // End of central directory record (EOCD): 22 bytes
        $eocd = pack('VvvvvVVv',
            0x06054b50,     // EOCD signature (V)
            0,              // Number of this disk (v)
            0,              // Disk where CD starts (v)
            $totalEntries,  // Total entries on this disk (v)
            $totalEntries,  // Total entries (v)
            $cdSize,        // Size of CD (V)
            $cdStartOffset, // Offset of CD start (V)
            0               // Comment length (v)
        );

        fwrite($this->fileHandle, $eocd);
        fclose($this->fileHandle);
        $this->fileHandle = null;

        return true;
    }

    private function unixToDosTime($time) {
        $date = getdate($time);
        if ($date['year'] < 1980) {
            return (1 << 21) | (1 << 16);
        }
        return (($date['year'] - 1980) << 25)
            | ($date['mon'] << 21)
            | ($date['mday'] << 16)
            | ($date['hours'] << 11)
            | ($date['minutes'] << 5)
            | ($date['seconds'] >> 1);
    }
}

/**
 * Universal ZIP Adapter: Uses ZipArchive if available, otherwise seamlessly falls back to SimpleZipWriter.
 */
class HireLahZipArchive {
    private $driver;
    private $isNative;

    public function __construct($filePath) {
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        if (class_exists('ZipArchive')) {
            $za = new ZipArchive();
            $res = $za->open($filePath, ZipArchive::CREATE);
            if ($res === true) {
                $this->driver = $za;
                $this->isNative = true;
                return;
            }
        }

        $this->driver = new SimpleZipWriter($filePath);
        $this->isNative = false;
    }

    public function addFile($diskPath, $zipPath) {
        return $this->driver->addFile($diskPath, $zipPath);
    }

    public function addFromString($zipPath, $content) {
        return $this->driver->addFromString($zipPath, $content);
    }

    public function close() {
        return $this->driver->close();
    }
}

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
$default_records = [];

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

// D. Saved Default Resumes (users.default_resume). No dedicated upload
// timestamp exists on `users`, so date filters fall back to account
// creation date as the closest available proxy — same caveat as the admin
// resume hub page.
if ($source_filter === 'all' || $source_filter === 'default_resume') {
    try {
        $sql = "SELECT u.id, u.name as user_name, u.email as user_email, u.default_resume, u.created_at
                FROM users u
                WHERE u.default_resume IS NOT NULL AND u.default_resume != ''";
        $params = [];

        if ($date_mode === 'exact') {
            $sql .= " AND DATE(u.created_at) = ?";
            $params[] = $exact_date;
        } elseif ($date_mode === 'range') {
            $sql .= " AND DATE(u.created_at) BETWEEN ? AND ?";
            $params[] = $start_date;
            $params[] = $end_date;
        }

        $sql .= " ORDER BY u.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $default_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

$total_found = count($candidates) + count($builder_records) + count($checker_records) + count($default_records);

if ($total_found === 0) {
    $_SESSION['error'] = "No resume documents found matching: " . implode(', ', $filter_desc_parts) . ". Try selecting 'All Time' or a broader date selection.";
    header("Location: admin_resumes.php");
    exit;
}

// Create temporary zip archive in writable directory
$temp_dir = sys_get_temp_dir();
if (!is_dir($temp_dir) || !is_writable($temp_dir)) {
    $temp_dir = __DIR__ . '/uploads';
}
$temp_zip = rtrim($temp_dir, '/\\') . '/hirelah_export_' . uniqid() . '.zip';

try {
    $zip = new HireLahZipArchive($temp_zip);
} catch (\Throwable $e) {
    $_SESSION['error'] = "Unable to initialize temporary ZIP archive file: " . $e->getMessage();
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
        $note = "JOB APPLICATION RECORD #{$c['id']}\r\n";
        $note .= "Candidate: " . ($c['name'] ?: 'Unknown') . "\r\n";
        $note .= "Email: " . ($c['email'] ?: 'N/A') . "\r\n";
        $note .= "Phone: " . ($c['phone'] ?: 'N/A') . "\r\n";
        $note .= "Job Title: " . ($c['job_title'] ?: 'General') . "\r\n";
        $note .= "Department: " . ($c['department'] ?: 'N/A') . "\r\n";
        $note .= "Employer: " . ($c['employer_name'] ?: 'Direct') . "\r\n";
        $note .= "Date Applied: " . $c['created_at'] . "\r\n";
        $note .= "Match Score: " . $c['overall_score'] . "%\r\n";
        $note .= "Status: " . ($c['status'] ?: 'Review') . "\r\n";
        $note .= "Original File: " . $orig_name . "\r\n";
        $note .= "Storage Notice: File attachment not found in server local disk storage.\r\n";

        $zip_entry_name = "job_applications/[{$app_date}] [{$clean_job}] {$clean_name} - {$orig_name}_REF.txt";
        $counter = 1;
        while (isset($used_filenames[$zip_entry_name])) {
            $zip_entry_name = "job_applications/[{$app_date}] [{$clean_job}] {$clean_name} - {$orig_name}_REF_({$counter}).txt";
            $counter++;
        }
        $used_filenames[$zip_entry_name] = true;
        $zip->addFromString('resumes/' . $zip_entry_name, $note);
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
// PACK 2: RESUME BUILDER (Generated PDF Resumes)
// ----------------------------------------------------
foreach ($builder_records as $b) {
    $build_date = date('Y-m-d', strtotime($b['created_at']));
    $clean_name = sanitize_zip_name($b['user_name'] ?: 'Candidate');
    $clean_title = sanitize_zip_name($b['target_title'] ?: 'Resume');

    try {
        $file_content = generate_resume_builder_pdf($b);
        $ext = 'pdf';
    } catch (\Throwable $e) {
        $file_content = render_resume_builder_html($b);
        $ext = 'html';
    }

    $zip_entry_name = "resume_builder/[{$build_date}] [Builder] {$clean_name} - {$clean_title}.{$ext}";
    $counter = 1;
    while (isset($used_filenames[$zip_entry_name])) {
        $zip_entry_name = "resume_builder/[{$build_date}] [Builder] {$clean_name} - {$clean_title}_({$counter}).{$ext}";
        $counter++;
    }
    $used_filenames[$zip_entry_name] = true;

    $zip->addFromString('resumes/' . $zip_entry_name, $file_content);
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
        'Built Resume (PDF)',
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

// ----------------------------------------------------
// PACK 4: SAVED DEFAULT RESUMES (Candidate Profile PDFs)
// ----------------------------------------------------
foreach ($default_records as $d) {
    $raw_path = $d['default_resume'];
    $default_file_path = null;
    $possible_paths = [
        $raw_path,
        __DIR__ . '/' . ltrim($raw_path, '/\\'),
        __DIR__ . '/uploads/resumes/' . basename($raw_path)
    ];

    foreach ($possible_paths as $p) {
        if (file_exists($p) && is_file($p)) {
            $default_file_path = $p;
            break;
        }
    }

    $saved_date = date('Y-m-d', strtotime($d['created_at']));
    $clean_name = sanitize_zip_name($d['user_name'] ?: 'Candidate');
    $orig_name = sanitize_zip_name(basename($raw_path));

    if (!$default_file_path) {
        $note = "SAVED USER RESUME RECORD #{$d['id']}\r\n";
        $note .= "User: " . ($d['user_name'] ?: 'Unknown') . "\r\n";
        $note .= "Email: " . ($d['user_email'] ?: 'N/A') . "\r\n";
        $note .= "Date Created: " . $d['created_at'] . "\r\n";
        $note .= "Original File Name: " . $orig_name . "\r\n";
        $note .= "Storage Notice: File attachment not found in server local disk storage.\r\n";

        $zip_entry_name = "default_resumes/[{$saved_date}] {$clean_name} - {$orig_name}_REF.txt";
        $counter = 1;
        while (isset($used_filenames[$zip_entry_name])) {
            $zip_entry_name = "default_resumes/[{$saved_date}] {$clean_name} - {$orig_name}_REF_({$counter}).txt";
            $counter++;
        }
        $used_filenames[$zip_entry_name] = true;
        $zip->addFromString('resumes/' . $zip_entry_name, $note);
        $added_count++;

        fputcsv($csv_handle, [
            'User Resume',
            'default_' . $d['id'],
            $d['user_name'] ?: 'Unknown',
            $d['user_email'] ?: 'N/A',
            'N/A',
            'Saved User Resume',
            'Candidate Profile',
            'Candidate Profile',
            $d['created_at'],
            'N/A',
            'Saved',
            basename($raw_path),
            'resumes/' . $zip_entry_name
        ]);
        continue;
    }

    $ext = pathinfo($default_file_path, PATHINFO_EXTENSION) ?: 'pdf';
    $base_zip_entry = "default_resumes/[{$saved_date}] {$clean_name} - {$orig_name}";
    if (!str_ends_with(strtolower($base_zip_entry), '.' . strtolower($ext))) {
        $base_zip_entry .= '.' . $ext;
    }

    $zip_entry_name = $base_zip_entry;
    $counter = 1;
    while (isset($used_filenames[$zip_entry_name])) {
        $name_part = pathinfo($base_zip_entry, PATHINFO_FILENAME);
        $zip_entry_name = "default_resumes/{$name_part}_({$counter}).{$ext}";
        $counter++;
    }
    $used_filenames[$zip_entry_name] = true;

    $zip->addFile($default_file_path, 'resumes/' . $zip_entry_name);
    $added_count++;

    fputcsv($csv_handle, [
        'User Resume',
        'default_' . $d['id'],
        $d['user_name'] ?: 'Unknown',
        $d['user_email'] ?: 'N/A',
        'N/A',
        'Saved User Resume',
        'Candidate Profile',
        'Candidate Profile',
        $d['created_at'],
        'N/A',
        'Saved',
        basename($raw_path),
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
$readme .= " - resumes/resume_builder/   : Resumes crafted using the AI Resume Builder (PDF & HTML)\r\n";
$readme .= " - resumes/resume_checker/   : Resumes audited by the AI Resume Quality Checker\r\n";
$readme .= " - resumes/default_resumes/  : Candidates' saved profile resumes\r\n";
$readme .= " - resume_export_manifest.csv: Complete spreadsheet with candidate details, scores, and file paths\r\n";

$zip->addFromString('README.txt', $readme);
$zip->close();

if (!file_exists($temp_zip) || filesize($temp_zip) === 0) {
    @unlink($temp_zip);
    $_SESSION['error'] = "Failed to construct the ZIP archive file on the server.";
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

while (ob_get_level() > 0) {
    ob_end_clean();
}

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
