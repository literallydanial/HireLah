<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>For Universities - Keria</title>
    <meta name="description" content="Partner with Keria to track your students' career-readiness and job-search activity, and give your career center a live view of graduate outcomes.">
    <link rel="canonical" href="https://thekeria.com/for_university.php">
    <meta name="robots" content="noindex, follow">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
    <style>
        .uni-hero-header {
            display:flex; align-items:center; justify-content:space-between;
            padding:20px 40px; background:var(--surf); border-bottom:1px solid var(--bdr);
        }
        .uni-hero-header a.uni-logo { display:flex; align-items:center; gap:8px; text-decoration:none; }
        .uni-hero-header a.uni-logo img { height:32px; width:auto; }
        .uni-nav-links a { color:var(--mut); text-decoration:none; font-size:13.5px; font-weight:600; margin-left:24px; }
        .uni-nav-links a:hover { color:var(--txt); }
        .uni-feature-card {
            background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:22px;
            text-align:left;
        }
        .uni-feature-icon {
            width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center;
            font-size:20px; margin-bottom:12px;
        }
        @media (max-width: 720px) {
            .uni-hero-header { padding:16px 20px; }
            .uni-nav-links a { margin-left:14px; font-size:12.5px; }
        }
        .uni-hero-banner {
            position:relative;
            display:flex;
            align-items:center;
            justify-content:flex-end;
            background-image: url("assets/university_hero_mascots_wide.jpg?v=<?php echo @filemtime(__DIR__.'/assets/university_hero_mascots_wide.jpg'); ?>");
            background-size: cover;
            background-position: 28% 38%;
            background-repeat: no-repeat;
            padding: 72px 5vw 80px;
            text-align: center;
            aspect-ratio: 2.4 / 1;
        }
        .uni-hero-banner .uni-hero-content {
            display:inline-block;
            max-width: 560px;
            background: rgba(8, 14, 8, 0.62);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 22px;
            padding: 32px 36px;
        }
        .uni-hero-banner .uni-hero-pill {
            display:inline-flex; align-items:center; gap:8px;
            background:rgba(217,255,79,0.18); border:1px solid rgba(217,255,79,0.45);
            border-radius:999px; padding:6px 14px; font-size:12px; font-weight:700;
            color:#D9FF4F; margin-bottom:18px;
        }
        .uni-hero-banner h1 {
            font-size: clamp(28px, 5vw, 44px); font-weight:800; color:#fff;
            margin:0 0 14px; line-height:1.15;
        }
        .uni-hero-banner p {
            font-size:15.5px; color:rgba(255,255,255,0.9); max-width:560px;
            margin:0 auto 32px; line-height:1.6;
        }
        @media (max-width: 720px) {
            .uni-hero-banner { padding:48px 16px 56px; background-position: center 22%; justify-content:center; aspect-ratio: auto; min-height:70vh; }
            .uni-hero-banner .uni-hero-content { padding:24px 20px; }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

    <header class="uni-hero-header" style="position:relative; z-index:2;">
        <a href="index.php" class="uni-logo">
            <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="keria">
        </a>
        <nav class="uni-nav-links">
            <a href="jobs.php">Jobs</a>
            <a href="register.php">For Employers</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="university_dashboard.php">Dashboard</a>
            <?php else: ?>
                <a href="login.php">Log in</a>
            <?php endif; ?>
        </nav>
    </header>

    <section class="uni-hero-banner" style="z-index:2;">
        <div class="uni-hero-content">
            <div class="uni-hero-pill">
                🎓 For Universities & Career Centers
            </div>
            <h1>
                See your students' job-readiness,<br>not just their graduation date.
            </h1>
            <p>
                Give your career center a live dashboard of resume completion, application activity, and job-search outcomes across every faculty and intake year — for the students who've linked their Keria account to your institution.
            </p>
            <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap; align-items:center;">
                <?php if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'university'): ?>
                    <a href="university_dashboard.php" class="btn-primary" style="display:inline-flex; width:auto; padding:14px 28px; text-decoration:none;">Go to Dashboard &rarr;</a>
                <?php else: ?>
                    <span class="btn-primary" style="display:inline-flex; width:auto; padding:14px 28px; cursor:default; opacity:0.75; pointer-events:none;">🚧 Coming Soon</span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <main style="max-width:880px; margin:0 auto; padding:48px 20px 80px; position:relative; z-index:2;">
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:48px;">
            <div class="uni-feature-card">
                <div class="uni-feature-icon" style="background:rgba(16,185,129,0.12); color:var(--grn);">📄</div>
                <div style="font-weight:700; font-size:14.5px; color:var(--txt); margin-bottom:6px;">Resume completion</div>
                <div style="font-size:12.5px; color:var(--mut); line-height:1.55;">See what share of your students have a job-ready resume built or checked on Keria.</div>
            </div>
            <div class="uni-feature-card">
                <div class="uni-feature-icon" style="background:rgba(79,63,240,0.12); color:var(--pur);">📮</div>
                <div style="font-weight:700; font-size:14.5px; color:var(--txt); margin-bottom:6px;">Application activity</div>
                <div style="font-size:12.5px; color:var(--mut); line-height:1.55;">Track how many applications are going out per faculty and intake year.</div>
            </div>
            <div class="uni-feature-card">
                <div class="uni-feature-icon" style="background:rgba(217,255,79,0.15); color:var(--acc);">🧭</div>
                <div style="font-weight:700; font-size:14.5px; color:var(--txt); margin-bottom:6px;">Career-readiness scores</div>
                <div style="font-size:12.5px; color:var(--mut); line-height:1.55;">Spot students who haven't engaged yet, so your career center can reach out early.</div>
            </div>
        </div>

        <div style="background:rgba(217,255,79,0.08); border:1px solid rgba(217,255,79,0.25); border-radius:14px; padding:18px 22px; font-size:12.5px; color:var(--mut); line-height:1.6;">
            🚧 <strong style="color:var(--txt);">Coming Soon:</strong> we're finalizing how student data is shared with career centers before opening university sign-ups. Check back soon.
        </div>
    </main>

<script src="theme.js"></script></body>
</html>
