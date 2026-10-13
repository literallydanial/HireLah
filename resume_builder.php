<?php
require_once 'auth.php';
require_once 'ai.php';
@require_once 'notifications_helper.php';

// AI Resume Builder — candidate fills a guided form (with an optional
// "Ask AI" wording-help assist per role), AI turns it into a polished
// resume, and the candidate can download it as a PDF or Word.

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

// Fetch notifications for the header bell (matching jobs.php)
$unreadCount = 0;
$notifItems = [];
if (isset($_SESSION['user_id']) && function_exists('getCandidateNotifications')) {
    try {
        $userNotifs = getCandidateNotifications($pdo, $_SESSION['user_id']);
        $notifItems = $userNotifs['items'] ?? [];
        $unreadCount = $userNotifs['unread_count'] ?? 0;
    } catch (\Throwable $e) {}
}

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

// ---- Edit a previously generated resume ----
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

    $references = [];
    if (isset($_POST['include_references'])) {
        foreach (($_POST['ref_name'] ?? []) as $i => $rname) {
            $rname = trim($rname);
            if ($rname === '') continue;
            $references[] = [
                'name' => $rname,
                'title' => trim($_POST['ref_title'][$i] ?? ''),
                'contact' => trim($_POST['ref_contact'][$i] ?? '')
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

            $extra_fields = ['full_name' => $full_name, 'contact_email' => $contact_email, 'contact_phone' => $contact_phone, 'location' => $location, 'references' => $references];

            $stmt = $pdo->prepare("INSERT INTO resume_builds (user_id, target_title, raw_input, generated_content) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $target_title,
                json_encode(array_merge($input, $extra_fields)),
                json_encode(array_merge($generated, $extra_fields))
            ]);
            $new_build_id = $pdo->lastInsertId();
            $generated['references'] = $references;
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
    <title>AI Resume Builder — Build a Professional Resume Fast | Keria</title>
    <meta name="description" content="Build a professional, ATS-friendly resume in minutes with Keria’s AI resume builder. Download as PDF or Word.">
    <link rel="canonical" href="https://thekeria.com/resume_builder.php">

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
            margin: 0;
            padding: 0;
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

        @media (max-width: 1024px) {
            .banner-right-doodle-box {
                display: none;
            }
        }

        /* ================= FORM & CARDS STYLING (Keria system) ================= */
        .builder-layout-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 26px;
            align-items: start;
        }

        @media (max-width: 1080px) {
            .builder-layout-grid {
                grid-template-columns: 1fr;
            }
        }

        .keria-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 20px;
            padding: 26px 28px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            transition: all 0.2s ease;
            position: relative;
            margin-bottom: 22px;
        }

        .keria-card:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.04);
            border-color: #D1D5DB;
        }

        .rb-card-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
        }

        .rb-num-badge {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #DCFCE7;
            color: #15803D;
            font-size: 15px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .rb-card-title {
            font-size: 17.5px;
            font-weight: 800;
            color: #0A0A0A;
            margin: 0;
            letter-spacing: -0.01em;
        }

        .rb-card-subtitle {
            font-size: 12.5px;
            color: #6B7280;
            margin: 2px 0 0;
        }

        /* Form Controls */
        .rb-field-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        .rb-field-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 16px;
        }

        @media (max-width: 768px) {
            .rb-field-grid-3, .rb-field-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        .rb-label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }

        .rb-input, .rb-textarea {
            width: 100%;
            background: #FAFAFA;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 13.5px;
            font-family: inherit;
            color: #0A0A0A;
            box-sizing: border-box;
            transition: all 0.2s ease;
        }

        .rb-input:focus, .rb-textarea:focus {
            background: #FFFFFF;
            border-color: #0A0A0A;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.06);
        }

        .rb-input::placeholder, .rb-textarea::placeholder {
            color: #9CA3AF;
        }

        .rb-checkbox-wrap {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #374151;
            font-weight: 500;
            cursor: pointer;
            user-select: none;
            margin-top: 4px;
        }

        .rb-checkbox-wrap input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: #0A0A0A;
            cursor: pointer;
        }

        /* Repeatable Blocks */
        .rb-repeat-block {
            background: #FAFAFA;
            border: 1px solid #E5E7EB;
            border-radius: 16px;
            padding: 18px 20px;
            margin-bottom: 14px;
            position: relative;
            transition: border-color 0.2s ease;
        }

        .rb-repeat-block:hover {
            border-color: #D1D5DB;
        }

        .rb-remove-btn {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: none;
            background: rgba(239, 68, 68, 0.1);
            color: #DC2626;
            font-size: 16px;
            line-height: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .rb-remove-btn:hover {
            background: rgba(239, 68, 68, 0.2);
        }

        .rb-add-pill-btn {
            background: #FFFFFF;
            border: 1px dashed #D1D5DB;
            color: #0A0A0A;
            font-weight: 700;
            font-size: 13px;
            border-radius: 12px;
            padding: 11px 22px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            width: 100%;
            justify-content: center;
        }

        .rb-add-pill-btn:hover {
            background: #F9FAFB;
            border-color: #0A0A0A;
            transform: translateY(-1px);
        }

        .ai-pen-btn {
            background: #DCFCE7;
            color: #15803D;
            border: 1px solid #BBF7D0;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            transition: all 0.2s ease;
        }

        .ai-pen-btn:hover {
            background: #BBF7D0;
            transform: translateY(-1px);
        }

        .ai-suggestions-box {
            display: none;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }

        .ai-suggestions-box.show {
            display: flex;
        }

        .ai-sugg-chip {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            padding: 8px 14px;
            font-size: 12px;
            color: #374151;
            cursor: pointer;
            line-height: 1.4;
            transition: all 0.15s ease;
        }

        .ai-sugg-chip:hover {
            border-color: #15803D;
            background: #F0FDF4;
            color: #15803D;
        }

        /* Mode Switcher Bar */
        .mode-toggle-bar {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 9999px;
            padding: 4px;
            display: inline-flex;
            gap: 4px;
            margin-bottom: 22px;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.03);
        }

        .mode-toggle-btn {
            background: transparent;
            border: none;
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 600;
            color: #6B7280;
            border-radius: 9999px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .mode-toggle-btn.active {
            background: #0A0A0A;
            color: #FFFFFF;
            font-weight: 800;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        /* Submit Button */
        .btn-submit-builder {
            background: #0A0A0A;
            color: #FFFFFF;
            font-weight: 800;
            font-size: 15px;
            border: none;
            border-radius: 14px;
            padding: 16px 36px;
            width: 100%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12);
            transition: all 0.2s ease;
            margin-top: 24px;
        }

        .btn-submit-builder:hover {
            background: #262626;
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.2);
        }

        /* Side Card: Previous Resumes */
        .recent-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
        }

        .recent-card-header {
            background: #F9FAFB;
            border-bottom: 1px solid #F3F4F6;
            padding: 14px 22px;
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
            padding: 14px 22px;
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

        /* Print / Resume Document Paper */
        .resume-doc-paper {
            background: #FFFFFF;
            color: #111827;
            padding: 44px 52px;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.06);
            max-width: 860px;
            margin: 0 auto;
            line-height: 1.5;
        }

        .resume-doc-paper h2 {
            font-size: 26px;
            font-weight: 900;
            margin: 0 0 2px;
            color: #0A0A0A;
            letter-spacing: -0.5px;
        }

        .resume-doc-paper .target-role {
            font-size: 15px;
            font-weight: 700;
            color: #15803D;
            margin-bottom: 8px;
        }

        .resume-doc-paper .contact-meta {
            font-size: 12.5px;
            color: #4B5563;
            margin-bottom: 22px;
            padding-bottom: 14px;
            border-bottom: 2px solid #E5E7EB;
        }

        .resume-doc-paper h4 {
            font-size: 13.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #0A0A0A;
            border-bottom: 1.5px solid #0A0A0A;
            padding-bottom: 4px;
            margin: 20px 0 10px;
        }

        @media print {
            header, .keria-hero-banner, .no-print, .mode-toggle-bar, footer {
                display: none !important;
            }
            body {
                background: #FFFFFF !important;
            }
            .keria-main-container {
                padding: 0 !important;
                max-width: 100% !important;
            }
            .resume-doc-paper {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
            }
        }

        /* Dark Mode */
        [data-theme="dark"] body {
            background: #18191C !important;
            color: #F9FAFB;
        }
        [data-theme="dark"] .keria-card,
        [data-theme="dark"] .recent-card {
            background: #26272C;
            border-color: #383940;
        }
        [data-theme="dark"] .recent-card-header {
            background: #212226;
            border-bottom-color: #383940;
        }
        [data-theme="dark"] .recent-row:hover {
            background: #2D2E34;
        }
        [data-theme="dark"] .rb-input,
        [data-theme="dark"] .rb-textarea,
        [data-theme="dark"] .rb-repeat-block {
            background: #1F2023;
            border-color: #383940;
            color: #F9FAFB;
        }
        [data-theme="dark"] .rb-card-title {
            color: #F9FAFB;
        }
        [data-theme="dark"] .rb-label {
            color: #D1D5DB;
        }
        [data-theme="dark"] .btn-submit-builder {
            background: var(--keria-lime);
            color: #0A0A0A;
        }
        [data-theme="dark"] .mode-toggle-bar {
            background: #26272C;
            border-color: #383940;
        }
    </style>
</head>
<body>
    <!-- ================= 1. HEADER / NAVBAR (identical to jobs.php) ================= -->
    <header class="no-print">
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
                <a href="candidate_dashboard.php">👤 My Applications</a>
                <a href="resume_builder.php" class="active">📝 AI Resume Builder</a>
                <a href="resume_check.php">✨ AI Resume Check</a>
                <a href="profile.php">⚙️ Profile Settings</a>
            </nav>

            <div class="header-right-actions" style="margin-left:auto; display:flex; align-items:center;">
                <!-- Notifications Bell -->
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

                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($candidate_name) ?> (Candidate)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <!-- ================= 2. HERO BANNER SECTION (matching jobs.php) ================= -->
    <section class="keria-hero-banner no-print">
        <div class="banner-content-inner">
            
            <div class="banner-left-intro">
                <svg class="banner-leaf-doodles" viewBox="0 0 50 50" fill="none">
                    <path d="M12 28 C 6 18, 14 6, 26 10 C 26 22, 18 30, 12 28 Z" fill="#97DF14" opacity="0.85"/>
                    <circle cx="8" cy="38" r="4" fill="#97DF14" opacity="0.7"/>
                </svg>
                <h1 class="banner-title-text">
                    AI Resume<br>Builder
                </h1>
                <p class="banner-sub-text">
                    Tell us about your background and our AI will turn it into a polished, professional resume ready to impress recruiters.
                </p>
                <div class="banner-sub-smile">
                    ☺
                </div>
            </div>

            <!-- Right Banner Doodle Area over Mascot -->
            <div class="banner-right-doodle-box">
                <div class="banner-doodle-text">
                    Craft<br>Your Best<br>Resume! ☺
                    <svg class="banner-doodle-sparks" viewBox="0 0 30 30">
                        <path d="M6 14 L2 14 M8 8 L4 4 M14 6 L14 2 M20 8 L24 4 M22 14 L26 14" stroke="#0A0A0A" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>

        </div>
    </section>

    <!-- ================= 3. MAIN CONTAINER ================= -->
    <main class="keria-main-container">

        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification no-print" style="margin-bottom:20px; background:#DCFCE7; border:1px solid #86EFAC; border-radius:14px; padding:12px 18px; color:#15803D; font-size:13.5px; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span>🌿</span>
                    <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                </div>
                <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:#15803D; font-weight:bold; cursor:pointer;">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="no-print" style="background:rgba(239, 68, 68, 0.08); border:1px solid rgba(239, 68, 68, 0.3); border-radius:14px; padding:12px 18px; margin-bottom:24px; color:#DC2626; font-size:13px; display:flex; align-items:center; gap:10px;">
                <span>⚠️</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if($generated): ?>
            <!-- ===== GENERATED RESUME RESULT VIEW ===== -->
            <div style="max-width:960px; margin:0 auto;">
                
                <!-- Action Bar Card -->
                <div class="keria-card no-print" style="margin-bottom:24px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                        <div>
                            <div style="font-size:22px; font-weight:900; color:#0A0A0A; margin:0;">🎉 Your AI Resume is Ready!</div>
                            <div style="font-size:13px; color:#6B7280; margin-top:4px;">Review your polished resume below, download it, or tweak details.</div>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap;">
                            <?php $current_build_id = $new_build_id ?? ($_GET['view'] ?? null); ?>
                            <?php if ($current_build_id): ?>
                                <a href="view_builder_pdf.php?id=<?= (int)$current_build_id ?>&download=1" class="btn-search-find" style="background:#0A0A0A; color:#FFFFFF; text-decoration:none; padding:10px 20px; font-size:13px; border-radius:12px; font-weight:800; display:inline-flex; align-items:center; gap:6px;">
                                    📥 Download as PDF
                                </a>
                                <a href="export_resume_docx.php?id=<?= (int)$current_build_id ?>" class="btn-browse-action" style="text-decoration:none; padding:10px 18px; font-size:13px; font-weight:700;">
                                    📝 Download as Word
                                </a>
                                <a href="resume_builder.php?edit=<?= (int)$current_build_id ?>" class="btn-browse-action" style="text-decoration:none; padding:10px 18px; font-size:13px; font-weight:700;">
                                    ✏️ Edit Details
                                </a>
                            <?php else: ?>
                                <button onclick="window.print()" class="btn-search-find" style="background:#0A0A0A; color:#FFFFFF; border:none; padding:10px 20px; font-size:13px; border-radius:12px; font-weight:800; cursor:pointer;">
                                    📥 Print / Save PDF
                                </button>
                            <?php endif; ?>
                            <a href="resume_builder.php" class="btn-browse-action" style="text-decoration:none; padding:10px 18px; font-size:13px; font-weight:700;">
                                🆕 Build New
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Printable Document Sheet -->
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
                        <div style="margin-bottom:14px; text-align:justify; font-size:13.5px;"><?= htmlspecialchars($generated['summary']) ?></div>
                    <?php endif; ?>

                    <?php
                        ob_start(); ?>
                        <?php if(!empty($generated['experience'])): ?>
                            <h4>Work Experience</h4>
                            <?php foreach($generated['experience'] as $exp): ?>
                                <div style="font-weight:700; font-size:13.5px;"><?= htmlspecialchars(($exp['role'] ?? '') . (!empty($exp['company']) ? ' — ' . $exp['company'] : '')) ?></div>
                                <?php if(!empty($exp['duration'])): ?><div style="font-size:12px; color:#666; margin-bottom:4px;"><?= htmlspecialchars($exp['duration']) ?></div><?php endif; ?>
                                <?php if(!empty($exp['bullets'])): ?>
                                    <ul style="margin:4px 0 12px 18px; padding:0; font-size:13px;">
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
                        <h4>Core Skills &amp; Competencies</h4>
                        <ul style="margin:4px 0 14px 18px; padding:0; display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:4px 16px; font-size:13px;">
                            <?php foreach($generated['skills'] as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if(!empty($generated['achievements'])): ?>
                        <h4>Key Achievements &amp; Certifications</h4>
                        <ul style="margin:4px 0 12px 18px; padding:0; font-size:13px;">
                            <?php foreach($generated['achievements'] as $a): ?><li><?= htmlspecialchars($a) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if(!empty($generated['references'])): ?>
                        <h4>References</h4>
                        <?php foreach($generated['references'] as $ref): ?>
                            <?php $rname = trim($ref['name'] ?? ''); if ($rname === '') continue; ?>
                            <div style="font-weight:700; font-size:13.5px;"><?= htmlspecialchars($rname) ?></div>
                            <?php if (!empty($ref['title'])): ?><div style="font-size:12px; color:#666; font-style:italic;"><?= htmlspecialchars($ref['title']) ?></div><?php endif; ?>
                            <?php if (!empty($ref['contact'])): ?><div style="font-size:12px; color:#374151; margin-bottom:8px;"><?= htmlspecialchars($ref['contact']) ?></div><?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>

            <!-- ===== FORM / CHAT BUILDER VIEW ===== -->
            
            <!-- Mode Switcher (Pill style) -->
            <div class="no-print" style="margin-bottom:20px;">
                <div class="mode-toggle-bar">
                    <button type="button" class="mode-toggle-btn active" id="tabFormBtn" onclick="switchMode('form')">📝 Step-by-Step Form</button>
                    <button type="button" class="mode-toggle-btn" id="tabChatBtn" onclick="switchMode('chat')">💬 Chat with AI</button>
                </div>
            </div>

            <div class="builder-layout-grid">
                
                <!-- Main Form Column -->
                <div class="builder-main-col">
                    <form method="POST" id="builderForm">
                        <input type="hidden" name="action" value="generate">

                        <div id="formPanel">
                            <!-- CARD 1: Basic Information -->
                            <div class="keria-card">
                                <div class="rb-card-header">
                                    <div class="rb-num-badge">1</div>
                                    <div>
                                        <h3 class="rb-card-title">Basic Information</h3>
                                        <p class="rb-card-subtitle">Let's start with your contact details and target role.</p>
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
                                        <span>I'm a fresh graduate / have little formal work experience</span>
                                    </label>
                                </div>
                            </div>

                            <!-- CARD 2: Work Experience -->
                            <div class="keria-card" id="expPanel">
                                <div class="rb-card-header">
                                    <div class="rb-num-badge">2</div>
                                    <div>
                                        <h3 class="rb-card-title" id="expPanelTitle">Work Experience</h3>
                                        <p class="rb-card-subtitle" id="expPanelSubtitle">Add your past roles, internships, or relevant projects.</p>
                                    </div>
                                </div>

                                <div id="expContainer"></div>

                                <button type="button" class="rb-add-pill-btn" onclick="addExp()">
                                    <span>+ Add Another Experience</span>
                                </button>
                            </div>

                            <!-- CARD 3: Education -->
                            <div class="keria-card" id="eduPanel">
                                <div class="rb-card-header">
                                    <div class="rb-num-badge">3</div>
                                    <div>
                                        <h3 class="rb-card-title">Education</h3>
                                        <p class="rb-card-subtitle">Tell us about your degrees and academic qualifications.</p>
                                    </div>
                                </div>

                                <div id="eduContainer"></div>

                                <button type="button" class="rb-add-pill-btn" onclick="addEdu()">
                                    <span>+ Add Another Education</span>
                                </button>
                            </div>

                            <!-- CARD 4: Skills -->
                            <div class="keria-card">
                                <div class="rb-card-header">
                                    <div class="rb-num-badge">4</div>
                                    <div>
                                        <h3 class="rb-card-title">Key Skills</h3>
                                        <p class="rb-card-subtitle">List the tools, technical skills, and languages you excel at.</p>
                                    </div>
                                </div>

                                <div>
                                    <input type="text" name="skills" id="skillsInputForm" class="rb-input" value="<?= htmlspecialchars($prefill['skills'] ?? '') ?>" placeholder="e.g. Figma, SEO, Google Analytics, Excel, Mandarin">
                                </div>
                            </div>

                            <!-- CARD 5: Anything Else? -->
                            <div class="keria-card">
                                <div class="rb-card-header">
                                    <div class="rb-num-badge">5</div>
                                    <div>
                                        <h3 class="rb-card-title">Additional Highlights (Optional)</h3>
                                        <p class="rb-card-subtitle">Certifications, awards, extracurricular activities, or achievements.</p>
                                    </div>
                                </div>

                                <div>
                                    <textarea name="extra_notes" class="rb-textarea" rows="3" placeholder="e.g. Google Project Management Certified, Dean's List, First Runner Up in Hackathon 2026..."><?= htmlspecialchars($prefill['extra_notes'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <!-- CARD 6: References -->
                            <div class="keria-card">
                                <div class="rb-card-header">
                                    <div class="rb-num-badge">6</div>
                                    <div>
                                        <h3 class="rb-card-title">References (Optional)</h3>
                                        <p class="rb-card-subtitle">Add mentors or supervisors who can vouch for your character.</p>
                                    </div>
                                </div>

                                <div>
                                    <label class="rb-checkbox-wrap">
                                        <input type="checkbox" name="include_references" id="includeRefsCheck" <?= !empty($prefill['references']) ? 'checked' : '' ?>>
                                        <span>Include a references section on my resume</span>
                                    </label>
                                </div>

                                <div id="refsPanelBody" style="display:<?= !empty($prefill['references']) ? 'block' : 'none' ?>; margin-top:16px;">
                                    <div id="refContainer"></div>
                                    <button type="button" class="rb-add-pill-btn" onclick="addRef()">
                                        <span>+ Add Another Reference</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Submit Action -->
                            <button type="submit" class="btn-submit-builder">
                                <span>✨ Generate My Resume &rarr;</span>
                            </button>
                        </div>

                        <!-- Chat Mode Panel -->
                        <div id="chatPanel" style="display:none;">
                            <div class="keria-card">
                                <div class="rb-card-header">
                                    <div class="rb-num-badge">💬</div>
                                    <div>
                                        <h3 class="rb-card-title">Interactive AI Builder</h3>
                                        <p class="rb-card-subtitle">Answer simple guided questions and let AI construct your resume.</p>
                                    </div>
                                </div>

                                <div class="chat-box" id="chatBox" style="background:#FAFAFA; border:1px solid #E5E7EB; border-radius:16px; padding:18px; display:flex; flex-direction:column; gap:10px; max-height:420px; overflow-y:auto; margin-bottom:14px;"></div>
                                <div class="chat-chip-row" id="chatChipRow" style="display:none; gap:8px; margin-bottom:12px; flex-wrap:wrap;"></div>
                                <div class="chat-input-row" id="chatInputRow" style="display:flex; gap:10px;">
                                    <input type="text" id="chatTextInput" class="rb-input" placeholder="Type your answer…" style="border-radius:9999px;">
                                    <button type="button" class="btn-submit-builder" style="width:auto; padding:0 24px; border-radius:9999px; margin:0;" onclick="chatSend()">Send</button>
                                </div>
                            </div>
                            <div id="chatHiddenFields"></div>
                        </div>

                    </form>
                </div>

                <!-- Sidebar Column: Previous Resumes -->
                <div class="builder-side-col no-print">
                    <?php if(!empty($past_builds)): ?>
                        <div class="recent-card">
                            <div class="recent-card-header">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#15803D" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                                <span>YOUR PREVIOUS RESUMES</span>
                            </div>

                            <div>
                                <?php foreach($past_builds as $pb): ?>
                                    <a href="resume_builder.php?view=<?= (int)$pb['id'] ?>" class="recent-row" title="View resume for <?= htmlspecialchars($pb['target_title']) ?>">
                                        <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                                            <span style="font-size:18px;">📄</span>
                                            <div style="font-size:13.5px; font-weight:700; color:#0A0A0A; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                                <?= htmlspecialchars($pb['target_title'] ?: 'Resume') ?>
                                            </div>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <span style="font-size:11.5px; color:#6B7280;"><?= date('d M Y', strtotime($pb['created_at'])) ?></span>
                                            <span style="color:#9CA3AF; font-size:16px; font-weight:700;">&rsaquo;</span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="keria-card" style="text-align:center; padding:32px 20px;">
                            <div style="font-size:32px; margin-bottom:8px;">💡</div>
                            <div style="font-weight:800; font-size:14.5px; color:#0A0A0A; margin-bottom:4px;">Pro Tip</div>
                            <div style="font-size:12.5px; color:#6B7280; line-height:1.5;">
                                After generating, you can download your resume in both ATS-friendly PDF and editable Word formats!
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        <?php endif; ?>

    </main>

    <!-- Templates for Repeatable Experience, Education, and References -->
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
                <label class="rb-label">Key Responsibilities &amp; Achievements</label>
                <textarea name="exp_notes[]" class="rb-textarea exp-notes" rows="2" placeholder="e.g. Managed social media, grew followers by 30%, handled campaign execution..."></textarea>
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
                    <label class="rb-label">Institution / School</label>
                    <input type="text" name="edu_school[]" class="rb-input" placeholder="e.g. UTM">
                </div>
                <div>
                    <label class="rb-label">Graduation Year</label>
                    <input type="text" name="edu_year[]" class="rb-input" placeholder="e.g. 2022">
                </div>
            </div>
        </div>
    </template>

    <template id="refTemplate">
        <div class="rb-repeat-block ref-block">
            <button type="button" class="rb-remove-btn" onclick="this.closest('.rb-repeat-block').remove()" title="Remove Reference">&times;</button>
            <div class="rb-field-grid-3">
                <div>
                    <label class="rb-label">Name</label>
                    <input type="text" name="ref_name[]" class="rb-input" placeholder="e.g. Amir Rahman">
                </div>
                <div>
                    <label class="rb-label">Title / Relationship</label>
                    <input type="text" name="ref_title[]" class="rb-input" placeholder="e.g. Former Manager, BrightWave Media">
                </div>
                <div>
                    <label class="rb-label">Contact</label>
                    <input type="text" name="ref_contact[]" class="rb-input" placeholder="e.g. amir@brightwave.com · +60 12-345 6789">
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
    function addRef(data) {
        const tpl = document.getElementById('refTemplate').content.cloneNode(true);
        const block = tpl.querySelector('.ref-block');
        if (data) {
            block.querySelector('[name="ref_name[]"]').value = data.name || '';
            block.querySelector('[name="ref_title[]"]').value = data.title || '';
            block.querySelector('[name="ref_contact[]"]').value = data.contact || '';
        }
        document.getElementById('refContainer').appendChild(tpl);
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
        if (PREFILL_DATA && Array.isArray(PREFILL_DATA.references) && PREFILL_DATA.references.length > 0) {
            PREFILL_DATA.references.forEach(ref => addRef(ref));
        } else {
            addRef();
        }
    });

    // References toggle handler
    document.addEventListener('DOMContentLoaded', function() {
        const includeRefsCheck = document.getElementById('includeRefsCheck');
        const refsPanelBody = document.getElementById('refsPanelBody');
        if (!includeRefsCheck || !refsPanelBody) return;
        function applyRefsVisibility(isChecked) {
            refsPanelBody.style.display = isChecked ? 'block' : 'none';
            refsPanelBody.querySelectorAll('input').forEach(el => el.disabled = !isChecked);
        }
        applyRefsVisibility(includeRefsCheck.checked);
        includeRefsCheck.addEventListener('change', function() {
            applyRefsVisibility(this.checked);
        });
    });

    // Fresh-grad toggle handler
    function applyFreshGradLayout(isChecked) {
        const formPanelEl = document.getElementById('formPanel');
        const expPanel = document.getElementById('expPanel');
        const eduPanel = document.getElementById('eduPanel');
        const expTitle = document.getElementById('expPanelTitle');
        const expSubtitle = document.getElementById('expPanelSubtitle');
        if (!formPanelEl || !expPanel || !eduPanel) return;
        if (isChecked) {
            if (expTitle) expTitle.textContent = 'Internships / Campus Roles / Projects (Optional)';
            if (expSubtitle) expSubtitle.textContent = 'Add internships, campus leadership, or personal projects.';
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
            div.style.background = '#DCFCE7';
            div.style.color = '#15803D';
            div.style.alignSelf = 'flex-start';
            div.style.border = '1px solid #BBF7D0';
        } else {
            div.style.background = '#0A0A0A';
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

    // Notifications dropdown
    function toggleNotifDropdown() {
        const dd = document.getElementById('notifDropdown');
        if (dd) dd.classList.toggle('show');
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

    <!-- FOOTER (matching site) -->
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
                <a href="terms.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Terms &amp; Conditions</a>
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
