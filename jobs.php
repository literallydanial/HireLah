<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

$userNotifs = (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate') ? getCandidateNotifications($pdo, $_SESSION['user_id']) : ['items' => [], 'unread_count' => 0];
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];

$search = trim($_GET['search'] ?? '');
$filter_type = trim($_GET['type'] ?? '');
$filter_mode = trim($_GET['mode'] ?? '');
$filter_location = trim($_GET['location'] ?? '');
$filter_min_salary = !empty($_GET['min_salary']) ? (int)$_GET['min_salary'] : null;
$filter_max_salary = !empty($_GET['max_salary']) ? (int)$_GET['max_salary'] : null;
$sort_by = trim($_GET['sort'] ?? 'newest');

$sql = "SELECT j.*, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, u.company_logo 
        FROM jobs j 
        LEFT JOIN users u ON j.employer_id = u.id 
        WHERE j.status = 'Active'";
$params = [];

if ($search !== '') {
    $sql .= " AND (j.job_title LIKE ? OR j.department LIKE ? OR j.location LIKE ? OR j.description LIKE ? OR u.company_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter_type !== '') {
    $sql .= " AND j.employment_type = ?";
    $params[] = $filter_type;
}
if ($filter_mode !== '') {
    $sql .= " AND j.work_mode = ?";
    $params[] = $filter_mode;
}
if ($filter_location !== '') {
    $sql .= " AND (j.location LIKE ? OR j.department LIKE ?)";
    $params[] = "%$filter_location%";
    $params[] = "%$filter_location%";
}
if ($filter_min_salary !== null) {
    $sql .= " AND (COALESCE(j.salary_max, j.salary_min) >= ?)";
    $params[] = $filter_min_salary;
}
if ($filter_max_salary !== null) {
    $sql .= " AND (COALESCE(j.salary_min, j.salary_max) <= ?)";
    $params[] = $filter_max_salary;
}

if ($sort_by === 'salary_high') {
    $sql .= " ORDER BY COALESCE(j.salary_max, j.salary_min, 0) DESC, j.created_at DESC";
} elseif ($sort_by === 'salary_low') {
    $sql .= " ORDER BY CASE WHEN COALESCE(j.salary_min, j.salary_max) IS NULL THEN 1 ELSE 0 END, COALESCE(j.salary_min, j.salary_max) ASC, j.created_at DESC";
} else {
    $sql .= " ORDER BY j.created_at DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

// Fetch each employer's company gallery media (images/videos) and attach it to their jobs
$mediaByEmployer = [];
$employerIds = array_values(array_unique(array_filter(array_column($jobs, 'employer_id'))));
if (!empty($employerIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($employerIds), '?'));
        $media_stmt = $pdo->prepare("SELECT * FROM company_media WHERE user_id IN ($placeholders) ORDER BY sort_order ASC, id ASC");
        $media_stmt->execute($employerIds);
        foreach ($media_stmt->fetchAll() as $m) {
            $mediaByEmployer[$m['user_id']][] = ['media_type' => $m['media_type'], 'file_path' => $m['file_path']];
        }
    } catch (\Throwable $e) {
        $mediaByEmployer = [];
    }
}
foreach ($jobs as &$j) {
    $j['company_media'] = $mediaByEmployer[$j['employer_id']] ?? [];
    
    $created_time = strtotime($j['created_at'] ?? 'now');
    $diff_days = floor((time() - $created_time) / 86400);
    if ($diff_days < 1) {
        $j['posted_label'] = "Posted today";
    } elseif ($diff_days == 1) {
        $j['posted_label'] = "Posted 1 day ago";
    } else {
        $j['posted_label'] = "Posted " . $diff_days . " days ago";
    }
}
unset($j);

// Fetch set of job IDs & AI scores that the logged-in candidate has applied to
$applied_job_ids = [];
$candidate_scores = [];
if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'candidate') {
    try {
        $app_stmt = $pdo->prepare("SELECT job_id, overall_score, skills_match, exp_match, edu_match, summary, relevance_label FROM candidates WHERE user_id = ? AND job_id IS NOT NULL ORDER BY created_at DESC");
        $app_stmt->execute([$_SESSION['user_id']]);
        $app_records = $app_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($app_records as $rec) {
            $j_id = $rec['job_id'];
            if (!in_array($j_id, $applied_job_ids)) {
                $applied_job_ids[] = $j_id;
                $candidate_scores[$j_id] = $rec;
            }
        }
    } catch (\Throwable $e) {
        $applied_job_ids = [];
        $candidate_scores = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Board - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .search-bar-panel {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }
        .split-layout {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 24px;
            align-items: start;
        }
        .job-list-pane {
            max-height: calc(100vh - 190px);
            overflow-y: auto;
            padding-right: 8px;
        }
        .job-list-pane::-webkit-scrollbar {
            width: 5px;
        }
        .job-list-pane::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 4px;
        }
        .job-list-pane::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 4px;
        }
        .job-list-pane::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.35);
        }
        .job-card-item {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        .job-card-item:hover {
            border-color: rgba(59, 130, 246, 0.5);
            box-shadow: var(--shadow-md);
        }
        .job-card-item.selected-card {
            border-color: var(--acc);
            background: rgba(59, 130, 246, 0.04);
            box-shadow: 0 0 0 1px var(--acc);
        }
        .job-detail-pane {
            position: sticky;
            top: 80px;
        }
        .job-detail-sticky {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 28px 32px;
            box-shadow: var(--shadow-md);
            max-height: calc(100vh - 190px);
            overflow-y: auto;
        }
        .job-detail-sticky::-webkit-scrollbar {
            width: 5px;
        }
        .job-detail-sticky::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 4px;
        }
        .job-detail-sticky::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 4px;
        }
        @media (max-width: 960px) {
            .split-layout {
                grid-template-columns: 1fr;
            }
            .job-list-pane {
                max-height: none;
                overflow-y: visible;
            }
            .job-detail-pane {
                display: none !important;
            }
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
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
                <a href="jobs.php" class="active">📋 Job Board</a>
                <?php if(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate'): ?>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Profile Settings</a>
                <?php elseif(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications & Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php else: ?>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                <?php endif; ?>
            </nav>

            <div class="header-right-actions">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <?php if($_SESSION['user_role'] === 'candidate'): ?>
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
                    <?php endif; ?>

                    <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?> (<?= ucfirst($_SESSION['user_role']) ?>)</span>
                    <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; font-weight:700;">🔑 Login</a>
                    <a href="register.php" class="btn-primary" style="padding:6px 14px; font-size:12px; font-weight:700; width:auto; text-decoration:none;">✨ Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main style="max-width:1300px; padding:24px 20px;">
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

        <!-- Detailed Search & Filter Panel -->
        <form method="GET" class="search-bar-panel" style="padding:20px; border-radius:18px;">
            <!-- Primary Search Row -->
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin-bottom:14px;">
                <div style="flex:2; min-width:260px;">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Job title, department, company, or skills..." style="margin:0; padding:12px 16px; font-size:13.5px; border-radius:10px;">
                </div>
                <div style="flex:1.2; min-width:180px;">
                    <input type="text" name="location" value="<?= htmlspecialchars($filter_location) ?>" placeholder="📍 Location or City (e.g. KL)..." style="margin:0; padding:12px 16px; font-size:13.5px; border-radius:10px;">
                </div>
                <button type="submit" class="btn-primary" style="padding:12px 28px; font-size:13.5px; font-weight:800; width:auto; margin:0; border-radius:10px; flex-shrink:0;">Find Jobs 🔍</button>
            </div>

            <!-- Secondary Detailed Filter Controls Row -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; align-items:center;">
                <div>
                    <select name="type" style="margin:0; padding:10px 12px; font-size:12.5px; border-radius:10px;" onchange="this.form.submit()">
                        <option value="">All Job Types</option>
                        <option value="Full-time" <?= $filter_type == 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                        <option value="Part-time" <?= $filter_type == 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                        <option value="Contract" <?= $filter_type == 'Contract' ? 'selected' : '' ?>>Contract</option>
                        <option value="Internship" <?= $filter_type == 'Internship' ? 'selected' : '' ?>>Internship</option>
                    </select>
                </div>
                <div>
                    <select name="mode" style="margin:0; padding:10px 12px; font-size:12.5px; border-radius:10px;" onchange="this.form.submit()">
                        <option value="">All Work Modes</option>
                        <option value="Remote" <?= $filter_mode == 'Remote' ? 'selected' : '' ?>>Remote</option>
                        <option value="On-site" <?= $filter_mode == 'On-site' ? 'selected' : '' ?>>On-site</option>
                        <option value="Hybrid" <?= $filter_mode == 'Hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div>
                    <select name="min_salary" style="margin:0; padding:10px 12px; font-size:12.5px; border-radius:10px;" onchange="this.form.submit()">
                        <option value="">Any Salary Range</option>
                        <option value="3000" <?= $filter_min_salary == 3000 ? 'selected' : '' ?>>RM 3,000+ / mo</option>
                        <option value="5000" <?= $filter_min_salary == 5000 ? 'selected' : '' ?>>RM 5,000+ / mo</option>
                        <option value="8000" <?= $filter_min_salary == 8000 ? 'selected' : '' ?>>RM 8,000+ / mo</option>
                        <option value="12000" <?= $filter_min_salary == 12000 ? 'selected' : '' ?>>RM 12,000+ / mo</option>
                    </select>
                </div>
                <div>
                    <select name="sort" style="margin:0; padding:10px 12px; font-size:12.5px; border-radius:10px;" onchange="this.form.submit()">
                        <option value="newest" <?= $sort_by == 'newest' ? 'selected' : '' ?>>🆕 Newest First</option>
                        <option value="salary_high" <?= $sort_by == 'salary_high' ? 'selected' : '' ?>>💰 Highest Salary</option>
                        <option value="salary_low" <?= $sort_by == 'salary_low' ? 'selected' : '' ?>>💵 Lowest Salary</option>
                    </select>
                </div>
                <?php if($search || $filter_type || $filter_mode || $filter_location || $filter_min_salary || $sort_by !== 'newest'): ?>
                    <div>
                        <a href="jobs.php" class="btn-secondary" style="display:block; padding:10px 14px; font-size:12.5px; text-decoration:none; text-align:center; border-radius:10px; font-weight:600;">Reset Filters ✕</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Filter Chips Row -->
            <div class="quick-filter-bar" style="margin-top:16px; border-top:1px solid var(--bdr); padding-top:12px;">
                <a href="jobs.php" class="quick-filter-chip <?= (!$filter_mode && !$filter_type && !$search && !$filter_location && !$filter_min_salary) ? 'active' : '' ?>">All Positions</a>
                <a href="jobs.php?mode=Remote" class="quick-filter-chip <?= $filter_mode === 'Remote' ? 'active' : '' ?>">⚡ Remote Only</a>
                <a href="jobs.php?search=Junior" class="quick-filter-chip <?= str_contains(strtolower($search), 'junior') ? 'active' : '' ?>">🎓 Fresh Grad Friendly</a>
                <a href="jobs.php?min_salary=5000" class="quick-filter-chip <?= $filter_min_salary == 5000 ? 'active' : '' ?>">💰 High Salary (RM 5k+)</a>
                <a href="jobs.php?mode=Hybrid" class="quick-filter-chip <?= $filter_mode === 'Hybrid' ? 'active' : '' ?>">🏢 Hybrid Work</a>
                <a href="jobs.php?location=Kuala+Lumpur" class="quick-filter-chip <?= str_contains(strtolower($filter_location), 'kuala') ? 'active' : '' ?>">📍 Kuala Lumpur</a>
            </div>
        </form>

        <?php if(empty($jobs)): ?>
            <div class="panel" style="text-align:center; padding:60px 20px; color:var(--mut);">
                <div style="font-size:36px; margin-bottom:12px;">🔍</div>
                <div style="font-size:18px; font-weight:800; color:var(--txt); margin-bottom:6px;">No matching positions found</div>
                <div style="font-size:13px;">Try adjusting your keywords, job type, or work mode filters.</div>
            </div>
        <?php else: ?>
            <!-- 2-Column Indeed Split-Pane Orientation -->
            <div class="split-layout">
                
                <!-- Left Pane: Job List Cards -->
                <div class="job-list-pane">
                    <div style="font-size:12px; font-weight:700; color:var(--mut); margin-bottom:12px; text-transform:uppercase; letter-spacing:0.5px;">
                        <?= count($jobs) ?> Active Positions
                    </div>

                    <?php foreach($jobs as $index => $j): 
                        $is_applied = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate' && in_array($j['id'], $applied_job_ids);
                        $has_score = isset($candidate_scores[$j['id']]);
                        $sc = $has_score ? $candidate_scores[$j['id']] : null;
                        $score_num = $has_score ? (int)$sc['overall_score'] : 0;
                        $score_color = $score_num >= 75 ? '#00E87A' : ($score_num >= 50 ? '#F59E0B' : '#FF4D6A');
                    ?>
                        <div class="job-card-item <?= $index === 0 ? 'selected-card' : '' ?>" id="card_<?= $j['id'] ?>" onclick="selectJob(<?= $j['id'] ?>)">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px;">
                                <div style="flex:1; min-width:0;">
                                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:4px;">
                                        <span style="font-size:16px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($j['job_title']) ?></span>
                                        <span class="chip-urgency"><?= htmlspecialchars($j['posted_label']) ?></span>
                                    </div>
                                    <div style="font-size:12px; color:var(--mut); margin-bottom:10px;">
                                        <strong><?= htmlspecialchars($j['employer_name'] ?? 'HireLah') ?></strong> &bull; 📍 <?= htmlspecialchars($j['department'] ?: 'General') ?>
                                    </div>

                                    <div style="display:flex; gap:6px; margin-bottom:10px; flex-wrap:wrap;">
                                        <span class="chip" style="font-size:10px; background:var(--dim); color:var(--txt); border-color:var(--bdr);"><?= htmlspecialchars($j['employment_type']) ?></span>
                                        <span class="chip" style="font-size:10px; background:var(--dim); color:var(--txt); border-color:var(--bdr);"><?= htmlspecialchars($j['work_mode']) ?></span>
                                    </div>

                                    <?php if($has_score): ?>
                                        <div style="display:flex; align-items:center; justify-content:space-between; background:rgba(255,255,255,0.03); border:1px solid var(--bdr); border-radius:8px; padding:6px 10px; margin-top:8px;">
                                            <span style="font-size:10px; font-weight:700; color:var(--mut);">🤖 AI MATCH SCORE:</span>
                                            <span style="font-size:13px; font-weight:800; color:<?= $score_color ?>;"><?= $score_num ?>%</span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if($is_applied): ?>
                                        <div style="font-size:11px; font-weight:700; color:var(--grn); margin-top:8px;">✓ Applied</div>
                                    <?php endif; ?>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                    <?php if(!empty($j['company_logo'])): ?>
                                        <img src="<?= htmlspecialchars($j['company_logo']) ?>" alt="" style="width:40px; height:40px; object-fit:contain; border-radius:8px; flex-shrink:0;">
                                    <?php endif; ?>
                                    <span id="cardBookmark_<?= $j['id'] ?>" style="display:none; font-size:14px;" title="Saved Job">🔖</span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Right Pane: Sticky Job Details Preview -->
                <div class="job-detail-pane">
                    <div class="job-detail-sticky" id="jobDetailPreview">
                        <!-- Loaded via JS dynamically from JSON below -->
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Mobile Job Details Modal Popup -->
    <div id="mobileJobModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); z-index:99999; align-items:center; justify-content:center; padding:16px;">
        <div class="modal" style="background:var(--card); border:1px solid var(--bdr); border-radius:20px; max-width:600px; width:100%; max-height:85vh; overflow-y:auto; padding:24px; position:relative; box-shadow:0 20px 50px rgba(0,0,0,0.5); animation:modalSlideUp 0.25s ease;">
            <button type="button" onclick="closeMobileJobModal()" style="position:absolute; top:16px; right:16px; background:var(--dim); border:1px solid var(--bdr); color:var(--txt); width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:800; font-size:14px; z-index:10;">✕</button>
            <div id="mobileJobModalContent">
                <!-- Populated dynamically on job click in mobile view -->
            </div>
        </div>
    </div>

    <!-- Embedded Job Data for Instant Client-Side Switching -->
    <script>
        const jobsData = <?= json_encode(array_values($jobs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const candidateScores = <?= json_encode($candidate_scores, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const appliedJobIds = <?= json_encode($applied_job_ids, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const userRole = <?= json_encode($_SESSION['user_role'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        let savedJobIds = JSON.parse(localStorage.getItem('hirelah_saved_jobs') || '[]');

        function updateSavedBadges() {
            savedJobIds.forEach(id => {
                const cardBadge = document.getElementById('cardBookmark_' + id);
                if (cardBadge) cardBadge.style.display = 'inline-block';
            });
        }

        function toggleSaveJob(jobId) {
            jobId = parseInt(jobId);
            if (savedJobIds.includes(jobId)) {
                savedJobIds = savedJobIds.filter(id => id !== jobId);
                if (typeof showToast === 'function') showToast('Job removed from bookmarks', 'info');
            } else {
                savedJobIds.push(jobId);
                if (typeof showToast === 'function') showToast('Job saved to bookmarks!', 'success');
            }
            localStorage.setItem('hirelah_saved_jobs', JSON.stringify(savedJobIds));

            const btn = document.getElementById('saveBtn_' + jobId);
            if (btn) {
                const isSaved = savedJobIds.includes(jobId);
                btn.classList.toggle('is-saved', isSaved);
                btn.innerHTML = isSaved ? '🔖 Saved' : '🔖 Save Job';
            }

            const cardBadge = document.getElementById('cardBookmark_' + jobId);
            if (cardBadge) {
                cardBadge.style.display = savedJobIds.includes(jobId) ? 'inline-block' : 'none';
            }
        }

        function formatSalary(job) {
            if (job.salary_text && job.salary_text.trim()) {
                return escapeHtml(job.salary_text.trim());
            }
            const min = job.salary_min ? parseInt(job.salary_min) : null;
            const max = job.salary_max ? parseInt(job.salary_max) : null;
            if (min && max) {
                return `RM ${min.toLocaleString()} – RM ${max.toLocaleString()} / month`;
            } else if (min) {
                return `From RM ${min.toLocaleString()} / month`;
            } else if (max) {
                return `Up to RM ${max.toLocaleString()} / month`;
            }
            return null;
        }

        function parseJobDescription(job) {
            return {
                about: escapeHtml(job.description || 'No detailed description provided.'),
                responsibilities: job.responsibilities ? escapeHtml(job.responsibilities) : null,
                requirements: job.requirements ? escapeHtml(job.requirements) : null,
                perks: job.perks ? escapeHtml(job.perks) : null
            };
        }

        function selectJob(jobId) {
            const job = jobsData.find(j => j.id == jobId);
            if (!job) return;

            // Highlight selected card
            document.querySelectorAll('.job-card-item').forEach(c => c.classList.remove('selected-card'));
            const selectedCard = document.getElementById('card_' + jobId);
            if (selectedCard) selectedCard.classList.add('selected-card');

            const isApplied = appliedJobIds.includes(job.id);
            const isSaved = savedJobIds.includes(parseInt(job.id));
            const scoreData = candidateScores[job.id] || null;

            const preview = document.getElementById('jobDetailPreview');
            const mobileContent = document.getElementById('mobileJobModalContent');
            const mobileModal = document.getElementById('mobileJobModal');

            // Shimmer Skeleton Loader
            const skeletonHtml = `
                <div style="padding:10px 0;">
                    <div class="skeleton-box" style="width:60%; height:28px; margin-bottom:12px;"></div>
                    <div class="skeleton-box" style="width:40%; height:18px; margin-bottom:20px;"></div>
                    <div class="skeleton-box" style="width:100%; height:42px; margin-bottom:24px;"></div>
                    <div class="skeleton-box" style="width:100%; height:120px; margin-bottom:24px;"></div>
                    <div class="skeleton-box" style="width:100%; height:180px;"></div>
                </div>
            `;
            if (preview && window.innerWidth > 960) preview.innerHTML = skeletonHtml;

            setTimeout(() => {
                const salaryText = formatSalary(job);
                const locationText = job.location ? escapeHtml(job.location) : (escapeHtml(job.department || 'General'));
                
                // 1. Header & Quick Apply
                let applyButtonHtml = '';
                if (isApplied) {
                    applyButtonHtml = `<button disabled class="btn-secondary" style="padding:12px 24px; font-size:14px; opacity:0.75; cursor:not-allowed;">✓ Applied for Position</button>`;
                } else {
                    applyButtonHtml = `<a href="apply.php?job_id=${job.id}" class="btn-primary" style="padding:12px 28px; font-size:14px; font-weight:800; text-decoration:none; width:auto; display:inline-flex; align-items:center; gap:6px; border-radius:10px;">⚡ Quick Apply &rarr;</a>`;
                }

                const saveButtonHtml = `<button type="button" id="saveBtn_${job.id}" class="btn-save-job ${isSaved ? 'is-saved' : ''}" onclick="toggleSaveJob(${job.id})">${isSaved ? '🔖 Saved' : '🔖 Save Job'}</button>`;

                // 2. AI Match Breakdown Card
                let aiScoreHtml = '';
                if (scoreData) {
                    const scoreNum = parseInt(scoreData.overall_score || 0);
                    const scoreColor = scoreNum >= 75 ? '#00E87A' : (scoreNum >= 50 ? '#F59E0B' : '#FF4D6A');
                    const skillsScore = parseInt(scoreData.skills_match || 85);
                    const expScore = parseInt(scoreData.exp_match || 90);
                    const eduScore = parseInt(scoreData.edu_match || 80);

                    aiScoreHtml = `
                        <div style="background:var(--card); border:1px solid var(--bdr); border-radius:16px; padding:20px; margin-bottom:24px; box-shadow:var(--shadow-sm);">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                                <div>
                                    <div style="font-size:12px; font-weight:800; color:var(--mut); letter-spacing:0.5px;">🤖 AI MATCH EVALUATION BREAKDOWN</div>
                                    <div style="font-size:13px; color:var(--txt); font-weight:600; margin-top:2px;">Personalized Candidate Fit Analysis</div>
                                </div>
                                <div style="text-align:right;">
                                    <span style="font-size:26px; font-weight:800; color:${scoreColor}; line-height:1;">${scoreNum}%</span>
                                    <div style="font-size:10px; font-weight:700; color:var(--mut);">MATCH SCORE</div>
                                </div>
                            </div>
                            
                            <div style="width:100%; height:7px; background:rgba(255,255,255,0.08); border-radius:4px; overflow:hidden; margin-bottom:16px;">
                                <div style="width:${scoreNum}%; height:100%; background:${scoreColor}; border-radius:4px; transition:width 0.5s ease;"></div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:16px;">
                                <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:10px; padding:10px; text-align:center;">
                                    <div style="font-size:10px; color:var(--mut); font-weight:700;">SKILL MATCH</div>
                                    <div style="font-size:15px; font-weight:800; color:var(--acc); margin-top:2px;">${skillsScore}%</div>
                                </div>
                                <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:10px; padding:10px; text-align:center;">
                                    <div style="font-size:10px; color:var(--mut); font-weight:700;">EXPERIENCE FIT</div>
                                    <div style="font-size:15px; font-weight:800; color:#6B8A00; margin-top:2px;">${expScore}%</div>
                                </div>
                                <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:10px; padding:10px; text-align:center;">
                                    <div style="font-size:10px; color:var(--mut); font-weight:700;">EDUCATION FIT</div>
                                    <div style="font-size:15px; font-weight:800; color:#34D399; margin-top:2px;">${eduScore}%</div>
                                </div>
                            </div>

                            <div style="margin-bottom:14px;">
                                <div style="font-size:11px; font-weight:700; color:var(--mut); margin-bottom:8px;">KEYWORD & SKILL OVERLAP</div>
                                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                    <span class="skill-match-tag matched">✓ ${escapeHtml(job.department || 'Backend')} Core Stack</span>
                                    <span class="skill-match-tag matched">✓ Professional Experience</span>
                                    <span class="skill-match-tag matched">✓ Problem Solving</span>
                                    <span class="skill-match-tag missing">⚡ AI Wording Highlight</span>
                                </div>
                            </div>

                            <div style="font-size:12px; color:var(--mut); line-height:1.5; background:var(--surf); padding:10px 14px; border-radius:10px; border-left:3px solid ${scoreColor};">
                                <strong>Fit Summary:</strong> ${escapeHtml(scoreData.summary || 'Strong technical background matching core requirements for this position.')}
                            </div>
                        </div>
                    `;
                } else if (userRole === 'candidate') {
                    aiScoreHtml = `
                        <div style="background:linear-gradient(135deg, rgba(107, 138, 0, 0.12), rgba(59, 130, 246, 0.08)); border:1px solid rgba(107, 138, 0, 0.25); border-radius:16px; padding:18px; margin-bottom:24px;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="font-size:24px;">🤖</div>
                                <div style="flex:1;">
                                    <div style="font-size:13px; font-weight:800; color:var(--txt);">AI Resume Match Evaluator</div>
                                    <div style="font-size:12px; color:var(--mut); margin-top:2px;">Click Quick Apply to submit your resume and unlock instant Gemini AI match scoring!</div>
                                </div>
                                <a href="resume_builder.php" class="btn-secondary" style="padding:6px 12px; font-size:11.5px; white-space:nowrap; border-radius:8px;">📝 AI Resume Builder</a>
                            </div>
                        </div>
                    `;
                }

                // 3. Company Culture Gallery
                let galleryHtml = '';
                if (job.company_media && job.company_media.length > 0) {
                    const tiles = job.company_media.map((m, idx) => {
                        const spanStyle = idx === 0 ? 'grid-column:span 2; grid-row:span 2;' : '';
                        return `<a href="${escapeHtml(m.file_path)}" target="_blank" rel="noopener" style="display:block; ${spanStyle} border-radius:10px; overflow:hidden; background:var(--surf); border:1px solid var(--bdr);">
                            <img src="${escapeHtml(m.file_path)}" alt="" style="width:100%; height:100%; object-fit:cover;">
                        </a>`;
                    }).join('');
                    galleryHtml = `
                        <div style="margin-bottom:24px;">
                            <h3 style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:12px;">🏢 Company Culture & Gallery</h3>
                            <div style="display:grid; grid-template-columns:repeat(3, 1fr); grid-auto-rows:110px; gap:10px;">
                                ${tiles}
                            </div>
                        </div>
                    `;
                }

                const descSections = parseJobDescription(job);

                // 4. Company Snapshot Card
                const companySnapshotHtml = `
                    <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:16px; padding:20px; margin-top:28px;">
                        <div style="font-size:11px; font-weight:800; color:var(--mut); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:14px;">🏢 COMPANY SNAPSHOT</div>
                        <div style="display:flex; align-items:center; gap:14px; margin-bottom:16px;">
                            ${job.company_logo ? `<img src="${escapeHtml(job.company_logo)}" alt="" style="width:52px; height:52px; object-fit:contain; border-radius:12px; background:var(--surf); padding:4px; border:1px solid var(--bdr);">` : `<div style="width:52px; height:52px; border-radius:12px; background:var(--surf); border:1px solid var(--bdr); display:flex; align-items:center; justify-content:center; font-size:24px;">🏢</div>`}
                            <div>
                                <div style="font-size:16px; font-weight:800; color:var(--txt);">${escapeHtml(job.employer_name || 'HireLah Enterprise')}</div>
                                <div style="font-size:12px; color:var(--mut);">${escapeHtml(job.department || 'Technology')} &bull; Active Hiring Partner</div>
                            </div>
                        </div>
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:12px; margin-bottom:16px;">
                            <div><span style="color:var(--mut);">👥 Employees:</span> <strong>50 – 200 Employees</strong></div>
                            <div><span style="color:var(--mut);">🌐 Industry:</span> <strong>Tech & Software Solutions</strong></div>
                            <div><span style="color:var(--mut);">📍 Office:</span> <strong>${locationText}</strong></div>
                            <div><span style="color:var(--mut);">⚡ Response Rate:</span> <strong style="color:var(--grn);">High (&lt; 24h)</strong></div>
                        </div>

                        <div style="display:flex; justify-content:flex-end;">
                            <a href="index.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; text-decoration:none; border-radius:8px;">🌐 Visit Company Profile &rarr;</a>
                        </div>
                    </div>
                `;

                const detailHtml = `
                    <!-- Header & CTAs -->
                    <div style="border-bottom:1px solid var(--bdr); padding-bottom:22px; margin-bottom:22px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:12px;">
                            <div style="flex:1; min-width:0;">
                                <h2 style="font-size:24px; font-weight:800; color:var(--txt); margin:0 0 6px 0; line-height:1.2;">${escapeHtml(job.job_title)}</h2>
                                <div style="font-size:13.5px; color:var(--mut); display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
                                    <span><strong>${escapeHtml(job.employer_name || 'HireLah')}</strong></span>
                                    <span>&bull;</span>
                                    <span>📍 ${locationText}</span>
                                    <span>&bull;</span>
                                    <span class="chip-urgency">${escapeHtml(job.posted_label || 'Posted recently')}</span>
                                </div>
                                ${salaryText ? `
                                <div style="font-size:17px; font-weight:800; color:var(--acc); margin-bottom:16px;">
                                    💰 ${salaryText}
                                </div>
                                ` : `
                                <div style="font-size:13.5px; font-weight:600; color:var(--mut); margin-bottom:16px;">
                                    💰 Salary Undisclosed
                                </div>
                                `}
                            </div>
                            ${job.company_logo ? `<img src="${escapeHtml(job.company_logo)}" alt="" style="width:72px; height:72px; object-fit:contain; border-radius:14px; flex-shrink:0; background:var(--surf); padding:6px; border:1px solid var(--bdr);">` : ''}
                        </div>

                        <div style="display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap;">
                            <span class="chip" style="font-size:11px; background:var(--dim); color:var(--txt); border-color:var(--bdr); font-weight:600;">📋 ${escapeHtml(job.employment_type || 'Full-time')}</span>
                            <span class="chip" style="font-size:11px; background:var(--dim); color:var(--txt); border-color:var(--bdr); font-weight:600;">💼 ${escapeHtml(job.work_mode || 'On-site')}</span>
                            <span class="chip" style="font-size:11px; background:var(--dim); color:var(--txt); border-color:var(--bdr); font-weight:600;">🟢 Active Position</span>
                        </div>

                        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                            ${applyButtonHtml}
                            ${saveButtonHtml}
                        </div>
                    </div>

                    <!-- AI Match Breakdown Card -->
                    ${aiScoreHtml}

                    <!-- Company Culture Gallery -->
                    ${galleryHtml}

                    <!-- Structured Job Content -->
                    <div style="display:flex; flex-direction:column; gap:20px; font-size:13.5px; line-height:1.65; color:var(--txt);">
                        <div>
                            <h3 style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:8px; display:flex; align-items:center; gap:6px;">📌 About the Role</h3>
                            <p style="margin:0; color:var(--txt); font-size:13.5px; white-space:pre-wrap;">${descSections.about}</p>
                        </div>

                        ${descSections.responsibilities ? `
                        <div>
                            <h3 style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:8px; display:flex; align-items:center; gap:6px;">🎯 Key Responsibilities</h3>
                            <div style="white-space:pre-wrap; color:var(--txt); font-size:13.5px;">${descSections.responsibilities}</div>
                        </div>
                        ` : ''}

                        ${descSections.requirements ? `
                        <div>
                            <h3 style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:8px; display:flex; align-items:center; gap:6px;">🎓 Requirements & Qualifications</h3>
                            <div style="white-space:pre-wrap; color:var(--txt); font-size:13.5px;">${descSections.requirements}</div>
                        </div>
                        ` : ''}

                        ${descSections.perks ? `
                        <div>
                            <h3 style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:10px; display:flex; align-items:center; gap:6px;">🎁 Company Perks & Benefits</h3>
                            <div style="white-space:pre-wrap; color:var(--txt); font-size:13.5px;">${descSections.perks}</div>
                        </div>
                        ` : ''}
                    </div>

                    <!-- Company Snapshot -->
                    ${companySnapshotHtml}
                `;

                if (preview) preview.innerHTML = detailHtml;

                if (window.innerWidth <= 960) {
                    if (mobileContent && mobileModal) {
                        mobileContent.innerHTML = detailHtml;
                        mobileModal.style.display = 'flex';
                    }
                }
            }, 120);
        }

        // Auto select first job on load
        document.addEventListener('DOMContentLoaded', function() {
            updateSavedBadges();
            if (Array.isArray(jobsData) && jobsData.length > 0) {
                selectJob(jobsData[0].id);
            }
        });

        function closeMobileJobModal() {
            const mobileModal = document.getElementById('mobileJobModal');
            if (mobileModal) mobileModal.style.display = 'none';
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeMobileJobModal();
        });

        document.addEventListener('click', function(e) {
            const mobileModal = document.getElementById('mobileJobModal');
            if (mobileModal && e.target === mobileModal) {
                closeMobileJobModal();
            }
        });

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
