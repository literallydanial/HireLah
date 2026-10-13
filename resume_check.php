<?php
require_once 'auth.php';
require_once 'parser.php';
require_once 'ai.php';
@require_once 'notifications_helper.php';

// AI Resume Checker — a standalone, general resume-quality feedback tool.
// Not tied to any job posting; gives the candidate coaching-style feedback
// on their own resume (clarity, impact, formatting, ATS-friendliness).
$is_candidate = is_logged_in() && ($_SESSION['user_role'] ?? '') === 'candidate';

$api_key = get_api_key();
$error = null;
$result = null;
$uploaded_filename = null;

// Fetch candidate notifications for the header bell (matching jobs.php)
$unreadCount = 0;
$notifItems = [];
if ($is_candidate && isset($_SESSION['user_id']) && function_exists('getCandidateNotifications')) {
    try {
        $userNotifs = getCandidateNotifications($pdo, $_SESSION['user_id']);
        $notifItems = $userNotifs['items'] ?? [];
        $unreadCount = $userNotifs['unread_count'] ?? 0;
    } catch (\Throwable $e) {
        error_log("Notifications error: " . $e->getMessage());
    }
}

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
                        // Not logged in as a candidate — hold result in session
                        $_SESSION['pending_resume_review'] = [
                            'filename' => $uploaded_filename,
                            'full_text' => $text,
                            'stripped_text' => $stripped,
                            'result' => $result,
                        ];
                        $_SESSION['redirect_after_login'] = "resume_check.php";
                    }
                } catch (\Throwable $e) {
                    $error = "Error processing resume: " . $e->getMessage();
                    error_log("Resume Check Error: " . $e->getMessage());
                }
            }
        }
    }

    // Post/Redirect/Get pattern
    $_SESSION['resume_check_flash'] = [
        'error' => $error,
        'result' => $result,
        'filename' => $uploaded_filename,
    ];
    header("Location: resume_check.php");
    exit;
}

// Pick up flashed outcome
if (!empty($_SESSION['resume_check_flash'])) {
    $flash = $_SESSION['resume_check_flash'];
    unset($_SESSION['resume_check_flash']);
    $error = $flash['error'] ?? null;
    $result = $flash['result'] ?? null;
    $uploaded_filename = $flash['filename'] ?? null;
}

// Pick up result analyzed before logging in
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

// View an existing past review if requested
if ($is_candidate && isset($_GET['review_id']) && empty($result)) {
    try {
        $r_stmt = $pdo->prepare("SELECT * FROM resume_reviews WHERE id = ? AND user_id = ?");
        $r_stmt->execute([(int)$_GET['review_id'], $_SESSION['user_id']]);
        $rev = $r_stmt->fetch();
        if ($rev) {
            $uploaded_filename = $rev['filename'];
            $result = [
                'overall_score' => $rev['overall_score'],
                'rating_label' => $rev['rating_label'],
                'summary' => $rev['summary'],
                'strengths' => json_decode($rev['strengths'] ?? '[]', true) ?: [],
                'improvements' => json_decode($rev['improvements'] ?? '[]', true) ?: [],
                'formatting_notes' => $rev['formatting_notes'],
                'ats_tips' => json_decode($rev['ats_tips'] ?? '[]', true) ?: [],
            ];
        }
    } catch (\Throwable $e) {
        error_log("Past review load error: " . $e->getMessage());
    }
}

$locked = ($result !== null) && !$is_candidate;

// Past checks for this candidate (most recent first)
$past_reviews = [];
if ($is_candidate) {
    try {
        $stmt = $pdo->prepare("SELECT id, filename, overall_score, rating_label, created_at FROM resume_reviews WHERE user_id = ? ORDER BY created_at DESC LIMIT 6");
        $stmt->execute([$_SESSION['user_id']]);
        $past_reviews = $stmt->fetchAll();
    } catch (Exception $e) {
        // table may not exist yet
    }
}

function score_color($score) {
    if ($score >= 75) return '#15803D';
    if ($score >= 50) return '#D97706';
    return '#DC2626';
}

function score_badge_class($score) {
    if ($score >= 75) return 'score-badge-green';
    if ($score >= 50) return 'score-badge-amber';
    return 'score-badge-red';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Resume Checker — Instant Resume Feedback &amp; ATS Score | Keria</title>
    <meta name="description" content="Upload your resume and get instant AI-powered feedback, an ATS compatibility score, and tips to improve your chances of landing a job.">
    <link rel="canonical" href="https://thekeria.com/resume_check.php">

    <!-- Fonts matching jobs.php & candidate_dashboard.php -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">

    <style>
        :root {
            --keria-lime: #D2FF3A;
            --keria-lime-hover: #C2F025;
            --keria-lime-light: #EBFFAA;
            --keria-green-text: #15803D;
            --keria-green-bg: #DCFCE7;
            --keria-dark: #0A0A0A;
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-hand: 'Caveat', cursive;
        }

        body {
            font-family: var(--font-sans);
            background: #F8FAF8 !important;
            color: var(--keria-dark, #0A0A0A);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        .keria-main-container {
            flex: 1 0 auto;
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 24px 64px;
        }

        /* Screen-filling Header Width matching jobs.php */
        header .header-inner {
            max-width: 1560px !important;
            width: 100% !important;
            padding: 0 16px !important;
        }

        /* ================= HERO BANNER SECTION (matching jobs.php & candidate_dashboard.php) ================= */
        .keria-hero-banner {
            position: relative;
            width: 100%;
            background-image: url('assets/Keria_banner_v2.jpg?v=<?php echo @filemtime(__DIR__.'/assets/Keria_banner_v2.jpg'); ?>');
            background-size: cover;
            background-position: center top;
            background-repeat: no-repeat;
            /* This section sits directly after <header>, outside <main>,
               so it didn't get style.css's main{margin-top} compensation
               for the header now being position:fixed. Clearance is
               margin-top, not padding -- padding would grow this box
               and force background-size:cover to zoom in further to
               still fill it, cropping the mascots. margin sits outside
               the box, so the image keeps its original sizing/crop. */
            margin-top: 72px;
            padding: 36px 32px 40px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        @media (max-width: 840px) {
            /* Below 840px style.css's header switches from a fixed
               72px to auto-height (its nav can wrap to a second line),
               so 72px of clearance stops being enough and the title
               gets covered. 170px errs generous, same estimate used
               for main{margin-top} at this breakpoint. */
            .keria-hero-banner {
                margin-top: 170px;
                padding: 24px 20px 28px;
            }
            /* style.css also forces main{margin-top:170px!important} at
               this breakpoint, assuming main sits directly under the
               fixed header. Here the hero banner above already provides
               that clearance, so without this override the two stack
               and leave a large empty gap between the banner and the
               page content. */
            .keria-main-container {
                margin-top: 0 !important;
            }
        }

        .banner-content-inner {
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            position: relative;
            z-index: 5;
        }

        .banner-left-intro {
            flex: 1;
            max-width: 680px;
            position: relative;
        }

        .banner-leaf-doodles {
            position: absolute;
            top: -24px;
            left: -14px;
            width: 42px;
            height: 42px;
            pointer-events: none;
        }

        .banner-title-text {
            font-size: 38px;
            font-weight: 900;
            color: #0A0A0A;
            line-height: 1.1;
            letter-spacing: -1.2px;
            margin-bottom: 10px;
        }

        .banner-sub-text {
            font-size: 14.5px;
            font-weight: 500;
            color: #374151;
            line-height: 1.45;
            max-width: 580px;
            margin-bottom: 6px;
        }

        .banner-sub-smile {
            font-family: var(--font-hand);
            font-size: 26px;
            font-weight: 700;
            color: #0A0A0A;
            display: inline-block;
            transform: rotate(6deg);
            margin-top: -2px;
        }

        /* Right Banner Doodle Area */
        .banner-right-doodle-box {
            flex: 0 0 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            /* Pushed well left of its old 40px, which sat right on top
               of the mascots with the new banner photo -- they sit much
               further right in this image than the old illustration
               did, and the box's own height matches its text (no extra
               room inside it), so nothing short of actually moving the
               whole box clears them. This lands it over the open lake/
               sky area instead, left of the mascots entirely. */
            margin-right: 600px;
            user-select: none;
        }

        .banner-doodle-text {
            font-family: var(--font-hand);
            font-size: 25px;
            font-weight: 700;
            line-height: 1.15;
            color: #0A0A0A;
            transform: rotate(6deg);
            position: relative;
        }

        .banner-doodle-sparks {
            position: absolute;
            top: -12px;
            right: -16px;
            width: 26px;
            height: 26px;
        }

        /* ================= 2-COLUMN SPLIT LAYOUT ================= */
        .checker-split-grid {
            display: grid;
            grid-template-columns: 360px 1fr;
            gap: 26px;
            align-items: start;
        }

        /* Grid items default to min-width:auto, so without this they
           refuse to shrink below their content's natural width -- on
           narrow screens that forces the whole grid (and everything
           inside it) wider than the viewport. Since body has
           overflow-x:hidden, that overflow doesn't scroll into view,
           it just gets silently clipped, which is what made the page
           look "stuck"/uncroll-able instead of simply wrapping. */
        .checker-left-pane,
        .checker-right-pane {
            min-width: 0;
        }

        @media (max-width: 1024px) {
            .checker-split-grid {
                grid-template-columns: 1fr;
            }
            .banner-right-doodle-box {
                display: none;
            }
        }

        /* Consistent Keria Card Style */
        .keria-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 20px;
            padding: 26px 28px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease;
            position: relative;
        }

        .keria-card:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.04);
            border-color: #D1D5DB;
        }

        /* Left Explainer Card */
        .badge-pill-green {
            background: #DCFCE7;
            color: #15803D;
            font-size: 11px;
            font-weight: 800;
            padding: 5px 12px;
            border-radius: 999px;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .explainer-desc {
            font-size: 13.5px;
            color: var(--mut);
            line-height: 1.55;
            margin: 14px 0 26px;
        }

        /* Stepper */
        .stepper-wrap {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .stepper-row {
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .step-num-badge {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #DCFCE7;
            color: #15803D;
            font-size: 12px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 6px;
        }

        .step-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: #F8FAF8;
            border: 1px solid #E5E7EB;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 18px;
        }

        .step-text-col {
            flex: 1;
            min-width: 0;
            padding-top: 2px;
        }

        .step-heading {
            font-size: 14px;
            font-weight: 800;
            color: var(--txt);
            margin-bottom: 2px;
        }

        .step-caption {
            font-size: 12px;
            color: var(--mut);
        }

        .step-line {
            width: 2px;
            height: 22px;
            background: #E5E7EB;
            margin-left: 11px;
            margin-top: 3px;
            margin-bottom: 3px;
            border-radius: 2px;
        }

        /* Dropzone Box */
        .keria-dropzone {
            border: 2px dashed #D1D5DB;
            border-radius: 16px;
            padding: 46px 24px;
            text-align: center;
            background: #FAFAFA;
            cursor: pointer;
            transition: all 0.22s ease;
        }

        .keria-dropzone:hover, .keria-dropzone.dragover {
            border-color: #0A0A0A;
            background: #F3F4F6;
            transform: translateY(-1px);
        }

        .dropzone-icon-circle {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            background: #EFF6FF;
            color: #2563EB;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
            border: 1px solid #DBEAFE;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.08);
        }

        .dropzone-heading {
            font-size: 17px;
            font-weight: 800;
            color: var(--txt);
            margin-bottom: 6px;
        }

        .dropzone-note {
            font-size: 12.5px;
            color: var(--mut);
            margin-bottom: 20px;
        }

        .btn-browse-action {
            background: #FFFFFF;
            border: 1px solid #D1D5DB;
            color: #0A0A0A;
            font-size: 13.5px;
            font-weight: 700;
            padding: 10px 24px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            transition: all 0.2s ease;
        }

        .btn-browse-action:hover {
            background: #F3F4F6;
            border-color: #9CA3AF;
            transform: translateY(-1px);
        }

        .file-status-box {
            display: none;
            align-items: center;
            justify-content: space-between;
            background: #FFFFFF;
            border: 1px solid #86EFAC;
            border-radius: 14px;
            padding: 12px 18px;
            margin-top: 16px;
            box-shadow: 0 2px 8px rgba(16, 185, 129, 0.06);
        }

        /* Signature Action Buttons (matching jobs.php .btn-search-find) */
        .btn-primary-action {
            background: #0A0A0A;
            color: #FFFFFF;
            font-weight: 800;
            font-size: 14.5px;
            border: none;
            border-radius: 12px;
            padding: 14px 28px;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 18px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-primary-action:hover:not(:disabled) {
            background: #262626;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.2);
        }

        .btn-primary-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            box-shadow: none;
        }

        .btn-lime-action {
            background: var(--keria-lime);
            color: #0A0A0A;
            font-weight: 800;
            font-size: 13.5px;
            border-radius: 12px;
            padding: 11px 22px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(210, 255, 58, 0.3);
            transition: all 0.2s ease;
        }

        .btn-lime-action:hover {
            background: var(--keria-lime-hover);
            transform: translateY(-1px);
        }

        /* Recent Checks Card */
        .recent-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            margin-top: 22px;
        }

        .recent-card-header {
            background: #F9FAFB;
            border-bottom: 1px solid #F3F4F6;
            padding: 13px 24px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 800;
            color: #15803D;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .recent-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 24px;
            border-bottom: 1px solid #F3F4F6;
            text-decoration: none;
            color: inherit;
            transition: background 0.15s ease;
        }

        .recent-row:last-child {
            border-bottom: none;
        }

        .recent-row:hover {
            background: #F8FAF8;
        }

        .recent-left {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .pdf-badge {
            width: 32px;
            height: 36px;
            background: #EF4444;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-weight: 900;
            font-size: 10px;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(239, 68, 68, 0.25);
        }

        .recent-filename {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--txt);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .recent-right {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }

        .recent-date {
            font-size: 12px;
            color: var(--mut);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .score-badge {
            font-size: 12px;
            font-weight: 800;
            padding: 4px 12px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .score-badge-green {
            background: #DCFCE7;
            color: #15803D;
        }

        .score-badge-amber {
            background: #FEF3C7;
            color: #B45309;
        }

        .score-badge-red {
            background: #FEE2E2;
            color: #B91C1C;
        }

        /* Loading Spinner */
        .spinner-icon {
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid #FFFFFF;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Dark mode cohesion */
        [data-theme="dark"] body {
            background: #18191C !important;
            color: #F9FAFB;
        }
        [data-theme="dark"] .keria-card,
        [data-theme="dark"] .recent-card {
            background: #26272C;
            border-color: #383940;
        }
        [data-theme="dark"] .keria-dropzone {
            background: #1F2024;
            border-color: #383940;
        }
        [data-theme="dark"] .recent-card-header {
            background: #212226;
            border-bottom-color: #383940;
        }
        [data-theme="dark"] .recent-row:hover {
            background: #2D2E34;
        }
        [data-theme="dark"] .btn-browse-action {
            background: #26272C;
            border-color: #383940;
            color: #F9FAFB;
        }
        [data-theme="dark"] .btn-primary-action {
            background: #D2FF3A;
            color: #0A0A0A;
        }
        [data-theme="dark"] .btn-primary-action:hover:not(:disabled) {
            background: #C2F025;
        }
    </style>
</head>
<body>
    <!-- ================= 1. HEADER / NAVBAR (identical to jobs.php) ================= -->
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box">
                    <a href="index.php" style="display:flex; align-items:center;">
                        <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;">
                    </a>
                </div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="jobs.php">📋 Job Board</a>
                <?php if($is_candidate): ?>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="resume_check.php" class="active">✨ AI Resume Check</a>
                    <a href="profile.php">⚙️ Profile Settings</a>
                <?php elseif(($_SESSION['user_role'] ?? '') === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications &amp; Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="resume_check.php" class="active">✨ AI Resume Check</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php elseif(($_SESSION['user_role'] ?? '') === 'admin'): ?>
                    <a href="admin_dashboard.php">🛡️ Control Panel</a>
                    <a href="resume_check.php" class="active">✨ AI Resume Check</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php else: ?>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="resume_check.php" class="active">✨ AI Resume Check</a>
                <?php endif; ?>
            </nav>

            <div class="header-right-actions" style="margin-left:auto; display:flex; align-items:center;">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <?php if($is_candidate): ?>
                        <div class="notif-bell-wrapper" style="position:relative; margin-right:8px;">
                            <button type="button" class="notif-bell-btn" id="notifBellBtn" onclick="toggleNotifDropdown()" title="Notifications">
                                🔔
                                <?php if($unreadCount > 0): ?>
                                    <span class="notif-badge" id="notifBadgeCount"><?= $unreadCount ?></span>
                                <?php endif; ?>
                            </button>

                            <div class="notif-dropdown" id="notifDropdown">
                                <div class="notif-header">
                                    <span style="font-weight:800; font-size:13px; color:var(--txt);">Notifications</span>
                                    <?php if($unreadCount > 0): ?>
                                        <button type="button" class="notif-mark-all" onclick="markAllNotifsRead()">Mark all as read</button>
                                    <?php endif; ?>
                                </div>

                                <div class="notif-list">
                                    <?php if(empty($notifItems)): ?>
                                        <div style="padding:24px; text-align:center; color:var(--mut); font-size:12px;">
                                            ✨ No notifications right now
                                        </div>
                                    <?php else: ?>
                                        <?php foreach($notifItems as $item): ?>
                                            <a href="<?= htmlspecialchars($item['link']) ?>" class="notif-item <?= $item['is_read'] ? 'read' : 'unread' ?>" onclick="markNotifRead('<?= htmlspecialchars($item['key']) ?>')">
                                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:3px;">
                                                    <span class="notif-item-title"><?= $item['title'] ?></span>
                                                    <?php if(!$item['is_read']): ?>
                                                        <span class="unread-dot"></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="notif-item-msg"><?= $item['message'] ?></div>
                                                <div class="notif-item-time"><?= date('M j, g:i a', strtotime($item['time'])) ?></div>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?> (<?= ucfirst($_SESSION['user_role'] ?? 'Candidate') ?>)</span>
                    <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; font-weight:700;">🔑 Login</a>
                    <a href="register.php" class="btn-primary" style="padding:6px 14px; font-size:12px; font-weight:700; width:auto; text-decoration:none; margin-left:8px;">✨ Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ================= 2. HERO BANNER SECTION (matching jobs.php & candidate_dashboard.php) ================= -->
    <section class="keria-hero-banner">
        <div class="banner-content-inner">
            
            <div class="banner-left-intro">
                <svg class="banner-leaf-doodles" viewBox="0 0 50 50" fill="none">
                    <path d="M12 28 C 6 18, 14 6, 26 10 C 26 22, 18 30, 12 28 Z" fill="#97DF14" opacity="0.85"/>
                    <circle cx="8" cy="38" r="4" fill="#97DF14" opacity="0.7"/>
                </svg>
                <h1 class="banner-title-text">
                    AI Resume<br>Checker
                </h1>
                <p class="banner-sub-text">
                    Upload your resume and get instant, honest feedback from AI — clarity, impact, formatting, and ATS-friendliness.
                </p>
                <div class="banner-sub-smile">
                    ☺
                </div>
            </div>

            <!-- Right Banner Doodle Area over Mascot -->
            <div class="banner-right-doodle-box">
                <div class="banner-doodle-text">
                    Better<br>Resume<br>Bigger<br>Opportunities! ☺
                    <svg class="banner-doodle-sparks" viewBox="0 0 30 30">
                        <path d="M6 14 L2 14 M8 8 L4 4 M14 6 L14 2 M20 8 L24 4 M22 14 L26 14" stroke="#0A0A0A" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>

        </div>
    </section>

    <!-- ================= 3. MAIN CONTAINER ================= -->
    <main class="keria-main-container">

        <?php if(!get_api_key()): ?>
            <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:14px; padding:12px 18px; color:var(--org); font-size:13px; margin-bottom:24px; display:flex; align-items:center; gap:10px;">
                <span>💡</span>
                <span><strong>Notice:</strong> AI screening API key is not configured on this server. Running in automated baseline analysis mode.</span>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div style="background:rgba(239, 68, 68, 0.08); border:1px solid rgba(239, 68, 68, 0.3); border-radius:14px; padding:12px 18px; margin-bottom:24px; color:#DC2626; font-size:13px; display:flex; align-items:center; gap:10px;">
                <span>⚠️</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if($result):
            $summary = $result['summary'] ?? '';
            $summary_teaser = $summary;
            $summary_rest = '';
            if ($locked && $summary !== '' && preg_match('/^(.*?[.!?])(\s+.*)?$/s', $summary, $m)) {
                $summary_teaser = $m[1];
                $summary_rest = trim($m[2] ?? '');
            }
        ?>
            <!-- ===== RESULTS VIEW ===== -->
            <div style="max-width:1100px; margin:0 auto;">
                
                <!-- Score & Overview Card -->
                <div class="keria-card" style="margin-bottom:22px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:18px; margin-bottom:18px; border-bottom:1px solid #F3F4F6; padding-bottom:18px;">
                        <div style="display:flex; align-items:center; gap:18px;">
                            <div style="width:76px; height:76px; border-radius:50%; border:5px solid <?= score_color($result['overall_score'] ?? 0) ?>; display:flex; align-items:center; justify-content:center; font-size:24px; font-weight:900; color:#0A0A0A; background:#FAFAFA; flex-shrink:0;">
                                <?= (int)($result['overall_score'] ?? 0) ?>
                            </div>
                            <div>
                                <div style="font-size:11.5px; color:var(--mut); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:3px;">
                                    Resume Score &middot; <?= htmlspecialchars($uploaded_filename ?? 'Resume.pdf') ?>
                                </div>
                                <div style="font-size:22px; font-weight:900; color:var(--txt);">
                                    <?= htmlspecialchars($result['rating_label'] ?? 'Reviewed') ?>
                                </div>
                            </div>
                        </div>
                        <div>
                            <a href="resume_check.php" class="btn-browse-action" style="text-decoration:none;">
                                <span>📄</span> Check Another Resume
                            </a>
                        </div>
                    </div>

                    <div style="font-size:14px; color:var(--mut); line-height:1.65;">
                        <?= nl2br(htmlspecialchars($summary_teaser)) ?>
                        <?php if ($summary_rest !== ''): ?>
                            <span style="filter:blur(4px); user-select:none;"><?= nl2br(htmlspecialchars($summary_rest)) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($locked): ?>
                    <!-- Teaser lock view for guest -->
                    <div style="position:relative; min-height:300px; margin-bottom:24px;">
                        <div style="filter:blur(7px); opacity:0.45; pointer-events:none; user-select:none;" aria-hidden="true">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                                <div class="keria-card">
                                    <div style="font-weight:800; color:#15803D; margin-bottom:12px;">✅ Strengths</div>
                                    <div style="font-size:13px; color:var(--mut); margin-bottom:6px;">&bull; Clear, relevant work history</div>
                                    <div style="font-size:13px; color:var(--mut); margin-bottom:6px;">&bull; Good use of measurable results</div>
                                </div>
                                <div class="keria-card">
                                    <div style="font-weight:800; color:#D97706; margin-bottom:12px;">🛠️ Areas to Improve</div>
                                    <div style="font-size:13px; color:var(--mut); margin-bottom:6px;">&bull; Tighten the summary section</div>
                                    <div style="font-size:13px; color:var(--mut); margin-bottom:6px;">&bull; Fix formatting inconsistencies</div>
                                </div>
                            </div>
                        </div>

                        <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; padding:20px;">
                            <div class="keria-card" style="max-width:440px; width:100%; text-align:center; box-shadow:0 18px 44px rgba(0,0,0,0.18); border-color:#D1D5DB;">
                                <div style="font-size:36px; margin-bottom:8px;">🔒</div>
                                <div style="font-size:18px; font-weight:800; color:var(--txt); margin-bottom:6px;">Unlock Your Full Resume Report</div>
                                <div style="font-size:13px; color:var(--mut); margin-bottom:20px; line-height:1.5;">
                                    Log in or create a free account to view your full strengths, areas to improve, formatting breakdown, and ATS compatibility tips.
                                </div>
                                <a href="register.php" class="btn-primary-action" style="margin-bottom:10px;">Create Free Account &rarr;</a>
                                <a href="login.php" class="btn-browse-action" style="display:inline-block; width:100%; text-align:center; box-sizing:border-box;">Already have an account? Log In</a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Full Review for logged-in candidate -->
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:22px;">
                        <div class="keria-card">
                            <div style="font-size:14.5px; font-weight:800; color:#15803D; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                                <span>✅</span> Strengths
                            </div>
                            <?php if(!empty($result['strengths'])): ?>
                                <?php foreach($result['strengths'] as $s): ?>
                                    <div style="font-size:13px; color:var(--mut); margin-bottom:10px; padding-left:18px; position:relative; line-height:1.55;">
                                        <span style="position:absolute; left:0; color:#16A34A; font-weight:bold;">&bull;</span><?= htmlspecialchars($s) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:13px; color:var(--mut);">No specific strengths identified.</div>
                            <?php endif; ?>
                        </div>

                        <div class="keria-card">
                            <div style="font-size:14.5px; font-weight:800; color:#D97706; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                                <span>🛠️</span> Areas to Improve
                            </div>
                            <?php if(!empty($result['improvements'])): ?>
                                <?php foreach($result['improvements'] as $s): ?>
                                    <div style="font-size:13px; color:var(--mut); margin-bottom:10px; padding-left:18px; position:relative; line-height:1.55;">
                                        <span style="position:absolute; left:0; color:#F59E0B; font-weight:bold;">&bull;</span><?= htmlspecialchars($s) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="font-size:13px; color:var(--mut);">No improvement suggestions noted.</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if(!empty($result['formatting_notes'])): ?>
                        <div class="keria-card" style="margin-bottom:22px;">
                            <div style="font-size:14.5px; font-weight:800; color:var(--txt); margin-bottom:10px; display:flex; align-items:center; gap:8px;">
                                <span>📐</span> Formatting &amp; Layout Notes
                            </div>
                            <div style="font-size:13px; color:var(--mut); line-height:1.65;"><?= htmlspecialchars($result['formatting_notes']) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($result['ats_tips'])): ?>
                        <div class="keria-card" style="margin-bottom:26px; background:#F0FDF4; border-color:#BBF7D0;">
                            <div style="font-size:14.5px; font-weight:800; color:#15803D; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                                <span>🔎</span> ATS Compatibility Tips
                            </div>
                            <?php foreach($result['ats_tips'] as $tip): ?>
                                <div style="font-size:13px; color:#1F2937; margin-bottom:10px; padding-left:18px; position:relative; line-height:1.55;">
                                    <span style="position:absolute; left:0; color:#059669; font-weight:bold;">&bull;</span><?= htmlspecialchars($tip) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; justify-content:center; gap:16px; margin-bottom:32px; flex-wrap:wrap;">
                        <a href="resume_check.php" class="btn-primary-action" style="width:auto; padding:12px 30px;">
                            Upload Another Resume &rarr;
                        </a>
                        <a href="resume_builder.php" class="btn-lime-action" style="padding:12px 26px;">
                            📝 Build an Optimized Resume
                        </a>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <!-- ===== 2-COLUMN SPLIT PANE VIEW (matching jobs.php explorer) ===== -->
            <div class="checker-split-grid">
                
                <!-- Left Pane: Explainer & Stepper -->
                <div class="checker-left-pane">
                    <div class="keria-card">
                        
                        <div style="margin-bottom:8px;">
                            <span class="badge-pill-green">
                                <span>📄</span> AI RESUME CHECKER
                            </span>
                        </div>

                        <p class="explainer-desc">
                            Get instant feedback on your resume — clarity, impact, formatting, and ATS-friendliness. Improve your chances of getting noticed!
                        </p>

                        <div class="stepper-wrap">
                            <!-- Step 1 -->
                            <div class="stepper-row">
                                <div class="step-num-badge">1</div>
                                <div class="step-icon-box">
                                    <span>📤</span>
                                </div>
                                <div class="step-text-col">
                                    <div class="step-heading">Upload Resume</div>
                                    <div class="step-caption">PDF only (Max 5MB)</div>
                                </div>
                            </div>

                            <div class="step-line"></div>

                            <!-- Step 2 -->
                            <div class="stepper-row">
                                <div class="step-num-badge">2</div>
                                <div class="step-icon-box">
                                    <span>✨</span>
                                </div>
                                <div class="step-text-col">
                                    <div class="step-heading">AI Analysis</div>
                                    <div class="step-caption">Our AI will review your resume</div>
                                </div>
                            </div>

                            <div class="step-line"></div>

                            <!-- Step 3 -->
                            <div class="stepper-row">
                                <div class="step-num-badge">3</div>
                                <div class="step-icon-box">
                                    <span>📋</span>
                                </div>
                                <div class="step-text-col">
                                    <div class="step-heading">Get Feedback</div>
                                    <div class="step-caption">Receive detailed suggestions</div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Right Pane: Upload Card & Recent Checks Card -->
                <div class="checker-right-pane">
                    
                    <!-- Upload Dropzone Card -->
                    <div class="keria-card">
                        <form method="POST" enctype="multipart/form-data" id="checkForm">
                            <div class="keria-dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                                <div class="dropzone-icon-circle">
                                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <polyline points="12 18 12 12 16 16"></polyline>
                                        <line x1="12" y1="12" x2="8" y2="16"></line>
                                    </svg>
                                </div>
                                <div class="dropzone-heading">Select Your Resume (PDF only)</div>
                                <div class="dropzone-note">Your resume is processed for feedback only &mdash; PII is stripped before AI review.</div>
                                
                                <div>
                                    <button type="button" class="btn-browse-action" onclick="event.stopPropagation(); document.getElementById('fileInput').click();">
                                        <span>📁</span> Browse Files
                                    </button>
                                </div>

                                <input type="file" name="resume" id="fileInput" accept=".pdf" required style="display:none" onchange="handleFileSelect(this)">
                            </div>

                            <!-- Selected File Preview -->
                            <div id="fileSelectedCard" class="file-status-box">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <span style="font-size:22px;">📄</span>
                                    <div>
                                        <div id="selectedFileName" style="font-size:13.5px; font-weight:700; color:#0A0A0A;"></div>
                                        <div id="selectedFileSize" style="font-size:12px; color:#6B7280;"></div>
                                    </div>
                                </div>
                                <button type="button" onclick="clearSelectedFile()" style="background:none; border:none; color:#EF4444; font-size:12.5px; font-weight:700; cursor:pointer;">✕ Remove</button>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" id="submitBtn" class="btn-primary-action" disabled>
                                <span id="btnIcon">🔍</span>
                                <span id="btnText">Check My Resume &rarr;</span>
                            </button>
                        </form>
                    </div>

                    <!-- Recent Checks Card (matching application list style) -->
                    <?php if(!empty($past_reviews)): ?>
                        <div class="recent-card">
                            <div class="recent-card-header">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#15803D" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                                <span>YOUR RECENT CHECKS</span>
                            </div>

                            <div>
                                <?php foreach($past_reviews as $pr): 
                                    $score = (int)$pr['overall_score'];
                                ?>
                                    <a href="resume_check.php?review_id=<?= $pr['id'] ?>" class="recent-row" title="Click to view report for <?= htmlspecialchars($pr['filename']) ?>">
                                        <div class="recent-left">
                                            <div class="pdf-badge">
                                                PDF
                                            </div>
                                            <div class="recent-filename">
                                                <?= htmlspecialchars($pr['filename'] ?: 'RESUME.pdf') ?>
                                            </div>
                                        </div>

                                        <div class="recent-right">
                                            <div class="recent-date">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6B7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                                    <line x1="16" y1="2" x2="16" y2="6"></line>
                                                    <line x1="8" y1="2" x2="8" y2="6"></line>
                                                    <line x1="3" y1="10" x2="21" y2="10"></line>
                                                </svg>
                                                <span><?= date('d M Y', strtotime($pr['created_at'])) ?></span>
                                            </div>

                                            <div class="score-badge <?= score_badge_class($score) ?>">
                                                <?= $score ?>
                                            </div>

                                            <div style="color:var(--mut); font-size:16px; font-weight:700;">&rsaquo;</div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </main>

    <script src="theme.js"></script>
    <script>
        // File selection handling
        function handleFileSelect(input) {
            const file = input.files[0];
            if (!file) return;

            if (!file.name.toLowerCase().endsWith('.pdf')) {
                alert('Please upload a PDF document.');
                input.value = '';
                return;
            }

            document.getElementById('selectedFileName').innerText = file.name;
            document.getElementById('selectedFileSize').innerText = (file.size / 1024).toFixed(1) + ' KB';
            document.getElementById('fileSelectedCard').style.display = 'flex';
            
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = false;
        }

        function clearSelectedFile() {
            const input = document.getElementById('fileInput');
            input.value = '';
            document.getElementById('fileSelectedCard').style.display = 'none';
            document.getElementById('submitBtn').disabled = true;
        }

        // Drag & Drop
        const dropzone = document.getElementById('dropzone');
        if (dropzone) {
            ['dragenter', 'dragover'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('dragover');
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('dragover');
                }, false);
            });

            dropzone.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files && files.length > 0) {
                    const fileInput = document.getElementById('fileInput');
                    fileInput.files = files;
                    handleFileSelect(fileInput);
                }
            }, false);
        }

        // Form Submit Loading State
        const checkForm = document.getElementById('checkForm');
        if (checkForm) {
            checkForm.addEventListener('submit', function() {
                const submitBtn = document.getElementById('submitBtn');
                submitBtn.disabled = true;
                document.getElementById('btnIcon').innerHTML = '<span class="spinner-icon"></span>';
                document.getElementById('btnText').innerText = 'Analyzing Resume with AI...';
            });
        }

        // Notifications dropdown toggle
        function toggleNotifDropdown() {
            const dd = document.getElementById('notifDropdown');
            if (dd) {
                dd.classList.toggle('show');
            }
        }

        document.addEventListener('click', function(e) {
            const btn = document.getElementById('notifBellBtn');
            const dd = document.getElementById('notifDropdown');
            if (dd && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
                dd.classList.remove('show');
            }
        });

        function markNotifRead(key) {
            fetch('mark_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&key=' + encodeURIComponent(key)
            }).catch(e => console.error(e));
        }

        function markAllNotifsRead() {
            fetch('mark_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            }).then(() => {
                const badge = document.getElementById('notifBadgeCount');
                if (badge) badge.style.display = 'none';
                document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
                document.querySelectorAll('.unread-dot').forEach(el => el.style.display = 'none');
            }).catch(e => console.error(e));
        }
    </script>
</body>
</html>
