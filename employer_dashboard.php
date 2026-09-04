<?php
require_once 'auth.php';
require_role('employer');
require_once 'notifications_helper.php';
require_once 'company_helpers.php';

$empNotifs = getEmployerNotifications($pdo, $_SESSION['user_id']);
$notifItems = $empNotifs['items'];
$unreadCount = $empNotifs['unread_count'];

// Fetch jobs for this employer's whole company team (shared workspace), not
// just the ones this particular HR account happened to post.
$active_company_id = get_active_company_id($pdo);
if ($active_company_id) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE company_id = ? OR (company_id IS NULL AND employer_id = ?)");
    $stmt->execute([$active_company_id, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE employer_id = ? OR employer_id IS NULL");
    $stmt->execute([$_SESSION['user_id']]);
}
$jobs = $stmt->fetchAll();

// Handle Job Selection
$job_id = $_GET['job_id'] ?? null;

// Validate job belongs to employer if selected
if ($job_id) {
    $valid = false;
    foreach($jobs as $j) { if($j['id'] == $job_id) $valid = true; }
    if(!$valid) $job_id = null;
}

// Fetch candidates
if ($job_id) {
    $stmt = $pdo->prepare("SELECT * FROM candidates WHERE job_id = ? ORDER BY overall_score DESC");
    $stmt->execute([$job_id]);
} else {
    // get all candidates for all this employer's jobs
    $job_ids = array_column($jobs, 'id');
    if (!empty($job_ids)) {
        $in = str_repeat('?,', count($job_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT * FROM candidates WHERE job_id IN ($in) ORDER BY overall_score DESC");
        $stmt->execute($job_ids);
    } else {
        $stmt = $pdo->query("SELECT * FROM candidates WHERE 1=0");
    }
}
$candidates = $stmt->fetchAll();

// Detailed Statistics & Analytics Calculation
$strong_hire = 0;
$hire = 0;
$maybe = 0;
$do_not_hire = 0;

$sum_skills = 0;
$sum_exp = 0;
$sum_edu = 0;
$total = count($candidates);
$screened = 0;
$total_score = 0;

$status_counts = [
    'New' => 0,
    'Reviewing' => 0,
    'Shortlisted' => 0,
    'Interviewed' => 0,
    'Hired' => 0,
    'Rejected' => 0
];

$skill_freq = [];

foreach ($candidates as $c) {
    if ($c['screened']) $screened++;
    $total_score += $c['overall_score'];

    $rec = $c['recommendation'] ?? 'Pending';
    if ($rec === 'Strong Hire') $strong_hire++;
    elseif ($rec === 'Hire') $hire++;
    elseif ($rec === 'Maybe') $maybe++;
    elseif ($rec === 'Do Not Hire') $do_not_hire++;

    $sum_skills += $c['skills_match'];
    $sum_exp += $c['exp_match'];
    $sum_edu += $c['edu_match'];

    $st = $c['status'] ?? 'New';
    if (isset($status_counts[$st])) {
        $status_counts[$st]++;
    } else {
        $status_counts['New']++;
    }

    $c_skills = $c['skills'] ? json_decode($c['skills'], true) : [];
    if (is_array($c_skills)) {
        foreach ($c_skills as $sk) {
            $sk_clean = trim($sk);
            if (!empty($sk_clean) && $sk_clean !== 'Invalid Document Format') {
                $skill_freq[$sk_clean] = ($skill_freq[$sk_clean] ?? 0) + 1;
            }
        }
    }
}

arsort($skill_freq);
$top_skills = array_slice($skill_freq, 0, 6, true);

$avg_score = $total > 0 ? round($total_score / $total) : 0;
$avg_skills = $total > 0 ? round($sum_skills / $total) : 0;
$avg_exp = $total > 0 ? round($sum_exp / $total) : 0;
$avg_edu = $total > 0 ? round($sum_edu / $total) : 0;

// Compute Hiring Funnel Analytics for Employer
$funnel = [
    'Applied' => $total,
    'Review' => 0,
    'Shortlisted' => 0,
    'Interviewing' => 0,
    'Rejected' => 0
];

foreach ($candidates as $c) {
    $st = strtolower($c['status'] ?? '');
    $int_st = $c['interview_status'] ?? 'None';

    if ($st === 'rejected') {
        $funnel['Rejected']++;
    } elseif ($st === 'shortlisted' || $st === 'shortlist') {
        $funnel['Shortlisted']++;
    } elseif (in_array($int_st, ['Proposed', 'Confirmed'])) {
        $funnel['Interviewing']++;
    } else {
        $funnel['Review']++;
    }
}

// Compute Candidate Status Count Per Job Table
$job_funnels = [];
foreach ($jobs as $j) {
    $j_id = $j['id'];
    $j_cands = array_filter($candidates, function($c) use ($j_id) { return $c['job_id'] == $j_id; });
    
    $j_applied = count($j_cands);
    $j_review = 0;
    $j_shortlisted = 0;
    $j_interviewing = 0;
    $j_rejected = 0;

    foreach ($j_cands as $jc) {
        $jst = strtolower($jc['status'] ?? '');
        $jint = $jc['interview_status'] ?? 'None';

        if ($jst === 'rejected') {
            $j_rejected++;
        } elseif ($jst === 'shortlisted' || $jst === 'shortlist') {
            $j_shortlisted++;
        } elseif (in_array($jint, ['Proposed', 'Confirmed'])) {
            $j_interviewing++;
        } else {
            $j_review++;
        }
    }

    $job_funnels[] = [
        'job_id' => $j_id,
        'job_title' => $j['job_title'],
        'department' => $j['department'],
        'applied' => $j_applied,
        'review' => $j_review,
        'shortlisted' => $j_shortlisted,
        'interviewing' => $j_interviewing,
        'rejected' => $j_rejected
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employer Dashboard & Analytics - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .stats-grid { display:grid; grid-template-columns:repeat(5, 1fr); gap:12px; margin-bottom:20px; }
        .analytics-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:24px; }
        .progress-bar-bg { background:var(--dim); height:8px; border-radius:4px; overflow:hidden; width:100%; }
        .progress-bar-val { height:100%; border-radius:4px; transition:width 0.4s ease; }
        .skill-badge-count { font-size:10px; background:rgba(255,255,255,0.08); padding:2px 6px; border-radius:4px; margin-left:6px; color:var(--mut); }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr) !important; gap: 10px !important; }
            .stats-grid .stat-box:last-child { grid-column: span 2; }
            .analytics-grid { grid-template-columns: 1fr !important; gap: 14px !important; }
            .filter-controls-panel { flex-direction: column !important; align-items: stretch !important; }
            .filter-controls-form { max-width: 100% !important; width: 100% !important; flex-direction: column !important; }
            .search-candidate-box { width: 100% !important; }
            .candidates-table-wrap { overflow-x: auto !important; -webkit-overflow-scrolling: touch !important; border-radius: 12px !important; }
            .candidates-table-wrap .table { min-width: 750px !important; }
        }

        @media (max-width: 500px) {
            main { padding: 16px 12px !important; }
            .stat-val { font-size: 20px !important; }
            .stat-lbl { font-size: 10px !important; }
            .panel { padding: 18px 14px !important; }
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
                <div>
                    <div style="font-size:15px; font-weight:800; line-height:1" class="header-brand-title">HireLah Job Portal</div>
                    <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">RECRUITMENT PLATFORM</div>
                </div>
            </div>
            
            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php" class="active">👥 Applications & Stats</a>
                <a href="job_dashboard.php">💼 My Jobs</a>
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
    
    <main>
        <?php if(isset($_SESSION['toast'])): ?>
            <div style="position:fixed; top:20px; right:20px; z-index:3000; background:rgba(0, 232, 122, 0.18); border:1px solid rgba(0, 232, 122, 0.5); border-radius:10px; padding:10px 18px; color:var(--grn); font-size:13px; font-weight:700;">
                <?= htmlspecialchars($_SESSION['toast']) ?>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <!-- KPI Header Grid -->
        <div class="stats-grid">
            <div class="stat-box">
                <div class="logo-box" style="color:var(--acc); border-color:var(--acc);">📋</div>
                <div><div class="stat-val" style="color:var(--acc)"><?= $total ?></div><div class="stat-lbl">Total Applicants</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--grn); border-color:var(--grn);">🌟</div>
                <div><div class="stat-val" style="color:var(--grn)"><?= $strong_hire ?></div><div class="stat-lbl">Strong Hires</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--gold); border-color:var(--gold);">⭐</div>
                <div><div class="stat-val" style="color:var(--gold)"><?= $avg_score ?>%</div><div class="stat-lbl">Avg Match Score</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--pur); border-color:var(--pur);">📌</div>
                <div><div class="stat-val" style="color:var(--pur)"><?= $status_counts['Shortlisted'] + $status_counts['Interviewed'] ?></div><div class="stat-lbl">Shortlisted / Interview</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--red); border-color:var(--red);">🚫</div>
                <div><div class="stat-val" style="color:var(--red)"><?= $status_counts['Rejected'] + $do_not_hire ?></div><div class="stat-lbl">Rejected / Invalid</div></div>
            </div>
        </div>

        <!-- Hiring Funnel Analytics Panel -->
        <div class="panel" style="margin-bottom:24px;">
            <div style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:14px; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span>🔻 Hiring Funnel Analytics</span>
                    <span class="chip" style="font-size:10px; background:rgba(217, 255, 79, 0.15); color:var(--acc); border-color:transparent;">Conversion Tracking</span>
                </div>
            </div>

            <!-- Visual Funnel Conversion Bars -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">1. Total Applied</div>
                    <div style="font-size:24px; font-weight:800; color:var(--txt); margin:4px 0;"><?= $funnel['Applied'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:100%; height:100%; background:linear-gradient(90deg, #B9D600, #0A0A0A);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;">100% Total</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">2. Under Review</div>
                    <div style="font-size:24px; font-weight:800; color:var(--acc); margin:4px 0;"><?= $funnel['Review'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $funnel['Applied'] > 0 ? round(($funnel['Review']/$funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--acc);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $funnel['Applied'] > 0 ? round(($funnel['Review']/$funnel['Applied'])*100) : 0 ?>% Rate</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">3. Shortlisted</div>
                    <div style="font-size:24px; font-weight:800; color:var(--grn); margin:4px 0;"><?= $funnel['Shortlisted'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $funnel['Applied'] > 0 ? round(($funnel['Shortlisted']/$funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--grn);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $funnel['Applied'] > 0 ? round(($funnel['Shortlisted']/$funnel['Applied'])*100) : 0 ?>% Rate</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">4. Interviewing</div>
                    <div style="font-size:24px; font-weight:800; color:var(--pur); margin:4px 0;"><?= $funnel['Interviewing'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $funnel['Applied'] > 0 ? round(($funnel['Interviewing']/$funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--pur);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $funnel['Applied'] > 0 ? round(($funnel['Interviewing']/$funnel['Applied'])*100) : 0 ?>% Rate</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">5. Rejected</div>
                    <div style="font-size:24px; font-weight:800; color:var(--red); margin:4px 0;"><?= $funnel['Rejected'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $funnel['Applied'] > 0 ? round(($funnel['Rejected']/$funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--red);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $funnel['Applied'] > 0 ? round(($funnel['Rejected']/$funnel['Applied'])*100) : 0 ?>% Rate</div>
                </div>
            </div>

            <!-- Candidate Status Counts Per Job Table -->
            <div style="font-size:13px; font-weight:800; color:var(--txt); margin-bottom:10px;">Candidates Status Breakdown Per Job Position</div>
            <div style="overflow-x:auto; border-radius:10px; border:1px solid var(--bdr);">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:var(--surf); text-align:left; border-bottom:1px solid var(--bdr);">
                            <th style="padding:10px 14px;">Job Position</th>
                            <th style="padding:10px 14px; text-align:center;">Applied</th>
                            <th style="padding:10px 14px; text-align:center;">Review</th>
                            <th style="padding:10px 14px; text-align:center;">Shortlisted</th>
                            <th style="padding:10px 14px; text-align:center;">Interviewing</th>
                            <th style="padding:10px 14px; text-align:center;">Rejected</th>
                            <th style="padding:10px 14px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($job_funnels)): ?>
                            <tr><td colspan="7" style="padding:14px; text-align:center; color:var(--mut);">No jobs available</td></tr>
                        <?php else: ?>
                            <?php foreach($job_funnels as $jf): ?>
                                <tr style="border-bottom:1px solid var(--bdr);">
                                    <td style="padding:10px 14px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($jf['job_title']) ?></td>
                                    <td style="padding:10px 14px; text-align:center; font-weight:700; color:var(--txt);"><?= $jf['applied'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--acc); font-weight:700;"><?= $jf['review'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--grn); font-weight:700;"><?= $jf['shortlisted'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--pur); font-weight:700;"><?= $jf['interviewing'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--red); font-weight:700;"><?= $jf['rejected'] ?></td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <a href="compare_candidates.php?job_id=<?= $jf['job_id'] ?>" class="btn-secondary" style="padding:4px 8px; font-size:11px; text-decoration:none;">⚖️ Compare</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Data Analytics Widgets -->
        <div class="analytics-grid">
            <!-- ATS Recommendation Distribution -->
            <div class="panel" style="margin:0;">
                <div class="panel-title">📊 ATS Recommendation Distribution</div>
                
                <div style="margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span style="color:var(--grn); font-weight:700;">🌟 Strong Hire (≥80%)</span>
                        <span style="font-weight:700; color:var(--grn);"><?= $strong_hire ?> (<?= $total > 0 ? round(($strong_hire/$total)*100) : 0 ?>%)</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-val" style="width:<?= $total > 0 ? ($strong_hire/$total)*100 : 0 ?>%; background:var(--grn);"></div>
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span style="color:var(--acc); font-weight:700;">✅ Hire (68-79%)</span>
                        <span style="font-weight:700; color:var(--acc);"><?= $hire ?> (<?= $total > 0 ? round(($hire/$total)*100) : 0 ?>%)</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-val" style="width:<?= $total > 0 ? ($hire/$total)*100 : 0 ?>%; background:var(--acc);"></div>
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span style="color:var(--org); font-weight:700;">⚠️ Maybe (50-67%)</span>
                        <span style="font-weight:700; color:var(--org);"><?= $maybe ?> (<?= $total > 0 ? round(($maybe/$total)*100) : 0 ?>%)</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-val" style="width:<?= $total > 0 ? ($maybe/$total)*100 : 0 ?>%; background:var(--org);"></div>
                    </div>
                </div>

                <div>
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span style="color:var(--red); font-weight:700;">❌ Do Not Hire (<50%)</span>
                        <span style="font-weight:700; color:var(--red);"><?= $do_not_hire ?> (<?= $total > 0 ? round(($do_not_hire/$total)*100) : 0 ?>%)</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-val" style="width:<?= $total > 0 ? ($do_not_hire/$total)*100 : 0 ?>%; background:var(--red);"></div>
                    </div>
                </div>
            </div>

            <!-- Average Match Category Averages -->
            <div class="panel" style="margin:0;">
                <div class="panel-title">🎯 Qualification Category Averages</div>
                
                <div style="margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span>🔧 Skills Alignment Avg</span>
                        <span style="font-weight:700; color:var(--acc);"><?= $avg_skills ?>%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-val" style="width:<?= $avg_skills ?>%; background:var(--acc);"></div>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span>💼 Experience Match Avg</span>
                        <span style="font-weight:700; color:var(--pur);"><?= $avg_exp ?>%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-val" style="width:<?= $avg_exp ?>%; background:var(--pur);"></div>
                    </div>
                </div>

                <div>
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
                        <span>🎓 Education Match Avg</span>
                        <span style="font-weight:700; color:var(--gold);"><?= $avg_edu ?>%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-val" style="width:<?= $avg_edu ?>%; background:var(--gold);"></div>
                    </div>
                </div>
            </div>

            <!-- Top Detected Applicant Skills -->
            <div class="panel" style="margin:0;">
                <div class="panel-title">🔧 Top Detected Applicant Skills</div>
                <?php if(!empty($top_skills)): ?>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach($top_skills as $sk => $cnt): ?>
                            <?php $pct = $total > 0 ? round(($cnt / $total) * 100) : 0; ?>
                            <div>
                                <div style="display:flex; justify-content:space-between; font-size:11px; font-weight:600; margin-bottom:3px;">
                                    <span><?= htmlspecialchars($sk) ?></span>
                                    <span style="color:var(--mut);"><?= $cnt ?> candidates (<?= $pct ?>%)</span>
                                </div>
                                <div class="progress-bar-bg">
                                    <div class="progress-bar-val" style="width:<?= $pct ?>%; background:linear-gradient(90deg, #6B8A00, #00E87A);"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:12px; color:var(--mut); padding:20px 0; text-align:center;">No candidate skill data recorded yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Filter & Search Controls -->
        <div class="panel filter-controls-panel" style="margin-bottom:16px; display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
            <form method="GET" action="employer_dashboard.php" class="filter-controls-form" style="display:flex; gap:10px; flex:1; max-width:500px;">
                <select name="job_id" onchange="this.form.submit()" style="flex:1;">
                    <option value="">All My Job Postings</option>
                    <?php foreach($jobs as $j): ?>
                        <option value="<?= $j['id'] ?>" <?= $job_id == $j['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($j['job_title'] . ($j['department'] ? " - {$j['department']}" : "") . " ({$j['employment_type']}, {$j['work_mode']})") ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if($job_id): ?>
                    <a href="compare_candidates.php?job_id=<?= $job_id ?>" class="btn-primary" style="white-space:nowrap; text-decoration:none; font-size:12px; padding:8px 14px;">⚖️ Compare Applicants</a>
                    <a href="employer_dashboard.php" class="btn-secondary" style="white-space:nowrap;">Clear Filter</a>
                <?php endif; ?>
            </form>

            <div class="search-candidate-box" style="display:flex; gap:10px; align-items:center;">
                <input type="text" id="candidateSearchInput" onkeyup="filterCandidatesTable()" placeholder="🔍 Search candidates..." style="padding:8px 14px; font-size:12px; border-radius:8px; border:1px solid var(--bdr); width:240px; background:var(--surf); color:var(--txt);">
            </div>
        </div>

        <?php if($total === 0): ?>
            <div class="panel" style="text-align:center; padding:70px 40px; border-style:dashed;">
                <div style="font-size:18px; font-weight:700; margin-bottom:10px;">📂 No applicants found</div>
                <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Post a new job to start receiving applications.</div>
                <a href="post_job.php" class="btn-secondary">📝 Post a Job</a>
            </div>
        <?php else: ?>
            <div class="candidates-table-wrap">
                <div class="table" id="candidatesTable">
                <div class="tr th">
                    <div>Rank</div><div>👤 Candidate</div><div>🔧 Top Skills</div>
                    <div style="text-align:center">📊 Score</div>
                    <div>🤖 Claude Verdict</div><div>📋 HR Status</div>
                    <div>📅 Applied</div><div style="text-align:center">⚙ Actions</div>
                </div>
                
                <?php $rank = 1; foreach($candidates as $c): ?>
                    <?php
                        $score = $c['overall_score'];
                        $color = $score >= 80 ? 'var(--grn)' : ($score >= 60 ? 'var(--acc)' : ($score >= 40 ? 'var(--org)' : 'var(--red)'));
                        $status_class = strtolower($c['status']) == 'shortlisted' ? 'chip-shortlisted' : (strtolower($c['status']) == 'rejected' ? 'chip-rejected' : 'chip-review');
                        $skills = $c['skills'] ? json_decode($c['skills'], true) : [];
                    ?>
                    <a href="candidate.php?id=<?= $c['id'] ?>" class="tr candidate-row" data-search="<?= htmlspecialchars(strtolower($c['name'] . ' ' . $c['email'] . ' ' . implode(' ', $skills))) ?>">
                        <div style="font-size:13px; font-weight:800; font-family:monospace; color:<?= $rank==1 ? 'var(--gold)' : 'var(--mut)' ?>">
                            #<?= $rank++ ?>
                        </div>
                        <div style="min-width:0;">
                            <div style="font-size:13px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($c['name']) ?></div>
                            <div style="font-size:10px; color:var(--mut); margin-top:1px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($c['email'] ?: $c['filename']) ?></div>
                        </div>
                        <div style="display:flex; flex-wrap:wrap; gap:3px;">
                            <?php foreach(array_slice($skills, 0, 3) as $s): ?>
                                <span style="background:var(--dim); border-radius:4px; padding:1px 6px; font-size:9px; color:var(--mut); border:1px solid var(--bdr);"><?= htmlspecialchars($s) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div style="text-align:center;">
                            <span style="font-size:16px; font-weight:800; color:<?= $color ?>; font-family:monospace;"><?= $score ?>%</span>
                        </div>
                        <div>
                            <?php 
                            $v_color = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'var(--grn)' : ($c['recommendation'] == 'Maybe' ? 'var(--org)' : 'var(--red)');
                            ?>
                            <span style="font-size:12px; font-weight:700; color:<?= $v_color ?>;"><?= htmlspecialchars($c['recommendation']) ?></span>
                        </div>
                        <div>
                            <span class="chip <?= $status_class ?>"><?= htmlspecialchars($c['status']) ?></span>
                        </div>
                        <div style="font-size:11px; color:var(--mut)">
                            <?= date('M d, Y', strtotime($c['created_at'])) ?>
                        </div>
                        <div style="display:flex; justify-content:center; align-items:center;" onclick="event.stopPropagation();">
                            <span class="btn-secondary" style="padding:4px 10px; font-size:11px; white-space:nowrap;">Review &rarr;</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            </div>
        <?php endif; ?>
    </main>

    <script>
    function filterCandidatesTable() {
        var input = document.getElementById('candidateSearchInput');
        var filter = input.value.toLowerCase().trim();
        var rows = document.getElementsByClassName('candidate-row');

        for (var i = 0; i < rows.length; i++) {
            var searchData = rows[i].getAttribute('data-search');
            if (searchData && searchData.indexOf(filter) > -1) {
                rows[i].style.display = 'grid';
            } else {
                rows[i].style.display = 'none';
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
<script src="theme.js"></script></body>
</html>
