<?php
require_once 'auth.php';
require_once 'parser.php';
require_once 'ai.php';

// AI Resume Checker — a standalone, general resume-quality feedback tool.
// Not tied to any job posting; gives the candidate coaching-style feedback
// on their own resume (clarity, impact, formatting, ATS-friendliness).

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'candidate') {
    $_SESSION['redirect_after_login'] = "resume_check.php";
    $_SESSION['toast'] = "Please log in (or create a free candidate account) to use the AI Resume Checker.";
    header("Location: register.php");
    exit;
}

$api_key = get_api_key();
$error = null;
$result = null;
$uploaded_filename = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $error = "Upload folder 'uploads/' is not writable on the server. Please check directory permissions.";
        } else {
            $uploaded_filename = basename($file['name']);
            $path = $upload_dir . uniqid() . '_' . $uploaded_filename;

            if (!move_uploaded_file($file['tmp_name'], $path)) {
                $error = "Failed to save uploaded file. Please try again.";
            } else {
                try {
                    $text = extract_text_from_pdf($path);
                    $stripped = strip_pii($text);

                    $result = generate_resume_review($api_key, $stripped);

                    $stmt = $pdo->prepare("INSERT INTO resume_reviews (
                        user_id, filename, full_text, stripped_text, overall_score, rating_label,
                        summary, strengths, improvements, formatting_notes, ats_tips
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    $stmt->execute([
                        $_SESSION['user_id'],
                        $uploaded_filename,
                        $text,
                        $stripped,
                        $result['overall_score'] ?? 0,
                        $result['rating_label'] ?? null,
                        $result['summary'] ?? null,
                        json_encode($result['strengths'] ?? []),
                        json_encode($result['improvements'] ?? []),
                        $result['formatting_notes'] ?? null,
                        json_encode($result['ats_tips'] ?? [])
                    ]);
                } catch (Exception $e) {
                    $error = "Error processing resume: " . $e->getMessage();
                    error_log("Resume Check Error: " . $e->getMessage());
                }
            }
        }
    }
}

// Past checks for this candidate (most recent first)
$past_reviews = [];
try {
    $stmt = $pdo->prepare("SELECT id, filename, overall_score, rating_label, created_at FROM resume_reviews WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $past_reviews = $stmt->fetchAll();
} catch (Exception $e) {
    // table may not exist yet if migration hasn't been run — fail quietly
}

function score_color($score) {
    if ($score >= 75) return 'var(--grn)';
    if ($score >= 50) return 'var(--org)';
    return 'var(--red)';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Resume Checker - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body style="padding:20px 0;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>

    <div style="max-width:760px; margin:0 auto; padding:0 16px; position:relative; z-index:2;">
        <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
            <a href="index.php" class="btn-secondary" style="padding:6px 12px; font-size:12px;">&larr; Back to Home</a>
        </div>

        <div class="panel" style="margin-bottom:24px;">
            <div class="panel-title">🤖 AI Resume Checker</div>
            <div style="font-size:13px; color:var(--mut); margin-bottom:4px;">
                Upload your resume and get instant, honest feedback from AI — clarity, impact, formatting, and ATS-friendliness. This is general coaching feedback, not tied to any specific job posting.
            </div>
        </div>

        <?php if(!get_api_key()): ?>
            <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:10px; padding:12px 16px; color:var(--org); font-size:12px; margin-bottom:20px;">
                <strong>Notice:</strong> AI screening is not yet configured on this server. You'll still get an automated basic check, but not full AI-generated coaching feedback.
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if($result): ?>
            <!-- ===== RESULTS ===== -->
            <div class="panel" style="margin-bottom:20px;">
                <div style="display:flex; align-items:center; gap:18px; margin-bottom:18px; flex-wrap:wrap;">
                    <div style="width:74px; height:74px; border-radius:50%; border:5px solid <?= score_color($result['overall_score'] ?? 0) ?>; display:flex; align-items:center; justify-content:center; font-size:22px; font-weight:800; color:var(--txt); flex-shrink:0;">
                        <?= (int)($result['overall_score'] ?? 0) ?>
                    </div>
                    <div>
                        <div style="font-size:11px; color:var(--mut); font-weight:700; text-transform:uppercase; letter-spacing:0.04em; margin-bottom:4px;">Resume Score &middot; <?= htmlspecialchars($uploaded_filename ?? '') ?></div>
                        <div style="font-size:18px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($result['rating_label'] ?? 'Reviewed') ?></div>
                    </div>
                </div>
                <div style="font-size:13px; color:var(--txt); line-height:1.6;"><?= nl2br(htmlspecialchars($result['summary'] ?? '')) ?></div>
            </div>

            <div class="grid-2" style="margin-bottom:20px;">
                <div class="panel">
                    <div class="panel-title" style="color:var(--grn);">✅ Strengths</div>
                    <?php if(!empty($result['strengths'])): ?>
                        <?php foreach($result['strengths'] as $s): ?>
                            <div style="font-size:12.5px; color:var(--txt); margin-bottom:8px; padding-left:16px; position:relative;">
                                <span style="position:absolute; left:0; color:var(--grn);">&bull;</span><?= htmlspecialchars($s) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:12.5px; color:var(--mut);">No strengths identified.</div>
                    <?php endif; ?>
                </div>
                <div class="panel">
                    <div class="panel-title" style="color:var(--org);">🛠️ Areas to Improve</div>
                    <?php if(!empty($result['improvements'])): ?>
                        <?php foreach($result['improvements'] as $s): ?>
                            <div style="font-size:12.5px; color:var(--txt); margin-bottom:8px; padding-left:16px; position:relative;">
                                <span style="position:absolute; left:0; color:var(--org);">&bull;</span><?= htmlspecialchars($s) ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="font-size:12.5px; color:var(--mut);">No improvement notes.</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if(!empty($result['formatting_notes'])): ?>
                <div class="panel" style="margin-bottom:20px;">
                    <div class="panel-title">📐 Formatting Notes</div>
                    <div style="font-size:12.5px; color:var(--txt); line-height:1.6;"><?= htmlspecialchars($result['formatting_notes']) ?></div>
                </div>
            <?php endif; ?>

            <?php if(!empty($result['ats_tips'])): ?>
                <div class="panel" style="margin-bottom:20px; background:rgba(0, 212, 255, 0.04); border-color:rgba(0, 212, 255, 0.15);">
                    <div class="panel-title">🔎 ATS Tips</div>
                    <?php foreach($result['ats_tips'] as $tip): ?>
                        <div style="font-size:12.5px; color:var(--txt); margin-bottom:8px; padding-left:16px; position:relative;">
                            <span style="position:absolute; left:0; color:var(--acc);">&bull;</span><?= htmlspecialchars($tip) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="text-align:center; margin-bottom:30px;">
                <a href="resume_check.php" class="btn-primary" style="display:inline-block;">Check Another Resume</a>
            </div>
        <?php else: ?>
            <!-- ===== UPLOAD FORM ===== -->
            <div class="panel" style="margin-bottom:24px;">
                <form method="POST" enctype="multipart/form-data" id="checkForm">
                    <div class="dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()" style="padding:36px 20px;">
                        <div style="font-size:42px; margin-bottom:12px;">📄</div>
                        <div style="font-size:14px; font-weight:700; color:var(--txt); margin-bottom:8px;">Select Your Resume (PDF only)</div>
                        <div style="font-size:12px; color:var(--mut); margin-bottom:16px;">Your resume is processed for feedback only &mdash; PII is stripped before AI review.</div>
                        <div class="btn-secondary">📂 Browse Files</div>
                        <input type="file" name="resume" id="fileInput" accept=".pdf" required style="display:none" onchange="document.getElementById('fileStatus').innerText = this.files[0].name; document.getElementById('submitBtn').disabled = false;">
                        <div id="fileStatus" style="font-size:12px; color:var(--acc); font-weight:600; margin-top:10px;"></div>
                    </div>

                    <button type="submit" id="submitBtn" class="btn-primary" style="margin-top:18px; width:100%;" disabled>Check My Resume &rarr;</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if(!empty($past_reviews)): ?>
            <div class="panel" style="margin-bottom:40px;">
                <div class="panel-title">🕓 Your Recent Checks</div>
                <?php foreach($past_reviews as $pr): ?>
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--bdr); font-size:12.5px;">
                        <div style="color:var(--txt); font-weight:600;"><?= htmlspecialchars($pr['filename'] ?: 'Resume') ?></div>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span style="color:var(--mut);"><?= date('d M Y', strtotime($pr['created_at'])) ?></span>
                            <span style="font-weight:800; color:<?= score_color((int)$pr['overall_score']) ?>;"><?= (int)$pr['overall_score'] ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<script src="theme.js"></script></body>
</html>
