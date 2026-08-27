<?php
require_once 'auth.php';
require_once 'parser.php';
require_once 'ai.php';

$job_id = $_GET['job_id'] ?? ($_POST['job_id'] ?? null);
if (!$job_id) {
    header("Location: jobs.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ?");
$stmt->execute([$job_id]);
$job = $stmt->fetch();

if (!$job) {
    $_SESSION['error'] = "Job position not found.";
    header("Location: jobs.php");
    exit;
}

// Require Candidate Login before applying (preserves job application target)
if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'candidate') {
    $_SESSION['redirect_after_login'] = "apply.php?job_id=" . (int)$job_id;
    $_SESSION['toast'] = "Please create a candidate account to apply for " . htmlspecialchars($job['job_title']) . ".";
    header("Location: register.php");
    exit;
}

// Block duplicate applications for candidates who already applied
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'candidate') {
    $check_stmt = $pdo->prepare("SELECT id FROM candidates WHERE user_id = ? AND job_id = ?");
    $check_stmt->execute([$_SESSION['user_id'], $job_id]);
    if ($check_stmt->fetch()) {
        $_SESSION['error'] = "You have already applied for this job.";
        header("Location: jobs.php");
        exit;
    }
}

$api_key = get_api_key(); 

// Fetch user profile to check for default resume
$stmt_u = $pdo->prepare("SELECT default_resume FROM users WHERE id = ?");
$stmt_u->execute([$_SESSION['user_id']]);
$user = $stmt_u->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $use_saved = isset($_POST['use_saved']) && $_POST['use_saved'] == '1';
    
    $path = null;
    $name = null;
    $error = null;
    
    if ($use_saved && $user['default_resume']) {
        $path = $user['default_resume'];
        $name = basename($path);
    } else {
        $file = $_FILES['resume'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            $error = "Uploaded file exceeds the maximum allowed size limit (10MB). Please upload a smaller PDF.";
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "Please upload a valid PDF resume file.";
        } else {
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            
            if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                $error = "Upload folder 'uploads/' is not writable on the server. Please check FTP directory permissions.";
            } else {
                $name = basename($file['name']);
                $path = $upload_dir . uniqid() . '_' . $name;
                if (!move_uploaded_file($file['tmp_name'], $path)) {
                    $error = "Failed to save uploaded file. Please verify directory permissions.";
                    $path = null;
                }
            }
        }
    }
    
    $youtube_url = trim($_POST['youtube_url'] ?? '');
    
    if (!empty($job['require_video']) && empty($youtube_url)) {
        $error = "Please provide a valid YouTube video introduction link as required for this position.";
    }

    if ($path && !$error) {
        try {
            $text = extract_text_from_pdf($path);
            $stripped = strip_pii($text);
                
                $candidate_id = 'app_' . substr(md5(uniqid()), 0, 8);
                
                $ai_data = null;
                if ($api_key) {
                    try {
                        $ai_data = generate_snapshot($api_key, $stripped, $job['description']);
                    } catch (Exception $e) {
                        error_log("AI Error: " . $e->getMessage());
                    }
                }
                
                $stmt = $pdo->prepare("INSERT INTO candidates (
                    id, job_id, user_id, name, email, filename, full_text, stripped_text,
                    recommendation, overall_score, skills_match, exp_match, edu_match,
                    summary, education, experience, skills, strengths, gaps,
                    relevance_label, note, suggested_question, screened, resume_path, youtube_url
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                $user_name = $_SESSION['user_name'];
                
                $stmt->execute([
                    $candidate_id,
                    $job_id,
                    $_SESSION['user_id'],
                    $user_name,
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
                    $youtube_url ?: null
                ]);
                
                $_SESSION['toast'] = "Application submitted successfully!";
                header("Location: candidate_dashboard.php");
                exit;
            } catch (Exception $e) {
                $error = "Error processing resume: " . $e->getMessage();
            }
        }
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply - <?= htmlspecialchars($job['job_title']) ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px 0;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <div class="panel" style="max-width:500px; width:100%; position:relative; z-index:2;">
        <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
            <a href="jobs.php" class="btn-secondary" style="padding:6px 12px; font-size:12px;">&larr; Back</a>
        </div>
        <!-- Selected Job Information Overview Card -->
        <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:20px; margin-bottom:24px;">
            <div style="font-size:20px; font-weight:800; color:var(--txt); margin-bottom:4px;"><?= htmlspecialchars($job['job_title']) ?></div>
            <div style="font-size:12px; color:var(--mut); margin-bottom:12px; display:flex; gap:8px;">
                <span>📍 <?= htmlspecialchars($job['department'] ?: 'General') ?></span>
                <span>|</span>
                <span>💼 <?= htmlspecialchars($job['employment_type']) ?></span>
                <?php if(!empty($job['work_mode'])): ?>
                    <span>|</span>
                    <span>🌐 <?= htmlspecialchars($job['work_mode']) ?></span>
                <?php endif; ?>
            </div>
            <?php if(!empty($job['description'])): ?>
                <div style="font-size:13px; color:var(--txt); line-height:1.5; white-space:pre-wrap; max-height:150px; overflow-y:auto; padding-top:10px; border-top:1px dashed var(--bdr);"><?= htmlspecialchars($job['description']) ?></div>
            <?php endif; ?>
        </div>

        <?php if(isset($error)): ?>
            <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if(!empty($job['require_video'])): ?>
            <div style="background:rgba(180, 214, 0, 0.08); border:1px solid rgba(180, 214, 0, 0.25); border-radius:10px; padding:14px; margin-bottom:20px;">
                <div style="font-size:13px; font-weight:700; color:var(--txt); margin-bottom:4px;">📹 Video Introduction Required</div>
                <div style="font-size:12px; color:var(--mut);">The employer requests a YouTube video introduction link for this position.</div>
            </div>
        <?php endif; ?>

        <?php if($user['default_resume']): ?>
            <div class="panel" style="margin-bottom:20px;">
                <div style="font-size:14px; font-weight:700; margin-bottom:12px;">Quick Apply</div>
                <div style="display:flex; align-items:center; gap:12px; margin-bottom:16px;">
                    <span style="font-size:24px;">📄</span>
                    <div>
                        <div style="font-size:13px; font-weight:600;">Saved Resume</div>
                        <div style="font-size:11px; color:var(--mut);">Ready to use for this application.</div>
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="job_id" value="<?= $job_id ?>">
                    <input type="hidden" name="use_saved" value="1">
                    
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">
                            YouTube Video Introduction Link <?= !empty($job['require_video']) ? '<span style="color:var(--red)">*</span>' : '(Optional)' ?>
                        </label>
                        <input type="url" name="youtube_url" placeholder="https://www.youtube.com/watch?v=..." <?= !empty($job['require_video']) ? 'required' : '' ?>>
                    </div>

                    <button type="submit" class="btn-primary" style="background:linear-gradient(135deg, var(--grn), #059669);">Quick Apply with Saved Resume &rarr;</button>
                </form>
            </div>
            
            <div style="text-align:center; font-size:12px; color:var(--mut); margin-bottom:20px; font-weight:600;">— OR UPLOAD A NEW ONE —</div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="job_id" value="<?= $job_id ?>">
            
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">
                    YouTube Video Introduction Link <?= !empty($job['require_video']) ? '<span style="color:var(--red)">*</span>' : '(Optional)' ?>
                </label>
                <input type="url" name="youtube_url" placeholder="https://www.youtube.com/watch?v=..." <?= !empty($job['require_video']) ? 'required' : '' ?>>
            </div>

            <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()" style="padding:30px 20px; margin-bottom:20px;">
                <div style="font-size:36px; margin-bottom:12px;">📁</div>
                <div style="font-size:14px; font-weight:700; color:var(--txt); margin-bottom:8px;">Select Resume (PDF only)</div>
                <input type="file" name="resume" id="fileInput" accept=".pdf" required style="display:none" onchange="document.getElementById('fileStatus').innerText = this.files[0].name">
                <div id="fileStatus" style="font-size:12px; color:var(--acc); font-weight:600;"></div>
            </div>
            
            <button type="submit" class="btn-primary">Submit Application &rarr;</button>
        </form>
    </div>
<script src="theme.js"></script></body>
</html>
