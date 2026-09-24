<?php
require_once 'auth.php';
require_once 'ai.php';

// AI Resume Builder — candidate fills a guided form (with an optional
// "Ask AI" wording-help assist per role), AI turns it into a polished
// resume, and the candidate can download it as a PDF (via browser print).

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'candidate') {
    $_SESSION['redirect_after_login'] = "resume_builder.php";
    $_SESSION['toast'] = "Please log in (or create a free candidate account) to use the AI Resume Builder.";
    header("Location: register.php");
    exit;
}

$api_key = get_api_key();
$error = null;
$generated = null;
$is_fresh_grad = false;
$prefill = null;
$new_build_id = null;

$stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user_info = $stmt->fetch();
$candidate_email = $user_info['email'] ?? '';
$candidate_name = $user_info['name'] ?? ($_SESSION['user_name'] ?? '');

// ---- View a previously generated resume ----
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM resume_builds WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['view'], $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $generated = json_decode($row['generated_content'], true);
        $generated_meta = ['full_name' => $candidate_name, 'target_title' => $row['target_title']];
        $raw_input = json_decode($row['raw_input'], true);
        $is_fresh_grad = !empty($raw_input['is_fresh_grad']);
    }
}

// ---- Edit a previously generated resume: reload its saved answers back
// into the (currently blank) step-by-step form instead of the read-only
// "Your Resume is Ready" screen, so the candidate can tweak rather than
// re-type everything from scratch. ----
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT raw_input FROM resume_builds WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['edit'], $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $prefill = json_decode($row['raw_input'], true);
    }
}

// ---- Generate a new resume ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $target_title = trim($_POST['target_title'] ?? '');
    $full_name = trim($_POST['full_name'] ?? $candidate_name);
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $is_fresh_grad = isset($_POST['is_fresh_grad']);

    $experience = [];
    foreach (($_POST['exp_company'] ?? []) as $i => $company) {
        if (!empty(trim($company)) || !empty(trim($_POST['exp_role'][$i] ?? ''))) {
            $experience[] = [
                'company' => trim($company),
                'role' => trim($_POST['exp_role'][$i] ?? ''),
                'duration' => trim($_POST['exp_duration'][$i] ?? ''),
                'notes' => trim($_POST['exp_notes'][$i] ?? '')
            ];
        }
    }

    $education = [];
    foreach (($_POST['edu_school'] ?? []) as $i => $school) {
        if (!empty(trim($school)) || !empty(trim($_POST['edu_degree'][$i] ?? ''))) {
            $education[] = [
                'school' => trim($school),
                'degree' => trim($_POST['edu_degree'][$i] ?? ''),
                'year' => trim($_POST['edu_year'][$i] ?? '')
            ];
        }
    }

    $input = [
        'target_title' => $target_title,
        'experience' => $experience,
        'education' => $education,
        'skills' => trim($_POST['skills'] ?? ''),
        'extra_notes' => trim($_POST['extra_notes'] ?? ''),
        'is_fresh_grad' => $is_fresh_grad
    ];

    if ($target_title === '' && empty($experience)) {
        $error = "Please fill in at least your target job title and one work experience entry.";
    } else {
        try {
            $generated = generate_resume_document($api_key, $input);
            $generated_meta = ['full_name' => $full_name, 'target_title' => $target_title, 'contact_email' => $contact_email, 'contact_phone' => $contact_phone, 'location' => $location];

            $stmt = $pdo->prepare("INSERT INTO resume_builds (user_id, target_title, raw_input, generated_content) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $target_title,
                json_encode(array_merge($input, ['full_name' => $full_name, 'contact_email' => $contact_email, 'contact_phone' => $contact_phone, 'location' => $location])),
                json_encode(array_merge($generated, ['full_name' => $full_name, 'contact_email' => $contact_email, 'contact_phone' => $contact_phone, 'location' => $location]))
            ]);
            $new_build_id = $pdo->lastInsertId();
        } catch (Exception $e) {
            error_log("Resume Builder Error: " . $e->getMessage());
            $error = "Something went wrong generating your resume. Please try again.";
        }
    }
}

// If we loaded generated content from DB (view mode), pull contact fields from it too
if ($generated && empty($generated_meta['contact_email']) && !empty($generated['contact_email'])) {
    $generated_meta['contact_email'] = $generated['contact_email'];
    $generated_meta['contact_phone'] = $generated['contact_phone'] ?? '';
    $generated_meta['location'] = $generated['location'] ?? '';
    $generated_meta['full_name'] = $generated['full_name'] ?? $generated_meta['full_name'];
}

// Past builds for this candidate
$past_builds = [];
try {
    $stmt = $pdo->prepare("SELECT id, target_title, created_at FROM resume_builds WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $past_builds = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Resume Builder - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Caveat:wght@600;700&display=swap');

        body {
            background-color: #F7FAF2 !important;
            background-image: 
                radial-gradient(circle at 10% 20%, rgba(212, 236, 115, 0.25) 0%, transparent 40%),
                radial-gradient(circle at 90% 15%, rgba(180, 214, 0, 0.18) 0%, transparent 45%),
                radial-gradient(circle at 15% 85%, rgba(142, 172, 134, 0.2) 0%, transparent 50%),
                radial-gradient(circle at 85% 80%, rgba(212, 236, 115, 0.22) 0%, transparent 45%) !important;
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif !important;
            color: #1A1D20;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        /* Ambient nature leaves illustrations */
        .nature-leaf {
            position: absolute;
            pointer-events: none;
            z-index: 1;
            opacity: 0.85;
            transition: transform 0.4s ease;
        }
        .leaf-1 { top: 110px; left: 24px; width: 90px; filter: drop-shadow(0 8px 16px rgba(0,0,0,0.06)); }
        .leaf-2 { top: 380px; left: 16px; width: 65px; opacity: 0.65; transform: rotate(25deg); }
        .leaf-3 { top: 620px; right: 30px; width: 75px; opacity: 0.7; transform: rotate(-30deg); }

        .header-inner {
            max-width: 100% !important;
            padding: 0 40px !important;
            box-sizing: border-box;
        }

        .rb-page-wrapper {
            max-width: 100% !important;
            width: 100% !important;
            margin: 0 auto !important;
            padding: 24px 40px 80px !important;
            box-sizing: border-box !important;
            position: relative;
            z-index: 2;
        }

        @media (max-width: 1024px) {
            .header-inner { padding: 0 16px !important; }
            .rb-page-wrapper { padding: 16px 16px 40px !important; }
            .rb-card { padding: 20px 18px !important; }
        }

        /* Hero Header Section */
        .rb-hero-section {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 24px;
            position: relative;
            padding: 10px 0 0;
        }

        .rb-hero-title-box {
            max-width: 100%;
        }

        .rb-hero-h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 42px;
            font-weight: 800;
            line-height: 1.15;
            color: #111827;
            margin: 0 0 10px;
            letter-spacing: -0.02em;
        }

        .rb-hero-h1 .accent-leaf {
            display: inline-block;
            color: #799A00;
            font-size: 34px;
            vertical-align: middle;
            margin-left: 4px;
        }

        .rb-hero-sub {
            font-size: 15px;
            line-height: 1.5;
            color: #4B5563;
            font-weight: 500;
            margin: 0;
        }

        /* 5 Numbered Clean Form Cards */
        .rb-card {
            background: #FFFFFF;
            border: 1px solid #E5E9D8;
            border-radius: 20px;
            padding: 26px 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px -2px rgba(40, 55, 20, 0.04), 0 2px 6px -1px rgba(0,0,0,0.02);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .rb-card:hover {
            border-color: #C2DE6E;
            box-shadow: 0 8px 26px -4px rgba(40, 55, 20, 0.08);
        }

        .rb-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
        }

        .rb-num-badge {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #D9EE7D;
            color: #1F2D06;
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 2px 8px rgba(180, 214, 0, 0.25);
        }

        .rb-card-title {
            font-size: 18px;
            font-weight: 800;
            color: #111827;
            margin: 0;
            letter-spacing: -0.01em;
        }

        .rb-card-subtitle {
            font-size: 12.5px;
            color: #6B7280;
            margin: 2px 0 0;
            font-weight: 500;
        }

        /* Form Inputs */
        .rb-field-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        .rb-field-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }

        @media (max-width: 768px) {
            .rb-field-grid-3, .rb-field-grid-2 {
                grid-template-columns: 1fr;
            }
            .bottom-left-mascot-widget {
                display: none;
            }
            .rb-hero-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 18px;
            }
            .top-mascot-card {
                width: 100%;
                height: 180px;
            }
        }

        .rb-label {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            color: #4B5563;
            margin-bottom: 6px;
            letter-spacing: 0.2px;
        }

        .rb-input, .rb-textarea {
            width: 100%;
            background: #FFFFFF;
            border: 1px solid #D1D5DB;
            border-radius: 10px;
            padding: 11px 14px;
            font-size: 13.5px;
            color: #111827;
            font-family: inherit;
            box-sizing: border-box;
            transition: all 0.2s ease;
            outline: none;
        }
        .rb-input:focus, .rb-textarea:focus {
            border-color: #6B8A00;
            box-shadow: 0 0 0 3px rgba(180, 214, 0, 0.22);
            background: #FFFFFF;
        }
        .rb-input::placeholder, .rb-textarea::placeholder {
            color: #9CA3AF;
        }

        .rb-textarea {
            resize: vertical;
            min-height: 84px;
            line-height: 1.5;
        }

        /* Checkbox styling */
        .rb-checkbox-wrap {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            user-select: none;
        }
        .rb-checkbox-wrap input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #6B8A00;
            cursor: pointer;
        }

        /* Repeatable Sub-blocks */
        .rb-repeat-block {
            background: #F9FBFA;
            border: 1px solid #E5EADF;
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 14px;
            position: relative;
            transition: border-color 0.2s ease;
        }
        .rb-repeat-block:hover {
            border-color: #BDDB60;
        }
        .rb-remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #FEE2E2;
            color: #DC2626;
            border: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            cursor: pointer;
            line-height: 1;
            transition: all 0.15s ease;
        }
        .rb-remove-btn:hover {
            background: #FCA5A5;
            transform: scale(1.1);
        }

        /* Add Item Pill Button */
        .rb-add-pill-btn {
            background: #F3F8E4;
            border: 1.5px solid #C4DF68;
            color: #425F00;
            font-size: 13px;
            font-weight: 700;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
        }
        .rb-add-pill-btn:hover {
            background: #EAF4D0;
            border-color: #6B8A00;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(180, 214, 0, 0.2);
        }

        /* Ask AI Floating Pen / Assist Button */
        .ai-pen-btn {
            background: linear-gradient(135deg, #F3F9DD, #E7F3BE);
            border: 1px solid #BEDB5C;
            color: #3B5300;
            font-size: 11.5px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 9999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 6px;
            transition: all 0.2s ease;
        }
        .ai-pen-btn:hover {
            background: #DDEF9A;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(180, 214, 0, 0.25);
        }

        .ai-suggestions-box {
            margin-top: 10px;
            display: none;
        }
        .ai-suggestions-box.show {
            display: block;
        }
        .ai-sugg-chip {
            background: #FFFFFF;
            border: 1px solid #D6E89B;
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 12px;
            color: #2D3A12;
            margin-bottom: 6px;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .ai-sugg-chip:hover {
            border-color: #6B8A00;
            background: #F7FBEC;
            transform: translateX(3px);
        }

        /* Generate Main Action Pill Button */
        .rb-submit-pill-btn {
            width: 100%;
            background: #0E0F12;
            color: #FFFFFF;
            border: none;
            border-radius: 9999px;
            padding: 16px 28px;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.2px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.24);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            margin-top: 10px;
        }
        .rb-submit-pill-btn:hover {
            background: #1F2228;
            transform: translateY(-2px);
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.32);
        }
        .rb-submit-pill-btn:active {
            transform: translateY(0);
        }

        /* Mode Switcher Tabs */
        .mode-toggle-bar {
            display: flex;
            gap: 8px;
            background: rgba(255, 255, 255, 0.8);
            border: 1px solid #E5EADF;
            border-radius: 14px;
            padding: 4px;
            margin-bottom: 20px;
            max-width: 320px;
        }
        .mode-toggle-btn {
            flex: 1;
            padding: 8px 14px;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #6B7280;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        .mode-toggle-btn.active {
            background: #0E0F12;
            color: #FFFFFF;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        /* Printable / Preview Document Paper */
        .resume-doc-paper {
            background: #FFFFFF;
            color: #111827;
            border-radius: 16px;
            padding: 40px;
            font-size: 13.5px;
            line-height: 1.6;
            border: 1px solid #E5E7EB;
            box-shadow: 0 16px 40px -8px rgba(0,0,0,0.12);
            margin-bottom: 24px;
        }
        .resume-doc-paper h2 { font-size: 22px; margin: 0 0 4px; font-weight: 800; }
        .resume-doc-paper .target-role { color: #6B8A00; font-size: 14px; font-weight: 700; margin-bottom: 6px; }
        .resume-doc-paper .contact-meta { font-size: 12px; color: #6B7280; margin-bottom: 16px; }
        .resume-doc-paper h4 {
            font-size: 12px; text-transform: uppercase; letter-spacing: 0.8px; color: #445900;
            border-bottom: 1.5px solid #6B8A00; padding-bottom: 4px; margin: 20px 0 10px;
        }

        @media print {
            .bg-watermark-logo, header, .rb-hero-section, .bottom-left-mascot-widget, #pastResumesPanel, footer, .keria-footer, .no-print { display: none !important; }
            body { background: #fff !important; }
            .rb-page-wrapper { margin: 0 !important; padding: 0 !important; max-width: 100% !important; }
            .resume-doc-paper { border: none !important; box-shadow: none !important; padding: 0 !important; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

    <!-- Top Navigation -->
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box" style="width:40px; height:40px; min-width:40px; max-width:40px;">
                    <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; object-fit:contain;">
                </div>
            </div>

            <nav style="display:flex; gap:6px; margin-left:24px">
                <a href="jobs.php">💼 Job Board</a>
                <a href="candidate_dashboard.php">📋 My Applications</a>
                <a href="resume_builder.php" class="active">🪄 AI Resume Builder</a>
                <a href="resume_check.php">✨ AI Resume Check</a>
                <a href="profile.php">👤 Profile Settings</a>
            </nav>

            <div class="header-right-actions" style="margin-left:auto; display:flex; align-items:center; gap:12px;">
                <span style="font-size:18px; cursor:pointer;" title="Notifications">🔔</span>
                <span class="avatar-chip" style="background:#111; color:#fff; width:30px; height:30px; font-weight:800; font-size:12px; border-radius:50%; display:flex; align-items:center; justify-content:center;">
                    <?= strtoupper(substr($candidate_name ?: 'S', 0, 1)) ?>
                </span>
                <span style="font-size:13px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($candidate_name) ?></span>
                <a href="logout.php" class="btn-secondary" style="padding:5px 12px; font-size:11.5px; border-radius:8px;">Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <div class="rb-page-wrapper">

        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification no-print" style="margin-bottom:18px;">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="no-print" style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.35); border-radius:14px; padding:12px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if($generated): ?>
            <!-- ===== GENERATED RESUME RESULT VIEW ===== -->
            <div class="rb-card" style="margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                    <div>
                        <h2 style="font-size:22px; font-weight:800; color:#111; margin:0;">🎉 Your AI Resume is Ready!</h2>
                        <p style="font-size:13px; color:#6B7280; margin:4px 0 0;">Review your polished resume below, download it as a PDF, or build another version.</p>
                    </div>
                    <div style="display:flex; gap:10px;">
                        <button onclick="window.print()" class="btn-primary" style="padding:10px 20px; font-size:13px; width:auto; border-radius:9999px;">
                            📥 Download as PDF
                        </button>
                        <?php $current_build_id = $new_build_id ?? ($_GET['view'] ?? null); ?>
                        <?php if ($current_build_id): ?>
                            <a href="resume_builder.php?edit=<?= (int)$current_build_id ?>" class="btn-secondary" style="padding:10px 18px; font-size:13px; text-decoration:none; border-radius:9999px;">
                                ✏️ Edit Details
                            </a>
                        <?php endif; ?>
                        <a href="resume_builder.php" class="btn-secondary" style="padding:10px 18px; font-size:13px; text-decoration:none; border-radius:9999px;">
                            🆕 Build New
                        </a>
                    </div>
                </div>

                <div class="resume-doc-paper" id="printArea">
                    <h2><?= htmlspecialchars($generated_meta['full_name'] ?? '') ?></h2>
                    <div class="target-role"><?= htmlspecialchars($generated_meta['target_title'] ?? '') ?></div>
                    <div class="contact-meta">
                        <?= htmlspecialchars($generated_meta['contact_email'] ?? '') ?>
                        <?= !empty($generated_meta['contact_phone']) ? ' &bull; ' . htmlspecialchars($generated_meta['contact_phone']) : '' ?>
                        <?= !empty($generated_meta['location']) ? ' &bull; ' . htmlspecialchars($generated_meta['location']) : '' ?>
                    </div>

                    <?php if(!empty($generated['summary'])): ?>
                        <h4>Professional Summary</h4>
                        <div style="margin-bottom:14px;"><?= htmlspecialchars($generated['summary']) ?></div>
                    <?php endif; ?>

                    <?php
                        ob_start(); ?>
                        <?php if(!empty($generated['experience'])): ?>
                            <h4>Work Experience</h4>
                            <?php foreach($generated['experience'] as $exp): ?>
                                <div style="font-weight:700; font-size:13.5px;"><?= htmlspecialchars(($exp['role'] ?? '') . (!empty($exp['company']) ? ' — ' . $exp['company'] : '')) ?></div>
                                <?php if(!empty($exp['duration'])): ?><div style="font-size:12px; color:#666; margin-bottom:4px;"><?= htmlspecialchars($exp['duration']) ?></div><?php endif; ?>
                                <?php if(!empty($exp['bullets'])): ?>
                                    <ul style="margin:4px 0 12px 18px; padding:0;">
                                        <?php foreach($exp['bullets'] as $b): ?><li><?= htmlspecialchars($b) ?></li><?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php $experience_html = ob_get_clean(); ?>

                    <?php ob_start(); ?>
                        <?php if(!empty($generated['education'])): ?>
                            <h4>Education</h4>
                            <?php foreach($generated['education'] as $edu): ?>
                                <div style="font-weight:700; font-size:13.5px;"><?= htmlspecialchars($edu['degree'] ?? '') ?></div>
                                <div style="font-size:12px; color:#666; margin-bottom:8px;"><?= htmlspecialchars(($edu['school'] ?? '') . (!empty($edu['year']) ? ' · ' . $edu['year'] : '')) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php $education_html = ob_get_clean(); ?>

                    <?= $is_fresh_grad ? ($education_html . $experience_html) : ($experience_html . $education_html) ?>

                    <?php if(!empty($generated['skills'])): ?>
                        <h4>Core Skills & Competencies</h4>
                        <div style="margin-bottom:14px;"><?= htmlspecialchars(implode(' · ', $generated['skills'])) ?></div>
                    <?php endif; ?>

                    <?php if(!empty($generated['achievements'])): ?>
                        <h4>Key Achievements & Certifications</h4>
                        <ul style="margin:4px 0 12px 18px; padding:0;">
                            <?php foreach($generated['achievements'] as $a): ?><li><?= htmlspecialchars($a) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>

            <!-- ===== HERO BANNER ===== -->
            <div class="rb-hero-section no-print" style="margin-bottom:28px;">
                <div class="rb-hero-title-box" style="max-width:100%;">
                    <h1 class="rb-hero-h1">
                        Build a<br>
                        Stronger You <span class="accent-leaf">🌿</span>
                    </h1>
                    <p class="rb-hero-sub">
                        Tell us about yourself and we'll turn it into a polished, professional resume you can download.
                    </p>
                </div>
            </div>

            <!-- Optional Mode Switcher -->
            <div class="mode-toggle-bar no-print">
                <button type="button" class="mode-toggle-btn active" id="tabFormBtn" onclick="switchMode('form')">📝 Step-by-Step Form</button>
                <button type="button" class="mode-toggle-btn" id="tabChatBtn" onclick="switchMode('chat')">💬 Chat with AI</button>
            </div>

            <!-- ===== MAIN FORM: THE 5 NUMBERED CARDS ===== -->
            <form method="POST" id="builderForm">
                <input type="hidden" name="action" value="generate">

                <div id="formPanel">
                    <!-- CARD 1: Basic Information -->
                    <div class="rb-card">
                        <div class="rb-card-header">
                            <div class="rb-num-badge">1</div>
                            <div>
                                <h3 class="rb-card-title">Basic Information</h3>
                                <p class="rb-card-subtitle">Let's start with the basics.</p>
                            </div>
                        </div>

                        <div class="rb-field-grid-3">
                            <div>
                                <label class="rb-label">Full Name</label>
                                <input type="text" name="full_name" class="rb-input" value="<?= htmlspecialchars($prefill['full_name'] ?? $candidate_name) ?>" placeholder="e.g. Sofea Cho" required>
                            </div>
                            <div>
                                <label class="rb-label">Email</label>
                                <input type="email" name="contact_email" class="rb-input" value="<?= htmlspecialchars($prefill['contact_email'] ?? $candidate_email) ?>" placeholder="e.g. sofeacho@gmail.com">
                            </div>
                            <div>
                                <label class="rb-label">Phone</label>
                                <input type="text" name="contact_phone" class="rb-input" value="<?= htmlspecialchars($prefill['contact_phone'] ?? '') ?>" placeholder="+60 12-345 6789">
                            </div>
                        </div>

                        <div class="rb-field-grid-2">
                            <div>
                                <label class="rb-label">Target Job Title</label>
                                <input type="text" name="target_title" id="targetTitleInput" class="rb-input" value="<?= htmlspecialchars($prefill['target_title'] ?? '') ?>" placeholder="e.g. Digital Marketing Executive" required>
                            </div>
                            <div>
                                <label class="rb-label">Location</label>
                                <input type="text" name="location" class="rb-input" value="<?= htmlspecialchars($prefill['location'] ?? '') ?>" placeholder="e.g. Kuala Lumpur">
                            </div>
                        </div>

                        <div>
                            <label class="rb-checkbox-wrap">
                                <input type="checkbox" name="is_fresh_grad" id="freshGradCheck" <?= !empty($prefill['is_fresh_grad']) ? 'checked' : '' ?>>
                                <span>I'm a fresh graduate / have little work experience</span>
                            </label>
                        </div>
                    </div>

                    <!-- CARD 2: Work Experience -->
                    <div class="rb-card" id="expPanel">
                        <div class="rb-card-header">
                            <div class="rb-num-badge">2</div>
                            <div>
                                <h3 class="rb-card-title" id="expPanelTitle">Work Experience</h3>
                                <p class="rb-card-subtitle" id="expPanelSubtitle">Add your work experience, internships, or relevant projects.</p>
                            </div>
                        </div>

                        <div id="expContainer"></div>

                        <button type="button" class="rb-add-pill-btn" onclick="addExp()">
                            <span>+ Add Another Experience</span>
                        </button>
                    </div>

                    <!-- CARD 3: Education -->
                    <div class="rb-card" id="eduPanel">
                        <div class="rb-card-header">
                            <div class="rb-num-badge">3</div>
                            <div>
                                <h3 class="rb-card-title">Education</h3>
                                <p class="rb-card-subtitle">Tell us about your academic background.</p>
                            </div>
                        </div>

                        <div id="eduContainer"></div>

                        <button type="button" class="rb-add-pill-btn" onclick="addEdu()">
                            <span>+ Add Another Education</span>
                        </button>
                    </div>

                    <!-- CARD 4: Skills -->
                    <div class="rb-card">
                        <div class="rb-card-header">
                            <div class="rb-num-badge">4</div>
                            <div>
                                <h3 class="rb-card-title">Skills</h3>
                                <p class="rb-card-subtitle">What are you good at?</p>
                            </div>
                        </div>

                        <div>
                            <input type="text" name="skills" id="skillsInputForm" class="rb-input" value="<?= htmlspecialchars($prefill['skills'] ?? '') ?>" placeholder="e.g. Canva, Meta Ads, Excel, Mandarin">
                        </div>
                    </div>

                    <!-- CARD 5: Anything Else? -->
                    <div class="rb-card">
                        <div class="rb-card-header">
                            <div class="rb-num-badge">5</div>
                            <div>
                                <h3 class="rb-card-title">Anything Else?</h3>
                                <p class="rb-card-subtitle">Certifications, achievements, languages — anything you want AI to consider. (Optional)</p>
                            </div>
                        </div>

                        <div>
                            <textarea name="extra_notes" class="rb-textarea" rows="3" placeholder="e.g. Google Ads Certified, Fluent in Malay and English, won first place in a marketing case study..."><?= htmlspecialchars($prefill['extra_notes'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Generate Action Button -->
                    <button type="submit" class="rb-submit-pill-btn">
                        <span>✨ Generate My Resume</span>
                    </button>
                </div>

                <!-- ---- CHAT MODE PANEL (Preserved) ---- -->
                <div id="chatPanel" style="display:none;">
                    <div class="rb-card">
                        <div class="rb-card-header">
                            <div class="rb-num-badge">💬</div>
                            <div>
                                <h3 class="rb-card-title">Interactive AI Builder</h3>
                                <p class="rb-card-subtitle">Answer simple guided questions and let AI construct your resume.</p>
                            </div>
                        </div>

                        <div class="chat-box" id="chatBox" style="background:#F9FBFA; border:1px solid #E5EADF; border-radius:16px; padding:18px; display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto; margin-bottom:14px;"></div>
                        <div class="chat-chip-row" id="chatChipRow" style="display:none; gap:8px; margin-bottom:12px; flex-wrap:wrap;"></div>
                        <div class="chat-input-row" id="chatInputRow" style="display:flex; gap:10px;">
                            <input type="text" id="chatTextInput" class="rb-input" placeholder="Type your answer…" style="border-radius:9999px;">
                            <button type="button" class="btn-primary" style="padding:0 24px; border-radius:9999px; width:auto;" onclick="chatSend()">Send</button>
                        </div>
                    </div>
                    <div id="chatHiddenFields"></div>
                </div>
            </form>
        <?php endif; ?>

        <!-- Previous Builds List -->
        <?php if(!empty($past_builds)): ?>
            <div class="rb-card no-print" id="pastResumesPanel" style="margin-top:28px;">
                <div style="font-size:16px; font-weight:800; color:#111; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                    <span>🕓 Your Previous Resumes</span>
                </div>
                <?php foreach($past_builds as $pb): ?>
                    <a href="resume_builder.php?view=<?= (int)$pb['id'] ?>" style="display:flex; justify-content:space-between; align-items:center; padding:11px 0; border-bottom:1px solid #E5EADF; font-size:13px; text-decoration:none; color:inherit;">
                        <div style="color:#111; font-weight:700;">📄 <?= htmlspecialchars($pb['target_title'] ?: 'Resume') ?></div>
                        <span style="color:#6B7280; font-size:12px;"><?= date('d M Y', strtotime($pb['created_at'])) ?> &rarr;</span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- Templates for Repeatable Experience and Education Blocks -->
    <template id="expTemplate">
        <div class="rb-repeat-block exp-block">
            <button type="button" class="rb-remove-btn" onclick="this.closest('.rb-repeat-block').remove()" title="Remove Experience">&times;</button>
            <div class="rb-field-grid-3">
                <div>
                    <label class="rb-label">Company</label>
                    <input type="text" name="exp_company[]" class="rb-input" placeholder="e.g. BrightWave Media">
                </div>
                <div>
                    <label class="rb-label">Role</label>
                    <input type="text" name="exp_role[]" class="rb-input" placeholder="e.g. Marketing Executive">
                </div>
                <div>
                    <label class="rb-label">Duration</label>
                    <input type="text" name="exp_duration[]" class="rb-input" placeholder="e.g. 2023 – Present">
                </div>
            </div>
            <div>
                <label class="rb-label">What did you do there? (Highlight key responsibilities and achievements)</label>
                <textarea name="exp_notes[]" class="rb-textarea exp-notes" placeholder="e.g. Managed social media, grew followers by 30%, handled end-to-end campaign execution..."></textarea>
                <div style="display:flex; justify-content:flex-end;">
                    <button type="button" class="ai-pen-btn" onclick="askAi(this)">
                        <span>🪄 Ask AI Wording Assist</span>
                    </button>
                </div>
                <div class="ai-suggestions-box"></div>
            </div>
        </div>
    </template>

    <template id="eduTemplate">
        <div class="rb-repeat-block edu-block">
            <button type="button" class="rb-remove-btn" onclick="this.closest('.rb-repeat-block').remove()" title="Remove Education">&times;</button>
            <div class="rb-field-grid-3">
                <div>
                    <label class="rb-label">Degree / Qualification</label>
                    <input type="text" name="edu_degree[]" class="rb-input" placeholder="e.g. Diploma in Mass Communication">
                </div>
                <div>
                    <label class="rb-label">Institution</label>
                    <input type="text" name="edu_school[]" class="rb-input" placeholder="e.g. UTM">
                </div>
                <div>
                    <label class="rb-label">Year</label>
                    <input type="text" name="edu_year[]" class="rb-input" placeholder="e.g. 2022">
                </div>
            </div>
        </div>
    </template>

    <script>
    const PREFILL_DATA = <?= $prefill ? json_encode($prefill) : 'null' ?>;

    function addExp(data) {
        const tpl = document.getElementById('expTemplate').content.cloneNode(true);
        const block = tpl.querySelector('.exp-block');
        if (data) {
            block.querySelector('[name="exp_company[]"]').value = data.company || '';
            block.querySelector('[name="exp_role[]"]').value = data.role || '';
            block.querySelector('[name="exp_duration[]"]').value = data.duration || '';
            block.querySelector('[name="exp_notes[]"]').value = data.notes || '';
        }
        document.getElementById('expContainer').appendChild(tpl);
    }
    function addEdu(data) {
        const tpl = document.getElementById('eduTemplate').content.cloneNode(true);
        const block = tpl.querySelector('.edu-block');
        if (data) {
            block.querySelector('[name="edu_degree[]"]').value = data.degree || '';
            block.querySelector('[name="edu_school[]"]').value = data.school || '';
            block.querySelector('[name="edu_year[]"]').value = data.year || '';
        }
        document.getElementById('eduContainer').appendChild(tpl);
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (!document.getElementById('expContainer')) return;
        if (PREFILL_DATA && Array.isArray(PREFILL_DATA.experience) && PREFILL_DATA.experience.length > 0) {
            PREFILL_DATA.experience.forEach(exp => addExp(exp));
        } else {
            addExp();
        }
        if (PREFILL_DATA && Array.isArray(PREFILL_DATA.education) && PREFILL_DATA.education.length > 0) {
            PREFILL_DATA.education.forEach(edu => addEdu(edu));
        } else {
            addEdu();
        }
    });

    // Fresh-grad toggle handler
    function applyFreshGradLayout(isChecked) {
        const formPanelEl = document.getElementById('formPanel');
        const expPanel = document.getElementById('expPanel');
        const eduPanel = document.getElementById('eduPanel');
        const expTitle = document.getElementById('expPanelTitle');
        const expSubtitle = document.getElementById('expPanelSubtitle');
        if (isChecked) {
            if (expTitle) expTitle.textContent = 'Internships / Part-Time Jobs / Projects (Optional)';
            if (expSubtitle) expSubtitle.textContent = 'Add internships, campus roles, or relevant personal projects.';
            formPanelEl.insertBefore(eduPanel, expPanel);
        } else {
            if (expTitle) expTitle.textContent = 'Work Experience';
            if (expSubtitle) expSubtitle.textContent = 'Add your work experience, internships, or relevant projects.';
            formPanelEl.insertBefore(expPanel, eduPanel);
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        const freshGradCheck = document.getElementById('freshGradCheck');
        if (!freshGradCheck) return;
        if (freshGradCheck.checked) applyFreshGradLayout(true);
        freshGradCheck.addEventListener('change', function() {
            applyFreshGradLayout(this.checked);
        });
    });

    // Mode Switcher
    function switchMode(mode) {
        document.getElementById('tabFormBtn').classList.toggle('active', mode === 'form');
        document.getElementById('tabChatBtn').classList.toggle('active', mode === 'chat');
        document.getElementById('formPanel').style.display = (mode === 'form') ? 'block' : 'none';
        document.getElementById('chatPanel').style.display = (mode === 'chat') ? 'block' : 'none';

        document.querySelectorAll('#formPanel input, #formPanel textarea, #formPanel button').forEach(el => el.disabled = (mode === 'chat'));
        document.querySelectorAll('#chatHiddenFields input').forEach(el => el.disabled = (mode === 'form'));

        if (mode === 'chat' && !chatStarted) {
            chatStarted = true;
            chatStart();
        }
    }

    // Chat mode scripts
    let chatStarted = false;
    let chatStep = null;
    let chatIsFreshGrad = false;
    let chatExperiences = [];
    let chatEducations = [];
    let chatCurrentExp = {};
    let chatCurrentEdu = {};

    function chatAddMsg(text, who) {
        const box = document.getElementById('chatBox');
        const div = document.createElement('div');
        div.style.padding = '10px 14px';
        div.style.borderRadius = '14px';
        div.style.fontSize = '13px';
        div.style.maxWidth = '80%';
        div.style.lineHeight = '1.45';

        if (who === 'ai') {
            div.style.background = '#EBF4D0';
            div.style.color = '#253504';
            div.style.alignSelf = 'flex-start';
            div.style.border = '1px solid #D1E797';
        } else {
            div.style.background = '#0E0F12';
            div.style.color = '#FFFFFF';
            div.style.alignSelf = 'flex-end';
        }
        div.innerText = text;
        box.appendChild(div);
        box.scrollTop = box.scrollHeight;
    }

    function chatShowChips(options) {
        const row = document.getElementById('chatChipRow');
        row.innerHTML = '';
        row.style.display = 'flex';
        document.getElementById('chatInputRow').style.display = 'none';
        options.forEach(opt => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ai-pen-btn';
            btn.innerText = opt;
            btn.onclick = function() { chatAddMsg(opt, 'user'); chatShowInput(); chatAdvance(opt); };
            row.appendChild(btn);
        });
    }

    function chatShowInput() {
        document.getElementById('chatChipRow').style.display = 'none';
        document.getElementById('chatInputRow').style.display = 'flex';
    }

    function chatSend() {
        const input = document.getElementById('chatTextInput');
        const val = input.value.trim();
        if (!val) return;
        chatAddMsg(val, 'user');
        input.value = '';
        chatAdvance(val);
    }

    document.addEventListener('DOMContentLoaded', function() {
        const ci = document.getElementById('chatTextInput');
        if (ci) ci.addEventListener('keydown', function(e) { if (e.key === 'Enter') chatSend(); });
    });

    function chatStart() {
        chatIsFreshGrad = document.getElementById('freshGradCheck').checked;
        if (chatIsFreshGrad) {
            chatAddMsg("Let's build your resume together! Since you're a fresh graduate, let's start with your education — what's your degree or qualification?", 'ai');
            chatStep = 'edu_degree';
        } else {
            chatAddMsg("Let's build your resume together! First, tell me about your most recent job — what company did you work at?", 'ai');
            chatStep = 'exp_company';
        }
    }

    function chatAdvance(answer) {
        switch (chatStep) {
            case 'exp_company':
                chatCurrentExp = { company: answer };
                chatAddMsg('And what was your role or job title there?', 'ai');
                chatStep = 'exp_role';
                break;
            case 'exp_role':
                chatCurrentExp.role = answer;
                chatAddMsg('How long were you there? (e.g. "2023 – Present")', 'ai');
                chatStep = 'exp_duration';
                break;
            case 'exp_duration':
                chatCurrentExp.duration = answer;
                chatAddMsg("What did you actually do day-to-day there? Rough notes are fine — I'll polish it.", 'ai');
                chatStep = 'exp_notes';
                break;
            case 'exp_notes':
                chatCurrentExp.notes = answer;
                chatExperiences.push(chatCurrentExp);
                chatCurrentExp = {};
                chatAddMsg('Got it! Want to add another job experience?', 'ai');
                chatStep = 'exp_more';
                chatShowChips(['Yes, add another', "No, that's it"]);
                break;
            case 'exp_more':
                if (answer.toLowerCase().startsWith('yes')) {
                    chatAddMsg('Great — what company was that at?', 'ai');
                    chatStep = 'exp_company';
                } else if (chatIsFreshGrad) {
                    chatAddMsg('Last thing — list your key skills, separated by commas (e.g. "Canva, Excel, Mandarin").', 'ai');
                    chatStep = 'skills';
                } else {
                    chatAddMsg("Now let's cover your education. What's your degree or qualification?", 'ai');
                    chatStep = 'edu_degree';
                }
                break;
            case 'edu_degree':
                chatCurrentEdu = { degree: answer };
                chatAddMsg('Which school or university?', 'ai');
                chatStep = 'edu_school';
                break;
            case 'edu_school':
                chatCurrentEdu.school = answer;
                chatAddMsg('What year did you graduate (or expect to)?', 'ai');
                chatStep = 'edu_year';
                break;
            case 'edu_year':
                chatCurrentEdu.year = answer;
                chatEducations.push(chatCurrentEdu);
                chatCurrentEdu = {};
                chatAddMsg('Add another education entry?', 'ai');
                chatStep = 'edu_more';
                chatShowChips(['Yes, add another', "No, that's it"]);
                break;
            case 'edu_more':
                if (answer.toLowerCase().startsWith('yes')) {
                    chatAddMsg('Sure — what degree or qualification?', 'ai');
                    chatStep = 'edu_degree';
                } else {
                    chatAddMsg('Last thing — list your key skills, separated by commas (e.g. "Canva, Excel, Mandarin").', 'ai');
                    chatStep = 'skills';
                }
                break;
            case 'skills':
                chatFillHiddenFields(answer);
                chatAddMsg("Perfect — generating your professional resume now… ✨", 'ai');
                chatStep = 'done';
                setTimeout(function() { document.getElementById('builderForm').submit(); }, 700);
                break;
        }
    }

    function chatFillHiddenFields(skillsAnswer) {
        const container = document.getElementById('chatHiddenFields');
        container.innerHTML = '';
        function addHidden(name, value) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = name;
            inp.value = value;
            container.appendChild(inp);
        }
        chatExperiences.forEach(exp => {
            addHidden('exp_company[]', exp.company || '');
            addHidden('exp_role[]', exp.role || '');
            addHidden('exp_duration[]', exp.duration || '');
            addHidden('exp_notes[]', exp.notes || '');
        });
        chatEducations.forEach(edu => {
            addHidden('edu_school[]', edu.school || '');
            addHidden('edu_degree[]', edu.degree || '');
            addHidden('edu_year[]', edu.year || '');
        });
        addHidden('skills', skillsAnswer);
    }

    function askAi(btn) {
        const block = btn.closest('.exp-block');
        const notesEl = block.querySelector('.exp-notes');
        const company = block.querySelector('input[name="exp_company[]"]').value;
        const role = block.querySelector('input[name="exp_role[]"]').value;
        const notes = notesEl.value;
        const suggBox = block.querySelector('.ai-suggestions-box');

        if (!notes.trim()) {
            alert('Type a few rough notes first, then ask AI to help word them.');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<span>⏳ Thinking…</span>';

        const fd = new FormData();
        fd.append('company', company);
        fd.append('role', role);
        fd.append('notes', notes);

        fetch('resume_ai_assist.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = '<span>🪄 Ask AI Wording Assist</span>';
                suggBox.innerHTML = '';
                if (data.error) {
                    suggBox.innerHTML = '<div style="font-size:11.5px; color:#DC2626;">' + data.error + '</div>';
                    suggBox.classList.add('show');
                    return;
                }
                (data.bullets || []).forEach(b => {
                    const div = document.createElement('div');
                    div.className = 'ai-sugg-chip';
                    div.innerText = '+ ' + b;
                    div.onclick = function() {
                        notesEl.value = (notesEl.value.trim() ? notesEl.value.trim() + '\n' : '') + b;
                    };
                    suggBox.appendChild(div);
                });
                suggBox.classList.add('show');
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<span>🪄 Ask AI Wording Assist</span>';
                suggBox.innerHTML = '<div style="font-size:11.5px; color:#DC2626;">Something went wrong. Please try again.</div>';
                suggBox.classList.add('show');
            });
    }
    </script>

    <!-- FOOTER -->
    <footer class="keria-footer no-print">
        <div class="footer-inner">
            <a href="index.php" class="keria-logo">
                <img src="logo/logo_white.png?v=<?php echo @filemtime(__DIR__.'/logo/logo_white.png'); ?>" alt="keria" class="keria-logo-img" style="height:40px; mix-blend-mode:normal;">
            </a>

            <div class="footer-nav-links" style="display:flex; gap:28px; align-items:center; flex-wrap:wrap;">
                <a href="jobs.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Jobs</a>
                <a href="resume_check.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">AI Resume Check</a>
                <a href="resume_builder.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Resume Builder</a>
                <a href="register.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">For Employers</a>
                <a href="terms.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Terms & Conditions</a>
            </div>
        </div>

        <div class="footer-bottom-copy">
            <p style="margin:0;">&copy; <?= date('Y') ?> keria. All rights reserved.</p>
            <p style="margin:0;">Made with ❤️ in Kuala Lumpur, Malaysia</p>
        </div>
    </footer>

    <script src="theme.js"></script>
</body>
</html>
