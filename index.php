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
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
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
            opacity: 0.18;
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
        .energy-cta-row { display: flex; align-items: center; gap: 14px; margin-bottom: 40px; flex-wrap: wrap; position: relative; z-index: 1; }
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

        @media (max-width: 900px) {
            .energy-header { padding: 16px 20px; }
            .energy-nav { gap: 16px; }
            .energy-nav a.energy-nav-link { display: none; }
            .energy-hero-section { padding: 40px 20px 0; }
            .energy-hero-grid { grid-template-columns: 1fr; }
            .energy-stat-bar { grid-template-columns: repeat(2, 1fr); }
            .energy-stat-item { border-bottom: 1px solid rgba(255,255,255,0.12); }
        }
        /* ================= End Hero Redesign ================= */
    </style>
</head>
<body>
    <?php if(isset($_SESSION['toast'])): ?>
        <div class="toast-notification">
            <span class="toast-icon-badge">🌿</span>
            <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
            <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
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
            <a href="jobs.php" class="energy-nav-link">Find Jobs</a>
            <a href="resume_builder.php" class="energy-nav-link">Resume Builder</a>
            <a href="register.php" class="energy-nav-link">Join HireLah</a>
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
                    <img src="assets/hero-photo.png" alt="" class="energy-headline-bg-photo">
                    <h1 class="energy-headline">
                        Find work that<br>actually fits, <span class="accent">lah.</span>
                    </h1>
                </div>

                <p class="energy-subtext">
                    Smart matches powered by AI-assisted screening. Real opportunities, reviewed with a human touch, built for your future.
                </p>

                <div class="energy-cta-row">
                    <a href="jobs.php" class="btn-lime">
                        Search Jobs
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>
                    </a>
                    <a href="resume_check.php" class="btn-outline-dark">
                        Upload Resume
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"></path><path d="m7 8 5-5 5 5"></path><path d="M5 21h14"></path></svg>
                    </a>
                </div>
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
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                <div><span class="energy-stat-num"><?= $total_live_roles ?>+</span><span class="energy-stat-label">Active Jobs</span></div>
            </div>
            <div class="energy-stat-item">
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"></path><path d="M5 21V7l7-4 7 4v14"></path><path d="M9 9h1"></path><path d="M9 13h1"></path><path d="M14 9h1"></path><path d="M14 13h1"></path></svg>
                <div><span class="energy-stat-num"><?= $departments_hiring ?>+</span><span class="energy-stat-label">Departments Hiring</span></div>
            </div>
            <div class="energy-stat-item">
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"></path></svg>
                <div><span class="energy-stat-num"><?= $strong_hires ?>+</span><span class="energy-stat-label">Strong Hires Flagged</span></div>
            </div>
            <div class="energy-stat-item">
                <svg class="energy-stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3v4a1 1 0 0 0 1 1h4"></path><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"></path></svg>
                <div><span class="energy-stat-num"><?= $resumes_screened ?>+</span><span class="energy-stat-label">Resumes Screened</span></div>
            </div>
        </div>
    </section>

    <!-- Sleek Grid Feature Breakdown -->
    <section class="features-section" id="features">
        <div class="section-header">
            <div class="section-tag">ENGINEERED FOR SPEED & EMPATHY</div>
            <h2 class="section-title">Why modern teams choose HireLah</h2>
        </div>

        <div class="grid-3">
            <div class="glass-card">
                <div class="card-icon-box">🧠</div>
                <h3 class="glass-card-title">Structured Resume Insights</h3>
                <p class="glass-card-desc">Instead of unorganized applicant files, our system extracts resume text cleanly, providing structured summaries of candidate experience and capability.</p>
            </div>

            <div class="glass-card">
                <div class="card-icon-box">🎯</div>
                <h3 class="glass-card-title">Custom Questionnaires</h3>
                <p class="glass-card-desc">Send tailored screening questionnaires specific to each position to evaluate skills and work history before inviting candidates to an interview.</p>
            </div>

            <div class="glass-card">
                <div class="card-icon-box">🔒</div>
                <h3 class="glass-card-title">Bias-Free Screening</h3>
                <p class="glass-card-desc">Automatically strip PII to evaluate talent purely on merit, keeping hiring decisions transparent, ethical, and human-guided.</p>
            </div>
        </div>
    </section>

    <script src="theme.js"></script>
</body>
</html>


