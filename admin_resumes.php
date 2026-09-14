<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'admin_logs_helper.php';

// Ensure user is logged in as admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    $_SESSION['error'] = "Access denied. Admin privileges required.";
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// Ensure table exists for resume_builds if not already created
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `resume_builds` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `target_title` varchar(255) DEFAULT NULL,
        `raw_input` longtext DEFAULT NULL,
        `generated_content` longtext DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `fk_resumebuild_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (\Throwable $e) {}

// Ensure table exists for resume_reviews if not already created
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `resume_reviews` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) DEFAULT NULL,
        `filename` varchar(255) DEFAULT NULL,
        `full_text` longtext DEFAULT NULL,
        `stripped_text` longtext DEFAULT NULL,
        `overall_score` int(11) DEFAULT 0,
        `rating_label` varchar(50) DEFAULT NULL,
        `summary` text DEFAULT NULL,
        `strengths` text DEFAULT NULL,
        `improvements` text DEFAULT NULL,
        `formatting_notes` text DEFAULT NULL,
        `ats_tips` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (\Throwable $e) {}

// ----------------------------------------------------
// 1. FETCH RESUMES ACROSS THE 3 SOURCES
// ----------------------------------------------------

// A. Job Applications (candidates table)
$job_resumes = $pdo->query("
    SELECT c.id, c.name as candidate_name, c.email as candidate_email, c.phone, c.filename, c.resume_path, 
           c.created_at, c.overall_score, c.status, c.recommendation, c.job_id,
           j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name, 'Direct') as employer_name,
           'job_apply' as source_type,
           'Job Application' as source_label
    FROM candidates c
    LEFT JOIN jobs j ON c.job_id = j.id
    LEFT JOIN users u ON j.employer_id = u.id
    WHERE c.resume_path IS NOT NULL AND c.resume_path != ''
    ORDER BY c.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// B. Resume Builder (resume_builds table)
$builder_resumes = $pdo->query("
    SELECT b.id, u.name as candidate_name, u.email as candidate_email, '' as phone,
           CONCAT('Resume_', COALESCE(NULLIF(b.target_title, ''), 'Document'), '.pdf') as filename,
           b.target_title, b.raw_input, b.generated_content, b.created_at,
           100 as overall_score, 'Generated' as status, 'AI Built' as recommendation, NULL as job_id,
           COALESCE(NULLIF(b.target_title, ''), 'Custom Resume') as job_title,
           'AI Resume Builder' as employer_name,
           'resume_builder' as source_type,
           'Resume Builder' as source_label
    FROM resume_builds b
    LEFT JOIN users u ON b.user_id = u.id
    ORDER BY b.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// C. Resume Checker (resume_reviews table)
$checker_resumes = $pdo->query("
    SELECT r.id, COALESCE(u.name, 'Guest / Candidate') as candidate_name, 
           COALESCE(u.email, 'Direct Upload') as candidate_email, '' as phone,
           r.filename, r.created_at, r.overall_score, 
           COALESCE(r.rating_label, 'Evaluated') as status,
           'ATS Audited' as recommendation, NULL as job_id,
           'Resume Review Feedback' as job_title,
           'AI Resume Checker' as employer_name,
           'resume_checker' as source_type,
           'Resume Checker' as source_label
    FROM resume_reviews r
    LEFT JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Combined List
$all_resumes = array_merge($job_resumes, $builder_resumes, $checker_resumes);

// Sort combined list by created_at DESC
usort($all_resumes, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Fetch active jobs list for filter dropdown
$jobs_list = $pdo->query("SELECT j.id, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id ORDER BY j.created_at DESC")->fetchAll();

$count_job_apply = count($job_resumes);
$count_builder = count($builder_resumes);
$count_checker = count($checker_resumes);
$count_total = count($all_resumes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume Hub & Bulk Export - Keria Admin</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <style>
        :root {
            --grad-admin-purple: linear-gradient(135deg, #1F1F1F, #000000);
            --grad-violet: linear-gradient(135deg, #6366F1, #4338CA);
            --grad-green:  linear-gradient(135deg, #10B981, #047857);
            --grad-amber:  linear-gradient(135deg, #F59E0B, #B45309);
            --grad-blue:   linear-gradient(135deg, #3B82F6, #1D4ED8);
            --glow-purple: 0 8px 20px rgba(180, 214, 0, 0.25);
            --glow-violet: 0 8px 20px rgba(99, 102, 241, 0.25);
            --glow-green:  0 8px 20px rgba(16, 185, 129, 0.25);
            --glow-amber:  0 8px 20px rgba(245, 158, 11, 0.25);
        }
        .avatar-chip {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: #fff;
            flex-shrink: 0;
        }
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .stats-summary-grid .stat-box .logo-box {
            background: var(--dim) !important;
            border-color: transparent !important;
        }
        .tab-controls {
            display: flex;
            gap: 8px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 9px 16px;
            border: 1px solid var(--bdr);
            border-radius: 10px;
            background: var(--surf);
            color: var(--mut);
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .tab-btn.active {
            color: #fff;
            background: var(--grad-admin-purple);
            border-color: transparent;
            box-shadow: var(--glow-purple);
        }
        .admin-table {
            background: var(--surf);
            border: var(--glass-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            width: 100%;
        }
        .admin-tr {
            display: grid;
            grid-template-columns: 140px 1.4fr 1.4fr 1.4fr 110px 90px 110px;
            gap: 10px;
            padding: 12px 16px;
            align-items: center;
            border-bottom: 1px solid var(--bdr);
            font-size: 12px;
            border-left: 3px solid transparent;
        }
        .admin-th {
            background: var(--surf);
            font-size: 11px;
            color: var(--mut);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            padding: 12px 16px;
            border-bottom: 2px solid var(--bdr);
        }
        .admin-tr:not(.admin-th):hover {
            background: var(--dim);
        }
        .badge-source {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 9px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge-source-job {
            background: rgba(16, 185, 129, 0.12);
            color: var(--grn);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .badge-source-builder {
            background: rgba(99, 102, 241, 0.12);
            color: #818CF8;
            border: 1px solid rgba(99, 102, 241, 0.3);
        }
        .badge-source-checker {
            background: rgba(245, 158, 11, 0.12);
            color: var(--gold);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }
        .export-card {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow-md);
            margin-top: 24px;
            backdrop-filter: var(--glass-blur);
        }
        .header-inner {
            max-width: 100% !important;
            padding: 0 36px !important;
            box-sizing: border-box;
        }
        main {
            max-width: 100% !important;
            width: 100% !important;
            padding: 24px 36px 40px !important;
            margin: 0 auto !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 1024px) {
            .header-inner { padding: 0 16px !important; }
            main { padding: 16px 16px 32px !important; }
            .stats-summary-grid { grid-template-columns: 1fr 1fr; }
            .admin-tr { grid-template-columns: 120px 1fr 1fr 100px 90px; }
            .admin-th-hide-mobile { display: none; }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="admin_dashboard.php">🛡️ Admin Control Panel</a>
                <a href="admin_resumes.php" class="active">📄 Resumes & Export</a>
                <a href="admin_logs.php">📜 Audit Logs</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>

            <div class="header-right-actions" style="margin-left:auto; display:flex; align-items:center; gap:10px;">
                <span class="user-info-text" style="font-size:12px; color:var(--mut);">Logged in as <strong><?= htmlspecialchars($admin_name) ?></strong> (System Admin)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main>
        
        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size:26px; font-weight:800; color:var(--txt); margin:0;">📄 Centralized Resume Hub & Bulk Export</h1>
                <p style="font-size:13px; color:var(--mut); margin-top:4px; margin-bottom:0;">Browse all candidate resumes collected across Job Applications, AI Resume Builder, and AI Resume Checker, and export them into a ZIP archive.</p>
            </div>
            <a href="#exportSection" class="btn-primary" style="padding:11px 22px; font-size:13.5px; width:auto; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border-radius:10px;">
                <span>📥 Go to Export Controls &darr;</span>
            </a>
        </div>

        <!-- Metric Overview Banner -->
        <div class="stats-summary-grid">
            <div class="stat-box" style="border-left:3px solid #6B8A00;">
                <div class="logo-box">📑</div>
                <div><div class="stat-val"><?= $count_total ?></div><div class="stat-lbl">Total Documents</div></div>
            </div>
            <div class="stat-box" style="border-left:3px solid #10B981;">
                <div class="logo-box">💼</div>
                <div><div class="stat-val"><?= $count_job_apply ?></div><div class="stat-lbl">Job Applications</div></div>
            </div>
            <div class="stat-box" style="border-left:3px solid #6366F1;">
                <div class="logo-box">🛠️</div>
                <div><div class="stat-val"><?= $count_builder ?></div><div class="stat-lbl">Resume Builder</div></div>
            </div>
            <div class="stat-box" style="border-left:3px solid #F59E0B;">
                <div class="logo-box">🔍</div>
                <div><div class="stat-val"><?= $count_checker ?></div><div class="stat-lbl">Resume Checker</div></div>
            </div>
        </div>

        <!-- List Section -->
        <div class="panel" style="padding:22px; margin-bottom:28px;">
            
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:18px;">
                <!-- Source Filter Tabs -->
                <div class="tab-controls" style="margin-bottom:0;">
                    <button type="button" class="tab-btn active" onclick="filterBySource('all', this)">🌟 All Sources (<?= $count_total ?>)</button>
                    <button type="button" class="tab-btn" onclick="filterBySource('job_apply', this)">💼 Job Applications (<?= $count_job_apply ?>)</button>
                    <button type="button" class="tab-btn" onclick="filterBySource('resume_builder', this)">🛠️ Resume Builder (<?= $count_builder ?>)</button>
                    <button type="button" class="tab-btn" onclick="filterBySource('resume_checker', this)">🔍 Resume Checker (<?= $count_checker ?>)</button>
                </div>

                <!-- Instant Search Input -->
                <input type="text" id="resumeSearchInput" onkeyup="filterResumeTable()" placeholder="🔍 Search name, email, job, filename..." style="max-width:320px; padding:9px 14px; font-size:13px; margin:0;">
            </div>

            <!-- Resumes Table -->
            <div class="admin-table">
                <div class="admin-tr admin-th">
                    <div>Source</div>
                    <div>Candidate / User</div>
                    <div>Target / Job Title</div>
                    <div class="admin-th-hide-mobile">Document Name</div>
                    <div>Submitted Date</div>
                    <div style="text-align:center;">Score</div>
                    <div style="text-align:center;">Action</div>
                </div>

                <?php if(empty($all_resumes)): ?>
                    <div style="padding:32px; text-align:center; color:var(--mut); font-size:13px;">No resumes found on the platform yet.</div>
                <?php else: ?>
                    <?php foreach($all_resumes as $r): ?>
                        <?php
                            $source_badge_class = $r['source_type'] === 'job_apply' ? 'badge-source-job' : ($r['source_type'] === 'resume_builder' ? 'badge-source-builder' : 'badge-source-checker');
                            $source_icon = $r['source_type'] === 'job_apply' ? '💼' : ($r['source_type'] === 'resume_builder' ? '🛠️' : '🔍');
                            $accent_border = $r['source_type'] === 'job_apply' ? '#10B981' : ($r['source_type'] === 'resume_builder' ? '#6366F1' : '#F59E0B');
                        ?>
                        <div class="admin-tr resume-row-item" 
                             style="border-left-color: <?= $accent_border ?>;"
                             data-source="<?= htmlspecialchars($r['source_type']) ?>" 
                             data-search="<?= strtolower(htmlspecialchars($r['candidate_name'] . ' ' . $r['candidate_email'] . ' ' . $r['job_title'] . ' ' . $r['filename'] . ' ' . $r['source_label'])) ?>">
                            
                            <!-- Source Badge -->
                            <div>
                                <span class="badge-source <?= $source_badge_class ?>">
                                    <?= $source_icon ?> <?= htmlspecialchars($r['source_label']) ?>
                                </span>
                            </div>

                            <!-- Candidate Details -->
                            <div style="display:flex; align-items:center; gap:8px; overflow:hidden;">
                                <span class="avatar-chip" style="background:linear-gradient(135deg, <?= $accent_border ?>, #0A0A0A);"><?= strtoupper(substr($r['candidate_name'] ?: 'C', 0, 1)) ?></span>
                                <div style="overflow:hidden; text-overflow:ellipsis;">
                                    <div style="font-weight:800; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($r['candidate_name'] ?: 'Unknown') ?></div>
                                    <div style="font-size:11px; color:var(--mut); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($r['candidate_email'] ?: 'N/A') ?></div>
                                </div>
                            </div>

                            <!-- Job / Target Title -->
                            <div style="overflow:hidden; text-overflow:ellipsis;">
                                <div style="font-weight:700; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($r['job_title'] ?: 'General') ?></div>
                                <div style="font-size:11px; color:var(--mut); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($r['employer_name'] ?: 'Platform') ?></div>
                            </div>

                            <!-- Document Name -->
                            <div class="admin-th-hide-mobile" style="color:var(--mut); font-size:11px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($r['filename']) ?>">
                                📄 <?= htmlspecialchars($r['filename'] ?: 'resume.pdf') ?>
                            </div>

                            <!-- Date Created -->
                            <div style="color:var(--mut); font-size:11.5px; white-space:nowrap;">
                                <?= date('M j, Y', strtotime($r['created_at'])) ?>
                            </div>

                            <!-- Match / Score -->
                            <div style="text-align:center;">
                                <span style="font-weight:800; color:<?= (int)$r['overall_score'] >= 70 ? 'var(--grn)' : ((int)$r['overall_score'] >= 50 ? 'var(--gold)' : 'var(--red)') ?>;">
                                    <?= (int)$r['overall_score'] ?>%
                                </span>
                            </div>

                            <!-- Action -->
                            <div style="text-align:center;">
                                <?php if($r['source_type'] === 'job_apply' && !empty($r['resume_path']) && file_exists($r['resume_path'])): ?>
                                    <a href="<?= htmlspecialchars($r['resume_path']) ?>" target="_blank" class="btn-secondary" style="padding:4px 9px; font-size:11px; text-decoration:none;" title="View Original PDF">
                                        📄 View PDF
                                    </a>
                                <?php elseif($r['source_type'] === 'job_apply'): ?>
                                    <a href="candidate.php?id=<?= urlencode($r['id']) ?>" target="_blank" class="btn-secondary" style="padding:4px 9px; font-size:11px; text-decoration:none;">
                                        👤 Details
                                    </a>
                                <?php elseif($r['source_type'] === 'resume_builder'): ?>
                                    <button type="button" onclick="openBuilderPreview(<?= htmlspecialchars(json_encode($r)) ?>)" class="btn-secondary" style="padding:4px 9px; font-size:11px;">
                                        👁️ View Build
                                    </button>
                                <?php else: ?>
                                    <span style="font-size:11px; color:var(--mut);">Audited</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dedicated Export Under The List Panel -->
        <div id="exportSection" class="export-card">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="font-size:30px; background:var(--dim); width:52px; height:52px; border-radius:14px; display:flex; align-items:center; justify-content:center; border:1px solid var(--bdr);">📦</div>
                    <div>
                        <div style="font-size:20px; font-weight:800; color:var(--txt);">Export Resumes Archive (ZIP)</div>
                        <div style="font-size:12.5px; color:var(--mut);">Select exact date, date range, or all-time applications, choose resume sources, and download.</div>
                    </div>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span class="badge-source badge-source-job">📁 PDF Documents</span>
                    <span class="badge-source badge-source-builder">📊 Manifest CSV Included</span>
                </div>
            </div>

            <form method="GET" action="export_resumes.php" style="margin-top:10px;">
                
                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:20px; margin-bottom:20px;">
                    
                    <!-- 1. Source Selector -->
                    <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:14px; padding:16px;">
                        <label style="display:block; font-size:12.5px; color:var(--txt); margin-bottom:8px; font-weight:700;">1. Resume Source</label>
                        <select name="source" id="exportSourceSelect" style="padding:10px 14px; font-size:13px; margin:0; width:100%; border-radius:10px;">
                            <option value="all">🌟 All Resume Sources (Job Apply + Builder + Checker)</option>
                            <option value="job_apply">💼 Job Applications Only (<?= $count_job_apply ?> resumes)</option>
                            <option value="resume_builder">🛠️ AI Resume Builder Only (<?= $count_builder ?> builds)</option>
                            <option value="resume_checker">🔍 AI Resume Checker Only (<?= $count_checker ?> reviews)</option>
                        </select>
                        <div style="font-size:11px; color:var(--mut); margin-top:8px;">Choose whether to download platform-wide resumes or a specific pipeline source.</div>
                    </div>

                    <!-- 2. Date Filter Mode Selector -->
                    <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:14px; padding:16px;">
                        <label style="display:block; font-size:12.5px; color:var(--txt); margin-bottom:8px; font-weight:700;">2. Date Criteria</label>
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:6px; background:var(--surf); padding:4px; border-radius:10px; border:1px solid var(--bdr); margin-bottom:12px;">
                            <button type="button" id="btnExportAll" onclick="setExportDateMode('all')" style="padding:7px 10px; font-size:12px; font-weight:700; border-radius:8px; border:none; cursor:pointer; background:var(--dim); color:var(--txt); box-shadow:var(--shadow-xs);">
                                🌐 All Time
                            </button>
                            <button type="button" id="btnExportExact" onclick="setExportDateMode('exact')" style="padding:7px 10px; font-size:12px; font-weight:700; border-radius:8px; border:none; cursor:pointer; background:transparent; color:var(--mut);">
                                📅 Exact Date
                            </button>
                            <button type="button" id="btnExportRange" onclick="setExportDateMode('range')" style="padding:7px 10px; font-size:12px; font-weight:700; border-radius:8px; border:none; cursor:pointer; background:transparent; color:var(--mut);">
                                🗓️ Date Range
                            </button>
                        </div>
                        <input type="hidden" name="date_mode" id="exportDateModeInput" value="all">

                        <!-- Exact Date Input Box -->
                        <div id="exactDateContainer" style="display:none;">
                            <input type="date" name="exact_date" id="exportExactDateInput" value="<?= date('Y-m-d') ?>" style="padding:8px 12px; font-size:13px; margin:0; width:100%;">
                        </div>

                        <!-- Date Range Input Box -->
                        <div id="rangeDateContainer" style="display:none;">
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                <input type="date" name="start_date" id="exportStartDateInput" value="<?= date('Y-m-01') ?>" style="padding:8px 10px; font-size:12px; margin:0;">
                                <input type="date" name="end_date" id="exportEndDateInput" value="<?= date('Y-m-d') ?>" style="padding:8px 10px; font-size:12px; margin:0;">
                            </div>
                        </div>
                    </div>

                    <!-- 3. Optional Job Position Filter -->
                    <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:14px; padding:16px;">
                        <label style="display:block; font-size:12.5px; color:var(--txt); margin-bottom:8px; font-weight:700;">3. Job Position Filter (Optional)</label>
                        <select name="job_id" style="padding:10px 14px; font-size:13px; margin:0; width:100%; border-radius:10px;">
                            <option value="all">📁 All Job Openings</option>
                            <?php foreach($jobs_list as $job_opt): ?>
                                <option value="<?= $job_opt['id'] ?>">💼 <?= htmlspecialchars($job_opt['job_title']) ?> (<?= htmlspecialchars($job_opt['employer_name'] ?? 'Direct') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div style="font-size:11px; color:var(--mut); margin-top:8px;">Applies to candidate resumes submitted for job openings.</div>
                    </div>
                </div>

                <!-- Footer Summary Bar & Submit Button -->
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px; background:var(--dim); border:1px solid var(--bdr); border-radius:12px; padding:14px 20px;">
                    <div style="font-size:12px; color:var(--mut); display:flex; align-items:center; gap:8px;">
                        <span>💡 The exported ZIP archive includes all matching PDF resumes, a UTF-8 Excel manifest CSV, and a README audit summary.</span>
                    </div>
                    <button type="submit" class="btn-primary" style="padding:12px 28px; width:auto; font-size:14px; border-radius:10px; display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-weight:800; box-shadow:0 8px 20px rgba(180, 214, 0, 0.35);">
                        <span>📥 Download Resumes (ZIP) &rarr;</span>
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Modal: View Resume Builder Preview -->
    <div id="builderPreviewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:680px; width:100%; max-height:85vh; overflow-y:auto; position:relative; border-radius:18px; box-shadow:var(--shadow-lg);">
            <button type="button" onclick="closeBuilderPreview()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:14px;">
                <div style="font-size:24px;">🛠️</div>
                <div>
                    <div id="modalBuilderTitle" style="font-size:18px; font-weight:800; color:var(--txt);">Generated Resume</div>
                    <div id="modalBuilderUser" style="font-size:12px; color:var(--mut);">Candidate Build Preview</div>
                </div>
            </div>
            <div id="modalBuilderContent" style="background:var(--dim); padding:16px; border-radius:12px; font-size:12.5px; line-height:1.6; white-space:pre-wrap; border:1px solid var(--bdr);"></div>
        </div>
    </div>

    <script>
        var currentSource = 'all';

        function filterBySource(source, btn) {
            currentSource = source;
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            if (btn) btn.classList.add('active');

            // Sync with export dropdown for convenience
            var sourceSelect = document.getElementById('exportSourceSelect');
            if (sourceSelect) sourceSelect.value = source;

            filterResumeTable();
        }

        function filterResumeTable() {
            var search = document.getElementById('resumeSearchInput').value.toLowerCase().trim();
            var rows = document.getElementsByClassName('resume-row-item');

            for (var i = 0; i < rows.length; i++) {
                var rowSource = rows[i].getAttribute('data-source') || '';
                var searchData = rows[i].getAttribute('data-search') || '';

                var matchesSource = (currentSource === 'all') || (rowSource === currentSource);
                var matchesSearch = !search || (searchData.indexOf(search) > -1);

                if (matchesSource && matchesSearch) {
                    rows[i].style.display = 'grid';
                } else {
                    rows[i].style.display = 'none';
                }
            }
        }

        function setExportDateMode(mode) {
            document.getElementById('exportDateModeInput').value = mode;

            var btnAll = document.getElementById('btnExportAll');
            var btnExact = document.getElementById('btnExportExact');
            var btnRange = document.getElementById('btnExportRange');

            var exactBox = document.getElementById('exactDateContainer');
            var rangeBox = document.getElementById('rangeDateContainer');

            [btnAll, btnExact, btnRange].forEach(btn => {
                btn.style.background = 'transparent';
                btn.style.color = 'var(--mut)';
                btn.style.boxShadow = 'none';
            });

            if (mode === 'exact') {
                btnExact.style.background = 'var(--dim)';
                btnExact.style.color = 'var(--txt)';
                btnExact.style.boxShadow = 'var(--shadow-xs)';
                exactBox.style.display = 'block';
                rangeBox.style.display = 'none';
            } else if (mode === 'range') {
                btnRange.style.background = 'var(--dim)';
                btnRange.style.color = 'var(--txt)';
                btnRange.style.boxShadow = 'var(--shadow-xs)';
                exactBox.style.display = 'none';
                rangeBox.style.display = 'block';
            } else {
                btnAll.style.background = 'var(--dim)';
                btnAll.style.color = 'var(--txt)';
                btnAll.style.boxShadow = 'var(--shadow-xs)';
                exactBox.style.display = 'none';
                rangeBox.style.display = 'none';
            }
        }

        function openBuilderPreview(item) {
            document.getElementById('modalBuilderTitle').textContent = item.job_title || 'Generated Resume';
            document.getElementById('modalBuilderUser').textContent = (item.candidate_name || 'Candidate') + ' • ' + (item.candidate_email || '');
            
            var text = '';
            try {
                var data = JSON.parse(item.generated_content);
                if (typeof data === 'object') {
                    if (data.summary) text += "SUMMARY:\n" + data.summary + "\n\n";
                    if (data.skills) text += "SKILLS:\n" + (Array.isArray(data.skills) ? data.skills.join(', ') : data.skills) + "\n\n";
                    if (data.experience) text += "EXPERIENCE:\n" + JSON.stringify(data.experience, null, 2) + "\n\n";
                    if (data.education) text += "EDUCATION:\n" + JSON.stringify(data.education, null, 2) + "\n\n";
                }
            } catch(e) {
                text = item.generated_content || item.raw_input || 'No preview content available.';
            }

            document.getElementById('modalBuilderContent').textContent = text || 'No preview text.';
            document.getElementById('builderPreviewModal').style.display = 'flex';
        }

        function closeBuilderPreview() {
            document.getElementById('builderPreviewModal').style.display = 'none';
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeBuilderPreview();
        });
    </script>
    <script src="theme.js"></script>
</body>
</html>
