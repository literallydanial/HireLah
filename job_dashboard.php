<?php
require_once 'auth.php';
require_role('employer');
require_once 'notifications_helper.php';
require_once 'company_helpers.php';

$empNotifs = getEmployerNotifications($pdo, $_SESSION['user_id']);
$notifItems = $empNotifs['items'];
$unreadCount = $empNotifs['unread_count'];
$active_company_id = get_active_company_id($pdo);

// Handle creation of a new job posting via popup modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_job') {
    $title = trim($_POST['job_title'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $type = trim($_POST['employment_type'] ?? 'Full-time');
    $mode = trim($_POST['work_mode'] ?? 'Hybrid');
    $location = trim($_POST['location'] ?? '');
    $salary_min = (!empty($_POST['salary_min']) && is_numeric($_POST['salary_min'])) ? (int)$_POST['salary_min'] : null;
    $salary_max = (!empty($_POST['salary_max']) && is_numeric($_POST['salary_max'])) ? (int)$_POST['salary_max'] : null;
    $salary_rate_type = in_array($_POST['salary_rate_type'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['salary_rate_type'] : 'monthly';
    $salary_text = !empty($_POST['salary_text']) ? trim($_POST['salary_text']) : null;
    $perks = !empty($_POST['perks']) ? trim($_POST['perks']) : null;
    $require_video = isset($_POST['require_video']) ? 1 : 0;
    $desc = trim($_POST['description'] ?? '');
    $employer_id = $_SESSION['user_id'];

    if (!empty($title) && !empty($desc) && $salary_min !== null && $salary_max !== null) {
        $stmt = $pdo->prepare("INSERT INTO jobs (employer_id, company_id, job_title, department, employment_type, work_mode, location, salary_min, salary_max, salary_rate_type, salary_text, perks, require_video, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        if ($stmt->execute([$employer_id, $active_company_id, $title, $dept, $type, $mode, $location, $salary_min, $salary_max, $salary_rate_type, $salary_text, $perks, $require_video, $desc])) {
            $_SESSION['toast'] = "Job position published successfully!";
            header("Location: job_dashboard.php");
            exit;
        }
    }
}

// Handle deletion of a job posting — any HR teammate on the same company can
// manage a job now, not just whoever originally posted it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_job') {
    $delete_id = $_POST['job_id'] ?? null;
    if ($delete_id) {
        if ($active_company_id) {
            $del_stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
            $del_stmt->execute([$delete_id, $active_company_id, $_SESSION['user_id']]);
        } else {
            $del_stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
            $del_stmt->execute([$delete_id, $_SESSION['user_id']]);
        }
        $_SESSION['toast'] = "Job posting deleted successfully.";
        header("Location: job_dashboard.php");
        exit;
    }
}

// Fetch all job postings for this employer's company team along with
// application counts & average scores
$sql = "SELECT j.*,
        (SELECT COUNT(*) FROM candidates c WHERE c.job_id = j.id) as applicant_count,
        (SELECT COUNT(*) FROM candidates c WHERE c.job_id = j.id AND c.status = 'Shortlisted') as shortlisted_count,
        (SELECT AVG(c.overall_score) FROM candidates c WHERE c.job_id = j.id) as avg_job_score
        FROM jobs j
        WHERE " . ($active_company_id ? "(j.company_id = ? OR (j.company_id IS NULL AND j.employer_id = ?))" : "(j.employer_id = ? OR j.employer_id IS NULL)") . "
        ORDER BY j.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($active_company_id ? [$active_company_id, $_SESSION['user_id']] : [$_SESSION['user_id']]);
$my_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary metrics
$total_jobs = count($my_jobs);
$active_jobs = 0;
$total_applicants = 0;
$total_shortlisted = 0;

foreach ($my_jobs as $j) {
    if (strtolower($j['status'] ?? 'active') === 'active') $active_jobs++;
    $total_applicants += (int)$j['applicant_count'];
    $total_shortlisted += (int)$j['shortlisted_count'];
}
$avg_per_job = $total_jobs > 0 ? round($total_applicants / $total_jobs, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Job Postings - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .stats-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .job-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }
        .job-card {
            background: var(--card);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: all 0.25s ease;
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
        }
        .job-card:hover {
            transform: translateY(-3px);
            border-color: var(--acc);
            box-shadow: var(--shadow-lg);
        }
        .job-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--acc), var(--pur));
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .job-card:hover::before {
            opacity: 1;
        }
        .job-title {
            font-size: 19px;
            font-weight: 800;
            color: var(--txt);
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .job-meta-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
        }
        .meta-chip {
            background: var(--dim);
            color: var(--mut);
            border: 1px solid var(--bdr);
            border-radius: 6px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .job-stats-pill-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
            padding: 12px;
            background: var(--surf);
            border-radius: 10px;
            border: 1px solid var(--bdr);
            margin-bottom: 16px;
        }
        .job-stat-box {
            text-align: center;
        }
        .job-stat-num {
            font-size: 18px;
            font-weight: 800;
            font-family: monospace;
        }
        .job-stat-tag {
            font-size: 10px;
            color: var(--mut);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-top: 2px;
        }
        .badge-status {
            font-size: 10px;
            font-weight: 800;
            padding: 4px 10px;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-active {
            background: rgba(0, 232, 122, 0.12);
            color: var(--grn);
            border: 1px solid rgba(0, 232, 122, 0.3);
        }
        .status-closed {
            background: rgba(255, 77, 106, 0.12);
            color: var(--red);
            border: 1px solid rgba(255, 77, 106, 0.3);
        }
        .job-action-bar {
            display: flex;
            gap: 8px;
            border-top: 1px solid var(--bdr);
            padding-top: 16px;
            margin-top: 12px;
            align-items: center;
        }
        @media (max-width: 900px) {
            .stats-summary-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 840px) {
            /* This page has no hero banner, so it relies on style.css's
               generic main{margin-top:170px!important} for header
               clearance -- but with the bell/notification icon and a
               min-height:44px Logout button in the top row (the mobile
               tap-target rule), this header renders taller in practice
               than that shared estimate assumed, so page content was
               getting covered. Bumping it a bit higher here. */
            main {
                margin-top: 195px !important;
            }
        }
    </style>
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
                <div>
                    <div style="font-size:15px; font-weight:800; line-height:1" class="header-brand-title">Keria Job Portal</div>
                    <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">RECRUITMENT PLATFORM</div>
                </div>
            </div>
            
            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php">👥 Applications & Stats</a>
                <a href="job_dashboard.php" class="active">💼 My Jobs</a>
                <a href="questionnaire.php">📋 Questionnaires</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>
            <?php include 'company_switcher.php'; ?>

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
                            <span style="font-weight:800; font-size:13px; color:var(--txt);">Employer Notifications</span>
                            <?php if($unreadCount > 0): ?>
                                <button type="button" class="notif-mark-all" onclick="markAllNotifsRead()">Mark all as read</button>
                            <?php endif; ?>
                        </div>

                        <div class="notif-list">
                            <?php if(empty($notifItems)): ?>
                                <div style="padding:24px; text-align:center; color:var(--mut); font-size:12px;">
                                    ✨ No new application notifications
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

                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?> (<?= ucfirst($_SESSION['user_role'] ?? 'Employer') ?>)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main style="max-width:1300px;">
        <?php if(isset($_SESSION['toast'])): ?>
            <div style="position:fixed; top:20px; right:20px; z-index:3000; background:rgba(0, 232, 122, 0.18); border:1px solid rgba(0, 232, 122, 0.5); border-radius:10px; padding:10px 18px; color:var(--grn); font-size:13px; font-weight:700;">
                <?= htmlspecialchars($_SESSION['toast']) ?>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 style="font-size:26px; font-weight:800; color:var(--txt); margin:0;">💼 Job Postings Overview</h1>
                <p style="font-size:13px; color:var(--mut); margin-top:4px; margin-bottom:0;">Publish, manage, and monitor applicant pipelines for all active job positions.</p>
            </div>
            <button onclick="openPostJobModal()" class="btn-primary" style="padding:11px 22px; font-size:14px; width:auto; display:inline-flex; gap:8px; align-items:center; border-radius:10px; cursor:pointer;">
                <span>+ Post New Position</span>
            </button>
        </div>

        <!-- Metric Analytics Summary Banner -->
        <div class="stats-summary-grid">
            <div class="stat-box">
                <div class="logo-box" style="color:var(--acc); border-color:var(--acc);">💼</div>
                <div><div class="stat-val" style="color:var(--acc)"><?= $total_jobs ?></div><div class="stat-lbl">Total Published Jobs</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--grn); border-color:var(--grn);">🟢</div>
                <div><div class="stat-val" style="color:var(--grn)"><?= $active_jobs ?></div><div class="stat-lbl">Active Openings</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--pur); border-color:var(--pur);">👥</div>
                <div><div class="stat-val" style="color:var(--pur)"><?= $total_applicants ?></div><div class="stat-lbl">Total Applicants</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--gold); border-color:var(--gold);">⭐</div>
                <div><div class="stat-val" style="color:var(--gold)"><?= $avg_per_job ?></div><div class="stat-lbl">Avg Applicants / Job</div></div>
            </div>
        </div>

        <!-- Filter & Real-Time Search Control Bar -->
        <?php if (!empty($my_jobs)): ?>
            <div class="panel" style="margin-bottom:24px; padding:16px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                <div style="font-size:13px; font-weight:700; color:var(--txt);">
                    Showing <span style="color:var(--acc);"><?= count($my_jobs) ?></span> Job Postings
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex:1; max-width:380px;">
                    <input type="text" id="jobSearchInput" onkeyup="filterJobsGrid()" placeholder="🔍 Search jobs by title, department, mode..." style="padding:9px 14px; font-size:13px; border-radius:8px; border:1px solid var(--bdr); background:var(--surf); color:var(--txt);">
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($my_jobs)): ?>
            <div class="panel" style="text-align:center; padding:70px 40px; border-style:dashed;">
                <div style="font-size:48px; margin-bottom:14px;">💼</div>
                <div style="font-size:20px; font-weight:800; color:var(--txt); margin-bottom:6px;">No Job Postings Yet</div>
                <p style="font-size:13px; color:var(--mut); margin-bottom:24px; max-width:450px; margin-left:auto; margin-right:auto;">Get started by creating your first job posting to automatically receive and AI-screen candidate resumes.</p>
                <a href="post_job.php" class="btn-primary" style="padding:12px 28px; font-size:14px; text-decoration:none; display:inline-flex; width:auto; border-radius:10px;">Post Your First Job &rarr;</a>
            </div>
        <?php else: ?>
            <div class="job-grid" id="jobsGrid">
                <?php foreach ($my_jobs as $j): ?>
                    <?php 
                        $avg_score = $j['avg_job_score'] !== null ? round($j['avg_job_score']) : null;
                        $score_color = $avg_score >= 80 ? 'var(--grn)' : ($avg_score >= 60 ? 'var(--acc)' : ($avg_score >= 40 ? 'var(--org)' : 'var(--red)'));
                    ?>
                    <div class="job-card job-card-item" data-search="<?= htmlspecialchars(strtolower($j['job_title'] . ' ' . $j['department'] . ' ' . $j['employment_type'] . ' ' . $j['work_mode'])) ?>">
                        <div>
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                                <span class="meta-chip" style="background:rgba(59, 130, 246, 0.1); color:var(--acc); border-color:rgba(59, 130, 246, 0.25);">
                                    🏢 <?= htmlspecialchars($j['department'] ?: 'General') ?>
                                </span>
                                <span class="badge-status <?= strtolower($j['status'] ?? 'active') === 'active' ? 'status-active' : 'status-closed' ?>">
                                    <?= htmlspecialchars($j['status'] ?? 'Active') ?>
                                </span>
                            </div>

                            <div class="job-title"><?= htmlspecialchars($j['job_title']) ?></div>
                            
                            <div class="job-meta-chips">
                                <span class="meta-chip">⏳ <?= htmlspecialchars($j['employment_type'] ?: 'Full-time') ?></span>
                                <span class="meta-chip">📍 <?= htmlspecialchars($j['work_mode'] ?: 'On-site') ?></span>
                                <span class="meta-chip">📅 Posted <?= date('M d, Y', strtotime($j['created_at'])) ?></span>
                            </div>

                            <!-- Job Pipeline Micro-Stats Row -->
                            <div class="job-stats-pill-row">
                                <div class="job-stat-box">
                                    <div class="job-stat-num" style="color:var(--acc);"><?= (int)$j['applicant_count'] ?></div>
                                    <div class="job-stat-tag">Applicants</div>
                                </div>
                                <div class="job-stat-box">
                                    <div class="job-stat-num" style="color:var(--pur);"><?= (int)$j['shortlisted_count'] ?></div>
                                    <div class="job-stat-tag">Shortlisted</div>
                                </div>
                                <div class="job-stat-box">
                                    <div class="job-stat-num" style="color:<?= $avg_score !== null ? $score_color : 'var(--mut)' ?>;">
                                        <?= $avg_score !== null ? $avg_score . '%' : '-' ?>
                                    </div>
                                    <div class="job-stat-tag">Avg Score</div>
                                </div>
                            </div>

                            <div style="font-size:12px; color:var(--mut); line-height:1.5; margin-bottom:18px; display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; text-overflow:ellipsis;">
                                <?= htmlspecialchars($j['description']) ?>
                            </div>
                        </div>

                        <!-- Action Toolbar -->
                        <div class="job-action-bar">
                            <a href="employer_dashboard.php?job_id=<?= (int)$j['id'] ?>" class="btn-primary" style="flex:1.2; padding:9px 12px; font-size:12px; text-align:center; text-decoration:none; border-radius:8px; font-weight:700;">👥 Applicants (<?= (int)$j['applicant_count'] ?>)</a>
                            <a href="compare_candidates.php?job_id=<?= (int)$j['id'] ?>" class="btn-secondary" style="padding:8px 11px; font-size:12px; text-decoration:none;" title="Compare Candidates Side-by-Side">⚖️ Compare</a>
                            <a href="questionnaire.php?job_id=<?= (int)$j['id'] ?>" class="btn-secondary" style="padding:8px 11px; font-size:12px; text-decoration:none;" title="Manage Job Questionnaire">📋</a>
                            <a href="edit_job.php?id=<?= (int)$j['id'] ?>" class="btn-secondary" style="padding:8px 11px; font-size:12px; text-decoration:none;" title="Edit Job Details">✏️</a>
                            
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this job posting? All candidate applications for this job will also be removed.');" style="margin:0;">
                                <input type="hidden" name="action" value="delete_job">
                                <input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>">
                                <button type="submit" class="btn-secondary" style="padding:8px 11px; font-size:12px; color:var(--red); border-color:rgba(255, 77, 106, 0.3);" title="Delete Job Posting">🗑️</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Post a Job Popup Modal Overlay -->
    <div id="postJobModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:680px; width:100%; max-height:90vh; overflow-y:auto; position:relative; border-radius:18px; box-shadow:var(--shadow-lg); animation:modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
            <button onclick="closePostJobModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:22px;">✨</div>
                <div style="font-size:22px; font-weight:800; color:var(--txt);">Post a New Job Opening</div>
            </div>
            <p style="font-size:13px; color:var(--mut); margin-bottom:24px;">Create a new role for candidates to apply to and enable automated Gemini AI resume screening.</p>

            <form method="POST" id="postJobForm" onsubmit="return jdValidateBeforeSubmit('modal_jd_sections','modal_description_hidden')">
                <input type="hidden" name="action" value="post_job">

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Job Title</label>
                    <input type="text" name="job_title" placeholder="e.g. Senior Backend Developer" required style="padding:10px 14px; font-size:14px;">
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:16px;">
                    <div>
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Department</label>
                        <input type="text" name="department" placeholder="e.g. Engineering" style="padding:9px 12px; font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Employment Type</label>
                        <select name="employment_type" style="padding:9px 12px; font-size:13px;">
                            <option value="Full-time">Full-time</option>
                            <option value="Part-time">Part-time</option>
                            <option value="Contract">Contract</option>
                            <option value="Internship">Internship</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Work Mode</label>
                        <select name="work_mode" style="padding:9px 12px; font-size:13px;">
                            <option value="Hybrid">Hybrid</option>
                            <option value="On-site">On-site</option>
                            <option value="Remote">Remote</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Location (State)</label>
                    <select name="location" style="padding:9px 12px; font-size:13px;">
                        <option value="">Select a state...</option>
                        <option value="Johor">Johor</option>
                        <option value="Kedah">Kedah</option>
                        <option value="Kelantan">Kelantan</option>
                        <option value="Kuala Lumpur">Kuala Lumpur</option>
                        <option value="Labuan">Labuan</option>
                        <option value="Melaka">Melaka</option>
                        <option value="Negeri Sembilan">Negeri Sembilan</option>
                        <option value="Pahang">Pahang</option>
                        <option value="Penang">Penang</option>
                        <option value="Perak">Perak</option>
                        <option value="Perlis">Perlis</option>
                        <option value="Putrajaya">Putrajaya</option>
                        <option value="Sabah">Sabah</option>
                        <option value="Sarawak">Sarawak</option>
                        <option value="Selangor">Selangor</option>
                        <option value="Terengganu">Terengganu</option>
                    </select>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Rate Type</label>
                    <select name="salary_rate_type" id="modal_salary_rate_type" onchange="updateSalaryRateLabels('modal_salary_rate_type', 'modal_min_salary_label', 'modal_max_salary_label')" style="padding:9px 12px; font-size:13px;">
                        <option value="monthly">Monthly</option>
                        <option value="daily">Daily (part-time)</option>
                        <option value="weekly">Weekly (part-time)</option>
                    </select>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px;">
                    <div>
                        <label id="modal_min_salary_label" style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Min Salary (RM/mo)</label>
                        <input type="number" name="salary_min" placeholder="e.g. 4000" required style="padding:9px 12px; font-size:13px;">
                    </div>
                    <div>
                        <label id="modal_max_salary_label" style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Max Salary (RM/mo)</label>
                        <input type="number" name="salary_max" placeholder="e.g. 7500" required style="padding:9px 12px; font-size:13px;">
                    </div>
                </div>

                <div style="margin-bottom:18px; padding:12px 16px; background:var(--surf); border:1px solid var(--bdr); border-radius:10px; display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="require_video" id="modal_require_video" value="1" style="width:18px; height:18px; cursor:pointer;">
                    <label for="modal_require_video" style="font-size:13px; font-weight:600; color:var(--txt); cursor:pointer;">
                        📹 Require YouTube Video Introduction Link from Applicants
                    </label>
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Job Details</label>
                    <div id="modal_jd_sections"></div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:4px;">
                        <button type="button" onclick="jdAddSection('modal_jd_sections','modal_description_hidden')" style="padding:7px 14px; border-radius:8px; border:1.5px dashed var(--bdr); background:var(--surf); cursor:pointer; font-size:12.5px; font-weight:700; color:var(--txt);">+ Add Section</button>
                        <span style="font-size:11px; color:var(--mut);">Quick add:</span>
                        <button type="button" class="jd-suggest-chip" onclick="jdAddSection('modal_jd_sections','modal_description_hidden','Key Responsibilities')">💼 Responsibilities</button>
                        <button type="button" class="jd-suggest-chip" onclick="jdAddSection('modal_jd_sections','modal_description_hidden','Requirements & Qualifications')">🎓 Qualifications</button>
                        <button type="button" class="jd-suggest-chip" onclick="jdAddSection('modal_jd_sections','modal_description_hidden','Benefits')">🎁 Benefits</button>
                        <button type="button" class="jd-suggest-chip" onclick="jdAddSection('modal_jd_sections','modal_description_hidden','Required Skills')">🛠️ Skills</button>
                        <button type="button" class="jd-suggest-chip" onclick="jdAddSection('modal_jd_sections','modal_description_hidden','Nice to Have')">⭐ Nice to Have</button>
                    </div>
                    <textarea id="modal_description_hidden" name="description" style="display:none;"></textarea>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:12px;">
                    <button type="button" onclick="closePostJobModal()" class="btn-secondary" style="padding:10px 20px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding:10px 24px; width:auto; border-radius:10px;">Publish Job Position &rarr;</button>
                </div>
            </form>
        </div>
    </div>

    <style>
    @keyframes modalPop {
        from { opacity: 0; transform: scale(0.95); }
        to { opacity: 1; transform: scale(1); }
    }
    .jd-suggest-chip {
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid var(--bdr);
        background: var(--dim);
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        color: var(--txt);
    }
    .jd-suggest-chip:hover {
        border-color: var(--keria-lime-hover, #C2F025);
        background: var(--surf);
    }
    .jd-section-card {
        border: 1px solid var(--bdr);
        border-radius: 10px;
        padding: 12px;
        margin-bottom: 10px;
        background: var(--surf);
    }
    .jd-section-title {
        flex: 1;
        padding: 8px 10px;
        font-size: 13px;
        font-weight: 700;
        border-radius: 8px;
        border: 1.5px solid var(--bdr);
        background: var(--card-solid, #fff);
        color: var(--txt);
    }
    .jd-section-remove-btn {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        border: 1px solid var(--bdr);
        background: var(--dim);
        cursor: pointer;
        color: var(--red, #FF4D6A);
        flex-shrink: 0;
    }
    .jd-section-toolbar-btn {
        border-radius: 6px;
        border: 1px solid var(--bdr);
        background: var(--dim);
        cursor: pointer;
        font-size: 11px;
        height: 28px;
    }
    .jd-section-content {
        width: 100%;
        box-sizing: border-box;
        resize: vertical;
        padding: 10px;
        font-size: 13px;
        border-radius: 8px;
        border: 1.5px solid var(--bdr);
        background: var(--card-solid, #fff);
        color: var(--txt);
        font-family: inherit;
    }
    </style>

    <script>
    function openPostJobModal() {
        document.getElementById('postJobModal').style.display = 'flex';
    }
    function closePostJobModal() {
        document.getElementById('postJobModal').style.display = 'none';
    }

    // Updates the "Min/Max Salary" field labels to match the chosen rate type.
    function updateSalaryRateLabels(selectId, minLabelId, maxLabelId) {
        var rate = document.getElementById(selectId).value;
        var unit = rate === 'daily' ? 'RM/day' : rate === 'weekly' ? 'RM/week' : 'RM/mo';
        document.getElementById(minLabelId).textContent = 'Min Salary (' + unit + ')';
        document.getElementById(maxLabelId).textContent = 'Max Salary (' + unit + ')';
    }

    // Wraps the selected text in a textarea with before/after markers (e.g. ** **).
    function jdWrapSelection(textareaId, before, after) {
        var ta = document.getElementById(textareaId);
        var start = ta.selectionStart, end = ta.selectionEnd;
        var selected = ta.value.substring(start, end) || 'text';
        ta.value = ta.value.substring(0, start) + before + selected + after + ta.value.substring(end);
        ta.focus();
        ta.selectionStart = start + before.length;
        ta.selectionEnd = start + before.length + selected.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Prefixes every line touched by the current selection with the given
    // marker ("- " or "• " for a bullet). If a line already starts with a
    // DIFFERENT bullet marker, that one is replaced rather than stacking a
    // second marker in front of it (which used to produce "• - text").
    var JD_BULLET_MARKER_RE = /^[-*•]\s+/;
    function jdPrefixLines(textareaId, prefix) {
        var ta = document.getElementById(textareaId);
        var start = ta.selectionStart, end = ta.selectionEnd;
        var value = ta.value;
        var lineStart = value.lastIndexOf('\n', start - 1) + 1;
        var lineEnd = value.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = value.length;
        var block = value.substring(lineStart, lineEnd);
        var lines = block.split('\n').map(function(l) {
            if (l.trim() === '') return l;
            if (JD_BULLET_MARKER_RE.test(l)) return l.replace(JD_BULLET_MARKER_RE, prefix);
            return prefix + l;
        });
        var newBlock = lines.join('\n');
        ta.value = value.substring(0, lineStart) + newBlock + value.substring(lineEnd);
        ta.focus();
        ta.selectionStart = lineStart;
        ta.selectionEnd = lineStart + newBlock.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Job description "section cards" — lets an employer build the description
    // out of titled blocks (Responsibilities, Benefits, etc.) instead of one
    // big text box. Behind the scenes these get stitched into the same
    // "## Title" + content text the formatter already understands, and that
    // combined text is what actually gets submitted as `description` — so no
    // backend or display code needed to change for this.
    var jdSectionCounter = 0;

    function jdAddSection(containerId, hiddenFieldId, presetTitle, presetContent) {
        jdSectionCounter++;
        var cardId = 'jdSection_' + jdSectionCounter;
        var taId = cardId + '_ta';
        var container = document.getElementById(containerId);
        var card = document.createElement('div');
        card.className = 'jd-section-card';
        card.id = cardId;

        var titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'jd-section-title';
        titleInput.placeholder = 'Section title, e.g. Responsibilities';
        titleInput.value = presetTitle || '';
        titleInput.addEventListener('input', function () { jdSyncDescription(containerId, hiddenFieldId); });

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'jd-section-remove-btn';
        removeBtn.title = 'Remove section';
        removeBtn.textContent = '✕';
        removeBtn.addEventListener('click', function () { jdRemoveSection(cardId, containerId, hiddenFieldId); });

        var headerRow = document.createElement('div');
        headerRow.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:8px;';
        headerRow.appendChild(titleInput);
        headerRow.appendChild(removeBtn);

        var boldBtn = document.createElement('button');
        boldBtn.type = 'button';
        boldBtn.className = 'jd-section-toolbar-btn';
        boldBtn.style.cssText = 'width:28px; font-weight:800;';
        boldBtn.title = 'Bold';
        boldBtn.textContent = 'B';
        boldBtn.addEventListener('click', function () { jdWrapSelection(taId, '**', '**'); });

        var dashListBtn = document.createElement('button');
        dashListBtn.type = 'button';
        dashListBtn.className = 'jd-section-toolbar-btn';
        dashListBtn.style.cssText = 'padding:0 8px;';
        dashListBtn.title = 'Bullet list (dash)';
        dashListBtn.innerHTML = '&ndash; List';
        dashListBtn.addEventListener('click', function () { jdPrefixLines(taId, '- '); });

        var dotListBtn = document.createElement('button');
        dotListBtn.type = 'button';
        dotListBtn.className = 'jd-section-toolbar-btn';
        dotListBtn.style.cssText = 'padding:0 8px;';
        dotListBtn.title = 'Bullet list (round)';
        dotListBtn.innerHTML = '&bull; List';
        dotListBtn.addEventListener('click', function () { jdPrefixLines(taId, '• '); });

        var toolbarRow = document.createElement('div');
        toolbarRow.style.cssText = 'display:flex; gap:6px; margin-bottom:6px;';
        toolbarRow.appendChild(boldBtn);
        toolbarRow.appendChild(dashListBtn);
        toolbarRow.appendChild(dotListBtn);

        var contentArea = document.createElement('textarea');
        contentArea.id = taId;
        contentArea.className = 'jd-section-content';
        contentArea.rows = 4;
        contentArea.placeholder = "Write this section's content...";
        contentArea.value = presetContent || '';
        contentArea.addEventListener('input', function () { jdSyncDescription(containerId, hiddenFieldId); });

        card.appendChild(headerRow);
        card.appendChild(toolbarRow);
        card.appendChild(contentArea);
        container.appendChild(card);

        jdSyncDescription(containerId, hiddenFieldId);
        contentArea.focus();
    }

    function jdRemoveSection(cardId, containerId, hiddenFieldId) {
        var card = document.getElementById(cardId);
        if (card) card.remove();
        jdSyncDescription(containerId, hiddenFieldId);
    }

    // Combines every section card into the same "## Title\ncontent" text the
    // description formatter (job_description_helper.php) already parses.
    function jdSyncDescription(containerId, hiddenFieldId) {
        var container = document.getElementById(containerId);
        var cards = container.querySelectorAll('.jd-section-card');
        var combined = '';
        cards.forEach(function (card) {
            var title = card.querySelector('.jd-section-title').value.trim();
            var content = card.querySelector('.jd-section-content').value.trim();
            if (!title && !content) return;
            if (title) combined += '## ' + title + '\n';
            if (content) combined += content + '\n';
            combined += '\n';
        });
        document.getElementById(hiddenFieldId).value = combined.trim();
    }

    // Keeps the combined text fresh right before submit (covers any edge case
    // where an input event was missed) and blocks submit if nothing was written.
    function jdValidateBeforeSubmit(containerId, hiddenFieldId) {
        jdSyncDescription(containerId, hiddenFieldId);
        if (!document.getElementById(hiddenFieldId).value.trim()) {
            alert('Please fill in at least one job description section before publishing.');
            return false;
        }
        return true;
    }

    // Close on ESC key or clicking outside content box
    window.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closePostJobModal();
    });
    document.getElementById('postJobModal').addEventListener('click', function(e) {
        if (e.target === this) closePostJobModal();
    });

    // Auto-open modal if URL contains ?open_post=1
    if (window.location.search.indexOf('open_post=1') > -1) {
        openPostJobModal();
    }

    // Seed the description builder with one starting section so it's never empty.
    jdAddSection('modal_jd_sections', 'modal_description_hidden', 'Job Requirements');

    function filterJobsGrid() {
        var input = document.getElementById('jobSearchInput');
        var filter = input.value.toLowerCase().trim();
        var cards = document.getElementsByClassName('job-card-item');

        for (var i = 0; i < cards.length; i++) {
            var searchData = cards[i].getAttribute('data-search');
            if (searchData && searchData.indexOf(filter) > -1) {
                cards[i].style.display = 'flex';
            } else {
                cards[i].style.display = 'none';
            }
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

