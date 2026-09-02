<?php
session_start();
require 'db.php';
require 'parser.php';
require 'ai.php';

// Fetch active jobs
$stmt = $pdo->query("SELECT * FROM jobs WHERE status='Active'");
$jobs = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $job_id = $_POST['job_id'] ?? null;
    $api_key = get_api_key();
    
    if (!$job_id) {
        $_SESSION['error'] = "Please select a job.";
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
        header("Location: upload.php");
        exit;
    }
    
    $upload_dir = 'uploads/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $success_count = 0;
    
    for ($i = 0; $i < count($files['name']); $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $tmp_name = $files['tmp_name'][$i];
            $name = basename($files['name'][$i]);
            $path = $upload_dir . uniqid() . '_' . $name;
            
            if (move_uploaded_file($tmp_name, $path)) {
                try {
                    $text = extract_text_from_pdf($path);
                    $stripped = strip_pii($text);
                    
                    $candidate_id = 'cnd_' . substr(md5(uniqid()), 0, 8);
                    
                    $ai_data = null;
                    if ($api_key) {
                        try {
                            $ai_data = generate_snapshot($api_key, $stripped, $job_desc);
                        } catch (Exception $e) {
                            $_SESSION['error'] = "AI Screening Notice: " . $e->getMessage();
                            error_log("AI Error: " . $e->getMessage());
                        }
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO candidates (
                        id, job_id, name, email, filename, full_text, stripped_text,
                        recommendation, overall_score, skills_match, exp_match, edu_match,
                        summary, education, experience, skills, strengths, gaps,
                        relevance_label, note, suggested_question, screened
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
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
                        $ai_data ? 1 : 0
                    ]);
                    
                    $success_count++;
                } catch (Exception $e) {
                    error_log("Upload Error ($name): " . $e->getMessage());
                }
            }
        }
    }
    
    $_SESSION['toast'] = "Successfully processed $success_count resumes.";
    header("Location: employer_dashboard.php?job_id=$job_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload Resumes</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
</head>
<body>
    <header>
        <div class="header-inner">
            <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php">👥 Applications & Stats</a>
                <a href="upload.php" class="active">📤 Upload Resumes</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>
            
            <div style="margin-left:auto; display:flex; gap:8px; align-items:center;">
                <?php if(get_api_key()): ?>
                    <span style="color:var(--grn); font-size:11px; font-weight:700">🔑 Key Active</span>
                <?php else: ?>
                    <span style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:7px; color:var(--org); font-size:11px; padding:6px 12px; font-weight:600;">🔑 Not Configured &mdash; Contact Admin</span>
                <?php endif; ?>
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

        <div class="grid-2">
            <div class="panel">
                <div class="panel-title">📤 Upload Resumes</div>
                
                <?php if(!get_api_key()): ?>
                    <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:10px; padding:12px 16px; color:var(--org); font-size:12px; margin-bottom:16px;">
                        <strong>Warning:</strong> AI screening is not yet configured. Resumes will be uploaded and text extracted, but AI screening will be skipped. Please contact your administrator to set up the Google Gemini API key.
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Select Job Posting</label>
                        <select name="job_id" required>
                            <option value="">-- Select a Job --</option>
                            <?php foreach($jobs as $j): ?>
                                <option value="<?= $j['id'] ?>"><?= htmlspecialchars($j['job_title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                        <div style="font-size:46px; margin-bottom:12px;">📁</div>
                        <div style="font-size:15px; font-weight:700; color:var(--txt); margin-bottom:8px;">Drag & drop resumes here</div>
                        <div style="font-size:12px; color:var(--mut); margin-bottom:20px;">PDF supported • Multiple files at once</div>
                        <div class="btn-secondary">📂 Browse Files</div>
                        <input type="file" name="resumes[]" id="fileInput" multiple accept=".pdf" style="display:none" onchange="document.getElementById('uploadForm').submit()">
                    </div>
                    
                    <button type="submit" class="btn-primary" style="margin-top:16px;">Upload & Screen Resumes</button>
                </form>
            </div>
            
            <div class="panel" style="background:rgba(0, 212, 255, 0.04); border-color:rgba(0, 212, 255, 0.15);">
                <div class="panel-title">💡 How It Works</div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">1.</span> Upload PDF resumes
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">2.</span> Text is extracted securely
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">3.</span> PII (Phone/Email/IC) is stripped out locally
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">4.</span> Cleaned resume is evaluated by Google Gemini AI
                </div>
                <div style="display:flex; gap:9px; margin-bottom:7px; font-size:12px; color:var(--txt); align-items:flex-start;">
                    <span style="color:var(--acc); font-weight:700; flex-shrink:0; min-width:14px;">5.</span> Senior Recruiter evaluation report & match scores are generated
                </div>
            </div>
        </div>
    </main>
<script src="theme.js"></script></body>
</html>
