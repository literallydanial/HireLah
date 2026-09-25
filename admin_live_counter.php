<?php
// =========================================================================
// KERIA / KERJA - LIVE TV MILESTONES COUNTER
// Fullscreen TV Display Dashboard for Office, Reception & Event Screens
// Real Data Mode enabled
// =========================================================================
session_start();
$is_admin = isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin';
$token = $_GET['token'] ?? '';
$token_valid = ($token === 'tv_milestones_2026' || $token === 'keria_live' || !empty($token));

// Query live real data directly from database on page load
require_once 'db.php';

$real_users = 0;
$real_users_new = 0;
$real_resumes = 0;
$real_resumes_new = 0;
$real_jobs = 0;
$real_jobs_new = 0;

try {
    if (isset($pdo)) {
        // 1. Registered Users Count
        $real_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $real_users_new = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();

        // 2. Resumes Count
        $candCount = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
        $candNew = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();

        $buildsCount = 0;
        $buildsNew = 0;
        try {
            $buildsCount = (int)$pdo->query("SELECT COUNT(*) FROM resume_builds")->fetchColumn();
            $buildsNew = (int)$pdo->query("SELECT COUNT(*) FROM resume_builds WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();
        } catch (\Throwable $e) {}

        $reviewsCount = 0;
        $reviewsNew = 0;
        try {
            $reviewsCount = (int)$pdo->query("SELECT COUNT(*) FROM resume_reviews")->fetchColumn();
            $reviewsNew = (int)$pdo->query("SELECT COUNT(*) FROM resume_reviews WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();
        } catch (\Throwable $e) {}

        $defaultResumesCount = 0;
        try {
            $defaultResumesCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE default_resume IS NOT NULL AND default_resume != ''")->fetchColumn();
        } catch (\Throwable $e) {}

        $real_resumes = $candCount + $buildsCount + $reviewsCount + $defaultResumesCount;
        $real_resumes_new = $candNew + $buildsNew + $reviewsNew;

        // 3. Job Openings Count
        $real_jobs = (int)$pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
        $real_jobs_new = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();
    }
} catch (\Throwable $e) {}

$user_display = $_SESSION['user_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Kerja Milestones - Live TV Counters</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="logo/logo.png">
    <style>
        :root {
            --bg-page: #f8fafc;
            --bg-gradient: radial-gradient(circle at 50% 20%, #ffffff 0%, #f1f5f9 100%);
            --txt-main: #111827;
            --txt-muted: #64748b;
            --txt-accent: #16a34a;
            --accent-glow: #24f068;
            --border-line: #e2e8f0;
            --chassis-bg: linear-gradient(180deg, #22272e 0%, #121519 100%);
            --chassis-border: #323b47;
            --cell-bg: linear-gradient(180deg, #0d1014 0%, #06080a 100%);
            --cell-border: #1a2029;
            --seg-off: rgba(36, 240, 104, 0.08);
            --seg-on: #26f06a;
            --seg-glow: 0 0 6px rgba(38, 240, 106, 0.85), 0 0 14px rgba(38, 240, 106, 0.4);
            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-sub: 'Space Grotesk', system-ui, sans-serif;
        }

        [data-tv-theme="dark"] {
            --bg-page: #0b0f17;
            --bg-gradient: radial-gradient(circle at 50% 20%, #131b29 0%, #080b10 100%);
            --txt-main: #f8fafc;
            --txt-muted: #94a3b8;
            --txt-accent: #34d399;
            --accent-glow: #24f068;
            --border-line: #1e293b;
            --chassis-bg: linear-gradient(180deg, #181d26 0%, #0a0d12 100%);
            --chassis-border: #2c3644;
            --cell-bg: linear-gradient(180deg, #06080b 0%, #020304 100%);
            --cell-border: #141b24;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            user-select: none;
            -webkit-user-select: none;
        }

        html, body {
            width: 100vw;
            height: 100vh;
            overflow: hidden;
            background: var(--bg-page);
            background-image: var(--bg-gradient);
            font-family: var(--font-main);
            color: var(--txt-main);
            transition: background 0.4s ease, color 0.4s ease;
        }

        body.hide-cursor {
            cursor: none;
        }

        /* TV Container - Optimized for 16:9 widescreen screens */
        .tv-screen {
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 4.5vh 5vw 3.5vh;
            position: relative;
            box-sizing: border-box;
        }

        /* Top Header */
        .tv-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            width: 100%;
            z-index: 10;
        }

        .brand-block {
            display: flex;
            flex-direction: column;
        }

        .brand-name {
            font-size: clamp(32px, 4.4vw, 54px);
            font-weight: 900;
            letter-spacing: 2px;
            line-height: 1.05;
            color: var(--txt-main);
        }

        .brand-milestones {
            font-size: clamp(14px, 1.8vw, 24px);
            font-weight: 600;
            letter-spacing: 5px;
            color: var(--txt-muted);
            margin-top: 4px;
            text-transform: uppercase;
        }

        .brand-logo-wrap {
            display: flex;
            align-items: center;
        }

        .brand-logo-img {
            height: clamp(56px, 8.5vh, 90px);
            max-width: 260px;
            object-fit: contain;
            mix-blend-mode: multiply;
            transition: filter 0.3s ease;
        }

        [data-tv-theme="dark"] .brand-logo-img {
            mix-blend-mode: normal;
            filter: drop-shadow(0 0 10px rgba(255,255,255,0.15));
            background: rgba(255,255,255,0.92);
            padding: 6px 14px;
            border-radius: 14px;
        }

        /* Main Counters Section */
        .tv-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            flex: 1;
            padding: 1.5vh 0;
        }

        .counters-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: clamp(20px, 3.2vw, 48px);
            width: 100%;
            max-width: 1680px;
            margin: 0 auto;
            align-items: center;
        }

        .counter-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
        }

        .counter-title {
            font-size: clamp(18px, 2.2vw, 30px);
            font-weight: 800;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--txt-main);
            margin-bottom: clamp(10px, 1.6vh, 18px);
            text-align: center;
        }

        /* Outer Chassis / Box for 7-Segment Display */
        .chassis-box-wrap {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .chassis-box {
            background: var(--chassis-bg);
            border: clamp(2px, 0.25vw, 3.5px) solid var(--chassis-border);
            border-radius: clamp(14px, 1.8vw, 24px);
            box-shadow: 
                0 18px 36px -10px rgba(0, 0, 0, 0.45),
                inset 0 2px 4px rgba(255, 255, 255, 0.12),
                inset 0 -3px 8px rgba(0, 0, 0, 0.7);
            padding: clamp(10px, 1.5vh, 18px) clamp(16px, 2vw, 28px);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: clamp(140px, 14vw, 240px);
            gap: clamp(4px, 0.5vw, 8px);
            position: relative;
        }

        /* Digit Slot Cell */
        .digit-cell {
            background: var(--cell-bg);
            border: 1px solid var(--cell-border);
            border-radius: clamp(5px, 0.6vw, 9px);
            padding: clamp(6px, 1vh, 12px) clamp(5px, 0.7vw, 9px);
            position: relative;
            overflow: hidden;
            box-shadow: inset 0 3px 6px rgba(0, 0, 0, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Authentic split-flap horizontal cut line across center */
        .digit-cell::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 1px;
            background: rgba(0, 0, 0, 0.75);
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.05);
            pointer-events: none;
            z-index: 5;
        }

        .comma-cell {
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0 clamp(1px, 0.2vw, 3px);
            display: flex;
            align-items: flex-end;
            justify-content: center;
            height: clamp(48px, 8.5vh, 100px);
        }

        /* 7-Segment SVG styling */
        .seg-digit-svg {
            width: clamp(26px, 3.8vw, 56px);
            height: clamp(48px, 8.5vh, 100px);
            transform: skewX(-4deg);
            display: block;
        }

        .seg-comma-svg {
            width: clamp(10px, 1.2vw, 18px);
            height: clamp(48px, 8.5vh, 100px);
            transform: skewX(-4deg);
            display: block;
        }

        .seg {
            fill: var(--seg-off);
            transition: fill 0.15s ease, filter 0.15s ease;
        }

        .seg.on {
            fill: var(--seg-on);
            filter: var(--seg-glow);
        }

        .seg-comma-glow {
            fill: var(--seg-on);
            filter: var(--seg-glow);
        }

        /* Bottom New Count with Celebration Sparks */
        .counter-footer {
            margin-top: clamp(10px, 1.6vh, 18px);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
        }

        .new-badge-text {
            color: var(--txt-accent);
            font-size: clamp(17px, 2.1vw, 28px);
            font-weight: 800;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Festive Celebratory Rays */
        .spark-rays {
            width: clamp(18px, 2.2vw, 30px);
            height: clamp(18px, 2.2vw, 30px);
            stroke: var(--txt-accent);
            stroke-width: 2.8;
            stroke-linecap: round;
            fill: none;
            opacity: 0.9;
        }

        .spark-corner {
            position: absolute;
            stroke: var(--txt-accent);
            stroke-width: 2.5;
            stroke-linecap: round;
            fill: none;
            pointer-events: none;
            opacity: 0.85;
            animation: pulse-sparks 3s infinite ease-in-out;
        }

        @keyframes pulse-sparks {
            0%, 100% { transform: scale(1); opacity: 0.85; }
            50% { transform: scale(1.12); opacity: 1; }
        }

        .spark-top-right {
            top: -14px;
            right: -16px;
            width: clamp(20px, 2.5vw, 34px);
            height: clamp(20px, 2.5vw, 34px);
        }

        .spark-bottom-left {
            bottom: -14px;
            left: -16px;
            width: clamp(20px, 2.5vw, 34px);
            height: clamp(20px, 2.5vw, 34px);
        }

        /* Center Bottom Title: LIVE COUNTERS */
        .live-counters-title {
            font-size: clamp(22px, 2.8vw, 38px);
            font-weight: 800;
            letter-spacing: clamp(4px, 0.7vw, 10px);
            text-transform: uppercase;
            color: var(--txt-main);
            text-align: center;
            margin-top: clamp(20px, 3.5vh, 44px);
        }

        /* Bottom TV Status Bar */
        .tv-footer {
            width: 100%;
            border-top: 1px solid var(--border-line);
            padding-top: clamp(12px, 2vh, 20px);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: clamp(13px, 1.4vw, 19px);
            font-weight: 600;
            color: var(--txt-muted);
            z-index: 10;
        }

        .live-time-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--txt-main);
        }

        .live-dot {
            width: 10px;
            height: 10px;
            background: #16a34a;
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 10px #16a34a;
            animation: live-pulse 1.8s infinite;
        }

        @keyframes live-pulse {
            0% { transform: scale(0.9); opacity: 0.7; }
            50% { transform: scale(1.25); opacity: 1; box-shadow: 0 0 14px #22c55e; }
            100% { transform: scale(0.9); opacity: 0.7; }
        }

        .live-badge {
            color: var(--txt-accent);
            font-weight: 700;
        }

        .powered-by {
            font-size: clamp(12px, 1.2vw, 17px);
            color: var(--txt-muted);
            letter-spacing: 0.5px;
        }

        /* Floating TV Controls Bar (Auto-hides after 3s of inactivity) */
        .tv-controls-floating {
            position: fixed;
            top: 20px;
            right: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(17, 24, 39, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 30px;
            padding: 6px 14px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.35);
            z-index: 9999;
            transition: opacity 0.35s ease, transform 0.35s ease;
        }

        .tv-controls-floating.faded {
            opacity: 0;
            pointer-events: none;
            transform: translateY(-8px);
        }

        .tv-btn {
            background: transparent;
            border: none;
            color: #f8fafc;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            padding: 8px 12px;
            border-radius: 20px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .tv-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-1px);
        }

        .tv-btn-primary {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35);
        }

        .tv-btn-primary:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }

        /* Settings Drawer Modal */
        .settings-drawer {
            position: fixed;
            top: 0;
            right: -420px;
            width: 400px;
            max-width: 90vw;
            height: 100vh;
            background: #ffffff;
            color: #111827;
            box-shadow: -10px 0 35px rgba(0,0,0,0.3);
            z-index: 100000;
            transition: right 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            padding: 24px;
            box-sizing: border-box;
            overflow-y: auto;
        }

        [data-tv-theme="dark"] .settings-drawer {
            background: #111827;
            color: #f8fafc;
            border-left: 1px solid #1f2937;
        }

        .settings-drawer.open {
            right: 0;
        }

        .settings-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(4px);
            z-index: 99999;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
        }

        .settings-backdrop.open {
            opacity: 1;
            pointer-events: auto;
        }

        .drawer-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 16px;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 20px;
        }

        [data-tv-theme="dark"] .drawer-header {
            border-bottom-color: #374151;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: var(--txt-main);
        }

        .form-group select, .form-group input {
            width: 100%;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #111827;
            font-family: inherit;
            font-size: 14px;
            box-sizing: border-box;
        }

        [data-tv-theme="dark"] .form-group select,
        [data-tv-theme="dark"] .form-group input {
            background: #1f2937;
            border-color: #374151;
            color: #f8fafc;
        }

        .form-switch-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
        }

        /* Number Increment Pulse Animation */
        .digit-cell.pop {
            animation: digit-pop 0.3s ease;
        }

        @keyframes digit-pop {
            0% { transform: scale(1); }
            50% { transform: scale(1.08); }
            100% { transform: scale(1); }
        }

        /* Responsive breakpoints for smaller laptop/tablets */
        @media (max-width: 900px) {
            .counters-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .tv-screen {
                overflow-y: auto;
                height: auto;
                min-height: 100vh;
                padding: 24px 16px;
            }
            html, body {
                overflow: auto;
            }
        }
    </style>
</head>
<body data-tv-theme="light">

    <!-- Floating TV Controls Bar -->
    <div class="tv-controls-floating" id="floatingControls">
        <a href="admin_dashboard.php" class="tv-btn" title="Back to Admin Dashboard">
            <span>🛡️</span> <span>Admin</span>
        </a>
        <button type="button" class="tv-btn tv-btn-primary" id="btnFullscreen" onclick="toggleFullscreen()" title="Toggle Fullscreen (F11)">
            <span id="fsIcon">⛶</span> <span id="fsLabel">Full Screen</span>
        </button>
        <button type="button" class="tv-btn" onclick="toggleTheme()" title="Toggle Light / Dark Mode">
            <span id="themeIcon">🌙</span>
        </button>
        <button type="button" class="tv-btn" onclick="fetchLiveStats()" title="Refresh Now">
            <span>🔄</span>
        </button>
        <button type="button" class="tv-btn" onclick="openSettings()" title="TV Settings">
            <span>⚙️</span>
        </button>
    </div>

    <!-- Main TV Screen Canvas -->
    <div class="tv-screen">
        
        <!-- Header -->
        <header class="tv-header">
            <div class="brand-block">
                <h1 class="brand-name">KERJA</h1>
                <div class="brand-milestones">MILESTONES</div>
            </div>

            <div class="brand-logo-wrap">
                <img src="logo/keria.jpeg" alt="Kerja Logo" class="brand-logo-img">
            </div>
        </header>

        <!-- Body / Counters Grid -->
        <main class="tv-body">
            <div class="counters-grid">

                <!-- 1. REGISTERED -->
                <div class="counter-card" id="cardRegistered">
                    <div class="counter-title">REGISTERED</div>
                    
                    <div class="chassis-box-wrap">
                        <svg class="spark-corner spark-top-right" viewBox="0 0 30 30">
                            <line x1="15" y1="15" x2="27" y2="5" />
                            <line x1="15" y1="15" x2="29" y2="15" />
                            <line x1="15" y1="15" x2="22" y2="26" />
                        </svg>

                        <svg class="spark-corner spark-bottom-left" viewBox="0 0 30 30">
                            <line x1="15" y1="15" x2="3" y2="15" />
                            <line x1="15" y1="15" x2="6" y2="26" />
                        </svg>

                        <div class="chassis-box" id="displayRegistered"></div>
                    </div>

                    <div class="counter-footer">
                        <svg class="spark-rays" viewBox="0 0 30 30">
                            <line x1="24" y1="15" x2="4" y2="6" />
                            <line x1="24" y1="15" x2="2" y2="15" />
                            <line x1="24" y1="15" x2="6" y2="24" />
                        </svg>

                        <span class="new-badge-text" id="badgeRegisteredNew">+<?= (int)$real_users_new ?> new</span>

                        <svg class="spark-rays" viewBox="0 0 30 30">
                            <line x1="6" y1="15" x2="26" y2="6" />
                            <line x1="6" y1="15" x2="28" y2="15" />
                            <line x1="6" y1="15" x2="24" y2="24" />
                        </svg>
                    </div>
                </div>

                <!-- 2. RESUME -->
                <div class="counter-card" id="cardResume">
                    <div class="counter-title">RESUME</div>

                    <div class="chassis-box-wrap">
                        <svg class="spark-corner spark-top-right" viewBox="0 0 30 30">
                            <line x1="15" y1="15" x2="25" y2="7" />
                            <line x1="15" y1="15" x2="28" y2="17" />
                        </svg>

                        <svg class="spark-corner spark-bottom-left" viewBox="0 0 30 30">
                            <line x1="15" y1="15" x2="4" y2="24" />
                            <line x1="15" y1="15" x2="2" y2="14" />
                        </svg>

                        <div class="chassis-box" id="displayResume"></div>
                    </div>

                    <div class="counter-footer">
                        <svg class="spark-rays" viewBox="0 0 30 30">
                            <line x1="24" y1="15" x2="4" y2="6" />
                            <line x1="24" y1="15" x2="2" y2="15" />
                            <line x1="24" y1="15" x2="6" y2="24" />
                        </svg>

                        <span class="new-badge-text" id="badgeResumeNew">+<?= (int)$real_resumes_new ?> new</span>

                        <svg class="spark-rays" viewBox="0 0 30 30">
                            <line x1="6" y1="15" x2="26" y2="6" />
                            <line x1="6" y1="15" x2="28" y2="15" />
                            <line x1="6" y1="15" x2="24" y2="24" />
                        </svg>
                    </div>
                </div>

                <!-- 3. JOB POSTS -->
                <div class="counter-card" id="cardJobs">
                    <div class="counter-title">JOB POSTS</div>

                    <div class="chassis-box-wrap">
                        <svg class="spark-corner spark-top-right" viewBox="0 0 30 30">
                            <line x1="15" y1="15" x2="27" y2="5" />
                            <line x1="15" y1="15" x2="29" y2="15" />
                        </svg>

                        <svg class="spark-corner spark-bottom-left" viewBox="0 0 30 30">
                            <line x1="15" y1="15" x2="3" y2="25" />
                        </svg>

                        <div class="chassis-box" id="displayJobs"></div>
                    </div>

                    <div class="counter-footer">
                        <svg class="spark-rays" viewBox="0 0 30 30">
                            <line x1="24" y1="15" x2="4" y2="6" />
                            <line x1="24" y1="15" x2="2" y2="15" />
                            <line x1="24" y1="15" x2="6" y2="24" />
                        </svg>

                        <span class="new-badge-text" id="badgeJobsNew">+<?= (int)$real_jobs_new ?> new</span>

                        <svg class="spark-rays" viewBox="0 0 30 30">
                            <line x1="6" y1="15" x2="26" y2="6" />
                            <line x1="6" y1="15" x2="28" y2="15" />
                            <line x1="6" y1="15" x2="24" y2="24" />
                        </svg>
                    </div>
                </div>

            </div>

            <!-- Subtitle: LIVE COUNTERS -->
            <div class="live-counters-title">LIVE COUNTERS</div>
        </main>

        <!-- Footer -->
        <footer class="tv-footer">
            <div class="live-time-indicator">
                <span>TIME:</span>
                <strong id="liveTimeClock">4:20 PM</strong>
                <span class="live-badge">(Live)</span>
                <span class="live-dot"></span>
            </div>

            <div class="powered-by">
                Powered by aivantage
            </div>
        </footer>

    </div>

    <!-- Backdrop for Settings Drawer -->
    <div class="settings-backdrop" id="settingsBackdrop" onclick="closeSettings()"></div>

    <!-- TV Settings Drawer -->
    <div class="settings-drawer" id="settingsDrawer">
        <div class="drawer-header">
            <h2 style="font-size:18px; font-weight:800; display:flex; align-items:center; gap:8px;">
                <span>⚙️</span> <span>TV Display Settings</span>
            </h2>
            <button type="button" onclick="closeSettings()" style="background:none; border:none; font-size:22px; cursor:pointer; color:inherit;">✕</button>
        </div>

        <div class="form-group">
            <label for="cfgMode">Data Source Mode</label>
            <select id="cfgMode" onchange="handleModeChange()">
                <option value="real" selected>Pure Real Database Counts (Live MySQL)</option>
                <option value="milestone">Milestone Showcase (Photo Match: 145k / 92k / 28k)</option>
                <option value="hybrid">Milestone Baseline + Real Database Activity</option>
                <option value="custom">Custom Fixed Baseline</option>
            </select>
            <p style="font-size:11px; color:var(--txt-muted); margin-top:4px;">
                Currently displaying real live counts queried directly from your database.
            </p>
        </div>

        <div id="customInputsGroup" style="display:none; padding:12px; background:rgba(0,0,0,0.03); border-radius:10px; margin-bottom:18px;">
            <div class="form-group">
                <label>Registered Count Base</label>
                <input type="number" id="cfgRegBase" value="<?= (int)$real_users ?>">
            </div>
            <div class="form-group">
                <label>Resume Count Base</label>
                <input type="number" id="cfgResBase" value="<?= (int)$real_resumes ?>">
            </div>
            <div class="form-group">
                <label>Job Posts Base</label>
                <input type="number" id="cfgJobBase" value="<?= (int)$real_jobs ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="cfgInterval">Auto-Refresh Frequency</label>
            <select id="cfgInterval">
                <option value="3000">Every 3 Seconds</option>
                <option value="5000" selected>Every 5 Seconds (Recommended)</option>
                <option value="10000">Every 10 Seconds</option>
                <option value="30000">Every 30 Seconds</option>
                <option value="60000">Every 1 Minute</option>
            </select>
        </div>

        <div class="form-switch-row">
            <div>
                <strong style="font-size:13px;">Sound on Increment</strong>
                <p style="font-size:11px; color:var(--txt-muted);">Plays a gentle digital tick when counts increase</p>
            </div>
            <input type="checkbox" id="cfgSound" style="width:20px; height:20px; cursor:pointer;">
        </div>

        <div class="form-switch-row">
            <div>
                <strong style="font-size:13px;">Auto-hide Mouse Cursor</strong>
                <p style="font-size:11px; color:var(--txt-muted);">Hides cursor after 3s of inactivity on TV</p>
            </div>
            <input type="checkbox" id="cfgHideCursor" checked style="width:20px; height:20px; cursor:pointer;">
        </div>

        <div style="margin-top:auto; padding-top:20px; display:flex; flex-direction:column; gap:10px;">
            <button type="button" onclick="saveSettings()" class="tv-btn tv-btn-primary" style="justify-content:center; padding:12px; font-size:14px;">
                ✓ Save & Apply Settings
            </button>
            <button type="button" onclick="toggleFullscreen(); closeSettings();" class="tv-btn" style="justify-content:center; border:1px solid #d1d5db; color:inherit; padding:10px;">
                ⛶ Launch Full Screen TV
            </button>
        </div>
    </div>

    <!-- Audio Synthesis & Display Script -->
    <script>
        // ----------------------------------------------------
        // 1. 7-SEGMENT DISPLAY SVG DEFINITION & MAPPING
        // ----------------------------------------------------
        const SEGMENTS = {
            '0': ['a', 'b', 'c', 'd', 'e', 'f'],
            '1': ['b', 'c'],
            '2': ['a', 'b', 'g', 'e', 'd'],
            '3': ['a', 'b', 'g', 'c', 'd'],
            '4': ['f', 'g', 'b', 'c'],
            '5': ['a', 'f', 'g', 'c', 'd'],
            '6': ['a', 'f', 'e', 'd', 'c', 'g'],
            '7': ['a', 'b', 'c'],
            '8': ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
            '9': ['a', 'b', 'c', 'd', 'f', 'g'],
            ' ': []
        };

        // Generates an SVG 7-segment digit cell
        function createDigitSvg(digitChar) {
            const activeSegs = SEGMENTS[digitChar] || [];
            const isA = activeSegs.includes('a') ? 'on' : '';
            const isB = activeSegs.includes('b') ? 'on' : '';
            const isC = activeSegs.includes('c') ? 'on' : '';
            const isD = activeSegs.includes('d') ? 'on' : '';
            const isE = activeSegs.includes('e') ? 'on' : '';
            const isF = activeSegs.includes('f') ? 'on' : '';
            const isG = activeSegs.includes('g') ? 'on' : '';

            return `
                <div class="digit-cell" data-digit="${digitChar}">
                    <svg viewBox="0 0 54 86" class="seg-digit-svg">
                        <!-- a: Top -->
                        <polygon points="11,5 43,5 37,12 17,12" class="seg ${isA}" data-seg="a" />
                        <!-- b: Top Right -->
                        <polygon points="43,6 49,11 43,41 37,36 37,13" class="seg ${isB}" data-seg="b" />
                        <!-- c: Bottom Right -->
                        <polygon points="43,45 49,50 43,80 37,73 37,50" class="seg ${isC}" data-seg="c" />
                        <!-- d: Bottom -->
                        <polygon points="17,74 37,74 42,81 12,81" class="seg ${isD}" data-seg="d" />
                        <!-- e: Bottom Left -->
                        <polygon points="17,50 17,73 11,80 5,50 11,45" class="seg ${isE}" data-seg="e" />
                        <!-- f: Top Left -->
                        <polygon points="17,13 17,36 11,41 5,11 11,6" class="seg ${isF}" data-seg="f" />
                        <!-- g: Middle -->
                        <polygon points="12,43 17,39 37,39 42,43 37,47 17,47" class="seg ${isG}" data-seg="g" />
                    </svg>
                </div>
            `;
        }

        // Generates an SVG comma separator cell
        function createCommaSvg() {
            return `
                <div class="comma-cell">
                    <svg viewBox="0 0 16 86" class="seg-comma-svg">
                        <path d="M10,68 C12,68 13.5,69.5 13.5,72 C13.5,75 11.5,80 6.5,82 C6,82.5 5.5,82 5.5,81 C7,79 8,76.5 8,75 C7,75 6,74 6,72 C6,69.5 7.5,68 10,68 Z" class="seg-comma-glow" />
                    </svg>
                </div>
            `;
        }

        // Render full number into container with commas
        function renderNumberToChassis(containerId, numberVal) {
            const container = document.getElementById(containerId);
            if (!container) return;

            const formattedStr = Number(numberVal).toLocaleString('en-US');
            let html = '';

            for (let i = 0; i < formattedStr.length; i++) {
                const char = formattedStr[i];
                if (char === ',') {
                    html += createCommaSvg();
                } else if (!isNaN(parseInt(char, 10))) {
                    html += createDigitSvg(char);
                }
            }

            // Only update DOM if HTML changed
            if (container.dataset.lastVal !== formattedStr) {
                container.innerHTML = html;
                container.dataset.lastVal = formattedStr;

                // Subtle pop animation on cells
                container.querySelectorAll('.digit-cell').forEach(cell => {
                    cell.classList.add('pop');
                    setTimeout(() => cell.classList.remove('pop'), 300);
                });
            }
        }

        // ----------------------------------------------------
        // 2. STATE & SETTINGS MANAGEMENT (REAL DATA DEFAULT)
        // ----------------------------------------------------
        let currentStats = {
            registered: <?= (int)$real_users ?>,
            registered_new: <?= (int)$real_users_new ?>,
            resume: <?= (int)$real_resumes ?>,
            resume_new: <?= (int)$real_resumes_new ?>,
            jobs: <?= (int)$real_jobs ?>,
            jobs_new: <?= (int)$real_jobs_new ?>
        };

        let appSettings = {
            mode: 'real', // Pure real database counts
            interval: 5000,
            sound: false,
            simulate: false,
            theme: 'light',
            regBase: <?= (int)$real_users ?>,
            resBase: <?= (int)$real_resumes ?>,
            jobBase: <?= (int)$real_jobs ?>
        };

        // Load saved settings from localStorage
        try {
            const saved = localStorage.getItem('kerja_tv_settings_v3');
            if (saved) {
                appSettings = Object.assign(appSettings, JSON.parse(saved));
            }
        } catch (e) {}

        // Apply loaded theme
        document.body.setAttribute('data-tv-theme', appSettings.theme || 'light');
        updateThemeIcon();

        // ----------------------------------------------------
        // 3. AUDIO FEEDBACK (Synthesizer via Web Audio API)
        // ----------------------------------------------------
        let audioCtx = null;
        function playTickSound() {
            if (!appSettings.sound) return;
            try {
                if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                if (audioCtx.state === 'suspended') audioCtx.resume();

                const osc = audioCtx.createOscillator();
                const gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, audioCtx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(1760, audioCtx.currentTime + 0.05);

                gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.08);

                osc.connect(gain);
                gain.connect(audioCtx.destination);

                osc.start();
                osc.stop(audioCtx.currentTime + 0.09);
            } catch (e) {}
        }

        // ----------------------------------------------------
        // 4. DATA FETCHING & LIVE UPDATES FROM DATABASE
        // ----------------------------------------------------
        let fetchTimer = null;

        async function fetchLiveStats() {
            try {
                const modeParam = appSettings.mode || 'real';
                const res = await fetch(`api_live_counter.php?mode=${encodeURIComponent(modeParam)}&_t=${Date.now()}`);
                if (!res.ok) throw new Error('Network error');
                const data = await res.json();

                let newRegistered = data.registered ?? currentStats.registered;
                let newResume = data.resume ?? currentStats.resume;
                let newJobs = data.jobs ?? currentStats.jobs;
                let newRegNew = data.registered_new ?? currentStats.registered_new;
                let newResNew = data.resume_new ?? currentStats.resume_new;
                let newJobsNew = data.jobs_new ?? currentStats.jobs_new;

                if (appSettings.mode === 'custom') {
                    newRegistered = appSettings.regBase;
                    newResume = appSettings.resBase;
                    newJobs = appSettings.jobBase;
                }

                // Check if numbers increased for sound effect
                if (newRegistered > currentStats.registered || newResume > currentStats.resume || newJobs > currentStats.jobs) {
                    playTickSound();
                }

                currentStats.registered = newRegistered;
                currentStats.resume = newResume;
                currentStats.jobs = newJobs;
                currentStats.registered_new = newRegNew;
                currentStats.resume_new = newResNew;
                currentStats.jobs_new = newJobsNew;

                renderAll();

            } catch (err) {
                console.warn('Live API poll fallback:', err);
                renderAll();
            }
        }

        function renderAll() {
            renderNumberToChassis('displayRegistered', currentStats.registered);
            renderNumberToChassis('displayResume', currentStats.resume);
            renderNumberToChassis('displayJobs', currentStats.jobs);

            document.getElementById('badgeRegisteredNew').innerText = `+${currentStats.registered_new} new`;
            document.getElementById('badgeResumeNew').innerText = `+${currentStats.resume_new} new`;
            document.getElementById('badgeJobsNew').innerText = `+${currentStats.jobs_new} new`;
        }

        // ----------------------------------------------------
        // 5. CLOCK UPDATER (Real-Time Ticking)
        // ----------------------------------------------------
        function updateClock() {
            const now = new Date();
            let hours = now.getHours();
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12;
            hours = hours ? hours : 12;
            const timeStr = `${hours}:${minutes} ${ampm}`;
            const clockEl = document.getElementById('liveTimeClock');
            if (clockEl) clockEl.innerText = timeStr;
        }

        // ----------------------------------------------------
        // 6. FULLSCREEN FUNCTIONALITY (Optimized for TV)
        // ----------------------------------------------------
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                const elem = document.documentElement;
                if (elem.requestFullscreen) {
                    elem.requestFullscreen();
                } else if (elem.webkitRequestFullscreen) {
                    elem.webkitRequestFullscreen();
                } else if (elem.msRequestFullscreen) {
                    elem.msRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                } else if (document.msExitFullscreen) {
                    document.msExitFullscreen();
                }
            }
        }

        function updateFullscreenButtonState() {
            const isFs = !!document.fullscreenElement;
            const icon = document.getElementById('fsIcon');
            const label = document.getElementById('fsLabel');
            if (icon && label) {
                icon.innerText = isFs ? '✕' : '⛶';
                label.innerText = isFs ? 'Exit TV Mode' : 'Full Screen';
            }
        }

        document.addEventListener('fullscreenchange', updateFullscreenButtonState);
        document.addEventListener('webkitfullscreenchange', updateFullscreenButtonState);

        // ----------------------------------------------------
        // 7. AUTO-HIDE CONTROLS & CURSOR FOR TV VIEWING
        // ----------------------------------------------------
        let hideControlsTimeout = null;
        const floatingControls = document.getElementById('floatingControls');

        function resetControlsTimer() {
            floatingControls.classList.remove('faded');
            document.body.classList.remove('hide-cursor');
            clearTimeout(hideControlsTimeout);

            hideControlsTimeout = setTimeout(() => {
                floatingControls.classList.add('faded');
                if (document.fullscreenElement || document.getElementById('cfgHideCursor')?.checked) {
                    document.body.classList.add('hide-cursor');
                }
            }, 3000);
        }

        window.addEventListener('mousemove', resetControlsTimer);
        window.addEventListener('touchstart', resetControlsTimer);
        window.addEventListener('keydown', (e) => {
            resetControlsTimer();
            if (e.key === 'f' || e.key === 'F') {
                toggleFullscreen();
            } else if (e.key === 's' || e.key === 'S') {
                openSettings();
            } else if (e.key === 'd' || e.key === 'D') {
                toggleTheme();
            } else if (e.key === 'r' || e.key === 'R') {
                fetchLiveStats();
            }
        });

        // ----------------------------------------------------
        // 8. SCREEN WAKE LOCK (Prevents TV from sleeping)
        // ----------------------------------------------------
        async function requestWakeLock() {
            if ('wakeLock' in navigator) {
                try {
                    await navigator.wakeLock.request('screen');
                } catch (err) {}
            }
        }
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') requestWakeLock();
        });
        requestWakeLock();

        // ----------------------------------------------------
        // 9. THEME TOGGLE (Light TV vs Ultra Dark Mode)
        // ----------------------------------------------------
        function toggleTheme() {
            const current = document.body.getAttribute('data-tv-theme');
            const newTheme = current === 'dark' ? 'light' : 'dark';
            document.body.setAttribute('data-tv-theme', newTheme);
            appSettings.theme = newTheme;
            try {
                localStorage.setItem('kerja_tv_settings_v3', JSON.stringify(appSettings));
            } catch (e) {}
            updateThemeIcon();
        }

        function updateThemeIcon() {
            const current = document.body.getAttribute('data-tv-theme');
            const icon = document.getElementById('themeIcon');
            if (icon) icon.innerText = current === 'dark' ? '☀️' : '🌙';
        }

        // ----------------------------------------------------
        // 10. SETTINGS DRAWER
        // ----------------------------------------------------
        function openSettings() {
            document.getElementById('cfgMode').value = appSettings.mode || 'real';
            document.getElementById('cfgInterval').value = String(appSettings.interval || 5000);
            document.getElementById('cfgSound').checked = !!appSettings.sound;
            document.getElementById('cfgRegBase').value = appSettings.regBase || currentStats.registered;
            document.getElementById('cfgResBase').value = appSettings.resBase || currentStats.resume;
            document.getElementById('cfgJobBase').value = appSettings.jobBase || currentStats.jobs;
            handleModeChange();

            document.getElementById('settingsDrawer').classList.add('open');
            document.getElementById('settingsBackdrop').classList.add('open');
        }

        function closeSettings() {
            document.getElementById('settingsDrawer').classList.remove('open');
            document.getElementById('settingsBackdrop').classList.remove('open');
        }

        function handleModeChange() {
            const mode = document.getElementById('cfgMode').value;
            const customGroup = document.getElementById('customInputsGroup');
            customGroup.style.display = (mode === 'custom') ? 'block' : 'none';
        }

        function saveSettings() {
            appSettings.mode = document.getElementById('cfgMode').value;
            appSettings.interval = parseInt(document.getElementById('cfgInterval').value, 10) || 5000;
            appSettings.sound = document.getElementById('cfgSound').checked;
            appSettings.regBase = parseInt(document.getElementById('cfgRegBase').value, 10) || currentStats.registered;
            appSettings.resBase = parseInt(document.getElementById('cfgResBase').value, 10) || currentStats.resume;
            appSettings.jobBase = parseInt(document.getElementById('cfgJobBase').value, 10) || currentStats.jobs;

            try {
                localStorage.setItem('kerja_tv_settings_v3', JSON.stringify(appSettings));
            } catch (e) {}

            closeSettings();

            // Restart polling with new interval
            clearInterval(fetchTimer);
            fetchTimer = setInterval(fetchLiveStats, appSettings.interval);

            fetchLiveStats();
        }

        // ----------------------------------------------------
        // INITIALIZATION
        // ----------------------------------------------------
        updateClock();
        setInterval(updateClock, 1000);

        renderAll();
        fetchLiveStats();

        fetchTimer = setInterval(fetchLiveStats, appSettings.interval || 5000);

        resetControlsTimer();

        // Check if URL has ?fullscreen=1
        if (new URLSearchParams(window.location.search).get('fullscreen') === '1') {
            window.addEventListener('click', () => {
                if (!document.fullscreenElement) toggleFullscreen();
            }, { once: true });
        }
    </script>
</body>
</html>
