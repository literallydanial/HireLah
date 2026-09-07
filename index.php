<?php
session_start();

$total_live_roles = 0;
$fresh_jobs = [];

$resumes_screened = 0;
$strong_hires = 0;
$departments_hiring = 0;

if (file_exists('db.php')) {
    try {
        require_once 'db.php';
        // Get total count of active roles
        $countStmt = $pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'Active' OR status IS NULL");
        $total_live_roles = (int) $countStmt->fetchColumn();

        // Fetch exactly 3 latest active jobs
        $jobsStmt = $pdo->query("SELECT id, job_title, department, employment_type FROM jobs WHERE status = 'Active' OR status IS NULL ORDER BY created_at DESC, id DESC LIMIT 3");
        $fresh_jobs = array_slice($jobsStmt->fetchAll(PDO::FETCH_ASSOC), 0, 3);

        // Stat bar numbers
        $resumes_screened = (int) $pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
        $strong_hires = (int) $pdo->query("SELECT COUNT(*) FROM candidates WHERE recommendation LIKE '%Hire%'")->fetchColumn();
        $departments_hiring = (int) $pdo->query("SELECT COUNT(DISTINCT department) FROM jobs WHERE department IS NOT NULL AND department <> ''")->fetchColumn();
    } catch (\Throwable $e) {
        // Fallback gracefully if database or table is not ready yet
        $total_live_roles = 0;
        $fresh_jobs = [];
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
    <title>HireLah - Applicant Tracking & Resume Intelligence</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;700&family=Anton&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="assets/casey-2.png?v=<?php echo @filemtime(__DIR__.'/assets/casey-2.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="assets/casey-2.png?v=<?php echo @filemtime(__DIR__.'/assets/casey-2.png'); ?>">
    <link rel="apple-touch-icon" href="assets/casey-1.png?v=<?php echo @filemtime(__DIR__.'/assets/casey-1.png'); ?>">
    <style>
        /* CSS Variables for Light Mode (Default) and Dark Mode */
        :root {
            --bg-body: #FFFFFF;
            --txt-primary: #0A0A0A;
            --txt-secondary: #56546C;
            --header-bg: rgba(255, 255, 255, 0.85);
            --header-border: rgba(128, 0, 255, 0.1);
            --nav-link-color: #4A4A68;
            --nav-link-hover: #7B00FF;
            --brand-name-color: #1A1A2E;
            
            --hero-bg: radial-gradient(circle at 80% 20%, #8616FF 0%, #6300E3 45%, #42009E 100%);
            --hero-txt-title: #FFFFFF;
            --hero-txt-desc: rgba(255, 255, 255, 0.9);
            --hero-badge-bg: rgba(255, 255, 255, 0.2);
            --hero-badge-border: rgba(255, 255, 255, 0.35);
            --hero-badge-txt: #FFFFFF;
            --hero-chip-bg: rgba(255, 255, 255, 0.18);
            --hero-chip-border: rgba(255, 255, 255, 0.28);
            --hero-chip-txt: #FFFFFF;

            --btn-join-bg: #8000FF;
            --btn-join-txt: #FFFFFF;
            --btn-join-hover: #6900D4;

            --outer-card-bg: rgba(255, 255, 255, 0.2);
            --outer-card-border: rgba(255, 255, 255, 0.35);
            --inner-card-bg: #FFFFFF;
            --inner-card-txt: #1A1A2E;
            --job-card-bg: #F7F9EF;
            --job-card-border: #E4E8D5;

            --glass-card-bg: #FFFFFF;
            --glass-card-border: rgba(180, 214, 0, 0.25);
            --glass-card-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
            --glass-card-title: #120D2C;
            --glass-card-desc: #56546C;
            --icon-box-bg: #D9FF4F;
            --icon-box-border: #0A0A0A;

            --ambient-1: radial-gradient(circle, rgba(217, 255, 79, 0.35) 0%, rgba(180, 214, 0, 0.08) 50%, transparent 70%);
            --ambient-2: radial-gradient(circle, rgba(10, 10, 10, 0.06) 0%, rgba(10, 10, 10, 0.02) 60%, transparent 75%);

            --font-heading: 'Space Grotesk', 'Plus Jakarta Sans', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
        }

        [data-theme="dark"] {
            --bg-body: #0A0A0A;
            --txt-primary: #FFFFFF;
            --txt-secondary: rgba(255, 255, 255, 0.75);
            --header-bg: rgba(10, 6, 30, 0.85);
            --header-border: rgba(255, 255, 255, 0.08);
            --nav-link-color: rgba(255, 255, 255, 0.7);
            --nav-link-hover: #FFFFFF;
            --brand-name-color: #FFFFFF;

            --hero-bg: radial-gradient(circle at 80% 20%, #9B26FF 0%, #6F00FF 45%, #0B0320 100%);
            --hero-txt-title: #FFFFFF;
            --hero-txt-desc: rgba(255, 255, 255, 0.75);
            --hero-badge-bg: rgba(157, 38, 255, 0.15);
            --hero-badge-border: rgba(180, 80, 255, 0.35);
            --hero-badge-txt: #E2B9FF;
            --hero-chip-bg: rgba(255, 255, 255, 0.05);
            --hero-chip-border: rgba(255, 255, 255, 0.12);
            --hero-chip-txt: rgba(255, 255, 255, 0.85);

            --btn-join-bg: linear-gradient(135deg, #ECE2FF, #D9C3FF);
            --btn-join-txt: #5C00C7;
            --btn-join-hover: #FFFFFF;

            --outer-card-bg: linear-gradient(145deg, rgba(255, 255, 255, 0.14) 0%, rgba(255, 255, 255, 0.03) 100%);
            --outer-card-border: rgba(255, 255, 255, 0.18);
            --inner-card-bg: #FFFFFF;
            --inner-card-txt: #1A1A2E;
            --job-card-bg: #FBFBFE;
            --job-card-border: #EAEAF4;

            --glass-card-bg: rgba(255, 255, 255, 0.03);
            --glass-card-border: rgba(255, 255, 255, 0.08);
            --glass-card-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            --glass-card-title: #FFFFFF;
            --glass-card-desc: rgba(255, 255, 255, 0.65);
            --icon-box-bg: #D9FF4F;
            --icon-box-border: #D9FF4F;

            --ambient-1: radial-gradient(circle, rgba(217, 255, 79, 0.22) 0%, rgba(180, 214, 0, 0.06) 50%, transparent 70%);
            --ambient-2: radial-gradient(circle, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.02) 60%, transparent 75%);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-body);
            background-color: var(--bg-body);
            color: var(--txt-primary);
            overflow-x: hidden;
            min-height: 100vh;
            line-height: 1.5;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Smooth Mesh Background Elements */
        .ambient-glow-1 {
            position: absolute;
            top: -10%;
            right: 5%;
            width: 650px;
            height: 650px;
            background: var(--ambient-1);
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
            transition: background 0.4s ease;
        }

        .ambient-glow-2 {
            position: absolute;
            top: 40%;
            left: -10%;
            width: 700px;
            height: 700px;
            background: var(--ambient-2);
            filter: blur(100px);
            pointer-events: none;
            z-index: 0;
            transition: background 0.4s ease;
        }

        /* Sleek Modern Header */
        .site-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 6%;
            background: var(--header-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--header-border);
            position: sticky;
            top: 0;
            z-index: 100;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .logo-badge {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #9D26FF, #6800E8);
            color: #FFFFFF;
            font-family: var(--font-heading);
            font-weight: 700;
            font-size: 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 18px rgba(138, 43, 226, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .brand-name {
            font-family: var(--font-heading);
            font-size: 22px;
            font-weight: 700;
            color: var(--brand-name-color);
            letter-spacing: -0.5px;
            transition: color 0.3s ease;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .nav-link {
            color: var(--nav-link-color);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.25s ease;
        }

        .nav-link:hover {
            color: var(--nav-link-hover);
        }

        .btn-join {
            background: var(--btn-join-bg);
            color: var(--btn-join-txt);
            padding: 10px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 15px rgba(128, 0, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .btn-join:hover {
            transform: translateY(-2px);
            background: var(--btn-join-hover);
            box-shadow: 0 8px 25px rgba(128, 0, 255, 0.35);
        }

        /* Hero Section */
        .hero-section {
            padding: 70px 6% 90px;
            position: relative;
            z-index: 2;
            background: var(--hero-bg);
            border-radius: 0 0 32px 32px;
            transition: background 0.4s ease;
            overflow: hidden;
        }

        .hero-bg-watermark {
            position: absolute;
            top: 50%;
            left: 2%;
            transform: translateY(-50%);
            width: 480px;
            max-width: 45vw;
            opacity: 0.16;
            pointer-events: none;
            z-index: 1;
            user-select: none;
        }

        .hero-bg-watermark img {
            width: 100%;
            height: auto;
            display: block;
        }

        .hero-container {
            position: relative;
            z-index: 2;
            max-width: 1280px;
            width: 100%;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 60px;
            align-items: center;
        }

        .hero-content {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .badge-pill {
            background: var(--hero-badge-bg);
            border: 1px solid var(--hero-badge-border);
            backdrop-filter: blur(12px);
            color: var(--hero-badge-txt);
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 28px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .badge-dot {
            width: 7px;
            height: 7px;
            background: #B354FF;
            border-radius: 50%;
            box-shadow: 0 0 10px #B354FF;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.3); }
        }

        .hero-title {
            font-family: var(--font-heading);
            font-size: 56px;
            font-weight: 700;
            line-height: 1.1;
            color: var(--hero-txt-title);
            letter-spacing: -1.5px;
            margin-bottom: 24px;
        }

        .hero-title span {
            background: linear-gradient(135deg, #FFFFFF 30%, #E3C4FF 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-description {
            font-size: 17px;
            line-height: 1.65;
            color: var(--hero-txt-desc);
            max-width: 530px;
            margin-bottom: 38px;
            font-weight: 400;
        }

        .cta-group {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 44px;
        }

        .btn-cta {
            background: linear-gradient(135deg, #9D26FF 0%, #6800E8 100%);
            color: #FFFFFF;
            padding: 15px 34px;
            border-radius: 30px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 10px 30px rgba(128, 0, 255, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.25);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-cta:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(157, 38, 255, 0.65);
            background: linear-gradient(135deg, #A836FF 0%, #7300FF 100%);
        }

        .btn-secondary-link {
            color: #FFFFFF;
            text-decoration: none;
            padding: 14px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.25);
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            transition: all 0.25s ease;
        }

        .btn-secondary-link:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.4);
        }

        .hero-features {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .feature-chip {
            background: var(--hero-chip-bg);
            border: 1px solid var(--hero-chip-border);
            backdrop-filter: blur(10px);
            padding: 9px 18px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: 600;
            color: var(--hero-chip-txt);
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
        }

        .feature-chip:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }

        /* Right Card Container with Glassmorphism */
        .preview-outer-card {
            background: var(--outer-card-bg);
            border: 1px solid var(--outer-card-border);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 28px;
            padding: 16px;
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.3);
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), background 0.3s ease;
        }

        .preview-outer-card:hover {
            transform: translateY(-4px) scale(1.01);
        }

        .preview-inner-card {
            background: var(--inner-card-bg);
            border-radius: 22px;
            padding: 30px 28px;
            color: var(--inner-card-txt);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .card-tag {
            font-size: 11px;
            font-weight: 800;
            color: #6B8A00;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .card-title {
            font-family: var(--font-heading);
            font-size: 22px;
            font-weight: 700;
            color: #0E0927;
        }

        .live-roles-badge {
            background: #EEFFB0;
            color: #5C7700;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid rgba(180, 214, 0, 0.35);
        }

        .job-item-card {
            background: var(--job-card-bg);
            border: 1px solid var(--job-card-border);
            border-radius: 16px;
            padding: 18px 20px;
            margin-bottom: 14px;
            transition: all 0.25s ease;
            text-decoration: none;
            display: block;
        }

        .job-item-card:hover {
            border-color: #B9D600;
            transform: translateX(4px);
            background: #FFFFFF;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.1);
        }

        .job-item-title {
            font-size: 16px;
            font-weight: 700;
            color: #120D2C;
            margin-bottom: 4px;
        }

        .job-item-meta {
            font-size: 13px;
            color: #6C6C8A;
            font-weight: 500;
        }

        .snapshot-box {
            background: #0A0A0A;
            border-radius: 16px;
            padding: 20px;
            color: #FFFFFF;
            margin-top: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
            overflow: hidden;
        }

        .snapshot-box::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(217, 255, 79, 0.35), transparent 70%);
            pointer-events: none;
        }

        .snapshot-title {
            font-size: 13px;
            font-weight: 700;
            color: #D9FF4F;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .snapshot-quote {
            font-size: 13px;
            color: #E2E2F0;
            line-height: 1.55;
            font-style: italic;
        }

        /* Modern Grid Feature Section */
        .features-section {
            max-width: 1280px;
            margin: 0 auto;
            padding: 60px 6% 100px;
            position: relative;
            z-index: 2;
        }

        .section-header {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-tag {
            color: #6B8A00;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }

        [data-theme="dark"] .section-tag {
            color: #D9FF4F;
        }

        .section-title {
            font-family: var(--font-heading);
            font-size: 38px;
            font-weight: 700;
            color: var(--txt-primary);
            transition: color 0.3s ease;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
        }

        .glass-card {
            background: var(--glass-card-bg);
            border: 1px solid var(--glass-card-border);
            border-radius: 24px;
            padding: 36px 30px;
            box-shadow: var(--glass-card-shadow);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .glass-card:hover {
            border-color: rgba(180, 214, 0, 0.5);
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.12);
        }

        .card-icon-box {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: var(--icon-box-bg);
            border: 1px solid var(--icon-box-border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 22px;
            transition: background 0.3s ease;
        }

        .glass-card-title {
            font-family: var(--font-heading);
            font-size: 20px;
            font-weight: 700;
            color: var(--glass-card-title);
            margin-bottom: 12px;
            transition: color 0.3s ease;
        }

        .glass-card-desc {
            font-size: 14px;
            color: var(--glass-card-desc);
            line-height: 1.6;
            transition: color 0.3s ease;
        }

        @media (max-width: 1024px) {
            .hero-container {
                grid-template-columns: 1fr;
                gap: 50px;
            }
            .hero-title {
                font-size: 44px;
            }
            .grid-3 {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 840px) {
            html, body {
                overflow-x: hidden !important;
                width: 100% !important;
            }
            .site-header {
                padding: 12px 16px;
                flex-wrap: wrap;
                gap: 10px;
                width: 100%;
                box-sizing: border-box;
            }
            .brand-logo {
                flex-shrink: 0;
            }
            .nav-links {
                display: flex;
                gap: 8px;
                align-items: center;
                overflow-x: auto;
                max-width: 100%;
                padding-bottom: 4px;
                -webkit-overflow-scrolling: touch;
            }
            .nav-links::-webkit-scrollbar {
                display: none;
            }
            .nav-link {
                white-space: nowrap;
                font-size: 13px;
            }
            .btn-join {
                padding: 8px 16px;
                font-size: 13px;
                white-space: nowrap;
                flex-shrink: 0;
            }
            .hero-section {
                padding: 36px 16px 50px;
                overflow-x: hidden;
            }
            .hero-bg-watermark {
                width: 260px;
                max-width: 80vw;
                opacity: 0.1;
                top: 15%;
                left: 50%;
                transform: translateX(-50%);
            }
            .hero-container {
                grid-template-columns: 1fr;
                gap: 36px;
                width: 100%;
            }
            .hero-title {
                font-size: 32px !important;
                line-height: 1.25 !important;
                letter-spacing: -0.5px !important;
                margin-bottom: 16px;
            }
            .hero-description {
                font-size: 14px;
                line-height: 1.6;
                margin-bottom: 24px;
            }
            .cta-group {
                flex-direction: column;
                width: 100%;
                gap: 10px;
            }
            .btn-cta, .btn-secondary-link {
                width: 100%;
                justify-content: center;
                text-align: center;
                box-sizing: border-box;
                padding: 14px 20px;
            }
            .hero-features {
                gap: 8px;
                flex-wrap: wrap;
            }
            .feature-chip {
                font-size: 12px;
                padding: 7px 14px;
            }
            .preview-outer-card {
                padding: 12px;
                border-radius: 20px;
            }
            .preview-inner-card {
                padding: 18px 16px;
            }
            .grid-3 {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            .section-title {
                font-size: 26px !important;
            }
        }

        @media (max-width: 500px) {
            .hero-title {
                font-size: 27px !important;
            }
            .badge-pill {
                font-size: 12px;
                padding: 6px 14px;
            }
            .site-header {
                padding: 10px 14px;
            }
            .brand-name {
                font-size: 18px;
            }
        }

        /* ================= HireLah "People / Career Energy" Hero Redesign ================= */
        .energy-header {
            position: sticky; top: 0; z-index: 500;
            display: flex; align-items: center; justify-content: space-between;
            padding: 18px 48px;
            background: #FFFFFF;
            border-bottom: 1px solid #ECECEC;
        }
        .energy-brand { display: flex; align-items: center; gap: 14px; text-decoration: none; }
        .energy-brand-logo-img { height: 50px; width: auto; display: block; object-fit: contain; }
        .energy-nav { display: flex; align-items: center; gap: 30px; }
        .energy-nav a.energy-nav-link { color: #1A1A1A; text-decoration: none; font-weight: 500; font-size: 14.5px; }
        .energy-nav a.energy-nav-link:hover { color: #6B7F00; }
        .energy-nav .btn-employer { background: #0A0A0A; color: #FFFFFF; padding: 10px 20px; border-radius: 30px; font-weight: 600; font-size: 14px; text-decoration: none; }
        .energy-nav .btn-employer:hover { background: #2A2A2A; }

        .energy-hero-section {
            background-color: #FFFFFF;
            background-image: radial-gradient(rgba(10, 10, 10, 0.14) 1.5px, transparent 1.5px);
            background-size: 24px 24px;
            background-position: -4px -4px;
            padding: 60px 48px 0;
            overflow: hidden;
        }
        .energy-hero-grid {
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 40px;
            align-items: center;
            max-width: 1300px;
            margin: 0 auto;
            position: relative;
        }
        .energy-headline-wrap {
            position: relative;
            margin: 0 0 22px;
            overflow: hidden;
            min-height: 480px;
        }
        .energy-headline-bg-photo {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: 50% 100%;
            transform: translateY(120px);
            opacity: 0.85;
            z-index: 0;
            pointer-events: none;
            user-select: none;
        }
        .energy-headline {
            font-family: 'Anton', 'Space Grotesk', sans-serif;
            font-weight: 400;
            font-size: clamp(42px, 5.2vw, 80px);
            line-height: 0.98;
            letter-spacing: -0.5px;
            color: #0A0A0A;
            text-transform: uppercase;
            margin: 0;
            position: relative;
            z-index: 1;
        }
        .energy-headline .accent { color: #0A0A0A; background: #D9FF4F; padding: 0 8px; position: relative; z-index: 1; }
        .energy-subtext { font-size: 16px; color: #555; max-width: 460px; margin: -190px 0 32px; line-height: 1.5; position: relative; z-index: 1; }
        .energy-cta-row { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; flex-wrap: wrap; position: relative; z-index: 1; }
        .btn-lime {
            display: inline-flex; align-items: center; gap: 8px;
            background: #D9FF4F; color: #0A0A0A;
            font-weight: 700; font-size: 15px;
            padding: 15px 26px; border-radius: 32px;
            text-decoration: none; border: 2px solid #0A0A0A;
            transition: transform 0.15s ease;
        }
        .btn-lime:hover { transform: translateY(-2px); }
        .btn-outline-dark {
            display: inline-flex; align-items: center; gap: 8px;
            background: #FFFFFF; color: #0A0A0A;
            font-weight: 700; font-size: 15px;
            padding: 15px 26px; border-radius: 32px;
            text-decoration: none; border: 2px solid #0A0A0A;
            transition: transform 0.15s ease;
        }
        .btn-outline-dark:hover { transform: translateY(-2px); }

        /* Callout so it's obvious "Upload Resume" runs the free AI Resume
           Check, not just a generic file upload. */
        .resume-check-wrap { position: relative; display: inline-flex; }
        .resume-check-flag {
            position: absolute; top: -16px; right: -14px; z-index: 2;
            background: #4F3FF0; color: #FFFFFF;
            font-weight: 800; font-size: 10.5px; letter-spacing: 0.2px;
            white-space: nowrap;
            padding: 5px 10px; border-radius: 999px;
            transform: rotate(8deg);
            box-shadow: 0 6px 14px rgba(79,63,240,0.35);
            pointer-events: none;
            animation: resumeFlagPop 2.4s ease-in-out infinite;
        }
        @keyframes resumeFlagPop {
            0%, 100% { transform: rotate(8deg) translateY(0); }
            50% { transform: rotate(8deg) translateY(-3px); }
        }
        .energy-cta-caption {
            width: 100%;
            font-size: 12.5px; color: #6B6B6B;
            margin: 0 0 40px; position: relative; z-index: 1;
        }
        [data-theme="dark"] .energy-cta-caption { color: rgba(249, 250, 251, 0.55); }

        .energy-visual { position: relative; padding: 20px; }
        .energy-visual-blob {
            position: absolute;
            top: -6%; left: -6%; right: -6%; bottom: -6%;
            background: #D9FF4F;
            clip-path: polygon(20% 3%, 42% 0%, 58% 4%, 76% 1%, 90% 11%, 98% 26%, 100% 40%, 96% 55%, 100% 70%, 93% 85%, 82% 96%, 68% 100%, 52% 97%, 36% 100%, 20% 95%, 8% 86%, 1% 72%, 4% 56%, 0% 40%, 5% 24%, 12% 10%);
            transform: rotate(-4deg);
            z-index: 0;
        }
        .energy-visual-card { position: relative; z-index: 1; }
        .sticky-note {
            position: absolute; top: -18px; right: -10px; z-index: 2;
            background: #4F3FF0; color: #FFFFFF;
            font-weight: 800; font-size: 12px; line-height: 1.35;
            padding: 14px 16px; border-radius: 10px;
            transform: rotate(6deg);
            max-width: 150px;
            box-shadow: 0 10px 20px rgba(79,63,240,0.25);
        }
        .energy-doodle { position: absolute; opacity: 0.85; z-index: 1; }

        .energy-stat-bar {
            margin-top: 56px;
            background: #0A0A0A;
            border-radius: 20px 20px 0 0;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            max-width: 1300px;
            margin-left: auto; margin-right: auto;
        }
        .energy-stat-item {
            display: flex; align-items: center; gap: 14px;
            padding: 26px 20px;
            border-right: 1px solid rgba(255,255,255,0.12);
        }
        .energy-stat-item:last-child { border-right: none; }
        .energy-stat-icon { width: 22px; height: 22px; flex-shrink: 0; color: #D9FF4F; }
        .energy-stat-num { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 20px; color: #FFFFFF; display: block; }
        .energy-stat-label { font-size: 12.5px; color: #A9A9A9; }

        /* ---- Dark mode overrides for the "energy" header/hero (built after the site-wide
           dark theme, so it needs its own explicit dark variants for these hardcoded colors) ---- */
        [data-theme="dark"] .energy-header {
            background: #18191C;
            border-bottom-color: #2E2F33;
        }
        [data-theme="dark"] .energy-nav a.energy-nav-link { color: rgba(249, 250, 251, 0.75); }
        [data-theme="dark"] .energy-nav a.energy-nav-link:hover { color: #D9FF4F; }
        [data-theme="dark"] .energy-nav .btn-employer { background: #D9FF4F; color: #0A0A0A; }
        [data-theme="dark"] .energy-nav .btn-employer:hover { background: #C2E63A; }

        [data-theme="dark"] .energy-hero-section {
            background-color: #18191C;
            background-image: radial-gradient(rgba(255, 255, 255, 0.10) 1.5px, transparent 1.5px);
        }
        [data-theme="dark"] .energy-headline { color: #F5F5F5; }
        [data-theme="dark"] .energy-headline-bg-photo {
            opacity: 0.85;
            filter: brightness(0) invert(1) drop-shadow(0 8px 25px rgba(255, 255, 255, 0.12));
        }
        [data-theme="dark"] .energy-subtext { color: rgba(249, 250, 251, 0.65); }
        [data-theme="dark"] .btn-outline-dark {
            background: transparent;
            color: #F5F5F5;
            border-color: rgba(249, 250, 251, 0.55);
        }
        [data-theme="dark"] .btn-outline-dark:hover { background: rgba(255, 255, 255, 0.08); }

        [data-theme="dark"] .energy-doodle { stroke: rgba(249, 250, 251, 0.55) !important; }
        [data-theme="dark"] .energy-doodle[fill]:not([fill="none"]),
        [data-theme="dark"] .energy-doodle circle[fill]:not([fill="none"]),
        [data-theme="dark"] .energy-doodle path[fill]:not([fill="none"]) {
            fill: rgba(249, 250, 251, 0.55) !important;
        }

        [data-theme="dark"] .preview-inner-card { background: #202124; color: #F5F5F5; }
        [data-theme="dark"] .card-title { color: #F5F5F5; }
        [data-theme="dark"] .card-tag { color: #D9FF4F; }
        [data-theme="dark"] .live-roles-badge {
            background: rgba(217, 255, 79, 0.14);
            color: #D9FF4F;
            border-color: rgba(217, 255, 79, 0.35);
        }
        [data-theme="dark"] .job-item-card { background: #26272B; border-color: #34353A; }
        [data-theme="dark"] .job-item-card:hover { background: #2E2F34; border-color: #D9FF4F; }
        [data-theme="dark"] .job-item-title { color: #F5F5F5; }
        [data-theme="dark"] .job-item-meta { color: #A6A6AE; }

        .energy-pill-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #0A0A0A;
            color: #D9FF4F;
            font-size: 11.5px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            padding: 6px 14px;
            border-radius: 999px;
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
        }
        [data-theme="dark"] .energy-pill-tag {
            background: #2A2B30;
            color: #D9FF4F;
            border: 1px solid rgba(217, 255, 79, 0.3);
        }

        .btn-link-jobs {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #555555;
            font-weight: 600;
            font-size: 14.5px;
            text-decoration: none;
            padding: 8px 12px;
            transition: color 0.2s ease, transform 0.2s ease;
        }
        .btn-link-jobs:hover {
            color: #0A0A0A;
            transform: translateX(3px);
        }
        [data-theme="dark"] .btn-link-jobs {
            color: rgba(249, 250, 251, 0.65);
        }
        [data-theme="dark"] .btn-link-jobs:hover {
            color: #D9FF4F;
        }

        /* AI Score Banner */
        .ai-score-banner {
            display: flex;
            align-items: center;
            gap: 16px;
            background: linear-gradient(135deg, rgba(79, 63, 240, 0.08) 0%, rgba(217, 255, 79, 0.12) 100%);
            border: 1px solid rgba(79, 63, 240, 0.2);
            border-radius: 16px;
            padding: 14px 18px;
            margin-bottom: 14px;
        }
        [data-theme="dark"] .ai-score-banner {
            background: linear-gradient(135deg, rgba(79, 63, 240, 0.18) 0%, rgba(217, 255, 79, 0.08) 100%);
            border-color: rgba(79, 63, 240, 0.35);
        }
        .ai-score-radial {
            width: 48px;
            height: 48px;
            background: #4F3FF0;
            color: #FFFFFF;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800;
            box-shadow: 0 4px 14px rgba(79, 63, 240, 0.35);
            flex-shrink: 0;
        }
        .ai-score-num { font-size: 19px; line-height: 1; }
        .ai-score-denom { font-size: 10px; opacity: 0.8; margin-left: 1px; }
        .ai-score-status { font-weight: 700; font-size: 13.5px; color: #120D2C; margin-bottom: 2px; }
        [data-theme="dark"] .ai-score-status { color: #FFFFFF; }
        .ai-score-sub { font-size: 11.5px; color: #6C6C8A; font-weight: 500; }
        [data-theme="dark"] .ai-score-sub { color: #A6A6AE; }

        /* Quick AI Tools Cards */
        .ai-quick-tools-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }
        .ai-tool-mini-card {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--job-card-bg);
            border: 1px solid var(--job-card-border);
            border-radius: 14px;
            padding: 12px 16px;
            text-decoration: none;
            transition: all 0.22s ease;
        }
        .ai-tool-mini-card:hover {
            border-color: #B9D600;
            transform: translateX(3px);
            background: #FFFFFF;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }
        [data-theme="dark"] .ai-tool-mini-card {
            background: #26272B;
            border-color: #34353A;
        }
        [data-theme="dark"] .ai-tool-mini-card:hover {
            background: #2E2F34;
            border-color: #D9FF4F;
        }
        .ai-tool-icon {
            font-size: 20px;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(217, 255, 79, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        [data-theme="dark"] .ai-tool-icon {
            background: rgba(217, 255, 79, 0.12);
        }
        .ai-tool-name {
            font-size: 14px;
            font-weight: 700;
            color: #120D2C;
        }
        [data-theme="dark"] .ai-tool-name { color: #F5F5F5; }
        .ai-tool-desc {
            font-size: 11.5px;
            color: #6C6C8A;
            font-weight: 500;
        }
        [data-theme="dark"] .ai-tool-desc { color: #A6A6AE; }
        .ai-tool-arrow {
            margin-left: auto;
            color: #6B7F00;
            font-weight: 700;
            font-size: 16px;
            transition: transform 0.2s ease;
        }
        [data-theme="dark"] .ai-tool-arrow { color: #D9FF4F; }
        .ai-tool-mini-card:hover .ai-tool-arrow { transform: translateX(3px); }

        .card-action-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13.5px;
            font-weight: 700;
            color: #4F3FF0;
            text-decoration: none;
            margin-top: 14px;
            transition: gap 0.2s ease, color 0.2s ease;
        }
        .card-action-link:hover {
            gap: 9px;
            color: #3729C9;
        }
        [data-theme="dark"] .card-action-link {
            color: #D9FF4F;
        }
        [data-theme="dark"] .card-action-link:hover {
            color: #E6FF80;
        }

        .nav-badge-pill {
            background: linear-gradient(135deg, #4F3FF0, #7B00FF);
            color: #FFFFFF;
            font-size: 10.5px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 999px;
            margin-left: 4px;
            letter-spacing: 0.3px;
        }

        /* ================= Social Buttons & Modern Footer ================= */
        .header-social-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: 6px;
        }
        .header-social-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(10, 10, 10, 0.05);
            color: #1A1A1A;
            text-decoration: none;
            transition: all 0.22s ease;
        }
        .header-social-btn:hover {
            transform: translateY(-2px);
        }
        .header-social-btn.instagram:hover {
            background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%);
            color: #FFFFFF;
            box-shadow: 0 4px 12px rgba(214, 36, 159, 0.35);
        }
        .header-social-btn.tiktok:hover {
            background: #000000;
            color: #FFFFFF;
            box-shadow: -2px -2px 8px rgba(0, 242, 254, 0.4), 2px 2px 8px rgba(254, 44, 85, 0.4);
        }
        [data-theme="dark"] .header-social-btn {
            background: #26272B;
            color: #F5F5F5;
        }

        .energy-footer {
            background: #0A0A0A;
            color: #FFFFFF;
            padding: 60px 6% 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            position: relative;
            z-index: 2;
        }
        [data-theme="dark"] .energy-footer {
            background: #111215;
            border-top-color: #222328;
        }
        .footer-container {
            max-width: 1280px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.4fr 1.3fr 1fr;
            gap: 48px;
            align-items: flex-start;
            padding-bottom: 40px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .footer-brand-col { max-width: 340px; }
        .footer-tagline {
            font-size: 14px;
            color: rgba(255, 255, 255, 0.65);
            line-height: 1.6;
            margin-top: 12px;
        }
        .footer-social-col { display: flex; flex-direction: column; gap: 14px; }
        .footer-col-title {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: #FFFFFF;
            letter-spacing: 0.5px;
        }
        .footer-social-buttons {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-social {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 700;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .btn-social.btn-instagram {
            background: rgba(255, 255, 255, 0.06);
            color: #FFFFFF;
        }
        .btn-social.btn-instagram:hover {
            background: radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%);
            border-color: transparent;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(214, 36, 159, 0.45);
        }
        .btn-social.btn-tiktok {
            background: rgba(255, 255, 255, 0.06);
            color: #FFFFFF;
        }
        .btn-social.btn-tiktok:hover {
            background: #000000;
            border-color: transparent;
            transform: translateY(-2px);
            box-shadow: -2px -2px 14px rgba(0, 242, 254, 0.6), 2px 2px 14px rgba(254, 44, 85, 0.6);
        }
        .footer-links-col { display: flex; flex-direction: column; gap: 14px; }
        .footer-quick-links {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .footer-quick-links a {
            color: rgba(255, 255, 255, 0.65);
            text-decoration: none;
            font-size: 13.5px;
            transition: color 0.2s ease, transform 0.2s ease;
        }
        .footer-quick-links a:hover {
            color: #D9FF4F;
            transform: translateX(3px);
        }
        .footer-bottom-bar {
            max-width: 1280px;
            margin: 24px auto 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12.5px;
            color: rgba(255, 255, 255, 0.45);
        }
        @media (max-width: 900px) {
            .footer-container {
                grid-template-columns: 1fr;
                gap: 32px;
            }
        }
        @media (max-width: 900px) {
            .energy-header { padding: 16px 20px; }
            .energy-nav { gap: 16px; }
            .energy-nav a.energy-nav-link { display: none; }
            .header-social-group { display: none; }
            .energy-hero-section { padding: 40px 20px 0; }
            .energy-hero-grid { grid-template-columns: 1fr; }
            .energy-stat-bar { grid-template-columns: repeat(2, 1fr); }
            .energy-stat-item { border-bottom: 1px solid rgba(255,255,255,0.12); }
        }
        @media (max-width: 520px) {
            /* The CTA buttons wrap onto separate lines here, so the "Free AI
               Check" flag needs room above Upload Resume instead of
               overlapping the Search Jobs button sitting right above it. */
            .energy-cta-row { row-gap: 30px; }
            .resume-check-flag { top: -13px; right: 2px; font-size: 10px; padding: 4px 9px; }
            /* Clear the fixed round theme-toggle button (bottom:20px;
               left:20px; 45px wide) so the caption's first line doesn't
               render underneath it. */
            .energy-cta-caption { padding-left: 58px; }
        }
        /* ================= End Hero Redesign ================= */
    </style>
</head>
<body>
    <?php if(isset($_SESSION['toast'])): ?>
        <div style="position:fixed; top:20px; right:20px; z-index:3000; background:rgba(0, 232, 122, 0.18); border:1px solid rgba(0, 232, 122, 0.5); border-radius:10px; padding:10px 18px; color:#00E87A; font-size:13px; font-weight:700; font-family:sans-serif; backdrop-filter:blur(10px);">
            ✓ <?= htmlspecialchars($_SESSION['toast']) ?>
            <?php unset($_SESSION['toast']); ?>
        </div>
    <?php endif; ?>

    <!-- Ambient Glowing Backdrops -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <!-- Header -->
    <header class="energy-header">
        <a href="index.php" class="energy-brand">
            <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah" class="energy-brand-logo-img">
        </a>

        <nav class="energy-nav">
            <a href="#features" class="energy-nav-link">How it works</a>
            <a href="resume_check.php" class="energy-nav-link" style="color:var(--txt-primary); font-weight:700;">✨ AI Check <span class="nav-badge-pill">Free</span></a>
            <a href="resume_builder.php" class="energy-nav-link">Resume Builder</a>
            <a href="jobs.php" class="energy-nav-link">Find Jobs</a>
            <a href="register.php" class="energy-nav-link">Join HireLah</a>
            <div class="header-social-group">
                <a href="https://www.instagram.com/hirelah.my" target="_blank" rel="noopener noreferrer" class="header-social-btn instagram" title="Follow us on Instagram @hirelah.my" aria-label="Instagram">
                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line></svg>
                </a>
                <a href="https://www.tiktok.com/@hirelah.my" target="_blank" rel="noopener noreferrer" class="header-social-btn tiktok" title="Follow us on TikTok @hirelah.my" aria-label="TikTok">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.29 0 .58.04.85.12V9.4a6.33 6.33 0 0 0-6.6 6.33 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.34-6.34V8.75a8.16 8.16 0 0 0 4.78 1.52V6.82a4.85 4.85 0 0 1-1.6-.13z"/></svg>
                </a>
            </div>
            <?php if(isset($_SESSION['user_id'])): ?>
                <span class="energy-nav-link" style="font-weight:600;">Hi, <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                <?php if($_SESSION['user_role'] === 'employer'): ?>
                    <a href="employer_dashboard.php" class="btn-employer">Dashboard</a>
                <?php elseif($_SESSION['user_role'] === 'admin'): ?>
                    <a href="admin_dashboard.php" class="btn-employer">Dashboard</a>
                <?php else: ?>
                    <a href="candidate_dashboard.php" class="btn-employer">Dashboard</a>
                <?php endif; ?>
                <a href="logout.php" class="energy-nav-link">Log out</a>
            <?php else: ?>
                <a href="login.php" class="energy-nav-link">Log in</a>
                <a href="register.php" class="btn-employer">For Employers</a>
            <?php endif; ?>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="energy-hero-section">
        <div class="energy-hero-grid">

            <!-- Left Column: Copy & CTAs -->
            <div>
                <div class="energy-headline-wrap">
                    <img src="assets/casey-2.png" alt="" class="energy-headline-bg-photo">
                    <div class="energy-pill-tag">✨ 100% FREE AI CAREER INTELLIGENCE</div>
                    <h1 class="energy-headline">
                       Find work that actually fits, <span class="accent">lah.</span>
                    </h1>
                </div>

                <p class="energy-subtext">
                    Get instant ATS keyword scoring, AI resume critiques, and build professional resumes in minutes — 100% free with zero sign-up required.
                </p>

                <div class="energy-cta-row">
                    <span class="resume-check-wrap">
                        <span class="resume-check-flag">⚡ Instant Score</span>
                        <a href="resume_check.php" class="btn-lime">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"></path></svg>
                            Free AI Resume Check
                        </a>
                    </span>
                    <a href="resume_builder.php" class="btn-outline-dark">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="10" y1="9" x2="8" y2="9"></line></svg>
                        Build Resume
                    </a>
                    <a href="jobs.php" class="btn-link-jobs">
                        Browse <?= $total_live_roles ?> live jobs &rarr;
                    </a>
                </div>
                <p class="energy-cta-caption">🚀 No account or payment required &mdash; instant AI diagnosis &amp; PDF resume builder ready to go.</p>
            </div>

            <!-- Right Column: Interactive Card Preview -->
            <div class="energy-visual">
                <div class="energy-visual-blob"></div>

                <svg class="energy-doodle" style="top:-6px; left:20px;" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#0A0A0A" stroke-width="2"><circle cx="9" cy="10" r="1.3" fill="#0A0A0A"></circle><circle cx="15" cy="10" r="1.3" fill="#0A0A0A"></circle><path d="M8 15c1 1.3 2.5 2 4 2s3-.7 4-2" stroke-linecap="round"></path><circle cx="12" cy="12" r="9.5"></circle></svg>
                <svg class="energy-doodle" style="bottom:10px; left:-4px;" width="22" height="22" viewBox="0 0 24 24" fill="#0A0A0A"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8z"></path></svg>
                <svg class="energy-doodle" style="top:6%; right:2%;" width="34" height="70" viewBox="0 0 34 70" fill="none" stroke="#0A0A0A" stroke-width="5" stroke-linecap="round"><path d="M6 6 L28 64"></path></svg>
                <svg class="energy-doodle" style="top:22%; right:-2%;" width="28" height="56" viewBox="0 0 28 56" fill="none" stroke="#0A0A0A" stroke-width="5" stroke-linecap="round"><path d="M22 4 L6 52"></path></svg>

                <div class="sticky-note">GOOD JOBS. BETTER YOU. BRIGHTER FUTURE. LAH.</div>

                <div class="preview-outer-card energy-visual-card">
                    <div class="preview-inner-card">
                        <div class="card-header">
                            <div>
                                <div class="card-tag">THIS WEEK</div>
                                <div class="card-title">Fresh opportunities</div>
                            </div>
                            <div class="live-roles-badge"><?= $total_live_roles ?> <?= $total_live_roles === 1 ? 'live role' : 'live roles' ?></div>
                        </div>

                        <?php if (!empty($fresh_jobs)): ?>
                            <?php foreach ($fresh_jobs as $job): ?>
                                <a href="apply.php?job_id=<?= (int)$job['id'] ?>" class="job-item-card">
                                    <div class="job-item-title"><?= htmlspecialchars($job['job_title']) ?></div>
                                    <div class="job-item-meta">
                                        <?= htmlspecialchars($job['department'] ?? 'General') ?>
                                        <?= !empty($job['employment_type']) ? ' • ' . htmlspecialchars($job['employment_type']) : '' ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <a href="jobs.php" class="job-item-card">
                                <div class="job-item-title">No active roles published</div>
                                <div class="job-item-meta">Check back soon for new opportunities</div>
                            </a>
                        <?php endif; ?>

                        <div class="snapshot-box">
                            <div class="snapshot-title">
                                ✨ Candidate snapshot preview
                            </div>
                            <div class="snapshot-quote">
                                "Strong CRM background with a calm approach to escalations and customer care."
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Stat Bar -->
        <div class="energy-stat-bar">
            <div class="energy-stat-item">
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3v4a1 1 0 0 0 1 1h4"></path><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"></path></svg>
                <div><span class="energy-stat-num" data-target="<?= $resumes_screened ?>"><?= $resumes_screened ?>+</span><span class="energy-stat-label">Resumes Screened</span></div>
            </div>
            <div class="energy-stat-item">
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"></path></svg>
                <div><span class="energy-stat-num" data-target="<?= $strong_hires ?>"><?= $strong_hires ?>+</span><span class="energy-stat-label">Strong Hires Flagged</span></div>
            </div>
            <div class="energy-stat-item">
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                <div><span class="energy-stat-num" data-target="<?= $total_live_roles ?>"><?= $total_live_roles ?>+</span><span class="energy-stat-label">Active Jobs</span></div>
            </div>
            <div class="energy-stat-item">
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l7-4 7 4v14"></path><path d="M9 9h1"></path><path d="M9 13h1"></path><path d="M14 9h1"></path><path d="M14 13h1"></path></svg>
                <div><span class="energy-stat-num" data-target="<?= $departments_hiring ?>"><?= $departments_hiring ?>+</span><span class="energy-stat-label">Departments Hiring</span></div>
            </div>
        </div>
    </section>

    <!-- Sleek Grid Feature Breakdown -->
    <section class="features-section" id="features">
        <div class="section-header">
            <div class="section-tag">POWERFUL AI CAREER TOOLKIT</div>
            <h2 class="section-title">Everything you need to get hired faster</h2>
        </div>

        <div class="grid-3">
            <div class="glass-card">
                <div class="card-icon-box">⚡</div>
                <h3 class="glass-card-title">Free AI Resume Checker</h3>
                <p class="glass-card-desc">Upload any PDF or Word resume to receive instant scoring on ATS readability, grammar, keyword matches, and recruiter appeal in seconds.</p>
                <a href="resume_check.php" class="card-action-link">Run Free AI Check &rarr;</a>
            </div>

            <div class="glass-card">
                <div class="card-icon-box">📄</div>
                <h3 class="glass-card-title">Smart Resume Builder</h3>
                <p class="glass-card-desc">Build clean, modern, and recruiter-approved resumes with live formatting, pre-filled sections, and instant 1-click PDF download.</p>
                <a href="resume_builder.php" class="card-action-link">Build Resume Now &rarr;</a>
            </div>

            <div class="glass-card">
                <div class="card-icon-box">🎯</div>
                <h3 class="glass-card-title">Direct Job Fast-Track</h3>
                <p class="glass-card-desc">Apply directly to open positions and get screened fairly with structured, bias-free AI evaluations that put your strengths first.</p>
                <a href="jobs.php" class="card-action-link">Search <?= $total_live_roles ?> Live Jobs &rarr;</a>
            </div>
        </div>
    </section>

    <!-- Modern Footer with Social Links -->
    <footer class="energy-footer">
        <div class="footer-container">
            <div class="footer-brand-col">
                <a href="index.php" class="energy-brand" style="margin-bottom:12px;">
                    <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah" class="energy-brand-logo-img" style="height:44px;">
                </a>
                <p class="footer-tagline">AI-powered resume screening, intelligence &amp; modern hiring made simple, lah.</p>
            </div>

            <div class="footer-social-col">
                <div class="footer-col-title">Follow Our Journey</div>
                <div class="footer-social-buttons">
                    <a href="https://www.instagram.com/hirelah.my" target="_blank" rel="noopener noreferrer" class="btn-social btn-instagram" title="Follow @hirelah.my on Instagram">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2" y="2" width="20" height="20" rx="5" ry="5"></rect>
                            <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path>
                            <line x1="17.5" y1="6.5" x2="17.51" y2="6.5"></line>
                        </svg>
                        <span>Instagram @hirelah.my</span>
                    </a>
                    <a href="https://www.tiktok.com/@hirelah.my" target="_blank" rel="noopener noreferrer" class="btn-social btn-tiktok" title="Follow @hirelah.my on TikTok">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64c.29 0 .58.04.85.12V9.4a6.33 6.33 0 0 0-6.6 6.33 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.34-6.34V8.75a8.16 8.16 0 0 0 4.78 1.52V6.82a4.85 4.85 0 0 1-1.6-.13z"/>
                        </svg>
                        <span>TikTok @hirelah.my</span>
                    </a>
                </div>
            </div>

            <div class="footer-links-col">
                <div class="footer-col-title">Quick Links</div>
                <div class="footer-quick-links">
                    <a href="resume_check.php">✨ Free AI Resume Check</a>
                    <a href="resume_builder.php">📄 AI Resume Builder</a>
                    <a href="jobs.php">💼 Browse Live Jobs</a>
                    <a href="terms.php">📋 Terms &amp; Conditions</a>
                </div>
            </div>
        </div>

        <div class="footer-bottom-bar">
            <p>&copy; <?= date('Y') ?> HireLah. All rights reserved.</p>
            <p>Made with ❤️ in Malaysia</p>
        </div>
    </footer>

    <script src="theme.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.2/anime.min.js"></script>
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        if (typeof anime === "undefined") return;

        // 1. Hero Entrance Timeline
        const heroTL = anime.timeline({
            easing: 'easeOutExpo'
        });

        // Header elements entrance
        heroTL.add({
            targets: ['.energy-brand', '.energy-nav-link', '.energy-nav .btn-employer'],
            translateY: [-24, 0],
            opacity: [0, 1],
            duration: 800,
            delay: anime.stagger(60),
            easing: 'easeOutQuad'
        })
        // Casey photo entrance
        .add({
            targets: '.energy-headline-bg-photo',
            translateY: [200, 120],
            scale: [0.92, 1],
            opacity: [0, document.documentElement.getAttribute('data-theme') === 'dark' ? 0.9 : 0.85],
            duration: 1300,
            easing: 'easeOutCubic'
        }, '-=600')
        // Headline text & accent badge
        .add({
            targets: '.energy-headline',
            translateY: [40, 0],
            opacity: [0, 1],
            duration: 900,
            easing: 'easeOutExpo'
        }, '-=1000')
        .add({
            targets: '.energy-headline .accent',
            scale: [0.8, 1],
            rotate: [-3, 0],
            duration: 600,
            easing: 'easeOutBack(1.8)'
        }, '-=500')
        // Subtext & CTAs
        .add({
            targets: ['.energy-subtext', '.energy-cta-row', '.energy-cta-caption'],
            translateY: [28, 0],
            opacity: [0, 1],
            duration: 800,
            delay: anime.stagger(100),
            easing: 'easeOutQuad'
        }, '-=600')
        // Free AI Check badge pop
        .add({
            targets: '.resume-check-flag',
            scale: [0, 1],
            rotate: [0, 8],
            duration: 600,
            easing: 'easeOutElastic(1, .6)'
        }, '-=500')
        // Visual blob & card entrance
        .add({
            targets: '.energy-visual-blob',
            scale: [0.6, 1],
            rotate: [-15, -4],
            opacity: [0, 1],
            duration: 1100,
            easing: 'easeOutElastic(1, .7)'
        }, '-=900')
        .add({
            targets: '.energy-visual-card',
            translateY: [60, 0],
            opacity: [0, 1],
            scale: [0.94, 1],
            duration: 1000,
            easing: 'easeOutCubic'
        }, '-=800')
        // Sticky note pop with elastic bounce
        .add({
            targets: '.sticky-note',
            scale: [0, 1],
            rotate: [-10, 6],
            translateY: [-20, 0],
            duration: 800,
            easing: 'easeOutBack(2)'
        }, '-=600')
        // Job item cards stagger inside preview card
        .add({
            targets: ['.job-item-card', '.snapshot-box'],
            translateX: [30, 0],
            opacity: [0, 1],
            duration: 650,
            delay: anime.stagger(90),
            easing: 'easeOutQuad'
        }, '-=500')
        // Doodles pop in
        .add({
            targets: '.energy-doodle',
            scale: [0, 1],
            opacity: [0, 0.85],
            duration: 600,
            delay: anime.stagger(80),
            easing: 'easeOutBack(2.5)'
        }, '-=600');

        // 2. Ambient Continuous Floating Loops
        anime({
            targets: '.energy-doodle:nth-of-type(1)',
            translateY: [-5, 5],
            rotate: [-8, 8],
            duration: 3400,
            direction: 'alternate',
            loop: true,
            easing: 'easeInOutSine'
        });
        anime({
            targets: '.energy-doodle:nth-of-type(2)',
            translateY: [6, -6],
            scale: [0.95, 1.05],
            duration: 4000,
            direction: 'alternate',
            loop: true,
            easing: 'easeInOutSine'
        });
        anime({
            targets: '.energy-doodle:nth-of-type(3), .energy-doodle:nth-of-type(4)',
            translateY: [-4, 4],
            duration: 2800,
            direction: 'alternate',
            loop: true,
            easing: 'easeInOutSine'
        });
        anime({
            targets: '.energy-visual-blob',
            rotate: [-6, -2],
            scale: [0.98, 1.02],
            duration: 4500,
            direction: 'alternate',
            loop: true,
            easing: 'easeInOutSine'
        });
        anime({
            targets: '.sticky-note',
            rotate: [4.5, 7.5],
            duration: 3200,
            direction: 'alternate',
            loop: true,
            easing: 'easeInOutSine'
        });
        anime({
            targets: '.energy-headline-bg-photo',
            translateY: [120, 115],
            scale: [1, 1.015],
            duration: 5000,
            direction: 'alternate',
            loop: true,
            easing: 'easeInOutSine'
        });

        // 3. Stats Counter Animation on Scroll
        const statBar = document.querySelector('.energy-stat-bar');
        if (statBar) {
            let statsAnimated = false;
            const statObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !statsAnimated) {
                        statsAnimated = true;

                        // Stagger entrance for stat items
                        anime({
                            targets: '.energy-stat-item',
                            translateY: [25, 0],
                            opacity: [0, 1],
                            duration: 700,
                            delay: anime.stagger(100),
                            easing: 'easeOutQuad'
                        });

                        // Count numbers up
                        document.querySelectorAll('.energy-stat-num').forEach(el => {
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
                    }
                });
            }, { threshold: 0.2 });
            statObserver.observe(statBar);
        }

        // 4. Features Section Reveal on Scroll
        const featuresSection = document.querySelector('#features');
        if (featuresSection) {
            let featuresAnimated = false;
            const featuresObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !featuresAnimated) {
                        featuresAnimated = true;

                        anime({
                            targets: ['#features .section-tag', '#features .section-title'],
                            translateY: [30, 0],
                            opacity: [0, 1],
                            duration: 800,
                            delay: anime.stagger(120),
                            easing: 'easeOutQuad'
                        });

                        anime({
                            targets: '#features .glass-card',
                            translateY: [50, 0],
                            scale: [0.92, 1],
                            opacity: [0, 1],
                            duration: 900,
                            delay: anime.stagger(150, { start: 250 }),
                            easing: 'easeOutElastic(1, .8)'
                        });

                        anime({
                            targets: '#features .card-icon-box',
                            rotate: [-20, 0],
                            scale: [0.4, 1],
                            duration: 800,
                            delay: anime.stagger(150, { start: 350 }),
                            easing: 'easeOutBack(2)'
                        });
                    }
                });
            }, { threshold: 0.15 });
            featuresObserver.observe(featuresSection);
        }

        // 5. Interactive Mouse Parallax & 3D Tilt on Hero Visual
        const visualWrap = document.querySelector('.energy-visual');
        if (visualWrap && window.innerWidth > 900) {
            const card = visualWrap.querySelector('.energy-visual-card');
            const blob = visualWrap.querySelector('.energy-visual-blob');
            const sticky = visualWrap.querySelector('.sticky-note');

            visualWrap.addEventListener('mousemove', (e) => {
                const rect = visualWrap.getBoundingClientRect();
                const x = (e.clientX - rect.left) / rect.width - 0.5;
                const y = (e.clientY - rect.top) / rect.height - 0.5;

                anime({
                    targets: card,
                    rotateY: x * 14,
                    rotateX: -y * 14,
                    translateZ: 10,
                    duration: 400,
                    easing: 'easeOutQuad'
                });

                anime({
                    targets: blob,
                    translateX: x * 20,
                    translateY: y * 20,
                    duration: 500,
                    easing: 'easeOutQuad'
                });

                anime({
                    targets: sticky,
                    translateX: x * 15,
                    translateY: y * 15,
                    duration: 350,
                    easing: 'easeOutQuad'
                });
            });

            visualWrap.addEventListener('mouseleave', () => {
                anime({
                    targets: card,
                    rotateY: 0,
                    rotateX: 0,
                    translateZ: 0,
                    duration: 800,
                    easing: 'easeOutElastic(1, .6)'
                });
                anime({
                    targets: blob,
                    translateX: 0,
                    translateY: 0,
                    duration: 800,
                    easing: 'easeOutElastic(1, .6)'
                });
                anime({
                    targets: sticky,
                    translateX: 0,
                    translateY: 0,
                    duration: 800,
                    easing: 'easeOutElastic(1, .6)'
                });
            });
        }

        // 6. Micro-interactions on CTA buttons
        document.querySelectorAll('.btn-lime, .btn-outline-dark, .btn-employer').forEach(btn => {
            btn.addEventListener('mouseenter', () => {
                anime({
                    targets: btn,
                    scale: 1.04,
                    duration: 250,
                    easing: 'easeOutBack(1.5)'
                });
            });
            btn.addEventListener('mouseleave', () => {
                anime({
                    targets: btn,
                    scale: 1,
                    duration: 350,
                    easing: 'easeOutQuad'
                });
            });
        });
    });
    </script>
</body>
</html>


