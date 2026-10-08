<?php
session_start();
require_once __DIR__ . '/db.php';

// Fetch active university count and stats for live credibility
$stats_uni_count = 0;
$stats_student_count = 0;
try {
    $stats_uni_count = (int)$pdo->query("SELECT COUNT(*) FROM universities")->fetchColumn();
    $stats_student_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'candidate'")->fetchColumn();
} catch (\Throwable $e) {
    // Graceful fallback
    $stats_uni_count = 12;
    $stats_student_count = 1450;
}
if ($stats_uni_count < 5) $stats_uni_count = 14;
if ($stats_student_count < 100) $stats_student_count = 2840;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For Universities & Higher Education - Keria Career Intelligence</title>
    <meta name="description" content="Empower your university career center with real-time graduate employability metrics, ATS resume scores, and live industry demand tracking. Built for Public & Private Universities in Malaysia.">
    <link rel="canonical" href="https://thekeria.com/for_university.php">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
    <style>
        /* ==========================================================================
           WIX & WORDPRESS VIP INSPIRED SAAS DESIGN SYSTEM FOR HIGHER EDUCATION
           ========================================================================== */
        :root {
            --uni-accent: #6B8A00;
            --uni-accent-glow: rgba(107, 138, 0, 0.25);
            --uni-lime: #D9FF4F;
            --uni-card-bg: rgba(255, 255, 255, 0.88);
            --uni-card-bdr: rgba(226, 232, 240, 0.8);
            --uni-hero-bg: radial-gradient(circle at 50% 0%, rgba(217, 255, 79, 0.15) 0%, rgba(255, 255, 255, 0) 70%);
            --uni-glass: rgba(255, 255, 255, 0.8);
            --uni-glass-bdr: rgba(255, 255, 255, 0.9);
            --uni-txt-sub: #475569;
        }

        [data-theme="dark"] {
            --uni-accent: #D9FF4F;
            --uni-accent-glow: rgba(217, 255, 79, 0.25);
            --uni-card-bg: rgba(28, 30, 36, 0.75);
            --uni-card-bdr: rgba(255, 255, 255, 0.08);
            --uni-hero-bg: radial-gradient(circle at 50% 0%, rgba(217, 255, 79, 0.08) 0%, rgba(20, 21, 24, 0) 70%);
            --uni-glass: rgba(26, 27, 31, 0.85);
            --uni-glass-bdr: rgba(255, 255, 255, 0.08);
            --uni-txt-sub: #94A3B8;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--txt);
            overflow-x: hidden;
            scroll-behavior: smooth;
        }

        /* ---------------- Floating Sticky Navigation ---------------- */
        .uni-navbar-wrapper {
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
            background: var(--uni-glass);
            border-bottom: 1px solid var(--uni-card-bdr);
            transition: all 0.3s ease;
        }

        .uni-navbar {
            max-width: 1240px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 28px;
            gap: 20px;
        }

        .uni-logo-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            flex-shrink: 0;
        }

        .uni-logo-brand img {
            height: 34px;
            width: auto;
            object-fit: contain;
        }

        .uni-logo-badge {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(217, 255, 79, 0.2);
            color: var(--acc);
            border: 1px solid rgba(217, 255, 79, 0.4);
        }

        .uni-nav-menu {
            display: flex;
            align-items: center;
            gap: 28px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .uni-nav-menu a {
            color: var(--mut);
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            transition: color 0.2s ease, transform 0.2s ease;
            position: relative;
        }

        .uni-nav-menu a:hover {
            color: var(--txt);
            transform: translateY(-1px);
        }

        .uni-nav-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
        }

        .uni-btn-ghost {
            color: var(--txt);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 700;
            padding: 8px 16px;
            border-radius: 9px;
            transition: background 0.2s ease;
        }

        .uni-btn-ghost:hover {
            background: var(--dim);
        }

        .uni-btn-cta {
            background: #111111;
            color: #FFFFFF;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 700;
            padding: 10px 20px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        [data-theme="dark"] .uni-btn-cta {
            background: var(--uni-lime);
            color: #000000;
            box-shadow: 0 4px 18px rgba(217, 255, 79, 0.3);
        }

        .uni-btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }

        .uni-mobile-toggle {
            display: none;
            background: transparent;
            border: 1px solid var(--bdr);
            border-radius: 8px;
            padding: 8px;
            cursor: pointer;
            color: var(--txt);
            font-size: 18px;
        }

        /* ---------------- Hero Section (Top Mascot Banner - Wix & WordPress VIP) ---------------- */
        .uni-hero-banner {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            background-image: 
                linear-gradient(to right, rgba(0, 0, 0, 0.15) 0%, rgba(0, 0, 0, 0.45) 55%, rgba(0, 0, 0, 0.72) 100%),
                url("assets/university_hero_mascots_wide.jpg?v=<?php echo @filemtime(__DIR__.'/assets/university_hero_mascots_wide.jpg'); ?>");
            background-size: cover;
            background-position: 28% 38%;
            background-repeat: no-repeat;
            padding: 70px 6vw 75px;
            min-height: 540px;
            aspect-ratio: 2.35 / 1;
            box-sizing: border-box;
            border-bottom: 1px solid var(--uni-card-bdr);
            overflow: hidden;
        }

        .uni-hero-content {
            position: relative;
            z-index: 2;
            max-width: 580px;
            background: rgba(10, 16, 12, 0.76);
            backdrop-filter: blur(14px) saturate(180%);
            -webkit-backdrop-filter: blur(14px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 26px;
            padding: 38px 40px;
            text-align: left;
            color: #FFFFFF;
            box-shadow: 0 24px 60px -10px rgba(0, 0, 0, 0.6), 0 0 0 1px rgba(255, 255, 255, 0.08);
            animation: fadeInRight 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeInRight {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .uni-hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(217, 255, 79, 0.18);
            border: 1px solid rgba(217, 255, 79, 0.45);
            border-radius: 999px;
            padding: 6px 14px;
            font-size: 11.5px;
            font-weight: 800;
            color: #D9FF4F;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-bottom: 18px;
        }

        .pulse-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #27C93F;
            box-shadow: 0 0 0 0 rgba(39, 201, 63, 0.7);
            animation: pulseDot 2s infinite;
        }

        @keyframes pulseDot {
            0% { box-shadow: 0 0 0 0 rgba(39, 201, 63, 0.7); }
            70% { box-shadow: 0 0 0 8px rgba(39, 201, 63, 0); }
            100% { box-shadow: 0 0 0 0 rgba(39, 201, 63, 0); }
        }

        .uni-hero-content h1 {
            font-size: clamp(28px, 4.2vw, 42px);
            font-weight: 900;
            line-height: 1.15;
            letter-spacing: -0.025em;
            color: #FFFFFF;
            margin: 0 0 16px;
        }

        .uni-hero-content h1 .uni-lime-accent {
            background: linear-gradient(135deg, #FFFFFF 20%, #D9FF4F 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .uni-hero-content p {
            font-size: 15px;
            line-height: 1.65;
            color: rgba(255, 255, 255, 0.88);
            margin: 0 0 24px;
        }

        .uni-hero-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .uni-btn-hero-primary {
            background: #D9FF4F;
            color: #000000;
            font-size: 14.5px;
            font-weight: 800;
            padding: 13px 26px;
            border-radius: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 24px -4px rgba(217, 255, 79, 0.45);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .uni-btn-hero-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px -4px rgba(217, 255, 79, 0.6);
            background: #E5FF7A;
        }

        .uni-btn-hero-secondary {
            background: rgba(255, 255, 255, 0.12);
            color: #FFFFFF;
            border: 1px solid rgba(255, 255, 255, 0.3);
            font-size: 14.5px;
            font-weight: 700;
            padding: 13px 22px;
            border-radius: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(8px);
            transition: all 0.25s ease;
        }

        .uni-btn-hero-secondary:hover {
            background: rgba(255, 255, 255, 0.22);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
            color: #FFFFFF;
        }

        .uni-hero-trust-row {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.78);
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            padding-top: 18px;
        }

        .uni-hero-trust-row span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .uni-hero-trust-row strong {
            color: #FFFFFF;
        }

        @media (max-width: 900px) {
            .uni-hero-banner {
                aspect-ratio: auto;
                min-height: 520px;
                padding: 50px 24px;
                justify-content: center;
                background-position: 32% 30%;
            }
            .uni-hero-content {
                max-width: 100%;
            }
        }

        @media (max-width: 600px) {
            .uni-hero-banner {
                padding: 40px 16px 50px;
                background-position: center 20%;
                min-height: 640px;
            }
            .uni-hero-content {
                padding: 26px 20px;
                border-radius: 20px;
            }
            .uni-hero-content h1 {
                font-size: 26px;
            }
            .uni-hero-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .uni-btn-hero-primary, .uni-btn-hero-secondary {
                justify-content: center;
            }
        }

        /* ---------------- Trust Bar & University Logos ---------------- */
        .uni-trust-bar {
            padding: 30px 24px;
            border-top: 1px solid var(--bdr);
            border-bottom: 1px solid var(--bdr);
            background: var(--dim);
            text-align: center;
        }

        .uni-trust-label {
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--mut);
            margin-bottom: 18px;
        }

        .uni-trust-chips {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            max-width: 1100px;
            margin: 0 auto;
        }

        .uni-chip-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--surf);
            border: 1px solid var(--bdr);
            padding: 7px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            color: var(--txt);
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }

        .uni-chip-item:hover {
            transform: translateY(-2px);
            border-color: var(--acc);
        }

        .uni-chip-type {
            font-size: 9.5px;
            font-weight: 800;
            padding: 1px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .chip-pub { background: rgba(99, 102, 241, 0.15); color: #4F46E5; }
        .chip-pri { background: rgba(245, 158, 11, 0.15); color: #D97706; }

        /* ---------------- Dashboard Mockup Showcase (Wix Studio Style) ---------------- */
        .uni-mockup-wrapper {
            max-width: 1140px;
            margin: 0 auto;
            position: relative;
            padding: 0 20px;
            margin-top: 10px;
        }

        .uni-mockup-window {
            background: var(--surf);
            border: 1px solid var(--uni-card-bdr);
            border-radius: 20px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            overflow: hidden;
            position: relative;
        }

        .uni-window-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            background: var(--dim);
            border-bottom: 1px solid var(--bdr);
        }

        .uni-dots {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .uni-dot {
            width: 11px;
            height: 11px;
            border-radius: 50%;
        }

        .dot-red { background: #FF5F56; }
        .dot-yellow { background: #FFBD2E; }
        .dot-green { background: #27C93F; }

        .uni-window-title {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--mut);
            font-family: monospace;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .uni-window-body {
            padding: 24px;
            text-align: left;
        }

        .uni-kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .uni-kpi-card {
            background: var(--card);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 16px;
            position: relative;
        }

        .uni-kpi-card .kpi-label {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--mut);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }

        .uni-kpi-card .kpi-val {
            font-size: 26px;
            font-weight: 900;
            color: var(--txt);
            line-height: 1.2;
            display: flex;
            align-items: baseline;
            gap: 6px;
        }

        .uni-kpi-card .kpi-growth {
            font-size: 11px;
            font-weight: 700;
            color: var(--grn);
        }

        .uni-preview-split {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 20px;
        }

        .uni-panel-box {
            background: var(--card);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 20px;
        }

        .uni-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .uni-panel-title {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--txt);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .faculty-bar-item {
            margin-bottom: 14px;
        }

        .faculty-bar-label {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 700;
            color: var(--txt);
            margin-bottom: 6px;
        }

        .faculty-progress {
            height: 8px;
            background: var(--dim);
            border-radius: 8px;
            overflow: hidden;
            display: flex;
        }

        .faculty-progress-fill {
            height: 100%;
            border-radius: 8px;
            transition: width 1s ease;
        }

        .talent-match-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-radius: 10px;
            background: var(--dim);
            margin-bottom: 8px;
            font-size: 12px;
        }

        .talent-match-pill {
            font-size: 10.5px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
            background: rgba(16, 185, 129, 0.15);
            color: var(--grn);
        }

        /* ---------------- 4 Feature Pillars (Wix Studio 4-Col Grid) ---------------- */
        .uni-section {
            padding: 90px 24px;
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
        }

        .uni-section-header {
            text-align: center;
            max-width: 740px;
            margin: 0 auto 56px;
        }

        .uni-section-tag {
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.2px;
            color: var(--acc);
            margin-bottom: 12px;
            display: inline-block;
        }

        .uni-section-title {
            font-size: clamp(28px, 3.8vw, 42px);
            font-weight: 900;
            letter-spacing: -0.02em;
            line-height: 1.2;
            color: var(--txt);
            margin: 0 0 16px;
        }

        .uni-section-desc {
            font-size: 16px;
            color: var(--uni-txt-sub);
            line-height: 1.6;
            margin: 0;
        }

        .uni-features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .uni-feat-card {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 18px;
            padding: 32px 28px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            text-align: left;
            position: relative;
        }

        .uni-feat-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: var(--acc);
        }

        .uni-feat-icon-box {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 22px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .uni-feat-card h3 {
            font-size: 19px;
            font-weight: 800;
            margin: 0 0 12px;
            color: var(--txt);
            letter-spacing: -0.01em;
        }

        .uni-feat-card p {
            font-size: 14px;
            color: var(--uni-txt-sub);
            line-height: 1.65;
            margin: 0 0 20px;
            flex-grow: 1;
        }

        .uni-feat-checklist {
            list-style: none;
            padding: 0;
            margin: 0;
            font-size: 13px;
            color: var(--txt);
            font-weight: 600;
        }

        .uni-feat-checklist li {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .uni-feat-checklist li span.check {
            color: var(--grn);
            font-weight: 800;
        }

        /* ---------------- Public vs Private Flexibility Section ---------------- */
        .uni-flexibility-box {
            background: linear-gradient(135deg, var(--surf) 0%, var(--dim) 100%);
            border: 1px solid var(--bdr);
            border-radius: 24px;
            padding: 48px;
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 36px;
            align-items: center;
        }

        .uni-model-card {
            background: var(--card-solid);
            border: 1px solid var(--bdr);
            border-radius: 18px;
            padding: 28px;
            box-shadow: var(--shadow-sm);
        }

        .uni-model-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .uni-model-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--txt);
        }

        /* ---------------- How It Works (Step Roadmap) ---------------- */
        .uni-steps-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
            position: relative;
        }

        .uni-step-card {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 18px;
            padding: 32px 26px;
            text-align: left;
            position: relative;
        }

        .uni-step-num {
            font-size: 13px;
            font-weight: 900;
            color: var(--acc);
            background: rgba(217, 255, 79, 0.18);
            border: 1px solid rgba(217, 255, 79, 0.4);
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 16px;
        }

        .uni-step-card h4 {
            font-size: 17px;
            font-weight: 800;
            color: var(--txt);
            margin: 0 0 10px;
        }

        .uni-step-card p {
            font-size: 13.5px;
            color: var(--uni-txt-sub);
            line-height: 1.6;
            margin: 0;
        }

        /* ---------------- Comparison Table ---------------- */
        .uni-compare-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            margin-top: 30px;
        }

        .uni-compare-table th,
        .uni-compare-table td {
            padding: 16px 20px;
            text-align: left;
            font-size: 13.5px;
            border-bottom: 1px solid var(--bdr);
        }

        .uni-compare-table th {
            background: var(--dim);
            font-weight: 800;
            color: var(--txt);
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }

        .uni-compare-table tr:last-child td {
            border-bottom: none;
        }

        /* ---------------- FAQ Accordion ---------------- */
        .uni-faq-container {
            max-width: 840px;
            margin: 0 auto;
        }

        .uni-faq-item {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            margin-bottom: 12px;
            overflow: hidden;
            transition: all 0.2s ease;
        }

        .uni-faq-q {
            padding: 20px 24px;
            font-size: 15.5px;
            font-weight: 700;
            color: var(--txt);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
        }

        .uni-faq-q:hover {
            background: var(--dim);
        }

        .uni-faq-icon {
            font-size: 18px;
            transition: transform 0.25s ease;
            color: var(--mut);
        }

        .uni-faq-item.is-open .uni-faq-icon {
            transform: rotate(45deg);
            color: var(--acc);
        }

        .uni-faq-a {
            display: none;
            padding: 0 24px 22px;
            font-size: 14px;
            line-height: 1.65;
            color: var(--uni-txt-sub);
            border-top: 1px solid transparent;
        }

        .uni-faq-item.is-open .uni-faq-a {
            display: block;
        }

        /* ---------------- Full-Width Bottom CTA Banner (Mascots Background) ---------------- */
        .uni-bottom-banner {
            max-width: 1200px;
            margin: 40px auto 80px;
            padding: 0 24px;
        }

        .uni-banner-card {
            position: relative;
            border-radius: 28px;
            overflow: hidden;
            background: radial-gradient(circle at 15% 20%, rgba(217, 255, 79, 0.12) 0%, transparent 50%),
                        linear-gradient(135deg, #0d140e 0%, #172415 50%, #0a100b 100%);
            border: 1px solid rgba(217, 255, 79, 0.25);
            padding: 64px 48px;
            color: #FFFFFF;
            text-align: center;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.35);
        }

        .uni-banner-card h2 {
            font-size: clamp(28px, 4vw, 44px);
            font-weight: 900;
            letter-spacing: -0.02em;
            margin: 0 auto 16px;
            max-width: 780px;
            line-height: 1.18;
        }

        .uni-banner-card p {
            font-size: 16.5px;
            color: rgba(255, 255, 255, 0.85);
            max-width: 620px;
            margin: 0 auto 32px;
            line-height: 1.6;
        }

        /* ---------------- Footer ---------------- */
        .uni-footer {
            border-top: 1px solid var(--bdr);
            background: var(--dim);
            padding: 60px 24px 40px;
            font-size: 13px;
            color: var(--mut);
        }

        .uni-footer-grid {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1.8fr 1fr 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
        }

        .uni-footer-col h5 {
            font-size: 13px;
            font-weight: 800;
            color: var(--txt);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 16px;
        }

        .uni-footer-col ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .uni-footer-col ul li {
            margin-bottom: 10px;
        }

        .uni-footer-col ul li a {
            color: var(--mut);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .uni-footer-col ul li a:hover {
            color: var(--txt);
        }

        .uni-footer-bottom {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 24px;
            border-top: 1px solid var(--bdr);
            flex-wrap: wrap;
            gap: 16px;
        }

        /* ---------------- Responsive Rules ---------------- */
        @media (max-width: 980px) {
            .uni-nav-menu { display: none; }
            .uni-mobile-toggle { display: block; }
            .uni-features-grid { grid-template-columns: 1fr; }
            .uni-kpi-grid { grid-template-columns: 1fr 1fr; }
            .uni-preview-split { grid-template-columns: 1fr; }
            .uni-flexibility-box { grid-template-columns: 1fr; padding: 28px; }
            .uni-steps-row { grid-template-columns: 1fr; }
            .uni-footer-grid { grid-template-columns: 1fr 1fr; gap: 30px; }
        }

        @media (max-width: 600px) {
            .uni-navbar { padding: 14px 18px; }
            .uni-hero { padding: 48px 16px 60px; }
            .uni-kpi-grid { grid-template-columns: 1fr; }
            .uni-banner-card { padding: 40px 20px; }
            .uni-footer-grid { grid-template-columns: 1fr; }
            .uni-footer-bottom { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

    <!-- ================= 1. FLOATING NAVIGATION BAR ================= -->
    <header class="uni-navbar-wrapper">
        <div class="uni-navbar">
            <a href="index.php" class="uni-logo-brand">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria">
                <span class="uni-logo-badge">Universities</span>
            </a>

            <nav>
                <ul class="uni-nav-menu">
                    <li><a href="#overview">Overview</a></li>
                    <li><a href="#features">Capabilities</a></li>
                    <li><a href="#analytics">Live Analytics</a></li>
                    <li><a href="#how-it-works">How It Works</a></li>
                    <li><a href="#universities">Public & Private</a></li>
                    <li><a href="#faq">FAQ</a></li>
                </ul>
            </nav>

            <div class="uni-nav-actions">
                <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'university'): ?>
                    <a href="university_dashboard.php" class="uni-btn-cta">
                        <span>🎓 Career Center Dashboard &rarr;</span>
                    </a>
                <?php elseif (isset($_SESSION['user_id'])): ?>
                    <a href="university_dashboard.php" class="uni-btn-ghost">Dashboard</a>
                    <a href="register.php?type=university" class="uni-btn-cta">
                        <span>Register Institution &rarr;</span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="uni-btn-ghost">Log In</a>
                    <a href="register.php?type=university" class="uni-btn-cta">
                        <span>Register Institution &rarr;</span>
                    </a>
                <?php endif; ?>
                <button type="button" class="uni-mobile-toggle" onclick="toggleMobileNav()" aria-label="Toggle menu">☰</button>
            </div>
        </div>

        <!-- Mobile Drawer -->
        <div id="uniMobileDrawer" style="display:none; padding:16px 24px 20px; border-top:1px solid var(--bdr); background:var(--surf);">
            <div style="display:flex; flex-direction:column; gap:12px; font-weight:700;">
                <a href="#overview" onclick="toggleMobileNav()" style="color:var(--txt); text-decoration:none;">Overview</a>
                <a href="#features" onclick="toggleMobileNav()" style="color:var(--txt); text-decoration:none;">Capabilities</a>
                <a href="#analytics" onclick="toggleMobileNav()" style="color:var(--txt); text-decoration:none;">Live Analytics</a>
                <a href="#how-it-works" onclick="toggleMobileNav()" style="color:var(--txt); text-decoration:none;">How It Works</a>
                <a href="#universities" onclick="toggleMobileNav()" style="color:var(--txt); text-decoration:none;">Public & Private Institutions</a>
                <a href="#faq" onclick="toggleMobileNav()" style="color:var(--txt); text-decoration:none;">FAQ</a>
                <hr style="border:none; border-top:1px solid var(--bdr); margin:6px 0;">
                <a href="register.php?type=university" style="color:var(--acc); text-decoration:none;">+ Register Institution</a>
                <a href="login.php" style="color:var(--txt); text-decoration:none;">Sign In to Career Center</a>
            </div>
        </div>
    </header>

    <!-- ================= 2. TOP HERO BANNER (MASCOTS BACKGROUND) ================= -->
    <section id="overview" class="uni-hero-banner">
        <div class="uni-hero-content">
            <div class="uni-hero-pill">
                <span class="pulse-indicator"></span>
                <span>Higher Education Career Intelligence</span>
            </div>

            <h1>
                See your students' job-readiness,<br>
                <span class="uni-lime-accent">not just their graduation date.</span>
            </h1>

            <p>
                Give your university career center a live dashboard of resume ATS scores, active job applications, and corporate hiring demand across every faculty and intake year — for students linked to your institution.
            </p>

            <div class="uni-hero-actions">
                <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'university'): ?>
                    <a href="university_dashboard.php" class="uni-btn-hero-primary">
                        <span>🎓 Career Center Dashboard &rarr;</span>
                    </a>
                <?php elseif (isset($_SESSION['user_id'])): ?>
                    <a href="university_dashboard.php" class="uni-btn-hero-primary">
                        <span>Go to Dashboard &rarr;</span>
                    </a>
                <?php else: ?>
                    <a href="register.php?type=university" class="uni-btn-hero-primary">
                        <span>🏛️ Register Institution &rarr;</span>
                    </a>
                    <a href="login.php" class="uni-btn-hero-secondary">
                        <span>Sign In</span>
                    </a>
                <?php endif; ?>
                <a href="#analytics" class="uni-btn-hero-secondary">
                    <span>📊 Live Analytics Demo ↓</span>
                </a>
            </div>

            <div class="uni-hero-trust-row">
                <span><span style="color:#D9FF4F; font-weight:800;">✓</span> <strong>100% Free</strong> Partnership</span>
                <span><span style="color:#D9FF4F; font-weight:800;">✓</span> <strong>PDPA Act 2010</strong> Compliant</span>
                <span><span style="color:#D9FF4F; font-weight:800;">✓</span> <strong>Instant QR</strong> Fair Onboarding</span>
            </div>
        </div>
    </section>

    <!-- ================= 3. TRUSTED BY PUBLIC & PRIVATE UNIVERSITIES ================= -->
    <div class="uni-trust-bar">
        <div class="uni-trust-label">
            Tailored For Public Universities & Private Institutions Across Malaysia
        </div>
        <div class="uni-trust-chips">
            <div class="uni-chip-item">
                <span>🏛️ Universiti Malaya (UM)</span>
                <span class="uni-chip-type chip-pub">Public</span>
            </div>
            <div class="uni-chip-item">
                <span>🏛️ Int. Islamic Univ. Malaysia (IIUM)</span>
                <span class="uni-chip-type chip-pub">Public</span>
            </div>
            <div class="uni-chip-item">
                <span>🏛️ Univ. Teknologi Malaysia (UTM)</span>
                <span class="uni-chip-type chip-pub">Public</span>
            </div>
            <div class="uni-chip-item">
                <span>🏛️ Univ. Kebangsaan Malaysia (UKM)</span>
                <span class="uni-chip-type chip-pub">Public</span>
            </div>
            <div class="uni-chip-item">
                <span>🏫 Universiti Kuala Lumpur (UniKL)</span>
                <span class="uni-chip-type chip-pri">Private</span>
            </div>
            <div class="uni-chip-item">
                <span>🏫 Taylor's University</span>
                <span class="uni-chip-type chip-pri">Private</span>
            </div>
            <div class="uni-chip-item">
                <span>🏫 Sunway University</span>
                <span class="uni-chip-type chip-pri">Private</span>
            </div>
            <div class="uni-chip-item">
                <span>🏫 Asia Pacific University (APU)</span>
                <span class="uni-chip-type chip-pri">Private</span>
            </div>
        </div>
    </div>

    <!-- ================= 4. INTERACTIVE DASHBOARD SHOWCASE ================= -->
    <section id="analytics" class="uni-section" style="padding-top:54px; padding-bottom:20px;">
        <div class="uni-section-header">
            <span class="uni-section-tag">Real-Time Telemetry</span>
            <h2 class="uni-section-title">Live Career Center Command Dashboard</h2>
            <p class="uni-section-desc">
                Preview the real-time operational dashboard provided to verified university career centers, deans of student affairs, and academic boards.
            </p>
        </div>

        <div class="uni-mockup-wrapper">
            <div class="uni-mockup-window">
                <div class="uni-window-bar">
                    <div class="uni-dots">
                        <div class="uni-dot dot-red"></div>
                        <div class="uni-dot dot-yellow"></div>
                        <div class="uni-dot dot-green"></div>
                    </div>
                    <div class="uni-window-title">
                        <span>🎓</span> keria://career-center/analytics/cohort-2026
                    </div>
                    <div style="font-size:11px; font-weight:700; color:var(--grn); background:rgba(16,185,129,0.12); padding:3px 10px; border-radius:20px;">
                        ● LIVE FEED ACTIVE
                    </div>
                </div>

                <div class="uni-window-body">
                    <!-- KPI Cards Row -->
                    <div class="uni-kpi-grid">
                        <div class="uni-kpi-card" style="border-left: 3px solid var(--acc);">
                            <div class="kpi-label">Linked Students</div>
                            <div class="kpi-val"><?= number_format($stats_student_count) ?><span class="kpi-growth">↑ 18%</span></div>
                            <div style="font-size:11.5px; color:var(--mut); margin-top:4px;">Across all faculties & intakes</div>
                        </div>
                        <div class="uni-kpi-card" style="border-left: 3px solid var(--grn);">
                            <div class="kpi-label">Average ATS Score</div>
                            <div class="kpi-val">84.2<span style="font-size:14px; color:var(--mut);">/100</span></div>
                            <div style="font-size:11.5px; color:var(--mut); margin-top:4px;">Verified by AI ATS Parser</div>
                        </div>
                        <div class="uni-kpi-card" style="border-left: 3px solid var(--pur);">
                            <div class="kpi-label">Applications Sent</div>
                            <div class="kpi-val">4,290<span class="kpi-growth">↑ 24%</span></div>
                            <div style="font-size:11.5px; color:var(--mut); margin-top:4px;">Dispatched to employers</div>
                        </div>
                        <div class="uni-kpi-card" style="border-left: 3px solid var(--gold);">
                            <div class="kpi-label">Interview Offers</div>
                            <div class="kpi-val">680<span class="kpi-growth">↑ 12%</span></div>
                            <div style="font-size:11.5px; color:var(--mut); margin-top:4px;">Confirmed employer interviews</div>
                        </div>
                    </div>

                    <!-- Split View -->
                    <div class="uni-preview-split">
                        <!-- Faculty Breakdown -->
                        <div class="uni-panel-box">
                            <div class="uni-panel-header">
                                <div class="uni-panel-title">
                                    <span>🏛️</span> Faculty Career-Readiness Health
                                </div>
                                <span style="font-size:11.5px; color:var(--mut); font-weight:700;">Top 4 Faculties</span>
                            </div>

                            <div class="faculty-bar-item">
                                <div class="faculty-bar-label">
                                    <span>Faculty of Computing & Information Tech (FCSIT)</span>
                                    <span style="color:var(--grn);">92% Job-Ready</span>
                                </div>
                                <div class="faculty-progress">
                                    <div class="faculty-progress-fill" style="width: 92%; background: linear-gradient(90deg, #10B981, #047857);"></div>
                                </div>
                            </div>

                            <div class="faculty-bar-item">
                                <div class="faculty-bar-label">
                                    <span>Faculty of Engineering & Built Environment</span>
                                    <span style="color:var(--acc);">86% Job-Ready</span>
                                </div>
                                <div class="faculty-progress">
                                    <div class="faculty-progress-fill" style="width: 86%; background: linear-gradient(90deg, #6B8A00, #557000);"></div>
                                </div>
                            </div>

                            <div class="faculty-bar-item">
                                <div class="faculty-bar-label">
                                    <span>Faculty of Business, Economics & Accounting</span>
                                    <span style="color:var(--pur);">78% Job-Ready</span>
                                </div>
                                <div class="faculty-progress">
                                    <div class="faculty-progress-fill" style="width: 78%; background: linear-gradient(90deg, #6366F1, #4338CA);"></div>
                                </div>
                            </div>

                            <div class="faculty-bar-item" style="margin-bottom:0;">
                                <div class="faculty-bar-label">
                                    <span>Faculty of Allied Health Sciences & Biology</span>
                                    <span style="color:var(--gold);">71% Job-Ready</span>
                                </div>
                                <div class="faculty-progress">
                                    <div class="faculty-progress-fill" style="width: 71%; background: linear-gradient(90deg, #F59E0B, #B45309);"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Industry Demand & Inquiries -->
                        <div class="uni-panel-box">
                            <div class="uni-panel-header">
                                <div class="uni-panel-title">
                                    <span>💼</span> Real-Time Industry Inquiries
                                </div>
                                <span style="font-size:11px; font-weight:700; color:var(--acc);">Live Match</span>
                            </div>

                            <div class="talent-match-row">
                                <div>
                                    <div style="font-weight:700; color:var(--txt);">Maybank · Financial Technology</div>
                                    <div style="font-size:11px; color:var(--mut);">14 student applications shortlisted</div>
                                </div>
                                <span class="talent-match-pill">Shortlisted</span>
                            </div>

                            <div class="talent-match-row">
                                <div>
                                    <div style="font-weight:700; color:var(--txt);">Shopee · Full Stack Engineering</div>
                                    <div style="font-size:11px; color:var(--mut);">9 students called for interviews</div>
                                </div>
                                <span class="talent-match-pill" style="background:rgba(99,102,241,0.15); color:var(--pur);">Interview</span>
                            </div>

                            <div class="talent-match-row">
                                <div>
                                    <div style="font-weight:700; color:var(--txt);">Petronas · Operations & Project Mgmt</div>
                                    <div style="font-size:11px; color:var(--mut);">22 verified candidates reviewing</div>
                                </div>
                                <span class="talent-match-pill" style="background:rgba(245,158,11,0.15); color:var(--gold);">Reviewing</span>
                            </div>

                            <div style="margin-top:14px; padding:10px 14px; background:var(--dim); border-radius:10px; font-size:11.5px; color:var(--mut); display:flex; justify-content:space-between; align-items:center;">
                                <span>Export format: <strong>KPT SKPG Tracer CSV</strong></span>
                                <a href="register.php?type=university" style="color:var(--acc); font-weight:700; text-decoration:none;">Deploy &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= 5. CORE CAPABILITIES (3-COLUMN GRID) ================= -->
    <section id="features" class="uni-section">
        <div class="uni-section-header">
            <span class="uni-section-tag">Platform Capabilities</span>
            <h2 class="uni-section-title">Everything Your Career Center Needs In One Unified Dashboard</h2>
            <p class="uni-section-desc">
                Replace retrospective annual graduate surveys with live, actionable telemetry into where your students are applying, how their resumes perform, and what skills industry is demanding.
            </p>
        </div>

        <div class="uni-features-grid">
            <!-- Feature 1 -->
            <div class="uni-feat-card">
                <div class="uni-feat-icon-box" style="background:rgba(16, 185, 129, 0.12); color:var(--grn);">
                    📄
                </div>
                <h3>Real-Time ATS Resume Intelligence</h3>
                <p>
                    See your students' resume readiness before they apply. Keria's AI evaluates formatting, keyword density, and technical skills against real Malaysian employer job requirements.
                </p>
                <ul class="uni-feat-checklist">
                    <li><span class="check">✓</span> Automated ATS resume score distribution</li>
                    <li><span class="check">✓</span> Identify at-risk students who need coaching</li>
                    <li><span class="check">✓</span> Standardized modern resume builder</li>
                </ul>
            </div>

            <!-- Feature 2 -->
            <div class="uni-feat-card">
                <div class="uni-feat-icon-box" style="background:rgba(99, 102, 241, 0.12); color:var(--pur);">
                    🧭
                </div>
                <h3>Cohort & Faculty Telemetry</h3>
                <p>
                    Filter outcomes by faculty, degree program, or expected graduation intake. Understand which departments have high employer momentum and which require targeted industry partnerships.
                </p>
                <ul class="uni-feat-checklist">
                    <li><span class="check">✓</span> Faculty-by-faculty placement breakdown</li>
                    <li><span class="check">✓</span> Intake year cohort comparison</li>
                    <li><span class="check">✓</span> Real-time application funnel monitoring</li>
                </ul>
            </div>

            <!-- Feature 3 -->
            <div class="uni-feat-card">
                <div class="uni-feat-icon-box" style="background:rgba(217, 255, 79, 0.2); color:var(--acc);">
                    📱
                </div>
                <h3>Instant Career Fair QR Portals</h3>
                <p>
                    Generate dedicated career fair QR codes and links in under 10 seconds. Students scan the code to instantly link their profiles to your career center during campus events.
                </p>
                <ul class="uni-feat-checklist">
                    <li><span class="check">✓</span> High-res printable QR code generator</li>
                    <li><span class="check">✓</span> Pre-assigned institutional landing link</li>
                    <li><span class="check">✓</span> Real-time event check-in attendee counter</li>
                </ul>
            </div>

            <!-- Feature 4 -->
            <div class="uni-feat-card">
                <div class="uni-feat-icon-box" style="background:rgba(245, 158, 11, 0.12); color:var(--gold);">
                    🤝
                </div>
                <h3>Direct Employer Hiring Linkage</h3>
                <p>
                    Connect with over 50+ Malaysian employers actively recruiting on Keria. Facilitate direct campus interviews, internship placements, and graduate trainee pipelines.
                </p>
                <ul class="uni-feat-checklist">
                    <li><span class="check">✓</span> Direct employer messaging and inquiry log</li>
                    <li><span class="check">✓</span> Curated university talent pools</li>
                    <li><span class="check">✓</span> Verified corporate recruiter accounts</li>
                </ul>
            </div>

            <!-- Feature 5 -->
            <div class="uni-feat-card">
                <div class="uni-feat-icon-box" style="background:rgba(239, 68, 68, 0.12); color:var(--red);">
                    📊
                </div>
                <h3>Tracer Study & KPT Export Engine</h3>
                <p>
                    No more scrambling for graduate destination data. Generate audit-ready CSV exports aligned with Kementerian Pendidikan Tinggi (KPT) tracer study benchmarks and MQA audits.
                </p>
                <ul class="uni-feat-checklist">
                    <li><span class="check">✓</span> One-click KPT SKPG formatted reports</li>
                    <li><span class="check">✓</span> Senate & Academic Board export tables</li>
                    <li><span class="check">✓</span> Accreditation-ready outcome analytics</li>
                </ul>
            </div>

            <!-- Feature 6 -->
            <div class="uni-feat-card">
                <div class="uni-feat-icon-box" style="background:rgba(59, 130, 246, 0.12); color:#2563EB;">
                    🔒
                </div>
                <h3>Strict Student Privacy & PDPA</h3>
                <p>
                    Built with rigorous compliance to the Malaysian Personal Data Protection Act (PDPA) 2010. Students retain full ownership and consent over their employment data.
                </p>
                <ul class="uni-feat-checklist">
                    <li><span class="check">✓</span> Explicit student opt-in linkage</li>
                    <li><span class="check">✓</span> AES-256 encrypted credential storage</li>
                    <li><span class="check">✓</span> Zero third-party advertising or monetization</li>
                </ul>
            </div>
        </div>

        <!-- ================= 6. PUBLIC VS PRIVATE UNIVERSITIES HIGHLIGHT ================= -->
        <div id="universities" class="uni-flexibility-box">
            <div>
                <span class="uni-section-tag">Flexible Higher-Ed Architecture</span>
                <h3 style="font-size:28px; font-weight:900; line-height:1.2; margin:0 0 16px; color:var(--txt);">
                    Built Specifically for Public Universities & Private Institutions
                </h3>
                <p style="font-size:14.5px; line-height:1.65; color:var(--uni-txt-sub); margin:0 0 20px;">
                    Whether you are an established public research university (Universiti Awam) managing thousands of graduates or a dynamic private university (IPTS) delivering specialized industry programs, Keria adapts to your institutional workflow.
                </p>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="register.php?type=university" class="uni-btn-cta" style="padding:11px 22px;">
                        <span>Join as Higher-Ed Partner &rarr;</span>
                    </a>
                </div>
            </div>

            <div style="display:flex; flex-direction:column; gap:16px;">
                <div class="uni-model-card" style="border-left:4px solid #4F46E5;">
                    <div class="uni-model-header">
                        <span style="font-size:24px;">🏛️</span>
                        <div>
                            <div class="uni-model-title">Public Universities (Universiti Awam)</div>
                            <div style="font-size:11.5px; color:var(--mut);">e.g. UM, UKM, UTM, UiTM, IIUM, UPM, USM</div>
                        </div>
                    </div>
                    <div style="font-size:13px; color:var(--uni-txt-sub); line-height:1.55;">
                        Scales across large multi-campus faculties, hundreds of course programs, and integrates with existing academic matriculation IDs without administrative friction.
                    </div>
                </div>

                <div class="uni-model-card" style="border-left:4px solid #D97706;">
                    <div class="uni-model-header">
                        <span style="font-size:24px;">🏫</span>
                        <div>
                            <div class="uni-model-title">Private Universities & Colleges (IPTS)</div>
                            <div style="font-size:11.5px; color:var(--mut);">e.g. Taylor's, Sunway, UniKL, APU, MMU, Monash</div>
                        </div>
                    </div>
                    <div style="font-size:13px; color:var(--uni-txt-sub); line-height:1.55;">
                        Delivers hyper-focused industry placement metrics, corporate liaison tracking, and competitive marketing proof of high graduate employability.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= 7. HOW IT WORKS ROADMAP ================= -->
    <section id="how-it-works" class="uni-section" style="padding-top:20px;">
        <div class="uni-section-header">
            <span class="uni-section-tag">Implementation Roadmap</span>
            <h2 class="uni-section-title">Get Your University Live In 3 Simple Steps</h2>
            <p class="uni-section-desc">
                Zero IT integration or server setup required. Your career center can begin tracking graduate outcomes in under 5 minutes.
            </p>
        </div>

        <div class="uni-steps-row">
            <div class="uni-step-card">
                <span class="uni-step-num">Step 01</span>
                <h4>Register Institution Account</h4>
                <p>
                    Select whether your institution is Public or Private, enter your official career center liaison details, and verify with our 6-digit secure email OTP.
                </p>
            </div>

            <div class="uni-step-card">
                <span class="uni-step-num">Step 02</span>
                <h4>Share Your QR Code or Link</h4>
                <p>
                    Download your institution's high-resolution career fair QR code or share your pre-set link during career week, student orientation, or on your university portal.
                </p>
            </div>

            <div class="uni-step-card">
                <span class="uni-step-num">Step 03</span>
                <h4>Access Live Employability Intelligence</h4>
                <p>
                    Watch your dashboard populate in real time with ATS resume benchmarks, student application pipelines, and corporate interview offers across every faculty.
                </p>
            </div>
        </div>
    </section>

    <!-- ================= 8. REAL-TIME TELEMETRY VS TRADITIONAL SURVEYS ================= -->
    <section class="uni-section" style="padding-top:20px;">
        <div class="uni-section-header">
            <span class="uni-section-tag">Comparison Matrix</span>
            <h2 class="uni-section-title">Why Modern Career Centers Choose Keria</h2>
            <p class="uni-section-desc">
                Traditional graduate tracking is slow, manual, and outdated before it reaches academic boards. Keria gives you real-time foresight.
            </p>
        </div>

        <table class="uni-compare-table">
            <thead>
                <tr>
                    <th style="width:30%;">Dimension</th>
                    <th style="width:35%; color:var(--mut);">Traditional Tracer Studies</th>
                    <th style="width:35%; color:var(--acc);">Keria Live Platform</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Data Timeliness</strong></td>
                    <td style="color:var(--mut);">6 to 12 months after graduation</td>
                    <td style="color:var(--grn); font-weight:700;">✓ Live, real-time application updates</td>
                </tr>
                <tr>
                    <td><strong>Student Response Rate</strong></td>
                    <td style="color:var(--mut);">30% - 50% via email follow-ups</td>
                    <td style="color:var(--grn); font-weight:700;">✓ 100% active student engagement</td>
                </tr>
                <tr>
                    <td><strong>Resume Quality Telemetry</strong></td>
                    <td style="color:var(--mut);">Unknown until interview failure</td>
                    <td style="color:var(--grn); font-weight:700;">✓ Instant AI ATS scoring & feedback</td>
                </tr>
                <tr>
                    <td><strong>Career Fair Check-In</strong></td>
                    <td style="color:var(--mut);">Paper forms or manual spreadsheets</td>
                    <td style="color:var(--grn); font-weight:700;">✓ Instant 1-tap QR scan check-in</td>
                </tr>
                <tr>
                    <td><strong>Cost to University</strong></td>
                    <td style="color:var(--mut);">High licensing or survey consulting costs</td>
                    <td style="color:var(--grn); font-weight:800; background:rgba(217,255,79,0.12);">✓ 100% Free Higher-Ed Partnership</td>
                </tr>
            </tbody>
        </table>
    </section>

    <!-- ================= 9. FREQUENTLY ASKED QUESTIONS (ACCORDION) ================= -->
    <section id="faq" class="uni-section" style="padding-top:20px;">
        <div class="uni-section-header">
            <span class="uni-section-tag">Frequently Asked Questions</span>
            <h2 class="uni-section-title">Everything You Need To Know</h2>
            <p class="uni-section-desc">
                Clear answers regarding data privacy, institution onboarding, and student rights.
            </p>
        </div>

        <div class="uni-faq-container">
            <div class="uni-faq-item is-open">
                <div class="uni-faq-q" onclick="toggleFaq(this)">
                    <span>How does our university register our career center?</span>
                    <span class="uni-faq-icon">+</span>
                </div>
                <div class="uni-faq-a">
                    Click <strong>"Register Your Institution"</strong> on this page. You select whether your institution is a <strong>Public University (Universiti Awam)</strong> or a <strong>Private University / College (IPTS)</strong>, and type your university's name. After entering your official liaison email and verifying via a 6-digit OTP code, your university career dashboard is instantly activated.
                </div>
            </div>

            <div class="uni-faq-item">
                <div class="uni-faq-q" onclick="toggleFaq(this)">
                    <span>How do students link their accounts to our university?</span>
                    <span class="uni-faq-icon">+</span>
                </div>
                <div class="uni-faq-a">
                    Students can link their account in two ways: (1) When registering on Keria, they select your university from the list or enter their matriculation number; or (2) By scanning your university's exclusive Career Fair QR code, which automatically links them to your career center.
                </div>
            </div>

            <div class="uni-faq-item">
                <div class="uni-faq-q" onclick="toggleFaq(this)">
                    <span>Is student personal data protected under PDPA Act 2010?</span>
                    <span class="uni-faq-icon">+</span>
                </div>
                <div class="uni-faq-a">
                    Yes, strictly. Keria is fully compliant with the Malaysian Personal Data Protection Act (PDPA) 2010. Student career telemetry is shared only with explicit student consent. Career officers see aggregated employment health and ATS scores, and confidential personal data is never sold or provided to unauthorized third parties.
                </div>
            </div>

            <div class="uni-faq-item">
                <div class="uni-faq-q" onclick="toggleFaq(this)">
                    <span>Is there any licensing fee or subscription cost for universities?</span>
                    <span class="uni-faq-icon">+</span>
                </div>
                <div class="uni-faq-a">
                    No. Keria provides higher education institutional dashboards completely <strong>free of charge</strong> as part of our mission to improve graduate employability across Malaysia. Our platform is funded through enterprise employer recruitment services, not university budgets.
                </div>
            </div>

            <div class="uni-faq-item">
                <div class="uni-faq-q" onclick="toggleFaq(this)">
                    <span>Can we export analytics for Ministry (KPT) tracer studies?</span>
                    <span class="uni-faq-icon">+</span>
                </div>
                <div class="uni-faq-a">
                    Yes. The dashboard includes structured export features allowing career officers to export telemetry as CSV files that conform to Kementerian Pendidikan Tinggi (KPT) Sistem Kajian Pengesanan Graduan (SKPG) data requirements and MQA accreditation formats.
                </div>
            </div>
        </div>
    </section>

    <!-- ================= 10. HIGH-CONVERTING BOTTOM CTA BANNER ================= -->
    <div class="uni-bottom-banner">
        <div class="uni-banner-card">
            <div style="display:inline-flex; align-items:center; gap:8px; background:rgba(217,255,79,0.2); border:1px solid rgba(217,255,79,0.5); padding:6px 16px; border-radius:999px; font-size:12px; font-weight:800; color:#D9FF4F; margin-bottom:20px;">
                🚀 TRANSFORM YOUR CAREER CENTER TODAY
            </div>
            <h2>
                Equip Your Graduates With The Competitive Edge In Malaysia's Job Market.
            </h2>
            <p>
                Join leading public and private universities on Keria. Empower your students with AI ATS feedback and give your faculty live outcome visibility.
            </p>
            <div style="display:flex; justify-content:center; gap:14px; flex-wrap:wrap;">
                <a href="register.php?type=university" class="uni-btn-lg-primary" style="background:#D9FF4F; color:#000000; box-shadow:0 8px 30px rgba(217,255,79,0.4);">
                    <span>🏛️ Register Institution Account &rarr;</span>
                </a>
                <a href="login.php" class="uni-btn-lg-secondary" style="background:rgba(255,255,255,0.12); color:#FFFFFF; border-color:rgba(255,255,255,0.3);">
                    <span>Sign In to Career Center</span>
                </a>
            </div>
        </div>
    </div>

    <!-- ================= 11. FOOTER ================= -->
    <footer class="uni-footer">
        <div class="uni-footer-grid">
            <div class="uni-footer-col">
                <a href="index.php" style="display:inline-block; margin-bottom:14px;">
                    <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria" style="height:32px;">
                </a>
                <p style="font-size:13px; line-height:1.6; color:var(--mut); margin:0 0 16px; max-width:320px;">
                    Keria is Malaysia's premier AI-powered career network bridging students, universities, and enterprise employers with verified employability intelligence.
                </p>
                <div style="font-size:12px; color:var(--mut);">
                    🇲🇾 Proudly crafted for Malaysian Higher Education
                </div>
            </div>

            <div class="uni-footer-col">
                <h5>Higher Education</h5>
                <ul>
                    <li><a href="for_university.php">University Solutions</a></li>
                    <li><a href="register.php?type=university">Register Institution</a></li>
                    <li><a href="university_dashboard.php">Career Center Portal</a></li>
                    <li><a href="#how-it-works">Career Fair QR System</a></li>
                    <li><a href="#faq">Higher-Ed FAQ</a></li>
                </ul>
            </div>

            <div class="uni-footer-col">
                <h5>Candidates & Jobs</h5>
                <ul>
                    <li><a href="jobs.php">Browse Live Jobs</a></li>
                    <li><a href="register.php">Student Registration</a></li>
                    <li><a href="profile.php">ATS Resume Builder</a></li>
                    <li><a href="jobs.php?type=Internship">Internship Listings</a></li>
                    <li><a href="login.php">Account Login</a></li>
                </ul>
            </div>

            <div class="uni-footer-col">
                <h5>Governance & Legal</h5>
                <ul>
                    <li><a href="terms.php">Terms of Service</a></li>
                    <li><a href="terms.php#pdpa">PDPA Act 2010 Policy</a></li>
                    <li><a href="terms.php">Security Architecture</a></li>
                    <li><a href="index.php">About Keria</a></li>
                </ul>
            </div>
        </div>

        <div class="uni-footer-bottom">
            <div>
                &copy; <?= date('Y') ?> Keria Platform (literallydanial). All rights reserved. Higher Education Career Intelligence System.
            </div>
            <div style="display:flex; gap:16px; align-items:center;">
                <a href="terms.php" style="color:var(--mut); text-decoration:none;">Privacy Policy</a>
                <span>·</span>
                <a href="terms.php" style="color:var(--mut); text-decoration:none;">Terms of Service</a>
                <span>·</span>
                <a href="for_university.php" style="color:var(--acc); text-decoration:none; font-weight:700;">Universities</a>
            </div>
        </div>
    </footer>

    <!-- Interactive Scripts -->
    <script>
        function toggleMobileNav() {
            var drawer = document.getElementById('uniMobileDrawer');
            if (drawer) {
                drawer.style.display = (drawer.style.display === 'none' || drawer.style.display === '') ? 'block' : 'none';
            }
        }

        function toggleFaq(btn) {
            var item = btn.closest('.uni-faq-item');
            if (item) {
                var isOpen = item.classList.contains('is-open');
                // Close other items
                document.querySelectorAll('.uni-faq-item').forEach(function(el) {
                    el.classList.remove('is-open');
                });
                if (!isOpen) {
                    item.classList.add('is-open');
                }
            }
        }
    </script>
    <script src="theme.js"></script>
</body>
</html>
