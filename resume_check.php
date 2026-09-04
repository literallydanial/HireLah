<?php
require_once 'auth.php';
require_once 'parser.php';
require_once 'ai.php';

// AI Resume Checker — a standalone, general resume-quality feedback tool.
// Not tied to any job posting; gives the candidate coaching-style feedback
// on their own resume (clarity, impact, formatting, ATS-friendliness).
//
// Flow: anyone can land here and upload a resume — no login wall up front.
// The upload + AI analysis run immediately, and a teaser of the result is
// shown right on this page (score, filename, a taste of the summary) with
// the rest blurred behind an "unlock" panel. Nothing is saved to the
// resume_reviews table yet — there's no user_id for it. The full result
// (and having it saved to their history) requires logging in or creating a
// free account; redirect_after_login brings them straight back here, where
// the stashed result is picked up, saved, and shown in full.
$is_candidate = is_logged_in() && ($_SESSION['user_role'] ?? '') === 'candidate';

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

                    if ($is_candidate) {
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
                    } else {
                        // Not logged in as a candidate — hold the result in the session
                        // (nothing written to the resume_reviews table until we have a
                        // real user_id) so that whenever they do log in / register,
                        // redirect_after_login brings them back here and the block below
                        // picks the stashed result back up, saves it, and shows it in
                        // full. Meanwhile the render below shows this same $result right
                        // now, teased/blurred, on this page — no redirect.
                        $_SESSION['pending_resume_review'] = [
                            'filename' => $uploaded_filename,
                            'full_text' => $text,
                            'stripped_text' => $stripped,
                            'result' => $result,
                        ];
                        $_SESSION['redirect_after_login'] = "resume_check.php";
                    }
                } catch (\Throwable $e) {
                    // \Throwable, not just \Exception — a PHP-level error (e.g. from the
                    // PDF library or a DB call) would otherwise bypass this catch entirely
                    // and crash the whole request instead of showing a friendly error.
                    $error = "Error processing resume: " . $e->getMessage();
                    error_log("Resume Check Error: " . $e->getMessage());
                }
            }
        }
    }

    // Post/Redirect/Get: never render the result straight from a POST
    // response. If we did, hitting the browser's Back button after
    // navigating away (e.g. to register.php or login.php) would re-show
    // Chrome's "Confirm Form Resubmission" page instead of the report.
    // Flash the outcome into the session for one GET request, then
    // redirect — the file upload itself already happened above, so a
    // page reload after this redirect is just a normal GET, no resubmit.
    $_SESSION['resume_check_flash'] = [
        'error' => $error,
        'result' => $result,
        'filename' => $uploaded_filename,
    ];
    header("Location: resume_check.php");
    exit;
}

// Pick up the flashed outcome of a POST we just redirected from (upload
// just submitted, or a validation error) — one-time read, cleared here.
if (!empty($_SESSION['resume_check_flash'])) {
    $flash = $_SESSION['resume_check_flash'];
    unset($_SESSION['resume_check_flash']);
    $error = $flash['error'] ?? null;
    $result = $flash['result'] ?? null;
    $uploaded_filename = $flash['filename'] ?? null;
}

// Picking up a result that was analyzed before the visitor logged in.
if ($is_candidate && !empty($_SESSION['pending_resume_review'])) {
    $pending = $_SESSION['pending_resume_review'];
    unset($_SESSION['pending_resume_review']);

    $result = $pending['result'];
    $uploaded_filename = $pending['filename'];

    try {
        $stmt = $pdo->prepare("INSERT INTO resume_reviews (
            user_id, filename, full_text, stripped_text, overall_score, rating_label,
            summary, strengths, improvements, formatting_notes, ats_tips
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $_SESSION['user_id'],
            $pending['filename'],
            $pending['full_text'],
            $pending['stripped_text'],
            $result['overall_score'] ?? 0,
            $result['rating_label'] ?? null,
            $result['summary'] ?? null,
            json_encode($result['strengths'] ?? []),
            json_encode($result['improvements'] ?? []),
            $result['formatting_notes'] ?? null,
            json_encode($result['ats_tips'] ?? [])
        ]);
    } catch (\Throwable $e) {
        error_log("Resume Check Error (pending save): " . $e->getMessage());
    }
}

// Whether the just-computed (or just-restored) $result should be shown in
// full or as a teaser with most of it blurred out. A logged-in candidate
// always gets the full view — including the moment right after login, when
// the block above just restored their pending review.
$locked = ($result !== null) && !$is_candidate;

// Past checks for this candidate (most recent first)
$past_reviews = [];
if ($is_candidate) {
    try {
        $stmt = $pdo->prepare("SELECT id, filename, overall_score, rating_label, created_at FROM resume_reviews WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$_SESSION['user_id']]);
        $past_reviews = $stmt->fetchAll();
    } catch (Exception $e) {
        // table may not exist yet if migration hasn't been run — fail quietly
    }
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
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>

    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo"></div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <?php if(($_SESSION['user_role'] ?? '') === 'candidate'): ?>
                    <a href="jobs.php">📋 Job Board</a>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="resume_builder.php" class="active">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Profile Settings</a>
                <?php elseif(($_SESSION['user_role'] ?? '') === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications & Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="resume_builder.php" class="active">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php elseif(($_SESSION['user_role'] ?? '') === 'admin'): ?>
                    <a href="admin_dashboard.php">🛡️ Control Panel</a>
                    <a href="resume_builder.php" class="active">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php else: ?>
                    <a href="jobs.php">📋 Find Jobs</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                <?php endif; ?>
            </nav>

            <div class="header-right-actions">
                <?php if(isset($_SESSION['user_name'])): ?>
                    <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                    <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; white-space:nowrap;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; margin-right:8px; white-space:nowrap; flex-shrink:0;">Log In</a>
                    <a href="register.php" class="btn-primary" style="padding:6px 14px; font-size:12px; width:auto; white-space:nowrap; flex-shrink:0;">Sign Up Free</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main style="max-width:1180px; margin:36px auto; padding:0 24px; position:relative; z-index:2;">

        <div class="panel" style="margin-bottom:24px;">
            <div class="panel-title">🤖 AI Resume Checker</div>
            <div style="font-size:13px; color:var(--mut); margin-bottom:4px;">
                Upload your resume and get instant, honest feedback from AI — clarity, impact, formatting, and ATS-friendliness. This is general coaching feedback, not tied to any specific job posting.
                <?php if (!$is_candidate): ?>
                    Free to try — you'll just need to log in or create a free account afterward to view your results.
                <?php endif; ?>
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

        <?php if($result):
            // For a locked (not-logged-in) view, the score/filename/rating stay
            // fully visible as the hook, and the summary shows its first
            // sentence in the clear with the rest blurred — real text, just
            // visually obscured, so it reads as "there's more" rather than as
            // a fake teaser.
            $summary = $result['summary'] ?? '';
            $summary_teaser = $summary;
            $summary_rest = '';
            if ($locked && $summary !== '' && preg_match('/^(.*?[.!?])(\s+.*)?$/s', $summary, $m)) {
                $summary_teaser = $m[1];
                $summary_rest = trim($m[2] ?? '');
            }
        ?>
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
                <div style="font-size:13px; color:var(--txt); line-height:1.6;">
                    <?= nl2br(htmlspecialchars($summary_teaser)) ?>
                    <?php if ($summary_rest !== ''): ?>
                        <span style="filter:blur(4px); user-select:none;"><?= nl2br(htmlspecialchars($summary_rest)) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($locked): ?>
                <div style="position:relative; min-height:300px; margin-bottom:20px;">
                    <div style="filter:blur(7px); opacity:0.55; pointer-events:none; user-select:none;" aria-hidden="true">
                        <div class="grid-2" style="margin-bottom:20px;">
                            <div class="panel">
                                <div class="panel-title" style="color:var(--grn);">✅ Strengths</div>
                                <?php foreach($result['strengths'] ?? ['Clear, relevant work history', 'Good use of measurable results', 'Well-organized sections'] as $s): ?>
                                    <div style="font-size:12.5px; color:var(--txt); margin-bottom:8px; padding-left:16px; position:relative;">
                                        <span style="position:absolute; left:0; color:var(--grn);">&bull;</span><?= htmlspecialchars($s) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="panel">
                                <div class="panel-title" style="color:var(--org);">🛠️ Areas to Improve</div>
                                <?php foreach($result['improvements'] ?? ['Tighten the summary section', 'Quantify more achievements', 'Fix formatting inconsistencies'] as $s): ?>
                                    <div style="font-size:12.5px; color:var(--txt); margin-bottom:8px; padding-left:16px; position:relative;">
                                        <span style="position:absolute; left:0; color:var(--org);">&bull;</span><?= htmlspecialchars($s) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="panel" style="margin-bottom:20px; background:rgba(0, 212, 255, 0.04); border-color:rgba(0, 212, 255, 0.15);">
                            <div class="panel-title">🔎 ATS Tips</div>
                            <?php foreach($result['ats_tips'] ?? ['Use standard section headings', 'Avoid tables and text boxes', 'Include keywords from the job description'] as $tip): ?>
                                <div style="font-size:12.5px; color:var(--txt); margin-bottom:8px; padding-left:16px; position:relative;">
                                    <span style="position:absolute; left:0; color:var(--acc);">&bull;</span><?= htmlspecialchars($tip) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; padding:20px;">
                        <div class="panel" style="max-width:420px; width:100%; text-align:center; box-shadow:0 16px 40px rgba(0,0,0,0.22);">
                            <div style="font-size:32px; margin-bottom:8px;">🔒</div>
                            <div style="font-size:16px; font-weight:800; color:var(--txt); margin-bottom:6px;">Unlock Your Full Resume Report</div>
                            <div style="font-size:12.5px; color:var(--mut); margin-bottom:18px;">
                                Log in or create a free account to see your full strengths, areas to improve, formatting notes, and ATS tips &mdash; free, takes under a minute.
                            </div>
                            <a href="register.php" class="btn-primary" style="display:block; margin-bottom:10px;">Create Free Account &rarr;</a>
                            <a href="login.php" class="btn-secondary" style="display:block;">Already have an account? Log In</a>
                        </div>
                    </div>
                </div>

                <div style="text-align:center; margin-bottom:30px;">
                    <a href="resume_check.php" class="btn-secondary" style="display:inline-block;">Check a Different Resume</a>
                </div>
            <?php else: ?>
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
            <?php endif; ?>
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
    </main>
<script src="theme.js"></script></body>
</html>
