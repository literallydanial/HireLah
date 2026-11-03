<?php
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';
require 'parser.php';
require 'ai.php';

// Scope selectable jobs to this employer's own company team, same rule used
// on employer_dashboard.php / compare_candidates.php, so HR can't attach an
// externally-sourced resume to a job posting they don't own.
$active_company_id = get_active_company_id($pdo);
if ($active_company_id) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE (company_id = ? OR (company_id IS NULL AND employer_id = ?)) AND status = 'Active'");
    $stmt->execute([$active_company_id, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE (employer_id = ? OR employer_id IS NULL) AND status = 'Active'");
    $stmt->execute([$_SESSION['user_id']]);
}
$jobs = $stmt->fetchAll();
$job_ids_allowed = array_column($jobs, 'id');

$preselect_job_id = $_GET['job_id'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job_id = $_POST['job_id'] ?? null;
    $api_key = get_api_key();

    if (!$job_id || !in_array($job_id, $job_ids_allowed)) {
        $_SESSION['error'] = "Please select one of your own job postings.";
        header("Location: upload.php");
        exit;
    }

    // Fetch job description for AI evaluation
    $stmt = $pdo->prepare("SELECT description FROM jobs WHERE id = ?");
    $stmt->execute([$job_id]);
    $job_desc = $stmt->fetchColumn();

    $files = $_FILES['resumes'] ?? null;

    if (!$files || empty($files['name'][0])) {
        $_SESSION['error'] = "No files uploaded.";
        header("Location: upload.php?job_id=$job_id");
        exit;
    }

    $upload_dir = 'uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

    // Duplicate guard: skip any resume whose exact file content was already
    // uploaded for this job (whether in an earlier request or elsewhere in
    // this same batch), e.g. from an accidental double-submit of the form.
    $dupe_stmt = $pdo->prepare("SELECT resume_hash FROM candidates WHERE job_id = ? AND resume_hash IS NOT NULL");
    $dupe_stmt->execute([$job_id]);
    $seen_hashes = array_flip($dupe_stmt->fetchAll(PDO::FETCH_COLUMN));

    $success_count = 0;
    $duplicate_count = 0;

    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $tmp_name = $files['tmp_name'][$i];
            $name = basename($files['name'][$i]);

            $hash = hash_file('sha256', $tmp_name);
            if ($hash !== false && isset($seen_hashes[$hash])) {
                $duplicate_count++;
                continue;
            }

            $path = $upload_dir . uniqid() . '_' . $name;

            if (move_uploaded_file($tmp_name, $path)) {
                try {
                    $text = extract_text_from_pdf($path);
                    $stripped = strip_pii($text);

                    $candidate_id = 'cnd_' . substr(md5(uniqid()), 0, 8);

                    $ai_data = null;
                    if ($api_key) {
                        try {
                            $ai_data = generate_snapshot($api_key, $stripped, $job_desc, 'bulk_upload');
                        } catch (Exception $e) {
                            $_SESSION['error'] = "AI Screening Notice: " . $e->getMessage();
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

                    if ($hash !== false) $seen_hashes[$hash] = true;
                    $success_count++;
                } catch (Exception $e) {
                    error_log("Upload Error ($name): " . $e->getMessage());
                }
            }
        }
    }

    $_SESSION['toast'] = "Successfully processed $success_count resume(s)."
        . ($duplicate_count > 0 ? " Skipped $duplicate_count duplicate resume(s) already on this job." : "")
        . " They now appear in this job's applicant list and comparison matrix.";
    header("Location: employer_dashboard.php?job_id=$job_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload External Resumes - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
                <div>
                    <div style="font-size:15px; font-weight:800; line-height:1" class="header-brand-title">Keria Job Portal</div>
                    <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">RECRUITMENT PLATFORM</div>
                </div>
            </div>
            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php">👥 Applications & Stats</a>
                <a href="job_dashboard.php">💼 My Jobs</a>
                <a href="upload.php" class="active">📤 Upload Resume</a>
                <a href="questionnaire.php">📋 Questionnaires</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>
            <?php include 'company_switcher.php'; ?>

            <div class="header-right-actions">
                <?php if(get_api_key()): ?>
                    <span style="color:var(--grn); font-size:11px; font-weight:700">🔑 Key Active</span>
                <?php else: ?>
                    <span style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:7px; color:var(--org); font-size:11px; padding:6px 12px; font-weight:600;">🔑 Not Configured &mdash; Contact Admin</span>
                <?php endif; ?>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; margin-left:8px;">Logout</a>
            </div>
        </div>
    </header>

    <main>
        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <div style="margin-bottom:18px;">
            <h1 style="font-size:20px; font-weight:800; color:var(--txt); margin:0 0 4px;">📤 Upload External Candidate Resumes</h1>
            <p style="font-size:13px; color:var(--mut); margin:0;">For candidates who applied outside Keria (e.g. email, walk-in, referral). Their resume gets the same AI screening and will appear in this job's applicant list and comparison matrix, marked as <strong>🧑‍💼 HR Added</strong>.</p>
        </div>

        <div class="grid-2">
            <div class="panel">
                <div class="panel-title">📤 Upload Resumes</div>

                <?php if(!get_api_key()): ?>
                    <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:10px; padding:12px 16px; color:var(--org); font-size:12px; margin-bottom:16px;">
                        <strong>Warning:</strong> AI screening is not yet configured. Resumes will be uploaded and text extracted, but AI screening will be skipped. Please contact your administrator to set up the Google Gemini API key.
                    </div>
                <?php endif; ?>

                <?php if(empty($jobs)): ?>
                    <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:10px; padding:12px 16px; color:var(--org); font-size:12px;">
                        You don't have any active job postings yet. <a href="job_dashboard.php" style="color:var(--acc);">Post a job</a> first.
                    </div>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Select Job Posting</label>
                            <select name="job_id" required>
                                <option value="">-- Select a Job --</option>
                                <?php foreach($jobs as $j): ?>
                                    <option value="<?= $j['id'] ?>" <?= ((string)$preselect_job_id === (string)$j['id']) ? 'selected' : '' ?>><?= htmlspecialchars($j['job_title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                            <div style="font-size:46px; margin-bottom:12px;">📁</div>
                            <div style="font-size:15px; font-weight:700; color:var(--txt); margin-bottom:8px;">Drag & drop resumes here</div>
                            <div style="font-size:12px; color:var(--mut); margin-bottom:20px;">PDF supported • Multiple files at once</div>
                            <div class="btn-secondary">📂 Browse Files</div>
                            <input type="file" name="resumes[]" id="fileInput" multiple accept=".pdf" style="display:none" onchange="submitUploadForm()">
                        </div>

                        <button type="submit" class="btn-primary" style="margin-top:16px;">Upload & Screen Resumes</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="panel" style="background:rgba(0, 212, 255, 0.04); border-color:rgba(0, 212, 255, 0.15);">
                <div class="panel-title">💡 How It Works</div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">1.</span> Upload PDF resumes of candidates who applied outside Keria
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">2.</span> Text is extracted securely
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">3.</span> PII (Phone/Email/IC) is stripped out locally
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">4.</span> Cleaned resume is evaluated by AI against this job's description
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">5.</span> Candidate is added to this job's ranked applicant list & comparison matrix, tagged 🧑‍💼 HR Added
                </div>
            </div>
        </div>
    </main>
<script>
(function() {
    var form = document.getElementById('uploadForm');
    if (!form) return;
    var submitting = false;
    form.addEventListener('submit', function(e) {
        if (submitting) { e.preventDefault(); return; }
        submitting = true;
        var btn = form.querySelector('button[type="submit"]');
        if (btn) { btn.disabled = true; btn.textContent = 'Uploading...'; }
        var dz = document.getElementById('dropzone');
        if (dz) dz.style.pointerEvents = 'none';
    });
})();
function submitUploadForm() {
    var form = document.getElementById('uploadForm');
    if (!form) return;
    if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    } else {
        form.submit();
    }
}
</script>
<script src="theme.js"></script></body>
</html>
