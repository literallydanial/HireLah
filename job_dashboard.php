<?php
require_once 'auth.php';
require_role('employer');
require_once 'notifications_helper.php';

$empNotifs = getEmployerNotifications($pdo, $_SESSION['user_id']);
$notifItems = $empNotifs['items'];
$unreadCount = $empNotifs['unread_count'];

// Auto-migrate schema columns on jobs table if missing
try { $pdo->exec("ALTER TABLE jobs ADD COLUMN location VARCHAR(255) NULL AFTER work_mode"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE jobs ADD COLUMN salary_min INT NULL AFTER location"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE jobs ADD COLUMN salary_max INT NULL AFTER salary_min"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE jobs ADD COLUMN salary_text VARCHAR(255) NULL AFTER salary_max"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE jobs ADD COLUMN responsibilities TEXT NULL AFTER description"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE jobs ADD COLUMN requirements TEXT NULL AFTER responsibilities"); } catch (\Throwable $e) {}
try { $pdo->exec("ALTER TABLE jobs ADD COLUMN perks TEXT NULL AFTER requirements"); } catch (\Throwable $e) {}

// Handle creation of a new job posting via popup modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_job') {
    $title = trim($_POST['job_title'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $type = trim($_POST['employment_type'] ?? 'Full-time');
    $mode = trim($_POST['work_mode'] ?? 'Hybrid');
    $location = trim($_POST['location'] ?? '');
    $salary_min = !empty($_POST['salary_min']) ? (int)$_POST['salary_min'] : null;
    $salary_max = !empty($_POST['salary_max']) ? (int)$_POST['salary_max'] : null;
    $salary_text = trim($_POST['salary_text'] ?? '');
    $require_video = isset($_POST['require_video']) ? 1 : 0;
    $desc = trim($_POST['description'] ?? '');
    $responsibilities = trim($_POST['responsibilities'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $perks = trim($_POST['perks'] ?? '');
    $employer_id = $_SESSION['user_id'];

    if (!empty($title) && !empty($desc)) {
        $stmt = $pdo->prepare("INSERT INTO jobs (
            employer_id, job_title, department, employment_type, work_mode, 
            location, salary_min, salary_max, salary_text, require_video, 
            description, responsibilities, requirements, perks, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        if ($stmt->execute([
            $employer_id, $title, $dept, $type, $mode, 
            $location, $salary_min, $salary_max, $salary_text, $require_video, 
            $desc, $responsibilities, $requirements, $perks
        ])) {
            $_SESSION['toast'] = "Job position published successfully!";
            header("Location: job_dashboard.php");
            exit;
        }
    }
}

// Handle editing of an existing job posting via popup modal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_job') {
    $job_id = (int)($_POST['job_id'] ?? 0);
    $title = trim($_POST['job_title'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $type = trim($_POST['employment_type'] ?? 'Full-time');
    $mode = trim($_POST['work_mode'] ?? 'Hybrid');
    $location = trim($_POST['location'] ?? '');
    $salary_min = !empty($_POST['salary_min']) ? (int)$_POST['salary_min'] : null;
    $salary_max = !empty($_POST['salary_max']) ? (int)$_POST['salary_max'] : null;
    $salary_text = trim($_POST['salary_text'] ?? '');
    $require_video = isset($_POST['require_video']) ? 1 : 0;
    $desc = trim($_POST['description'] ?? '');
    $responsibilities = trim($_POST['responsibilities'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $perks = trim($_POST['perks'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');

    if ($job_id > 0 && !empty($title)) {
        $stmt = $pdo->prepare("UPDATE jobs SET 
            job_title = ?, department = ?, employment_type = ?, work_mode = ?, 
            location = ?, salary_min = ?, salary_max = ?, salary_text = ?, 
            require_video = ?, description = ?, responsibilities = ?, 
            requirements = ?, perks = ?, status = ? 
            WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
        if ($stmt->execute([
            $title, $dept, $type, $mode, 
            $location, $salary_min, $salary_max, $salary_text, 
            $require_video, $desc, $responsibilities, 
            $requirements, $perks, $status,
            $job_id, $_SESSION['user_id']
        ])) {
            $_SESSION['toast'] = "Job position updated successfully!";
            header("Location: job_dashboard.php");
            exit;
        }
    }
}

// Handle deletion of a job posting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_job') {
    $delete_id = $_POST['job_id'] ?? null;
    if ($delete_id) {
        $del_stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
        $del_stmt->execute([$delete_id, $_SESSION['user_id']]);
        $_SESSION['toast'] = "Job posting deleted successfully.";
        header("Location: job_dashboard.php");
        exit;
    }
}

// Fetch all job postings for this employer along with application counts & average scores
$sql = "SELECT j.*, 
        (SELECT COUNT(*) FROM candidates c WHERE c.job_id = j.id) as applicant_count,
        (SELECT COUNT(*) FROM candidates c WHERE c.job_id = j.id AND c.status = 'Shortlisted') as shortlisted_count,
        (SELECT AVG(c.overall_score) FROM candidates c WHERE c.job_id = j.id) as avg_job_score
        FROM jobs j 
        WHERE j.employer_id = ? OR j.employer_id IS NULL 
        ORDER BY j.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute([$_SESSION['user_id']]);
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
    <title>My Job Postings - HireLah</title>
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
            background: var(--toast-icon-bg);
            color: var(--toast-icon-color);
            border: 1px solid var(--toast-bdr);
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
    </style>
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>
            
            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php">👥 Applications & Stats</a>
                <a href="job_dashboard.php" class="active">💼 My Jobs</a>
                <a href="questionnaire.php">📋 Questionnaires</a>
                <a href="profile.php">⚙️ Settings</a>
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
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
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
        <div class="panel" style="max-width:740px; width:100%; max-height:90vh; overflow-y:auto; position:relative; border-radius:18px; box-shadow:var(--shadow-lg); animation:modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
            <button onclick="closePostJobModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:22px;">✨</div>
                <div style="font-size:22px; font-weight:800; color:var(--txt);">Post a New Job Opening</div>
            </div>
            <p style="font-size:13px; color:var(--mut); margin-bottom:24px;">Create a new role for candidates to apply to with customized salary range, location, responsibilities, and perks.</p>

            <form method="POST">
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

                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; margin-bottom:16px;">
                    <div>
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Office Location</label>
                        <input type="text" name="location" placeholder="e.g. Kuala Lumpur, MY" style="padding:9px 12px; font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Min Salary (RM)</label>
                        <input type="number" name="salary_min" placeholder="e.g. 4500" style="padding:9px 12px; font-size:13px;">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Max Salary (RM)</label>
                        <input type="number" name="salary_max" placeholder="e.g. 7500" style="padding:9px 12px; font-size:13px;">
                    </div>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Salary Display Label (Optional)</label>
                    <input type="text" name="salary_text" placeholder="e.g. RM 4,500 – RM 7,500 / month" style="padding:9px 12px; font-size:13px;">
                </div>

                <div style="margin-bottom:18px; padding:12px 16px; background:var(--surf); border:1px solid var(--bdr); border-radius:10px; display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="require_video" id="modal_require_video" value="1" style="width:18px; height:18px; cursor:pointer;">
                    <label for="modal_require_video" style="font-size:13px; font-weight:600; color:var(--txt); cursor:pointer;">
                        📹 Require YouTube Video Introduction Link from Applicants
                    </label>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">About the Role (Description)</label>
                    <textarea name="description" rows="4" placeholder="Enter role summary & mission..." required style="resize:vertical; padding:12px; font-size:13px;"></textarea>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Key Responsibilities</label>
                    <textarea name="responsibilities" rows="3" placeholder="List core daily duties..." style="resize:vertical; padding:12px; font-size:13px;"></textarea>
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Requirements & Qualifications</label>
                    <textarea name="requirements" rows="3" placeholder="List required skills, education, and years of experience..." style="resize:vertical; padding:12px; font-size:13px;"></textarea>
                </div>

                <div style="margin-bottom:24px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Company Perks & Benefits</label>
                    <textarea name="perks" rows="3" placeholder="e.g. Flexible Hours, Remote Allowance, Health Insurance..." style="resize:vertical; padding:12px; font-size:13px;"></textarea>
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
    </style>

    <script>
    function openPostJobModal() {
        document.getElementById('postJobModal').style.display = 'flex';
    }
    function closePostJobModal() {
        document.getElementById('postJobModal').style.display = 'none';
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

