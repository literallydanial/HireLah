<?php
require_once 'auth.php';
require_role('candidate');
require_once 'notifications_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond_interview') {
    $cand_id = $_POST['candidate_id'] ?? '';
    $resp = $_POST['interview_response'] ?? '';

    if ($cand_id && in_array($resp, ['confirm', 'decline'])) {
        $new_status = ($resp === 'confirm') ? 'Confirmed' : 'Declined';
        $up = $pdo->prepare("UPDATE candidates SET interview_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
        $up->execute([$new_status, $cand_id, $_SESSION['user_id']]);

        if ($resp === 'confirm') {
            $c_info = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, COALESCE(NULLIF(u.contact_email, ''), u.email) as employer_email FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.id = ?");
            $c_info->execute([$cand_id]);
            $cand = $c_info->fetch();

            if ($cand && !empty($cand['employer_email'])) {
                require_once 'mailer.php';
                send_interview_confirmed_email($cand['employer_name'], $cand['employer_email'], $cand['name'], $cand['job_title'], $cand['interview_datetime'], $cand['interview_notes']);
            }
            $_SESSION['toast'] = "Interview slot confirmed! Employer notified.";
        } else {
            $_SESSION['toast'] = "Interview proposal declined.";
        }
    }
    header("Location: candidate_dashboard.php");
    exit;
}

$userNotifs = getCandidateNotifications($pdo, $_SESSION['user_id']);
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];

$stmt = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM candidates c JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.user_id = ? ORDER BY c.created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$applications = $stmt->fetchAll();

// Map questionnaire requests for candidate's applications
$app_ids = array_column($applications, 'id');
$questionnaire_requests = [];
$unread_msgs_counts = [];

if (!empty($app_ids)) {
    $in = str_repeat('?,', count($app_ids) - 1) . '?';
    $q_stmt = $pdo->prepare("SELECT qr.*, q.title FROM questionnaire_requests qr JOIN questionnaires q ON qr.questionnaire_id = q.id WHERE qr.candidate_id IN ($in) ORDER BY qr.sent_at DESC");
    $q_stmt->execute($app_ids);
    $q_list = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($q_list as $q_item) {
        if (!isset($questionnaire_requests[$q_item['candidate_id']])) {
            $questionnaire_requests[$q_item['candidate_id']] = [];
        }
        $questionnaire_requests[$q_item['candidate_id']][] = $q_item;
    }

    // Unread message count per application
    $msg_cnt_stmt = $pdo->prepare("SELECT candidate_id, COUNT(*) as cnt FROM messages WHERE candidate_id IN ($in) AND sender_role = 'employer' AND read_at IS NULL GROUP BY candidate_id");
    $msg_cnt_stmt->execute($app_ids);
    $msg_cnt_list = $msg_cnt_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($msg_cnt_list as $mc) {
        $unread_msgs_counts[$mc['candidate_id']] = (int)$mc['cnt'];
    }
}

// Calculate summary stats
$total_apps = count($applications);
$shortlisted_cnt = 0;
$pending_q_cnt = 0;
$total_score_sum = 0;

foreach ($applications as $a) {
    if (in_array(strtolower($a['status']), ['shortlisted', 'offered', 'contacted'])) $shortlisted_cnt++;
    $total_score_sum += (int)($a['overall_score'] ?? 0);
    $a_q_reqs = $questionnaire_requests[$a['id']] ?? [];
    foreach ($a_q_reqs as $q_req) {
        if ($q_req['status'] === 'Pending') $pending_q_cnt++;
    }
}
$avg_score = $total_apps > 0 ? round($total_score_sum / $total_apps) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Job Applications — keria</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">

    <style>
        :root {
            --keria-lime: #D2FF3A;
            --keria-lime-hover: #C2F025;
            --keria-dark: #0A0A0A;
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-hand: 'Caveat', cursive;
        }

        body {
            font-family: var(--font-sans);
            color: var(--keria-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        .keria-main-container {
            flex: 1 0 auto;
        }


        /* Screen-filling Header Width matching jobs.php */
        header .header-inner {
            max-width: 1560px !important;
            width: 100% !important;
            padding: 0 16px !important;
        }

        /* ================= HERO BANNER SECTION ================= */
        .keria-hero-banner {
            position: relative;
            width: 100%;
            background-image: url('assets/Keria_banner.jpg?v=<?php echo @filemtime(__DIR__.'/assets/Keria_banner.jpg'); ?>');
            background-size: cover;
            background-position: center center;
            background-repeat: no-repeat;
            padding: 44px 32px 48px;
            min-height: 280px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
            display: flex;
            align-items: center;
        }

        .banner-content-inner {
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            position: relative;
            z-index: 5;
        }

        .banner-top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 28px;
        }

        .banner-title-block {
            max-width: 680px;
        }

        .banner-title-text {
            font-size: 38px;
            font-weight: 900;
            color: #0A0A0A;
            line-height: 1.1;
            letter-spacing: -1.2px;
            margin: 0 0 6px 0;
        }

        .banner-sub-text {
            font-size: 14.5px;
            font-weight: 500;
            color: #374151;
            line-height: 1.45;
            margin: 0;
        }

        .btn-banner-explore {
            background: #0A0A0A;
            color: #FFFFFF;
            font-weight: 800;
            font-size: 13.5px;
            border-radius: 12px;
            padding: 11px 24px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }

        .btn-banner-explore:hover {
            background: #262626;
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.22);
        }

        /* 4 Executive Stats Cards floating in banner */
        .banner-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
        }

        .banner-stat-card {
            background: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.85);
            border-radius: 20px;
            padding: 20px 24px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: center;
            transition: transform 0.2s ease;
            min-height: 94px;
        }

        .banner-stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card-label {
            font-size: 10px;
            font-weight: 800;
            color: #64748B;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
        }

        .stat-card-val {
            font-size: 28px;
            font-weight: 900;
            color: #0A0A0A;
            line-height: 1;
        }

        /* ================= MAIN CONTAINER ================= */
        .keria-main-container {
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            padding: 24px 28px 60px;
        }

        /* Search Bar */
        .applications-search-bar-wrap {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 999px;
            padding: 12px 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 22px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .applications-search-bar-wrap:focus-within {
            border-color: #9CA3AF;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
        }

        .applications-search-input {
            border: none;
            outline: none;
            background: transparent;
            width: 100%;
            font-size: 14px;
            color: #0A0A0A;
            font-weight: 500;
            font-family: inherit;
            padding: 0;
            margin: 0;
            box-shadow: none !important;
        }

        .applications-search-input::placeholder {
            color: #9CA3AF;
        }

        /* Application Card Item */
        .app-card-box {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 20px;
            padding: 20px 26px;
            margin-bottom: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        .app-card-box:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.06);
            border-color: #D1D5DB;
            transform: translateY(-1px);
        }

        .app-card-main-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .app-card-left-col {
            flex: 1;
            min-width: 260px;
        }

        .app-title-group {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .app-job-title {
            font-size: 18px;
            font-weight: 800;
            color: #0A0A0A;
            line-height: 1.25;
        }

        /* Status Pills next to title */
        .badge-status-pill {
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 11px;
            display: inline-flex;
            align-items: center;
            line-height: 1.2;
        }

        .badge-review {
            background: #FFEDD5;
            color: #C2410C;
            border: 1px solid #FED7AA;
        }

        .badge-offered {
            background: #DCFCE7;
            color: #15803D;
            border: 1px solid #BBF7D0;
        }

        .badge-contacted {
            background: #EDE9FE;
            color: #6366F1;
            border: 1px solid #DDD6FE;
        }

        .badge-rejected {
            background: #FEE2E2;
            color: #DC2626;
            border: 1px solid #FECACA;
        }

        .app-meta-subline {
            font-size: 13px;
            color: #6B7280;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .app-meta-subline strong {
            color: #374151;
            font-weight: 600;
        }

        /* Right Actions Group */
        .app-card-right-col {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .status-badge-lg {
            padding: 6px 14px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
            display: inline-flex;
            align-items: center;
        }

        .status-contacted {
            background: #6366F1;
            color: #FFFFFF;
        }

        .status-offered {
            background: #0F172A;
            color: #FFFFFF;
        }

        .status-rejected {
            background: #DC2626;
            color: #FFFFFF;
        }

        /* AI Score Badge in Card */
        .ai-score-card {
            min-width: 60px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            margin: 0 4px;
        }

        .ai-score-num {
            font-size: 20px;
            font-weight: 900;
            line-height: 1;
        }

        .ai-score-lbl {
            font-size: 8.5px;
            font-weight: 800;
            color: #64748B;
            letter-spacing: 0.5px;
            margin-top: 3px;
            text-transform: uppercase;
        }

        .ai-score-underline {
            width: 32px;
            height: 3.5px;
            border-radius: 999px;
            margin-top: 4px;
        }

        /* Action Buttons */
        .btn-card-action {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-card-action:hover {
            background: #F8FAFC;
            border-color: #CBD5E1;
            color: #0F172A;
            transform: translateY(-1px);
        }

        .btn-card-action.has-unread {
            background: #F7FEE7;
            border-color: #84CC16;
            color: #15803D;
        }

        .action-dot {
            color: #0A0A0A;
            font-size: 10px;
        }

        .badge-unread-count {
            background: #84CC16;
            color: #0A0A0A;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 999px;
        }

        /* Expandable AI Drawer */
        .ai-drawer {
            display: none;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px dashed #E5E7EB;
            animation: drawerFadeIn 0.25s ease-in-out;
        }

        @keyframes drawerFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Empty State */
        .empty-apps-panel {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 20px;
            text-align: center;
            padding: 70px 20px;
            color: #6B7280;
        }

        @media (max-width: 1024px) {
            .banner-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .banner-stats-grid {
                grid-template-columns: 1fr;
            }
            .app-card-right-col {
                width: 100%;
                justify-content: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    
    <!-- Retained intact Navbar -->
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="jobs.php">📋 Job Board</a>
                <a href="candidate_dashboard.php" class="active">👤 My Applications</a>
                <a href="resume_builder.php">📝 AI Resume Builder</a>
                <a href="profile.php">⚙️ Profile Settings</a>
            </nav>

            <div class="header-right-actions">
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

                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?> (<?= ucfirst($_SESSION['user_role']) ?>)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <!-- ================= HERO BANNER SECTION ================= -->
    <section class="keria-hero-banner">
        <div class="banner-content-inner">
            
            <div class="banner-top-row">
                <div class="banner-title-block">
                    <h1 class="banner-title-text">My Job Applications</h1>
                    <p class="banner-sub-text">Track your application statuses, AI match feedback, and employer questionnaire requests.</p>
                </div>
                <div>
                    <a href="jobs.php" class="btn-banner-explore">
                        🔍 Explore More Jobs
                    </a>
                </div>
            </div>

            <!-- 4 Executive Metric Cards in Banner -->
            <div class="banner-stats-grid">
                <div class="banner-stat-card">
                    <div class="stat-card-label">APPLIED POSITIONS</div>
                    <div class="stat-card-val"><?= $total_apps ?></div>
                </div>
                <div class="banner-stat-card">
                    <div class="stat-card-label">SHORTLISTED ROLES</div>
                    <div class="stat-card-val" style="color: #15803D;"><?= $shortlisted_cnt ?></div>
                </div>
                <div class="banner-stat-card">
                    <div class="stat-card-label">AVG AI MATCH SCORE</div>
                    <div class="stat-card-val" style="color: <?= $avg_score >= 70 ? '#15803D' : ($avg_score >= 50 ? '#D97706' : '#DC2626') ?>;"><?= $avg_score ?>%</div>
                </div>
                <div class="banner-stat-card">
                    <div class="stat-card-label">PENDING QUESTIONNAIRES</div>
                    <div class="stat-card-val" style="color: <?= $pending_q_cnt > 0 ? '#D97706' : '#0A0A0A' ?>;"><?= $pending_q_cnt ?></div>
                </div>
            </div>

        </div>
    </section>

    <!-- ================= MAIN DASHBOARD BODY ================= -->
    <main class="keria-main-container">
        
        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <!-- Search Bar -->
        <div class="applications-search-bar-wrap">
            <span style="font-size: 16px; color:#9CA3AF;">🔍</span>
            <input type="text" id="appSearch" placeholder="Search applications by job title or employer..." onkeyup="filterApplications()" class="applications-search-input">
        </div>

        <!-- Upcoming & Proposed Interviews Section -->
        <?php 
        $interview_apps = array_filter($applications, function($a) {
            return !empty($a['interview_status']) && in_array($a['interview_status'], ['Proposed', 'Confirmed']);
        });
        ?>
        <?php if(!empty($interview_apps)): ?>
            <div style="background:#FFFFFF; border:1px solid #E5E7EB; border-radius:20px; padding:22px 24px; margin-bottom:24px; box-shadow:0 4px 14px rgba(0,0,0,0.03);">
                <div style="font-size:16px; font-weight:800; color:#0A0A0A; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                    <span>📅 Upcoming & Proposed Interviews</span>
                    <span class="badge-status-pill badge-offered"><?= count($interview_apps) ?> Active</span>
                </div>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:14px;">
                    <?php foreach($interview_apps as $ia): ?>
                        <div style="background:#F9FAFB; border:1px solid #E5E7EB; border-radius:14px; padding:16px; position:relative;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <div style="font-size:15px; font-weight:800; color:#0A0A0A;"><?= htmlspecialchars($ia['job_title']) ?></div>
                                <span class="badge-status-pill <?= $ia['interview_status'] === 'Confirmed' ? 'badge-offered' : 'badge-review' ?>">
                                    <?= htmlspecialchars($ia['interview_status']) ?>
                                </span>
                            </div>

                            <div style="font-size:12.5px; color:#6B7280; margin-bottom:10px;">Employer: <strong><?= htmlspecialchars($ia['employer_name'] ?: 'Keria Employer') ?></strong></div>

                            <?php if(!empty($ia['interview_datetime'])): ?>
                                <div style="font-size:13px; font-weight:800; color:#15803D; margin-bottom:10px; background:#DCFCE7; border:1px solid #BBF7D0; padding:6px 12px; border-radius:8px; display:inline-block;">
                                    🕒 <?= date('F j, Y \a\t g:i A', strtotime($ia['interview_datetime'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($ia['interview_notes'])): ?>
                                <div style="font-size:12px; color:#374151; margin-bottom:14px; background:#FFFFFF; padding:8px 12px; border-radius:8px; border:1px dashed #D1D5DB;">
                                    <strong>Notes:</strong> <?= nl2br(htmlspecialchars($ia['interview_notes'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if($ia['interview_status'] === 'Proposed'): ?>
                                <div style="display:flex; gap:10px; margin-top:10px;">
                                    <form method="POST" style="flex:1; margin:0;">
                                        <input type="hidden" name="action" value="respond_interview">
                                        <input type="hidden" name="candidate_id" value="<?= $ia['id'] ?>">
                                        <input type="hidden" name="interview_response" value="confirm">
                                        <button type="submit" class="btn-primary" style="padding:8px; font-size:12px; border-radius:8px; width:100%;">✓ Confirm Slot</button>
                                    </form>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="respond_interview">
                                        <input type="hidden" name="candidate_id" value="<?= $ia['id'] ?>">
                                        <input type="hidden" name="interview_response" value="decline">
                                        <button type="submit" class="btn-secondary" style="padding:8px 12px; font-size:12px; color:#DC2626; border-color:#FECACA;">✕ Decline</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="font-size:12px; font-weight:700; color:#15803D; margin-top:6px;">✓ Interview Slot Confirmed</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if($total_apps === 0): ?>
            <div class="empty-apps-panel">
                <div style="font-size:42px; margin-bottom:12px;">📂</div>
                <div style="font-size:20px; font-weight:800; color:#0A0A0A; margin-bottom:6px;">No job applications submitted yet</div>
                <div style="font-size:14px; margin-bottom:20px;">Browse open positions on the job board and submit your resume to start tracking your progress.</div>
                <a href="jobs.php" class="btn-banner-explore" style="display:inline-flex;">View Job Board &rarr;</a>
            </div>
        <?php else: ?>
            <div id="applicationsList">
                <?php foreach($applications as $app): 
                    $raw_status = strtolower($app['status'] ?? 'review');
                    
                    // Badges logic matching screenshot
                    $status_label = 'Review';
                    $status_badge_class = 'badge-review';
                    $right_badge_html = '';

                    if ($raw_status === 'offered') {
                        $status_label = 'Offered';
                        $status_badge_class = 'badge-offered';
                        $right_badge_html = '<span class="status-badge-lg status-offered">Offered</span>';
                    } elseif ($raw_status === 'shortlisted' || $raw_status === 'contacted') {
                        $status_label = 'Review';
                        $status_badge_class = 'badge-review';
                        $right_badge_html = '<span class="status-badge-lg status-contacted">Contacted</span>';
                    } elseif ($raw_status === 'rejected') {
                        $status_label = 'Review';
                        $status_badge_class = 'badge-review';
                        $right_badge_html = '<span class="status-badge-lg status-rejected">Rejected</span>';
                    } else {
                        $status_label = 'Review';
                        $status_badge_class = 'badge-review';
                    }

                    $app_q_reqs = $questionnaire_requests[$app['id']] ?? [];
                    $score = (int)($app['overall_score'] ?? 0);
                    $score_color = $score >= 70 ? '#15803D' : ($score >= 50 ? '#D97706' : '#DC2626');
                    $strengths = !empty($app['strengths']) ? (json_decode($app['strengths'], true) ?: []) : [];
                    $app_resume = !empty($app['filename']) ? ('uploads/resumes/' . $app['filename']) : '';
                    $un_cnt = $unread_msgs_counts[$app['id']] ?? 0;
                ?>
                    <div class="app-card-box" data-title="<?= htmlspecialchars(strtolower($app['job_title'])) ?>" data-employer="<?= htmlspecialchars(strtolower($app['employer_name'] ?? 'keria')) ?>">
                        
                        <div class="app-card-main-row">
                            <!-- Left Column: Title & Meta -->
                            <div class="app-card-left-col">
                                <div class="app-title-group">
                                    <span class="app-job-title"><?= htmlspecialchars($app['job_title']) ?></span>
                                    <span class="badge-status-pill <?= $status_badge_class ?>"><?= $status_label ?></span>
                                </div>
                                <div class="app-meta-subline">
                                    <span>🏢 <strong><?= htmlspecialchars($app['employer_name'] ?? 'test company sdn bhd') ?></strong></span>
                                    <span>&bull;</span>
                                    <span>📅 Applied <?= date('M d, Y', strtotime($app['created_at'])) ?></span>
                                </div>
                            </div>

                            <!-- Right Column: Status Tag, AI Score, Action Buttons -->
                            <div class="app-card-right-col">
                                
                                <?= $right_badge_html ?>

                                <!-- AI Match Score Badge -->
                                <div class="ai-score-card">
                                    <div class="ai-score-num" style="color: <?= $score_color ?>;"><?= $score ?>%</div>
                                    <div class="ai-score-lbl">AI MATCH</div>
                                    <div class="ai-score-underline" style="background: <?= $score_color ?>;"></div>
                                </div>

                                <?php if(!empty($app_resume)): ?>
                                    <button type="button" class="btn-card-action" onclick="openResumeModal('<?= htmlspecialchars($app_resume) ?>', '<?= htmlspecialchars($app['job_title']) ?> Resume')">
                                        <span class="action-dot">●</span> Preview Resume
                                    </button>
                                <?php endif; ?>

                                <button type="button" class="btn-card-action" onclick="toggleDrawer('drawer_<?= $app['id'] ?>')">
                                    🔍 AI Match Breakdown
                                </button>
                                
                                <a href="candidate.php?id=<?= $app['id'] ?>" class="btn-card-action <?= $un_cnt > 0 ? 'has-unread' : '' ?>">
                                    💬 Discussion Thread
                                    <?php if($un_cnt > 0): ?>
                                        <span class="badge-unread-count"><?= $un_cnt ?> New</span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>

                        <!-- Questionnaire Action Banner -->
                        <?php if(!empty($app_q_reqs)): ?>
                            <div style="margin-top:16px; padding:12px 16px; background:#FEFCE8; border:1px solid #FEF08A; border-radius:12px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                <div>
                                    <div style="font-size:13px; font-weight:800; color:#854D0E;">📋 Screening Questionnaire Notification</div>
                                    <div style="font-size:12px; color:#713F12;">The hiring team requested you to complete a brief questionnaire for this role.</div>
                                </div>
                                <?php foreach($app_q_reqs as $q_req): ?>
                                    <?php if($q_req['status'] === 'Pending'): ?>
                                        <a href="answer_questionnaire.php?token=<?= $q_req['id'] ?>" class="btn-banner-explore" style="padding:6px 14px; font-size:11.5px;">
                                            Answer Screening Questions &rarr;
                                        </a>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:#15803D; font-weight:800;">✓ Questionnaire Submitted</span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Expandable AI Evaluation Drawer -->
                        <div id="drawer_<?= $app['id'] ?>" class="ai-drawer">
                            <div style="font-size:13.5px; font-weight:800; color:#0A0A0A; margin-bottom:10px;">🤖 AI Resume Match Evaluation Breakdown:</div>
                            
                            <!-- Progress Bar -->
                            <div style="width:100%; height:6px; background:#E5E7EB; border-radius:999px; overflow:hidden; margin-bottom:14px;">
                                <div style="width:<?= $score ?>%; height:100%; background:<?= $score_color ?>; border-radius:999px;"></div>
                            </div>

                            <div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
                                <span class="badge-status-pill" style="background:#EFF6FF; color:#1D4ED8; border:1px solid #DBEAFE;">
                                    🎯 Skills Match: <?= (int)($app['skills_match'] ?? 80) ?>%
                                </span>
                                <span class="badge-status-pill" style="background:#F0FDF4; color:#15803D; border:1px solid #DCFCE7;">
                                    💼 Experience Match: <?= (int)($app['exp_match'] ?? 85) ?>%
                                </span>
                                <span class="badge-status-pill" style="background:#FEFCE8; color:#A16207; border:1px solid #FEF08A;">
                                    🎓 Education Match: <?= (int)($app['edu_match'] ?? 75) ?>%
                                </span>
                            </div>

                            <?php if(!empty($app['summary'])): ?>
                                <div style="font-size:12.5px; color:#374151; line-height:1.55; margin-bottom:12px; background:#F9FAFB; padding:12px 14px; border-radius:10px; border-left:3px solid <?= $score_color ?>;">
                                    "<?= htmlspecialchars($app['summary']) ?>"
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($strengths)): ?>
                                <div style="font-size:11.5px; font-weight:800; color:#15803D; margin-bottom:6px;">✨ Key Highlight Evidence:</div>
                                <ul style="margin:0; padding-left:18px; font-size:12.5px; color:#374151; line-height:1.55;">
                                    <?php foreach(array_slice($strengths, 0, 3) as $s): ?>
                                        <li><?= htmlspecialchars(preg_replace('/\[Resume Evidence:.*?\]/i', '', $s)) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- FOOTER -->
    <footer class="keria-footer">
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


    <!-- Resume PDF Preview Popup Modal Overlay -->
    <div id="resumePreviewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:9000; align-items:center; justify-content:center; padding:20px;">
        <div style="background:#FFFFFF; max-width:920px; width:100%; max-height:92vh; display:flex; flex-direction:column; padding:0; border-radius:20px; overflow:hidden; box-shadow:0 20px 50px rgba(0,0,0,0.25); border:1px solid #E5E7EB;">
            
            <!-- Modal Header -->
            <div style="padding:16px 24px; background:#F9FAFB; border-bottom:1px solid #E5E7EB; display:flex; justify-content:space-between; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                    <div style="font-size:24px;">📄</div>
                    <div style="min-width:0;">
                        <div id="resumeModalTitle" style="font-size:15px; font-weight:800; color:#0A0A0A; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Candidate Resume Preview</div>
                        <div id="resumeModalFilename" style="font-size:11.5px; color:#6B7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">PDF Document</div>
                    </div>
                </div>
                
                <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                    <a id="resumeModalDownloadBtn" href="#" download class="btn-banner-explore" style="padding:6px 14px; font-size:11.5px; border-radius:8px;">
                        📥 Download PDF
                    </a>
                    <button type="button" onclick="closeResumeModal()" style="background:none; border:none; color:#6B7280; font-size:22px; cursor:pointer; line-height:1; padding:4px 8px;" title="Close Modal">✕</button>
                </div>
            </div>

            <!-- Modal PDF Viewer Iframe Body -->
            <div style="flex:1; background:#181825; position:relative; min-height:580px; display:flex; align-items:center; justify-content:center;">
                <iframe id="resumeModalIframe" src="" style="width:100%; height:100%; min-height:580px; border:none;"></iframe>
            </div>
        </div>
    </div>

    <script>
        function filterApplications() {
            const query = document.getElementById('appSearch').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.app-card-box');
            
            cards.forEach(card => {
                const title = card.getAttribute('data-title') || '';
                const employer = card.getAttribute('data-employer') || '';
                if (title.includes(query) || employer.includes(query)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function openResumeModal(pdfUrl, title) {
            const modal = document.getElementById('resumePreviewModal');
            const iframe = document.getElementById('resumeModalIframe');
            const titleEl = document.getElementById('resumeModalTitle');
            const fileEl = document.getElementById('resumeModalFilename');
            const downloadBtn = document.getElementById('resumeModalDownloadBtn');

            if (!modal || !iframe) return;

            titleEl.textContent = title ? ('📄 ' + title) : '📄 Candidate Resume Preview';
            fileEl.textContent = pdfUrl;
            downloadBtn.href = pdfUrl;
            iframe.src = pdfUrl;

            modal.style.display = 'flex';
        }

        function closeResumeModal() {
            const modal = document.getElementById('resumePreviewModal');
            const iframe = document.getElementById('resumeModalIframe');
            if (modal) modal.style.display = 'none';
            if (iframe) iframe.src = '';
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeResumeModal();
        });

        document.getElementById('resumePreviewModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeResumeModal();
        });

        function toggleDrawer(id) {
            const drawer = document.getElementById(id);
            if (drawer) {
                drawer.style.display = (drawer.style.display === 'block') ? 'none' : 'block';
            }
        }

        function toggleNotifDropdown() {
            const dropdown = document.getElementById('notifDropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        }

        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-bell-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                const dropdown = document.getElementById('notifDropdown');
                if (dropdown) dropdown.classList.remove('show');
            }
        });

        function markNotifRead(key) {
            fetch('mark_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_key: key })
            }).catch(err => console.error(err));
        }

        function markAllNotifsRead() {
            fetch('mark_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mark_all: true })
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    const badge = document.getElementById('notifBadgeCount');
                    if (badge) badge.remove();
                    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
                    document.querySelectorAll('.unread-dot').forEach(el => el.remove());
                }
            }).catch(err => console.error(err));
        }
    </script>
    <script src="theme.js"></script>
</body>
</html>
