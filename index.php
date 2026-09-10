<?php
session_start();

$total_live_roles = 0;
$fresh_jobs = [];
$live_jobs = [];

$resumes_screened = 0;
$strong_hires = 0;
$departments_hiring = 0;

if (!function_exists('formatTimeAgo')) {
    function formatTimeAgo($datetime) {
        if (empty($datetime)) return 'Recently';
        $time = strtotime($datetime);
        if (!$time) return 'Recently';
        $diff = time() - $time;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return max(1, floor($diff / 60)) . 'm ago';
        if ($diff < 86400) return max(1, floor($diff / 3600)) . 'h ago';
        if ($diff < 604800) return max(1, floor($diff / 86400)) . 'd ago';
        return date('M j', $time);
    }
}

if (!function_exists('getCompanyLogoHtml')) {
    function getCompanyLogoHtml($employer_name, $company_logo) {
        if (!empty($company_logo) && file_exists(__DIR__ . '/' . $company_logo)) {
            return '<div class="live-job-logo-box" style="background:#FFFFFF; border:1px solid #E5E7EB; padding:2px;"><img src="' . htmlspecialchars($company_logo) . '" alt="' . htmlspecialchars($employer_name) . '" style="width:100%; height:100%; object-fit:contain; border-radius:11px;"></div>';
        }
        $name_clean = trim($employer_name ?? 'Company');
        $lower = strtolower($name_clean);
        if (strpos($lower, 'maybank') !== false) {
            return '<div class="live-job-logo-box logo-maybank"><svg viewBox="0 0 40 40" width="30" height="30" fill="none"><circle cx="20" cy="20" r="18" fill="#FFC800"/><path d="M12 26 C12 22, 16 16, 22 15 C26 14, 28 17, 30 18 C31 16, 32 14, 30 12 C26 10, 20 12, 16 15 C13 18, 11 22, 12 26 Z" fill="#1A1A1A"/><circle cx="24" cy="18" r="1.8" fill="#FFC800"/><path d="M18 22 C22 21, 26 23, 28 25" stroke="#1A1A1A" stroke-width="2" stroke-linecap="round"/></svg></div>';
        }
        if (strpos($lower, 'shopee') !== false) {
            return '<div class="live-job-logo-box logo-shopee"><svg viewBox="0 0 36 36" width="26" height="26" fill="none"><path d="M10 13 C10 8.5, 14 8.5, 18 8.5 C22 8.5, 26 8.5, 26 13 L28 27 C28 29, 26 30, 24 30 L12 30 C10 30, 8 29, 8 27 L10 13 Z" fill="#FFFFFF"/><path d="M14 13 C14 10.5, 15.8 8.5, 18 8.5 C20.2 8.5, 22 10.5, 22 13" stroke="#EE4D2D" stroke-width="2" stroke-linecap="round"/><path d="M19.5 17 C17 16.5, 16 17.5, 16 19 C16 21, 20.5 21, 20.5 23 C20.5 24.5, 19.5 25.5, 17 25.5 C15.5 25.5, 15 25, 15 25" stroke="#EE4D2D" stroke-width="2.2" stroke-linecap="round"/></svg></div>';
        }
        if (strpos($lower, 'grab') !== false) {
            return '<div class="live-job-logo-box logo-grab"><span style="color:#00B14F; font-weight:900; font-size:15px; letter-spacing:-0.5px; font-family:sans-serif;">Grab</span></div>';
        }
        if (strpos($lower, 'airasia') !== false) {
            return '<div class="live-job-logo-box logo-airasia"><span style="color:#ED1C24; font-weight:900; font-style:italic; font-size:14px; font-family:serif;">AirAsia</span></div>';
        }
        if (strpos($lower, 'google') !== false) {
            return '<div class="live-job-logo-box logo-google"><svg viewBox="0 0 24 24" width="24" height="24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/></svg></div>';
        }

        // Color palette for custom company names
        $colors = ['#2563EB', '#059669', '#7C3AED', '#D97706', '#DB2777', '#0891B2', '#4F46E5', '#16A34A'];
        $idx = abs(crc32($name_clean)) % count($colors);
        $bg = $colors[$idx];
        $initial = mb_strtoupper(mb_substr($name_clean, 0, 1));

        return '<div class="live-job-logo-box" style="background:' . $bg . '; color:#FFFFFF; font-size:18px; font-weight:800; text-shadow:0 1px 2px rgba(0,0,0,0.2);">' . htmlspecialchars($initial) . '</div>';
    }
}

if (file_exists('db.php')) {
    try {
        require_once 'db.php';
        // Get total count of active roles
        $countStmt = $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'Active' OR status IS NULL");
        $total_live_roles = (int) $countStmt->fetchColumn();

        // Fetch latest active jobs for Section 3 with full employer info (exactly latest 5)
        $liveJobsStmt = $pdo->query("
            SELECT j.id, j.job_title, j.department, j.employment_type, j.work_mode, j.location, j.created_at, 
                   COALESCE(NULLIF(u.company_name, ''), u.name, 'Keria Employer') as employer_name, 
                   u.company_logo 
            FROM jobs j 
            LEFT JOIN users u ON j.employer_id = u.id 
            WHERE j.status = 'Active' OR j.status IS NULL 
            ORDER BY j.created_at DESC, j.id DESC 
            LIMIT 5
        ");
        $live_jobs = $liveJobsStmt->fetchAll(PDO::FETCH_ASSOC);
        $fresh_jobs = array_slice($live_jobs, 0, 3);

        // Stat bar numbers
        $resumes_screened = (int) $pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
        $strong_hires = (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE recommendation LIKE '%Hire%'")->fetchColumn();
        $departments_hiring = (int) $pdo->query("SELECT COUNT(DISTINCT department) FROM jobs WHERE department IS NOT NULL AND department <> ''")->fetchColumn();
    } catch (\Throwable $e) {
        // Fallback gracefully if database or table is not ready yet
        $total_live_roles = 0;
        $fresh_jobs = [];
        $live_jobs = [];
        $resumes_screened = 0;
        $strong_hires = 0;
        $departments_hiring = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>keria — good people, brighter days.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="assets/casey-2.png?v=<?php echo @filemtime(__DIR__.'/assets/casey-2.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="assets/casey-2.png?v=<?php echo @filemtime(__DIR__.'/assets/casey-2.png'); ?>">
    <link rel="apple-touch-icon" href="assets/casey-1.png?v=<?php echo @filemtime(__DIR__.'/assets/casey-1.png'); ?>">
    
    <style>
        :root {
            --keria-lime: #D2FF3A;
            --keria-lime-hover: #C2F025;
            --keria-lime-light: #EBFFAA;
            --keria-dark: #0A0A0A;
            --keria-charcoal: #1E1E1E;
            --keria-gray: #656565;
            --keria-bg: #FFFFFF;
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-hand: 'Caveat', cursive;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--keria-bg);
            color: var(--keria-dark);
            overflow-x: hidden;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* ================= HERO WRAPPER ================= */
        .keria-hero-wrapper {
            position: relative;
            min-height: 100vh;
            width: 100%;
            background-color: #F8F9FA;
            background-image: url('assets/keria_hero_bg.jpg?v=<?php echo @filemtime(__DIR__.'/assets/keria_hero_bg.jpg'); ?>');
            background-size: cover;
            background-position: center top;
            background-repeat: no-repeat;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            overflow: hidden;
        }

        /* Subtle dark gradient overlay for optimal readability */
        .keria-hero-wrapper::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(
                90deg,
                rgba(255, 255, 255, 0.45) 0%,
                rgba(255, 255, 255, 0.20) 45%,
                rgba(0, 0, 0, 0.05) 100%
            );
            pointer-events: none;
            z-index: 1;
        }

        /* ================= NAVBAR ================= */
        .keria-navbar {
            position: relative;
            z-index: 50;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 48px;
            width: 100%;
            max-width: 100%;
            margin: 0;
            border-radius: 0;
            box-sizing: border-box;
        }

        .keria-logo {
            display: flex;
            align-items: center;
            text-decoration: none;
            position: relative;
            transition: transform 0.2s ease;
            background: transparent;
        }

        .keria-logo:hover {
            transform: scale(1.02);
        }

        .keria-logo-img {
            height: 44px;
            width: auto;
            object-fit: contain;
            display: block;
            mix-blend-mode: multiply;
            background: transparent;
        }

        .keria-nav-center {
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .keria-nav-link {
            color: #2D2D2D;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            transition: color 0.2s ease, transform 0.2s ease;
            position: relative;
        }

        .keria-nav-link:hover {
            color: #000000;
            transform: translateY(-1px);
        }

        .keria-nav-link.active {
            color: #000000;
            font-weight: 700;
        }

        .keria-nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .btn-round-search {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.7);
            color: #1A1A1A;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-round-search:hover {
            background: #FFFFFF;
            transform: scale(1.06);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
        }

        .btn-nav-login {
            padding: 11px 26px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.90);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            color: #1A1A1A;
            font-size: 14.5px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-nav-login:hover {
            background: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.1);
        }

        .btn-nav-signup {
            padding: 11px 26px;
            border-radius: 999px;
            background: var(--keria-lime);
            color: #0A0A0A;
            font-size: 14.5px;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 6px 18px rgba(210, 255, 58, 0.4);
            border: 1px solid rgba(0, 0, 0, 0.04);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-nav-signup:hover {
            background: var(--keria-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 26px rgba(210, 255, 58, 0.6);
        }

        /* ================= HERO CONTENT ================= */
        .keria-hero-main {
            position: relative;
            z-index: 10;
            max-width: 1540px;
            width: 100%;
            margin: 0 auto;
            padding: 40px 60px 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: calc(100vh - 120px);
        }

        .keria-hero-content-left {
            max-width: 680px;
            position: relative;
            z-index: 15;
        }

        .keria-hero-title {
            font-size: clamp(52px, 6.2vw, 84px);
            font-weight: 900;
            line-height: 1.04;
            letter-spacing: -2px;
            color: #0A0A0A;
            margin-bottom: 22px;
        }

        .keria-title-underline-wrap {
            position: relative;
            display: inline-block;
            white-space: nowrap;
        }

        .keria-brush-underline {
            position: absolute;
            left: 0;
            bottom: 4px;
            width: 102%;
            height: 18px;
            background: var(--keria-lime);
            border-radius: 12px;
            z-index: -1;
            transform: rotate(-0.5deg);
        }

        .keria-hero-sub {
            font-size: 20px;
            font-weight: 500;
            line-height: 1.45;
            color: #1F1F1F;
            margin-bottom: 24px;
            max-width: 480px;
        }

        /* Hero Quick Action Buttons */
        .hero-actions-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .btn-hero-resume-check {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 22px;
            background: #0A0A0A;
            color: #FFFFFF;
            font-size: 14.5px;
            font-weight: 700;
            border-radius: 999px;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.16);
            border: 1.5px solid rgba(255, 255, 255, 0.2);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-hero-resume-check:hover {
            transform: translateY(-2px);
            background: #1F1F1F;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.25);
            color: #FFFFFF;
        }

        .btn-hero-resume-check svg {
            color: var(--keria-lime);
        }

        .btn-pill-instant {
            background: var(--keria-lime);
            color: #0A0A0A;
            font-size: 11px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 999px;
            letter-spacing: 0.2px;
            margin-left: 2px;
        }

        .btn-hero-resume-builder {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.85);
            color: #1A1A1A;
            font-size: 14px;
            font-weight: 700;
            border-radius: 999px;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.05);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-hero-resume-builder:hover {
            background: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            border-color: #0A0A0A;
        }

        /* ================= FLOATING SEARCH BAR ================= */
        .keria-search-container {
            width: 100%;
            max-width: 620px;
            margin-bottom: 20px;
        }

        .keria-search-box {
            display: flex;
            align-items: center;
            background: #FFFFFF;
            border-radius: 999px;
            padding: 8px 10px 8px 20px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.12), 0 4px 12px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .keria-search-box:focus-within {
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.18), 0 0 0 3px rgba(210, 255, 58, 0.6);
            border-color: rgba(210, 255, 58, 0.8);
            transform: translateY(-2px);
        }

        .keria-search-input-group {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1.3;
        }

        .keria-search-input-group.location-group {
            flex: 1;
            padding-left: 14px;
            border-left: 1px solid #E5E7EB;
        }

        .keria-search-icon {
            color: #1A1A1A;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .keria-search-input {
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            font-family: var(--font-sans);
            font-size: 15px;
            font-weight: 500;
            color: #0A0A0A;
            padding: 8px 0;
        }

        .keria-search-input::placeholder {
            color: #8C8C8C;
            font-weight: 400;
        }

        .keria-search-btn-submit {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: var(--keria-lime);
            color: #0A0A0A;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            margin-left: 8px;
            box-shadow: 0 4px 12px rgba(210, 255, 58, 0.45);
        }

        .keria-search-btn-submit:hover {
            background: var(--keria-lime-hover);
            transform: scale(1.08) translateX(2px);
            box-shadow: 0 8px 20px rgba(210, 255, 58, 0.7);
        }

        /* ================= POPULAR SEARCHES PILLS ================= */
        .keria-popular-searches {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .keria-popular-label {
            font-size: 13.5px;
            font-weight: 600;
            color: rgba(26, 26, 26, 0.85);
            text-shadow: 0 1px 4px rgba(255, 255, 255, 0.8);
            margin-right: 4px;
        }

        .keria-tag-pill {
            display: inline-flex;
            align-items: center;
            padding: 6px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.75);
            color: #1A1A1A;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
        }

        .keria-tag-pill:hover {
            background: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.1);
            border-color: #0A0A0A;
        }

        /* ================= BOTTOM SECTIONS BASE ================= */
        .keria-bottom-section {
            background: #FFFFFF;
            position: relative;
            z-index: 20;
            padding: 30px 48px;
        }

        .keria-bottom-container {
            max-width: 1300px;
            margin: 0 auto;
        }

        /* ================= 1. HOW IT WORKS / MASCOT PROCESS CARD ================= */
        .keria-process-card {
            background: #F3F8EA;
            border: 1px solid #E2EBD6;
            border-radius: 28px;
            padding: 40px 48px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: space-between;
            overflow: hidden;
        }

        .keria-process-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 32px;
            flex: 1;
            max-width: 960px;
        }

        .keria-process-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: transform 0.25s ease;
        }

        .keria-process-step:hover {
            transform: translateY(-4px);
        }

        .keria-mascot-icon-wrap {
            width: 84px;
            height: 84px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .keria-mascot-svg {
            width: 100%;
            height: 100%;
            filter: drop-shadow(0 4px 10px rgba(0, 0, 0, 0.05));
        }

        .keria-process-title {
            font-size: 18px;
            font-weight: 800;
            color: #0A0A0A;
            margin-bottom: 4px;
        }

        .keria-process-desc {
            font-size: 13.5px;
            color: #555555;
            line-height: 1.4;
            max-width: 170px;
        }

        .keria-process-doodle {
            font-family: var(--font-hand);
            font-size: 26px;
            line-height: 1.15;
            color: #0A0A0A;
            text-align: center;
            transform: rotate(6deg);
            position: relative;
            user-select: none;
            padding-left: 20px;
        }

        .doodle-sparkle-rays {
            position: absolute;
            top: -12px;
            right: -16px;
            width: 32px;
            height: 32px;
        }

        /* ================= 2. POPULAR JOB CATEGORIES ================= */
        .keria-categories-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 28px;
        }

        .keria-section-pill-tag {
            font-size: 12px;
            font-weight: 800;
            color: #707070;
            letter-spacing: 1.5px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pill-dash {
            width: 16px;
            height: 4px;
            background: var(--keria-lime);
            border-radius: 999px;
            display: inline-block;
        }

        .keria-section-heading {
            font-size: 32px;
            font-weight: 900;
            letter-spacing: -0.8px;
            color: #0A0A0A;
        }

        .keria-view-all-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14.5px;
            font-weight: 700;
            color: #0A0A0A;
            text-decoration: none;
            transition: gap 0.2s ease, color 0.2s ease;
        }

        .keria-view-all-link:hover {
            gap: 10px;
            color: #5C7700;
        }

        .keria-categories-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 16px;
        }

        .category-card-item {
            background: #FFFFFF;
            border: 1px solid #EBECEF;
            border-radius: 20px;
            padding: 24px 12px;
            text-align: center;
            text-decoration: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.02);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .category-card-item:hover {
            transform: translateY(-5px);
            border-color: #0A0A0A;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.08);
        }

        .category-card-icon {
            font-size: 28px;
            margin-bottom: 12px;
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #F4F5F7;
            transition: transform 0.2s ease;
        }

        .category-card-item:hover .category-card-icon {
            transform: scale(1.1);
        }

        .category-card-name {
            font-size: 14px;
            font-weight: 800;
            color: #0A0A0A;
            margin-bottom: 4px;
        }

        .category-card-count {
            font-size: 12px;
            color: #7A7A7A;
            font-weight: 500;
        }

        /* Specific Icon Backgrounds */
        .cat-tech { background: #EAF2FF; }
        .cat-marketing { background: #FFF0EB; }
        .cat-finance { background: #E8F8F5; }
        .cat-design { background: #FDEEF4; }
        .cat-ops { background: #F0EDFF; }
        .cat-sales { background: #FFF7E6; }
        .cat-hr { background: #F3EDFF; }
        .cat-intern { background: #EBF3FF; }

        /* ================= 3. LIVE JOB POSTINGS SECTION ================= */
        .keria-live-jobs-card {
            background: #EDF6E8;
            border: 1px solid #DCECD5;
            border-radius: 32px;
            padding: 44px 48px;
            display: grid;
            grid-template-columns: 310px 1fr;
            gap: 48px;
            position: relative;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.03);
            overflow: visible;
        }

        /* Left Column */
        .live-jobs-left {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        .live-jobs-tag {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 1.5px;
            color: #2D3D20;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        .live-jobs-tag-bar {
            width: 22px;
            height: 5px;
            background: #A8E334;
            border-radius: 3px;
            display: inline-block;
        }

        .live-jobs-title-wrap {
            position: relative;
            display: inline-block;
            margin-bottom: 14px;
        }

        .live-jobs-title {
            font-family: 'Space Grotesk', var(--font-sans);
            font-size: 46px;
            font-weight: 900;
            line-height: 1.05;
            color: #0A0A0A;
            letter-spacing: -1.5px;
            margin: 0;
            display: inline-block;
        }

        .live-jobs-title-sparks {
            position: absolute;
            top: -12px;
            right: -24px;
            width: 32px;
            height: 32px;
            pointer-events: none;
        }

        .live-jobs-sub {
            font-size: 15px;
            line-height: 1.5;
            color: #384833;
            font-weight: 500;
            margin-bottom: 24px;
            max-width: 280px;
        }

        /* Mascot & Hand-drawn Doodle */
        .live-jobs-mascot-wrap {
            position: relative;
            margin-top: auto;
            display: flex;
            align-items: flex-end;
            justify-content: flex-start;
            padding-top: 30px;
        }

        .mascot-doodle-speech {
            position: absolute;
            top: -16px;
            right: 10px;
            font-family: var(--font-hand);
            font-size: 23px;
            line-height: 1.08;
            color: #0A0A0A;
            transform: rotate(2deg);
            user-select: none;
            text-align: center;
            z-index: 5;
        }

        .mascot-doodle-arrow {
            position: absolute;
            right: -22px;
            bottom: 4px;
            width: 38px;
            height: 24px;
        }

        .live-jobs-mascot-img {
            width: 220px;
            height: auto;
            display: block;
            filter: drop-shadow(0 12px 20px rgba(0, 0, 0, 0.08));
            transform: translateY(6px);
        }

        /* Right Column */
        .live-jobs-right {
            display: flex;
            flex-direction: column;
            gap: 12px;
            position: relative;
        }

        /* Filter Tabs Header */
        .live-jobs-filter-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 6px;
        }

        .live-jobs-filters {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-pill-btn {
            background: #DEEBD9;
            border: 1px solid transparent;
            color: #384A33;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            font-family: var(--font-sans);
        }

        .filter-pill-btn:hover {
            background: #D2E4CC;
            color: #0A0A0A;
        }

        .filter-pill-btn.active {
            background: #D2FF3A;
            color: #0A0A0A;
            font-weight: 800;
            box-shadow: 0 2px 8px rgba(166, 227, 52, 0.4);
        }

        .live-jobs-view-all-link {
            font-size: 14px;
            font-weight: 800;
            color: #0A0A0A;
            text-decoration: underline;
            text-underline-offset: 4px;
            text-decoration-color: #84CC16;
            text-decoration-thickness: 2px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        }

        .live-jobs-view-all-link:hover {
            color: #2D6A05;
            text-decoration-color: #2D6A05;
        }

        /* Job Card List Item */
        .live-job-item {
            background: #FFFFFF;
            border-radius: 18px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.02);
            border: 1px solid #E5EFE2;
            transition: all 0.2s ease;
        }

        .live-job-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
            border-color: #C8E3BE;
        }

        .live-job-item-left {
            display: flex;
            align-items: center;
            gap: 16px;
            min-width: 0;
        }

        .live-job-logo-box {
            width: 46px;
            height: 46px;
            border-radius: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            font-weight: 900;
        }

        .logo-maybank { background: #FFC800; }
        .logo-shopee { background: #EE4D2D; }
        .logo-grab { background: #FFFFFF; border: 1.5px solid #F0F0F0; }
        .logo-airasia { background: #FFFFFF; border: 1.5px solid #F0F0F0; }
        .logo-google { background: #FFFFFF; border: 1.5px solid #F0F0F0; }

        .live-job-info {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 0;
        }

        .live-job-item-title {
            font-size: 15px;
            font-weight: 800;
            color: #0A0A0A;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .live-job-item-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px 12px;
            font-size: 12.5px;
            color: #5A6655;
        }

        .live-job-company {
            font-weight: 600;
            color: #4A5645;
        }

        .live-job-location {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #6B7865;
            font-weight: 500;
        }

        .live-job-pill {
            background: #EDF5EA;
            color: #43543E;
            padding: 2px 10px;
            border-radius: 6px;
            font-size: 11.5px;
            font-weight: 600;
        }

        .live-job-item-right {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-shrink: 0;
        }

        .live-job-bookmark-btn {
            background: none;
            border: none;
            color: #0A0A0A;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            transition: color 0.2s;
        }

        .live-job-bookmark-btn:hover {
            color: #84CC16;
        }

        .live-job-new-badge {
            background: #D2FF3A;
            color: #0A0A0A;
            font-size: 11.5px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 999px;
            letter-spacing: 0.2px;
        }

        .live-job-time-ago {
            font-size: 12px;
            color: #72826E;
            font-weight: 600;
            min-width: 44px;
            text-align: right;
        }

        .live-job-chevron {
            color: #0A0A0A;
            font-size: 16px;
            display: flex;
            align-items: center;
            transition: transform 0.2s;
        }

        .live-job-item:hover .live-job-chevron {
            transform: translateX(3px);
        }

        /* Bottom Alert Banner */
        .live-job-alerts-banner {
            background: #EDF6E8;
            border: 1px solid #D5E8CE;
            border-radius: 20px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-top: 4px;
            position: relative;
        }

        .alert-banner-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .alert-bell-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #D8F5C4;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #1A2810;
        }

        .alert-banner-text-wrap {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .alert-banner-title {
            font-size: 14.5px;
            font-weight: 800;
            color: #0A0A0A;
        }

        .alert-banner-sub {
            font-size: 12px;
            color: #556650;
            font-weight: 500;
        }

        .btn-create-job-alert {
            background: #FFFFFF;
            border: 1.5px solid #0A0A0A;
            border-radius: 999px;
            padding: 9px 20px;
            font-size: 13px;
            font-weight: 800;
            color: #0A0A0A;
            text-decoration: none;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .btn-create-job-alert:hover {
            background: #0A0A0A;
            color: #FFFFFF;
            transform: translateY(-1px);
        }

        /* Bottom Right Doodle Outside Alert */
        .live-jobs-bottom-doodle {
            position: absolute;
            right: -32px;
            bottom: -8px;
            display: flex;
            align-items: center;
            gap: 8px;
            pointer-events: none;
            z-index: 10;
        }

        .live-jobs-doodle-text {
            font-family: var(--font-hand);
            font-size: 22px;
            line-height: 1.05;
            color: #0A0A0A;
            transform: rotate(-6deg);
            text-align: center;
        }

        .live-jobs-doodle-sparks {
            width: 28px;
            height: 28px;
        }

        /* ================= 4. BOTTOM CTA BANNER ================= */
        .keria-final-cta-card {
            border-radius: 32px;
            min-height: 380px;
            overflow: hidden;
            position: relative;
            box-shadow: 0 16px 48px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 48px 60px;
        }

        .final-cta-bg-wrap {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
        }

        .final-cta-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center bottom;
        }

        .final-cta-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, rgba(0, 0, 0, 0) 0%, rgba(255, 255, 255, 0.2) 35%, rgba(255, 255, 255, 0.65) 100%);
        }

        .final-cta-doodle-left {
            position: absolute;
            top: 36px;
            left: 48px;
            font-family: var(--font-hand);
            font-size: 26px;
            line-height: 1.15;
            color: #0A0A0A;
            transform: rotate(-5deg);
            user-select: none;
            z-index: 5;
            text-shadow: 0 1px 4px rgba(255, 255, 255, 0.8);
        }

        .final-cta-content {
            position: relative;
            z-index: 5;
            max-width: 480px;
            text-align: left;
            margin-right: 60px;
        }

        .final-cta-title {
            font-size: 40px;
            font-weight: 900;
            color: #0A0A0A;
            line-height: 1.1;
            margin-bottom: 12px;
            letter-spacing: -1px;
        }

        .final-cta-subtitle {
            font-size: 16px;
            color: #2D2D2D;
            line-height: 1.5;
            margin-bottom: 28px;
            font-weight: 500;
        }

        .final-cta-buttons {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .btn-final-lime {
            padding: 14px 30px;
            border-radius: 999px;
            background: var(--keria-lime);
            color: #0A0A0A;
            font-size: 15px;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(210, 255, 58, 0.45);
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-final-lime:hover {
            background: var(--keria-lime-hover);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(210, 255, 58, 0.65);
        }

        .btn-final-outline {
            padding: 14px 28px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1.5px solid #0A0A0A;
            color: #0A0A0A;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-final-outline:hover {
            background: #FFFFFF;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        .final-cta-doodle-right {
            position: absolute;
            top: 36px;
            right: 40px;
            font-family: var(--font-hand);
            font-size: 26px;
            line-height: 1.15;
            color: #0A0A0A;
            transform: rotate(4deg);
            user-select: none;
            z-index: 5;
            text-align: center;
        }

        .doodle-sparkle-rays-cta {
            position: absolute;
            bottom: -16px;
            right: 20px;
        }

        /* Footer */
        .keria-footer {
            background: #0A0A0A;
            color: #FFFFFF;
            padding: 60px 48px 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            position: relative;
            z-index: 20;
        }

        .footer-inner {
            max-width: 1300px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
            padding-bottom: 30px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .footer-bottom-copy {
            max-width: 1300px;
            margin: 24px auto 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            color: #707070;
        }

        /* ================= RESPONSIVE ================= */
        @media (max-width: 1100px) {
            .keria-navbar { padding: 20px 30px; }
            .keria-hero-main { padding: 30px 30px 60px; }
            .keria-categories-grid { grid-template-columns: repeat(4, 1fr); }
            .keria-process-card { flex-direction: column; text-align: center; gap: 24px; padding: 36px 28px; }
            .keria-process-grid { grid-template-columns: repeat(2, 1fr); max-width: 100%; }
            .keria-live-jobs-card { grid-template-columns: 1fr; gap: 32px; padding: 32px 28px; }
            .live-jobs-mascot-wrap { justify-content: center; padding-top: 10px; }
            .live-jobs-sub { max-width: 100%; }
            .live-jobs-bottom-doodle { display: none; }
            .final-cta-doodle-right { display: none; }
        }

        @media (max-width: 860px) {
            .keria-nav-center { display: none; }
            .keria-hero-wrapper {
                background-position: 70% top;
            }
            .keria-hero-title {
                font-size: 44px;
            }
            .keria-hero-sub {
                font-size: 16px;
            }
            .keria-search-box {
                flex-direction: column;
                border-radius: 20px;
                padding: 16px;
                gap: 12px;
            }
            .keria-search-input-group {
                width: 100%;
            }
            .keria-search-input-group.location-group {
                padding-left: 0;
                border-left: none;
                border-top: 1px solid #E5E7EB;
                padding-top: 10px;
            }
            .keria-search-btn-submit {
                width: 100%;
                border-radius: 14px;
                height: 44px;
                margin-left: 0;
                margin-top: 4px;
            }
            .keria-categories-grid { grid-template-columns: repeat(2, 1fr); }
            .live-job-item { flex-direction: column; align-items: flex-start; gap: 12px; }
            .live-job-item-right { width: 100%; justify-content: space-between; border-top: 1px solid #F0F4EE; padding-top: 10px; }
            .live-job-alerts-banner { flex-direction: column; align-items: flex-start; gap: 14px; }
            .btn-create-job-alert { width: 100%; text-align: center; }
            .final-cta-doodle-left { display: none; }
            .keria-final-cta-card { justify-content: center; padding: 40px 20px; }
            .final-cta-content { margin-right: 0; text-align: center; }
            .final-cta-buttons { justify-content: center; flex-wrap: wrap; }
            .keria-bottom-section { padding: 20px 18px; }
        }
    </style>
</head>
<body>

    <!-- Toast Notification if present -->
    <?php if(isset($_SESSION['toast'])): ?>
        <div style="position:fixed; top:20px; right:20px; z-index:3000; background:rgba(0, 232, 122, 0.9); border-radius:10px; padding:12px 20px; color:#0A0A0A; font-size:14px; font-weight:700; box-shadow:0 10px 30px rgba(0,0,0,0.15);">
            ✓ <?= htmlspecialchars($_SESSION['toast']) ?>
            <?php unset($_SESSION['toast']); ?>
        </div>
    <?php endif; ?>

    <!-- HERO SECTION WITH FULL IMAGE BACKDROP -->
    <div class="keria-hero-wrapper">
        
        <!-- TOP NAVBAR -->
        <header class="keria-navbar">
            <a href="index.php" class="keria-logo">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="keria" class="keria-logo-img">
            </a>

            <nav class="keria-nav-center">
                <a href="jobs.php" class="keria-nav-link active">Jobs</a>
                <a href="jobs.php" class="keria-nav-link">Companies</a>
                <a href="resume_check.php" class="keria-nav-link" style="color:#0A0A0A; font-weight:700;">✨ Resume Checker</a>
                <a href="resume_builder.php" class="keria-nav-link">Resume Builder</a>
                <a href="register.php" class="keria-nav-link">For Employers</a>
                <a href="#features" class="keria-nav-link">About</a>
            </nav>

            <div class="keria-nav-right">
                <a href="jobs.php" class="btn-round-search" title="Search Jobs" aria-label="Search">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </a>

                <?php if(isset($_SESSION['user_id'])): ?>
                    <span style="font-weight:700; font-size:14px; margin-right:4px;">Hi, <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                    <?php if($_SESSION['user_role'] === 'employer'): ?>
                        <a href="employer_dashboard.php" class="btn-nav-signup">Dashboard</a>
                    <?php elseif($_SESSION['user_role'] === 'admin'): ?>
                        <a href="admin_dashboard.php" class="btn-nav-signup">Admin</a>
                    <?php else: ?>
                        <a href="candidate_dashboard.php" class="btn-nav-signup">Dashboard</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn-nav-login" style="padding:10px 18px;">Log out</a>
                <?php else: ?>
                    <a href="login.php" class="btn-nav-login">Log in</a>
                    <a href="register.php" class="btn-nav-signup">Sign up</a>
                <?php endif; ?>
            </div>
        </header>

        <!-- MAIN HERO CONTENT & SEARCH -->
        <main class="keria-hero-main">
            <div class="keria-hero-content-left">
                
                <h1 class="keria-hero-title">
                    good people<br>
                    <span class="keria-title-underline-wrap">
                        brighter days.
                        <span class="keria-brush-underline"></span>
                    </span>
                </h1>

                <p class="keria-hero-sub">
                    Find opportunities that fit you.<br>
                    Build a future you're excited about.
                </p>

                <!-- HERO QUICK ACTION BUTTONS -->
                <div class="hero-actions-row">
                    <a href="resume_check.php" class="btn-hero-resume-check">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                        </svg>
                        <span>Free AI Resume Checker</span>
                        <span class="btn-pill-instant">Instant Score</span>
                    </a>
                    <a href="resume_builder.php" class="btn-hero-resume-builder">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                        </svg>
                        <span>Resume Builder</span>
                    </a>
                </div>

                <!-- INTERACTIVE PILL SEARCH BAR -->
                <form action="jobs.php" method="GET" class="keria-search-container">
                    <div class="keria-search-box">
                        <div class="keria-search-input-group">
                            <span class="keria-search-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                                </svg>
                            </span>
                            <input 
                                type="text" 
                                name="search" 
                                id="heroSearchInput"
                                class="keria-search-input" 
                                placeholder="Job title, keyword or company"
                                autocomplete="off"
                            >
                        </div>

                        <div class="keria-search-input-group location-group">
                            <span class="keria-search-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M12 21s-8-7.5-8-12a8 8 0 1 1 16 0c0 4.5-8 12-8 12z"></path>
                                    <circle cx="12" cy="9" r="3"></circle>
                                </svg>
                            </span>
                            <input 
                                type="text" 
                                name="location" 
                                id="heroLocationInput"
                                class="keria-search-input" 
                                placeholder="All locations"
                                autocomplete="off"
                            >
                        </div>

                        <button type="submit" class="keria-search-btn-submit" aria-label="Search">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                                <polyline points="12 5 19 12 12 19"></polyline>
                            </svg>
                        </button>
                    </div>

                    <!-- POPULAR SEARCHES PILLS -->
                    <div class="keria-popular-searches">
                        <span class="keria-popular-label">Popular searches:</span>
                        <a href="jobs.php?type=Internship" class="keria-tag-pill" onclick="quickFill('Internship');">Internship</a>
                        <a href="jobs.php?mode=Remote" class="keria-tag-pill" onclick="quickFill('Remote');">Remote</a>
                        <a href="jobs.php?search=Marketing" class="keria-tag-pill" onclick="quickFill('Marketing');">Marketing</a>
                        <a href="jobs.php?location=Kuala+Lumpur" class="keria-tag-pill" onclick="quickFillLocation('Kuala Lumpur');">Kuala Lumpur</a>
                        <a href="jobs.php?search=Fresh+Graduate" class="keria-tag-pill" onclick="quickFill('Fresh Graduate');">Fresh Graduate</a>
                    </div>
                </form>

            </div>
        </main>

    </div>

    <!-- ================= 1. HOW IT WORKS / MASCOT PROCESS STRIP ================= -->
    <section class="keria-bottom-section">
        <div class="keria-bottom-container">
            
            <div class="keria-process-card">
                <div class="keria-process-grid">
                    
                    <div class="keria-process-step">
                        <div class="keria-mascot-icon-wrap">
                            <!-- Mascot with magnifying glass -->
                            <svg viewBox="0 0 100 100" class="keria-mascot-svg">
                                <!-- Mascot Body -->
                                <path d="M45 25 C30 25, 25 45, 30 70 C33 85, 55 90, 60 75 C66 60, 62 25, 45 25 Z" fill="#D2FF3A" stroke="#0A0A0A" stroke-width="3" stroke-linejoin="round"/>
                                <!-- Eyes & Smile -->
                                <circle cx="42" cy="45" r="3" fill="#0A0A0A"/>
                                <circle cx="52" cy="43" r="3" fill="#0A0A0A"/>
                                <path d="M45 53 Q48 57 52 52" stroke="#0A0A0A" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                                <!-- Magnifying Glass -->
                                <circle cx="28" cy="40" r="14" fill="#FFFFFF" fill-opacity="0.8" stroke="#0A0A0A" stroke-width="3.5"/>
                                <circle cx="28" cy="40" r="10" fill="#EBFFAA" fill-opacity="0.6"/>
                                <line x1="38" y1="50" x2="48" y2="60" stroke="#0A0A0A" stroke-width="4.5" stroke-linecap="round"/>
                                <!-- Sparks -->
                                <path d="M20 18 L24 22 M14 26 L20 28 M24 12 L26 18" stroke="#0A0A0A" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3 class="keria-process-title">Search</h3>
                        <p class="keria-process-desc">Find opportunities that fit you</p>
                    </div>

                    <div class="keria-process-step">
                        <div class="keria-mascot-icon-wrap">
                            <!-- Mascot holding resume -->
                            <svg viewBox="0 0 100 100" class="keria-mascot-svg">
                                <!-- Mascot Body -->
                                <path d="M40 28 C26 28, 22 48, 26 72 C29 86, 50 90, 56 76 C62 62, 58 28, 40 28 Z" fill="#D2FF3A" stroke="#0A0A0A" stroke-width="3" stroke-linejoin="round"/>
                                <!-- Eyes & Smile -->
                                <circle cx="36" cy="46" r="3" fill="#0A0A0A"/>
                                <circle cx="46" cy="45" r="3" fill="#0A0A0A"/>
                                <path d="M39 54 Q43 58 47 54" stroke="#0A0A0A" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                                <!-- Resume Sheet -->
                                <rect x="50" y="32" width="28" height="36" rx="3" fill="#FFFFFF" stroke="#0A0A0A" stroke-width="3"/>
                                <line x1="56" y1="40" x2="72" y2="40" stroke="#0A0A0A" stroke-width="2.2" stroke-linecap="round"/>
                                <line x1="56" y1="46" x2="72" y2="46" stroke="#0A0A0A" stroke-width="2.2" stroke-linecap="round"/>
                                <line x1="56" y1="52" x2="68" y2="52" stroke="#0A0A0A" stroke-width="2.2" stroke-linecap="round"/>
                                <line x1="56" y1="58" x2="64" y2="58" stroke="#D2FF3A" stroke-width="3" stroke-linecap="round"/>
                                <!-- Sparks -->
                                <path d="M45 15 L47 22 M35 18 L39 23" stroke="#0A0A0A" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3 class="keria-process-title">Apply</h3>
                        <p class="keria-process-desc">Easily apply with your profile</p>
                    </div>

                    <div class="keria-process-step">
                        <div class="keria-mascot-icon-wrap">
                            <!-- Mascot with Laptop -->
                            <svg viewBox="0 0 100 100" class="keria-mascot-svg">
                                <!-- Mascot Body -->
                                <path d="M35 28 C22 28, 18 48, 22 72 C25 86, 46 90, 52 76 C58 62, 53 28, 35 28 Z" fill="#D2FF3A" stroke="#0A0A0A" stroke-width="3" stroke-linejoin="round"/>
                                <!-- Eyes & Smile -->
                                <circle cx="32" cy="46" r="3" fill="#0A0A0A"/>
                                <circle cx="42" cy="45" r="3" fill="#0A0A0A"/>
                                <path d="M34 54 Q38 58 42 54" stroke="#0A0A0A" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                                <!-- Laptop -->
                                <path d="M48 44 L78 44 L74 68 L48 68 Z" fill="#4B4B4B" stroke="#0A0A0A" stroke-width="3" stroke-linejoin="round"/>
                                <circle cx="61" cy="56" r="3" fill="#D2FF3A"/>
                                <rect x="42" y="68" width="42" height="6" rx="2" fill="#2B2B2B" stroke="#0A0A0A" stroke-width="2.5"/>
                                <!-- Sparks -->
                                <path d="M40 16 L42 22 M30 18 L34 23" stroke="#0A0A0A" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3 class="keria-process-title">Grow</h3>
                        <p class="keria-process-desc">Gain experience and upskill</p>
                    </div>

                    <div class="keria-process-step">
                        <div class="keria-mascot-icon-wrap">
                            <!-- Mascot with Cap Celebrating -->
                            <svg viewBox="0 0 100 100" class="keria-mascot-svg">
                                <!-- Mascot Body -->
                                <path d="M50 30 C35 30, 30 50, 35 74 C38 88, 62 90, 68 76 C74 62, 70 30, 50 30 Z" fill="#D2FF3A" stroke="#0A0A0A" stroke-width="3" stroke-linejoin="round"/>
                                <!-- Cap -->
                                <path d="M35 32 C35 20, 60 18, 65 26 L78 30 C80 30, 78 35, 72 35 L40 35 Z" fill="#0A0A0A"/>
                                <!-- Eyes & Smile -->
                                <circle cx="46" cy="48" r="3" fill="#0A0A0A"/>
                                <circle cx="56" cy="48" r="3" fill="#0A0A0A"/>
                                <path d="M48 57 Q52 62 58 57" stroke="#0A0A0A" stroke-width="2.5" fill="none" stroke-linecap="round"/>
                                <!-- Joyful Arms -->
                                <path d="M32 55 Q20 48 18 40" stroke="#0A0A0A" stroke-width="3.5" stroke-linecap="round" fill="none"/>
                                <path d="M68 55 Q80 48 82 40" stroke="#0A0A0A" stroke-width="3.5" stroke-linecap="round" fill="none"/>
                                <!-- Celebration Radiance -->
                                <path d="M14 28 L20 34 M80 34 L86 28 M50 10 L50 16 M26 18 L32 24 M68 24 L74 18" stroke="#0A0A0A" stroke-width="2.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <h3 class="keria-process-title">Succeed</h3>
                        <p class="keria-process-desc">Build the career you want</p>
                    </div>

                </div>

                <!-- Hand-drawn Doodle on the right -->
                <div class="keria-process-doodle">
                    more<br>than just<br>jobs ☺
                    <svg class="doodle-sparkle-rays" viewBox="0 0 30 30">
                        <path d="M6 14 L2 14 M8 8 L4 4 M14 6 L14 2 M20 8 L24 4 M22 14 L26 14" stroke="#D2FF3A" stroke-width="3.5" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>

        </div>
    </section>

    <!-- ================= 2. POPULAR JOB CATEGORIES ================= -->
    <section class="keria-bottom-section">
        <div class="keria-bottom-container">
            
            <div class="keria-categories-header">
                <div>
                    <div class="keria-section-pill-tag">
                        <span class="pill-dash"></span> EXPLORE
                    </div>
                    <h2 class="keria-section-heading">Popular Job Categories</h2>
                </div>
                <a href="jobs.php" class="keria-view-all-link">
                    View all categories &rarr;
                </a>
            </div>

            <div class="keria-categories-grid">
                
                <a href="jobs.php?search=Technology" class="category-card-item">
                    <div class="category-card-icon cat-tech">
                        💻
                    </div>
                    <div class="category-card-name">Technology</div>
                    <div class="category-card-count">1,200+ jobs</div>
                </a>

                <a href="jobs.php?search=Marketing" class="category-card-item">
                    <div class="category-card-icon cat-marketing">
                        📢
                    </div>
                    <div class="category-card-name">Marketing</div>
                    <div class="category-card-count">980+ jobs</div>
                </a>

                <a href="jobs.php?search=Finance" class="category-card-item">
                    <div class="category-card-icon cat-finance">
                        📊
                    </div>
                    <div class="category-card-name">Finance</div>
                    <div class="category-card-count">650+ jobs</div>
                </a>

                <a href="jobs.php?search=Design" class="category-card-item">
                    <div class="category-card-icon cat-design">
                        🎨
                    </div>
                    <div class="category-card-name">Design</div>
                    <div class="category-card-count">420+ jobs</div>
                </a>

                <a href="jobs.php?search=Operations" class="category-card-item">
                    <div class="category-card-icon cat-ops">
                        ⚙️
                    </div>
                    <div class="category-card-name">Operations</div>
                    <div class="category-card-count">800+ jobs</div>
                </a>

                <a href="jobs.php?search=Sales" class="category-card-item">
                    <div class="category-card-icon cat-sales">
                        🤝
                    </div>
                    <div class="category-card-name">Sales</div>
                    <div class="category-card-count">760+ jobs</div>
                </a>

                <a href="jobs.php?search=Human+Resources" class="category-card-item">
                    <div class="category-card-icon cat-hr">
                        👥
                    </div>
                    <div class="category-card-name">Human Resources</div>
                    <div class="category-card-count">320+ jobs</div>
                </a>

                <a href="jobs.php?type=Internship" class="category-card-item">
                    <div class="category-card-icon cat-intern">
                        🎓
                    </div>
                    <div class="category-card-name">Internships</div>
                    <div class="category-card-count">510+ jobs</div>
                </a>

            </div>

        </div>
    </section>

    <!-- ================= 3. LIVE JOB POSTINGS SECTION ================= -->
    <section class="keria-bottom-section">
        <div class="keria-bottom-container">
            
            <div class="keria-live-jobs-card">
                
                <!-- Left Column: Header, Title with Sparks, Subtitle, Casey Mascot & Doodle -->
                <div class="live-jobs-left">
                    <div>
                        <!-- Pill Tag -->
                        <div class="live-jobs-tag">
                            <span class="live-jobs-tag-bar"></span>
                            LIVE OPPORTUNITIES
                        </div>

                        <!-- Main Title with Radiance Sparks -->
                        <div class="live-jobs-title-wrap">
                            <h2 class="live-jobs-title">Live Job<br>Postings</h2>
                            <svg class="live-jobs-title-sparks" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 18L14 16" stroke="#A8E334" stroke-width="4" stroke-linecap="round"/>
                                <path d="M8 8L20 14" stroke="#A8E334" stroke-width="4" stroke-linecap="round"/>
                                <path d="M16 2L22 10" stroke="#A8E334" stroke-width="4" stroke-linecap="round"/>
                            </svg>
                        </div>

                        <!-- Subtitle -->
                        <p class="live-jobs-sub">
                            Be among the first to apply. Fresh opportunities, updated in real time.
                        </p>
                    </div>

                    <!-- Mascot and Hand-Drawn Doodle Speech -->
                    <div class="live-jobs-mascot-wrap">
                        <div class="mascot-doodle-speech">
                            your next<br>opportunity<br>is here! ☺
                            <svg class="mascot-doodle-arrow" viewBox="0 0 45 30" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M5 5 C 18 18, 30 10, 38 22" stroke="#0A0A0A" stroke-width="2" stroke-linecap="round" fill="none"/>
                                <path d="M30 20 L38 22 L35 14" stroke="#0A0A0A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                            </svg>
                        </div>
                        <img src="assets/casey_pointing_right.png?v=<?php echo @filemtime(__DIR__.'/assets/casey_pointing_right.png'); ?>" alt="Casey Pointing Mascot" class="live-jobs-mascot-img">
                    </div>
                </div>

                <!-- Right Column: Filters, Stacked Job Cards, Alert Box, Bottom-Right Doodle -->
                <div class="live-jobs-right">
                    
                    <!-- Filter Row -->
                    <div class="live-jobs-filter-bar">
                        <div class="live-jobs-filters" id="liveJobFilters">
                            <button type="button" class="filter-pill-btn active" data-filter="all">All Jobs</button>
                            <button type="button" class="filter-pill-btn" data-filter="full-time">Full-time</button>
                            <button type="button" class="filter-pill-btn" data-filter="part-time">Part-time</button>
                            <button type="button" class="filter-pill-btn" data-filter="internship">Internship</button>
                            <button type="button" class="filter-pill-btn" data-filter="remote">Remote</button>
                            <button type="button" class="filter-pill-btn" data-filter="hybrid">Hybrid</button>
                        </div>
                        <a href="jobs.php" class="live-jobs-view-all-link">
                            View all jobs &rarr;
                        </a>
                    </div>

                    <!-- Dynamic Real Job Cards List from Database -->
                    <?php if (!empty($live_jobs)): ?>
                        <?php foreach ($live_jobs as $job): 
                            $job_id = (int)$job['id'];
                            $emp_name = !empty($job['employer_name']) ? $job['employer_name'] : 'Keria Employer';
                            $job_title = $job['job_title'];
                            $emp_type = !empty($job['employment_type']) ? $job['employment_type'] : 'Full-time';
                            $work_mode = !empty($job['work_mode']) ? $job['work_mode'] : 'On-site';
                            $location = !empty($job['location']) ? $job['location'] : (!empty($job['department']) ? $job['department'] : 'Kuala Lumpur');
                            $time_ago = formatTimeAgo($job['created_at']);
                            
                            // Normalized tags for filter buttons
                            $type_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $emp_type));
                            $mode_slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $work_mode));
                        ?>
                        <a href="jobs.php?id=<?= $job_id ?>" class="live-job-item" data-type="<?= htmlspecialchars($type_slug) ?>" data-workplace="<?= htmlspecialchars($mode_slug) ?>">
                            <div class="live-job-item-left">
                                <?= getCompanyLogoHtml($emp_name, $job['company_logo']) ?>
                                <div class="live-job-info">
                                    <div class="live-job-item-title"><?= htmlspecialchars($job_title) ?></div>
                                    <div class="live-job-item-meta">
                                        <span class="live-job-company"><?= htmlspecialchars($emp_name) ?></span>
                                        <span class="live-job-location">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                            <?= htmlspecialchars($location) ?>
                                        </span>
                                        <span class="live-job-pill"><?= htmlspecialchars($emp_type) ?></span>
                                        <span class="live-job-pill"><?= htmlspecialchars($work_mode) ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="live-job-item-right">
                                <button type="button" class="live-job-bookmark-btn" title="Bookmark" onclick="event.preventDefault(); this.style.color=this.style.color==='rgb(132, 204, 22)'?'#0A0A0A':'#84CC16';">
                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
                                </button>
                                <span class="live-job-new-badge">New</span>
                                <span class="live-job-time-ago"><?= htmlspecialchars($time_ago) ?></span>
                                <span class="live-job-chevron">&#x203A;</span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="background:#FFFFFF; border-radius:18px; padding:32px; text-align:center; color:#6B7280; font-size:14px; border:1px solid #E5EFE2;">
                            No active job postings found. Check back shortly or browse all categories!
                        </div>
                    <?php endif; ?>

                    <!-- Bottom Alerts Banner -->
                    <div class="live-job-alerts-banner">
                        <div class="alert-banner-left">
                            <div class="alert-bell-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                </svg>
                            </div>
                            <div class="alert-banner-text-wrap">
                                <div class="alert-banner-title">Get new job alerts</div>
                                <div class="alert-banner-sub">Be the first to know when new opportunities match your interests.</div>
                            </div>
                        </div>
                        <a href="register.php" class="btn-create-job-alert">Create job alert</a>
                    </div>

                    <!-- Bottom Right Doodle outside alert box -->
                    <div class="live-jobs-bottom-doodle">
                        <svg class="live-jobs-doodle-sparks" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M10 26 L2 34" stroke="#A8E334" stroke-width="4.5" stroke-linecap="round"/>
                            <path d="M18 18 L26 8" stroke="#A8E334" stroke-width="4.5" stroke-linecap="round"/>
                            <path d="M26 28 L34 22" stroke="#A8E334" stroke-width="4.5" stroke-linecap="round"/>
                        </svg>
                        <div class="live-jobs-doodle-text">
                            good<br>jobs,<br>real<br>people ☺
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </section>

    <!-- ================= 4. BOTTOM CTA BANNER ================= -->
    <section class="keria-bottom-section" style="padding-bottom: 70px;">
        <div class="keria-bottom-container">
            
            <div class="keria-final-cta-card">
                <div class="final-cta-bg-wrap">
                    <img src="assets/bottom_cta_kl.jpg" alt="Join Keria" class="final-cta-img">
                    <div class="final-cta-overlay"></div>
                </div>

                <!-- Left Doodle: work towards a happier you :) -->
                <div class="final-cta-doodle-left">
                    work<br>towards<br>a happier<br>you ☺
                </div>

                <!-- Center/Right Content -->
                <div class="final-cta-content">
                    <h2 class="final-cta-title">Ready for what's next?</h2>
                    <p class="final-cta-subtitle">Join thousands finding brighter opportunities with Keria.</p>
                    
                    <div class="final-cta-buttons">
                        <a href="register.php" class="btn-final-lime">
                            Create an account
                        </a>
                        <a href="jobs.php" class="btn-final-outline">
                            Browse jobs
                        </a>
                    </div>
                </div>

                <!-- Right Doodle: find your fit with keria :) -->
                <div class="final-cta-doodle-right">
                    find<br>your fit<br>with keria ☺
                    <svg class="doodle-sparkle-rays-cta" viewBox="0 0 30 30" width="28" height="28">
                        <path d="M6 14 L2 14 M8 8 L4 4 M14 6 L14 2 M20 8 L24 4 M22 14 L26 14" stroke="#D2FF3A" stroke-width="3.5" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>

        </div>
    </section>

    <!-- FOOTER -->
    <footer class="keria-footer">
        <div class="footer-inner">
            <a href="index.php" class="keria-logo">
                <img src="logo/logo_white.png?v=<?php echo @filemtime(__DIR__.'/logo/logo_white.png'); ?>" alt="keria" class="keria-logo-img" style="height:40px; mix-blend-mode:normal;">
            </a>

            <div style="display:flex; gap:28px; align-items:center; flex-wrap:wrap;">
                <a href="jobs.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Jobs</a>
                <a href="resume_check.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">AI Resume Check</a>
                <a href="resume_builder.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Resume Builder</a>
                <a href="register.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">For Employers</a>
                <a href="terms.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Terms & Conditions</a>
            </div>
        </div>

        <div class="footer-bottom-copy">
            <p>&copy; <?= date('Y') ?> keria. All rights reserved.</p>
            <p>Made with ❤️ in Kuala Lumpur, Malaysia</p>
        </div>
    </footer>

    <!-- INTERACTION SCRIPT & ANIME.JS -->
    <!-- INTERACTION SCRIPT & ANIME.JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.2/anime.min.js"></script>
    <script>if(typeof anime==='undefined'){document.write('<script src="assets/anime.min.js"><\/script>');}</script>
    <script>
        function quickFill(term) {
            const input = document.getElementById('heroSearchInput');
            if (input) {
                input.value = term;
                input.focus();
                if (typeof anime !== 'undefined') {
                    anime({
                        targets: input,
                        scale: [0.98, 1],
                        duration: 300,
                        easing: 'easeOutQuad'
                    });
                }
            }
        }

        function quickFillLocation(loc) {
            const input = document.getElementById('heroLocationInput');
            if (input) {
                input.value = loc;
                input.focus();
                if (typeof anime !== 'undefined') {
                    anime({
                        targets: input,
                        scale: [0.98, 1],
                        duration: 300,
                        easing: 'easeOutQuad'
                    });
                }
            }
        }

        document.addEventListener("DOMContentLoaded", () => {
            if (typeof anime === "undefined") return;

            // ================= 1. HERO ENTRANCE ANIMATION TIMELINE =================
            const heroTL = anime.timeline({
                easing: 'easeOutExpo'
            });

            heroTL
                .add({
                    targets: '.keria-navbar',
                    opacity: [0, 1],
                    translateY: [-24, 0],
                    duration: 800
                })
                .add({
                    targets: '.keria-hero-title',
                    opacity: [0, 1],
                    translateY: [35, 0],
                    duration: 900,
                    easing: 'easeOutCubic'
                }, '-=500')
                .add({
                    targets: '.keria-brush-underline',
                    scaleX: [0, 1],
                    opacity: [0, 1],
                    duration: 650,
                    easing: 'easeOutBack(1.6)'
                }, '-=450')
                .add({
                    targets: ['.keria-hero-sub', '.hero-actions-row'],
                    opacity: [0, 1],
                    translateY: [20, 0],
                    duration: 750,
                    delay: anime.stagger(90)
                }, '-=400')
                .add({
                    targets: '.keria-search-box',
                    opacity: [0, 1],
                    translateY: [25, 0],
                    scale: [0.96, 1],
                    duration: 850,
                    easing: 'easeOutBack(1.2)'
                }, '-=450')
                .add({
                    targets: '.keria-popular-searches',
                    opacity: [0, 1],
                    translateY: [15, 0],
                    duration: 650
                }, '-=500');

            // ================= 2. CONTINUOUS FLOATING & IDLE LOOPS =================

            // Process section doodle floating
            anime({
                targets: '.keria-process-doodle',
                translateY: [-4, 4],
                rotate: [1, 5],
                duration: 3600,
                direction: 'alternate',
                loop: true,
                easing: 'easeInOutSine'
            });

            // Live jobs sparks pulsing radiance
            anime({
                targets: ['.live-jobs-title-sparks path', '.live-jobs-doodle-sparks path', '.doodle-sparkle-rays path'],
                strokeDashoffset: [anime.setDashoffset, 0],
                opacity: [0.6, 1],
                scale: [0.95, 1.05],
                duration: 2000,
                direction: 'alternate',
                loop: true,
                easing: 'easeInOutQuad'
            });

            // Mascot subtle breathing idle
            anime({
                targets: '.live-jobs-mascot-img',
                translateY: [4, 8],
                scaleY: [0.99, 1.01],
                duration: 2200,
                direction: 'alternate',
                loop: true,
                easing: 'easeInOutSine'
            });

            // Mascot speech doodle bob
            anime({
                targets: '.mascot-doodle-speech',
                translateY: [-3, 3],
                rotate: [0, 3],
                duration: 2600,
                direction: 'alternate',
                loop: true,
                easing: 'easeInOutSine'
            });

            // New badge gentle pulsing glow
            anime({
                targets: '.live-job-new-badge',
                scale: [1, 1.06],
                duration: 1600,
                direction: 'alternate',
                loop: true,
                easing: 'easeInOutSine'
            });

            // Final CTA doodle float
            anime({
                targets: '.final-cta-doodle-left',
                translateY: [-4, 4],
                rotate: [-6, -3],
                duration: 3500,
                direction: 'alternate',
                loop: true,
                easing: 'easeInOutSine'
            });

            // ================= 3. SCROLL-TRIGGERED REVEAL OBSERVERS =================
            const observerOptions = { threshold: 0.15 };

            // Helper to trigger once
            const createScrollObserver = (selector, callback) => {
                const el = document.querySelector(selector);
                if (!el) return;
                let triggered = false;
                const obs = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting && !triggered) {
                            triggered = true;
                            callback(el);
                            obs.unobserve(el);
                        }
                    });
                }, observerOptions);
                obs.observe(el);
            };

            // Stat Bar Count-up
            createScrollObserver('.keria-stat-bar', (bar) => {
                anime({
                    targets: bar,
                    opacity: [0, 1],
                    translateY: [25, 0],
                    duration: 700,
                    easing: 'easeOutCubic'
                });

                document.querySelectorAll('.stat-num-val').forEach(el => {
                    const target = parseInt(el.getAttribute('data-target') || '0', 10);
                    const counter = { val: 0 };
                    anime({
                        targets: counter,
                        val: target,
                        round: 1,
                        duration: 1800,
                        easing: 'easeOutExpo',
                        update: () => {
                            el.textContent = counter.val + '+';
                        }
                    });
                });
            });

            // How Keria Works Section
            createScrollObserver('.keria-process-card', (card) => {
                anime({
                    targets: card,
                    opacity: [0, 1],
                    translateY: [35, 0],
                    duration: 800,
                    easing: 'easeOutCubic'
                });

                anime({
                    targets: '.keria-process-step',
                    opacity: [0, 1],
                    translateY: [30, 0],
                    scale: [0.92, 1],
                    duration: 750,
                    delay: anime.stagger(120, { start: 200 }),
                    easing: 'easeOutBack(1.4)'
                });

                anime({
                    targets: '.keria-mascot-svg',
                    rotate: [-8, 0],
                    scale: [0.85, 1],
                    duration: 800,
                    delay: anime.stagger(120, { start: 300 }),
                    easing: 'easeOutBack(1.8)'
                });
            });

            // Popular Job Categories Grid
            createScrollObserver('.keria-categories-grid', () => {
                anime({
                    targets: '.keria-categories-header',
                    opacity: [0, 1],
                    translateY: [20, 0],
                    duration: 700,
                    easing: 'easeOutCubic'
                });

                anime({
                    targets: '.category-card-item',
                    opacity: [0, 1],
                    translateY: [35, 0],
                    scale: [0.9, 1],
                    duration: 650,
                    delay: anime.stagger(55, { start: 150 }),
                    easing: 'easeOutCubic'
                });
            });

            // Live Job Postings Card & Stacked Cards
            createScrollObserver('.keria-live-jobs-card', (card) => {
                anime({
                    targets: card,
                    opacity: [0, 1],
                    translateY: [40, 0],
                    scale: [0.97, 1],
                    duration: 850,
                    easing: 'easeOutCubic'
                });

                anime({
                    targets: '.live-jobs-left > div > *',
                    opacity: [0, 1],
                    translateY: [25, 0],
                    duration: 700,
                    delay: anime.stagger(80, { start: 200 }),
                    easing: 'easeOutCubic'
                });

                anime({
                    targets: '.live-jobs-mascot-wrap',
                    opacity: [0, 1],
                    scale: [0.9, 1],
                    duration: 800,
                    delay: 350,
                    easing: 'easeOutBack(1.3)'
                });

                anime({
                    targets: '.live-job-item',
                    opacity: [0, 1],
                    translateX: [40, 0],
                    duration: 750,
                    delay: anime.stagger(75, { start: 300 }),
                    easing: 'easeOutCubic'
                });

                anime({
                    targets: '.live-job-alerts-banner',
                    opacity: [0, 1],
                    translateY: [20, 0],
                    duration: 700,
                    delay: 650,
                    easing: 'easeOutCubic'
                });
            });

            // Final Bottom CTA Card
            createScrollObserver('.keria-final-cta-card', (card) => {
                anime({
                    targets: card,
                    opacity: [0, 1],
                    scale: [0.96, 1],
                    duration: 850,
                    easing: 'easeOutCubic'
                });

                anime({
                    targets: ['.final-cta-title', '.final-cta-subtitle', '.final-cta-buttons a'],
                    opacity: [0, 1],
                    translateY: [25, 0],
                    duration: 700,
                    delay: anime.stagger(90, { start: 250 }),
                    easing: 'easeOutCubic'
                });
            });

            // ================= 4. TAB FILTERING ANIMATIONS =================
            const filterBtns = document.querySelectorAll('#liveJobFilters .filter-pill-btn');
            const jobItems = document.querySelectorAll('.live-job-item');

            filterBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    filterBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');

                    // Button pop micro-animation
                    anime({
                        targets: btn,
                        scale: [0.92, 1.06, 1],
                        duration: 350,
                        easing: 'easeOutQuad'
                    });

                    const rawFilter = (btn.getAttribute('data-filter') || 'all').toLowerCase();
                    const filter = rawFilter.replace(/[^a-z0-9]/g, '');

                    const matchingItems = [];
                    jobItems.forEach(item => {
                        const type = (item.getAttribute('data-type') || '').toLowerCase().replace(/[^a-z0-9]/g, '');
                        const workplace = (item.getAttribute('data-workplace') || '').toLowerCase().replace(/[^a-z0-9]/g, '');

                        if (filter === 'all' || type.includes(filter) || workplace.includes(filter) || filter.includes(type) || filter.includes(workplace)) {
                            item.style.display = 'flex';
                            matchingItems.push(item);
                        } else {
                            item.style.display = 'none';
                        }
                    });

                    // Smooth stagger reveal on filtered cards
                    if (matchingItems.length > 0) {
                        anime({
                            targets: matchingItems,
                            opacity: [0, 1],
                            scale: [0.96, 1],
                            translateY: [12, 0],
                            duration: 400,
                            delay: anime.stagger(45),
                            easing: 'easeOutCubic'
                        });
                    }
                });
            });

            // Bookmark button pop
            document.querySelectorAll('.live-job-bookmark-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    anime({
                        targets: btn,
                        scale: [1, 1.35, 1],
                        rotate: [0, -12, 12, 0],
                        duration: 450,
                        easing: 'easeOutBack(2)'
                    });
                });
            });
        });
    </script>
</body>
</html>
