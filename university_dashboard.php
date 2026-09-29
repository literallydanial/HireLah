<?php
require_once 'auth.php';
require_once 'db.php';

// ============================================================
// PROTOTYPE — University Monitoring Dashboard
// ------------------------------------------------------------
// There is no `university` role or institution table in the schema
// yet, so this page runs behind the existing `admin` role and uses
// hardcoded sample data (clearly labeled below) so it can be demoed
// to BD/university stakeholders before the backend work is scoped.
//
// To make this real: add a `university` role + `institutions` table,
// link `candidates` to an institution (verified via student email
// domain or a claimed matriculation number), and replace the
// $sample_students array below with real queries scoped to
// WHERE institution_id = <the logged-in university's id>.
// ============================================================
require_role('admin');

$institution_name = 'Universiti Putra Malaysia (UPM Serdang)';

// ---- SAMPLE DATA (replace with real DB queries once schema exists) ----
$sample_students = [
    ['name' => 'Nur Aisyah Rahman',   'faculty' => 'Computer Science', 'intake' => 2023, 'resume' => true,  'applications' => 6, 'score' => 82, 'last_active' => '2 days ago'],
    ['name' => 'Muhammad Haziq Idris','faculty' => 'Computer Science', 'intake' => 2023, 'resume' => true,  'applications' => 3, 'score' => 68, 'last_active' => '5 days ago'],
    ['name' => 'Tan Wei Ling',        'faculty' => 'Business & Mgmt',   'intake' => 2022, 'resume' => true,  'applications' => 9, 'score' => 91, 'last_active' => '1 day ago'],
    ['name' => 'Kavitha Selvam',      'faculty' => 'Business & Mgmt',   'intake' => 2023, 'resume' => false, 'applications' => 0, 'score' => 0,  'last_active' => '38 days ago'],
    ['name' => 'Ahmad Fikri Zainal',  'faculty' => 'Engineering',       'intake' => 2022, 'resume' => true,  'applications' => 4, 'score' => 74, 'last_active' => '6 days ago'],
    ['name' => 'Siti Nurhaliza Yusof','faculty' => 'Engineering',       'intake' => 2024, 'resume' => false, 'applications' => 0, 'score' => 0,  'last_active' => '52 days ago'],
    ['name' => 'Lim Jun Wei',         'faculty' => 'Agriculture',       'intake' => 2023, 'resume' => true,  'applications' => 2, 'score' => 61, 'last_active' => '11 days ago'],
    ['name' => 'Farah Adibah Kamal',  'faculty' => 'Computer Science',  'intake' => 2024, 'resume' => true,  'applications' => 5, 'score' => 77, 'last_active' => '3 days ago'],
    ['name' => 'Ravindran Muthu',     'faculty' => 'Agriculture',       'intake' => 2022, 'resume' => true,  'applications' => 1, 'score' => 55, 'last_active' => '19 days ago'],
    ['name' => 'Nurul Izzah Othman',  'faculty' => 'Business & Mgmt',   'intake' => 2024, 'resume' => false, 'applications' => 0, 'score' => 0,  'last_active' => '45 days ago'],
    ['name' => 'Chong Yee Ern',       'faculty' => 'Engineering',       'intake' => 2023, 'resume' => true,  'applications' => 7, 'score' => 85, 'last_active' => 'Today'],
    ['name' => 'Amirul Hakim Rosli',  'faculty' => 'Computer Science',  'intake' => 2022, 'resume' => true,  'applications' => 4, 'score' => 70, 'last_active' => '4 days ago'],
];

// Monthly engagement trend (resumes built vs applications submitted)
$monthly_trend = [
    ['month' => 'Apr', 'resumes' => 14, 'applications' => 22],
    ['month' => 'May', 'resumes' => 19, 'applications' => 31],
    ['month' => 'Jun', 'resumes' => 11, 'applications' => 18],
    ['month' => 'Jul', 'resumes' => 8,  'applications' => 12],
    ['month' => 'Aug', 'resumes' => 22, 'applications' => 40],
    ['month' => 'Sep', 'resumes' => 27, 'applications' => 52],
];

// ---- Derived stats (this part is real logic — swap the source array only) ----
$total_students   = count($sample_students);
$with_resume      = count(array_filter($sample_students, fn($s) => $s['resume']));
$resume_rate      = $total_students > 0 ? round(($with_resume / $total_students) * 100) : 0;
$total_apps       = array_sum(array_column($sample_students, 'applications'));
$scored           = array_filter($sample_students, fn($s) => $s['score'] > 0);
$avg_score        = count($scored) > 0 ? round(array_sum(array_column($scored, 'score')) / count($scored)) : 0;
$at_risk          = array_filter($sample_students, fn($s) => !$s['resume'] || $s['applications'] === 0);
$at_risk_count    = count($at_risk);

// Faculty breakdown
$faculties = [];
foreach ($sample_students as $s) {
    $f = $s['faculty'];
    if (!isset($faculties[$f])) {
        $faculties[$f] = ['total' => 0, 'resume' => 0, 'applications' => 0];
    }
    $faculties[$f]['total']++;
    if ($s['resume']) $faculties[$f]['resume']++;
    $faculties[$f]['applications'] += $s['applications'];
}

$max_trend = max(array_merge(array_column($monthly_trend, 'resumes'), array_column($monthly_trend, 'applications')));

$faculty_list = array_unique(array_column($sample_students, 'faculty'));
$intake_list  = array_unique(array_column($sample_students, 'intake'));
sort($intake_list);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>University Portal — Keria</title>
<link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
<style>
    .stats-grid-uni { display:grid; grid-template-columns:repeat(5, 1fr); gap:12px; margin-bottom:20px; }
    .trend-bars-row { display:flex; align-items:flex-end; gap:14px; height:140px; padding:0 4px; }
    .trend-bar-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; gap:4px; }
    .trend-bar-pair { display:flex; align-items:flex-end; gap:3px; height:100%; width:100%; justify-content:center; }
    .trend-bar { width:12px; border-radius:4px 4px 0 0; transition:height 0.4s ease; }
    .risk-row { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border:1px solid var(--bdr); border-radius:10px; background:var(--surf); margin-bottom:8px; }
    .faculty-row { display:grid; grid-template-columns:1.4fr 0.8fr 0.8fr 1fr; gap:10px; align-items:center; padding:10px 14px; border-bottom:1px solid var(--bdr); font-size:12.5px; }
    .faculty-row:last-child { border-bottom:none; }

    @media (max-width: 900px) {
        .stats-grid-uni { grid-template-columns: repeat(2, 1fr) !important; gap: 10px !important; }
        .stats-grid-uni .stat-box:last-child { grid-column: span 2; }
    }
</style>
</head>
<body>
<div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

<header>
    <div class="header-inner">
        <div style="display:flex; align-items:center; gap:10px;">
            <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; object-fit:contain;"></div>
            <div>
                <div style="font-size:15px; font-weight:800; line-height:1" class="header-brand-title">Keria Job Portal</div>
                <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">UNIVERSITY PORTAL</div>
            </div>
        </div>
        <nav style="display:flex; gap:4px; margin-left:24px">
            <a href="university_dashboard.php" class="active">🎓 Student Activity</a>
            <a href="#faculty-breakdown">🏛️ Faculties</a>
            <a href="#at-risk">⚠️ At-Risk</a>
        </nav>
        <div class="header-right-actions">
            <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
            <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
        </div>
    </div>
</header>

<main>

    <div style="background:rgba(217, 255, 79, 0.1); border:1px solid rgba(217, 255, 79, 0.3); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); display:flex; align-items:center; gap:10px;">
        <span style="font-size:16px;">🧪</span>
        <div><strong>Prototype view</strong> — showing sample data for demo purposes. Once the university role and student-linking are built, this page will scope automatically to each institution's own students only.</div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
        <div>
            <div style="font-size:22px; font-weight:800; color:var(--txt);">🎓 <?= htmlspecialchars($institution_name) ?></div>
            <div style="font-size:12.5px; color:var(--mut); margin-top:2px;">Monitoring student career-readiness and job-search activity on Keria</div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <select class="rb-input" style="width:auto; padding:8px 12px; font-size:12.5px;">
                <option>All Faculties</option>
                <?php foreach ($faculty_list as $f): ?>
                    <option><?= htmlspecialchars($f) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="rb-input" style="width:auto; padding:8px 12px; font-size:12.5px;">
                <option>All Intake Years</option>
                <?php foreach ($intake_list as $y): ?>
                    <option><?= htmlspecialchars($y) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn-secondary" style="padding:8px 16px; font-size:12.5px;">⬇️ Export Report (CSV)</button>
        </div>
    </div>

    <!-- KPI Header Grid -->
    <div class="stats-grid-uni">
        <div class="stat-box">
            <div class="logo-box" style="color:var(--acc); border-color:var(--acc);">🎓</div>
            <div><div class="stat-val" style="color:var(--acc)"><?= $total_students ?></div><div class="stat-lbl">Total Students</div></div>
        </div>
        <div class="stat-box">
            <div class="logo-box" style="color:var(--grn); border-color:var(--grn);">📄</div>
            <div><div class="stat-val" style="color:var(--grn)"><?= $resume_rate ?>%</div><div class="stat-lbl">Resume Completion</div></div>
        </div>
        <div class="stat-box">
            <div class="logo-box" style="color:var(--pur); border-color:var(--pur);">📮</div>
            <div><div class="stat-val" style="color:var(--pur)"><?= $total_apps ?></div><div class="stat-lbl">Applications Submitted</div></div>
        </div>
        <div class="stat-box">
            <div class="logo-box" style="color:var(--gold); border-color:var(--gold);">⭐</div>
            <div><div class="stat-val" style="color:var(--gold)"><?= $avg_score ?>%</div><div class="stat-lbl">Avg Resume Score</div></div>
        </div>
        <div class="stat-box">
            <div class="logo-box" style="color:var(--red); border-color:var(--red);">⚠️</div>
            <div><div class="stat-val" style="color:var(--red)"><?= $at_risk_count ?></div><div class="stat-lbl">At-Risk Students</div></div>
        </div>
    </div>

    <div class="analytics-grid" style="display:grid; grid-template-columns:1.3fr 1fr; gap:16px; margin-bottom:24px;">
        <!-- Engagement Trend -->
        <div class="panel" style="margin:0;">
            <div class="panel-title">📈 Monthly Engagement Trend</div>
            <div style="display:flex; gap:14px; font-size:11px; color:var(--mut); margin-bottom:10px;">
                <span><span style="display:inline-block; width:8px; height:8px; border-radius:2px; background:var(--acc); margin-right:4px;"></span>Resumes Built</span>
                <span><span style="display:inline-block; width:8px; height:8px; border-radius:2px; background:var(--pur); margin-right:4px;"></span>Applications</span>
            </div>
            <div class="trend-bars-row">
                <?php foreach ($monthly_trend as $m): ?>
                    <div class="trend-bar-col">
                        <div class="trend-bar-pair">
                            <div class="trend-bar" style="height:<?= round(($m['resumes']/$max_trend)*100) ?>%; background:var(--acc);" title="<?= $m['resumes'] ?> resumes"></div>
                            <div class="trend-bar" style="height:<?= round(($m['applications']/$max_trend)*100) ?>%; background:var(--pur);" title="<?= $m['applications'] ?> applications"></div>
                        </div>
                        <div style="font-size:10.5px; color:var(--mut); font-weight:700;"><?= $m['month'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Faculty Breakdown -->
        <div class="panel" style="margin:0;" id="faculty-breakdown">
            <div class="panel-title">🏛️ Faculty Breakdown</div>
            <div class="faculty-row" style="font-weight:700; color:var(--mut); font-size:10.5px; text-transform:uppercase; letter-spacing:0.5px;">
                <div>Faculty</div><div>Students</div><div>Resume %</div><div>Applications</div>
            </div>
            <?php foreach ($faculties as $fname => $f): ?>
                <div class="faculty-row">
                    <div style="font-weight:700; color:var(--txt);"><?= htmlspecialchars($fname) ?></div>
                    <div style="color:var(--txt);"><?= $f['total'] ?></div>
                    <div style="color:var(--acc); font-weight:700;"><?= round(($f['resume']/$f['total'])*100) ?>%</div>
                    <div style="color:var(--pur); font-weight:700;"><?= $f['applications'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- At-Risk Students -->
    <div class="panel" style="margin-bottom:24px;" id="at-risk">
        <div class="panel-title" style="display:flex; align-items:center; justify-content:space-between;">
            <span>⚠️ At-Risk Students <span class="chip" style="font-size:10px; background:rgba(239, 68, 68, 0.1); color:var(--red); border-color:rgba(239,68,68,0.25);"><?= $at_risk_count ?> flagged</span></span>
            <button class="btn-secondary" style="padding:6px 14px; font-size:11.5px;">📣 Send Reminder to All</button>
        </div>
        <?php if (empty($at_risk)): ?>
            <div style="padding:14px; text-align:center; color:var(--mut); font-size:12.5px;">No students currently flagged — nice work!</div>
        <?php else: ?>
            <?php foreach ($at_risk as $s): ?>
                <div class="risk-row">
                    <div>
                        <div style="font-weight:700; color:var(--txt); font-size:13px;"><?= htmlspecialchars($s['name']) ?></div>
                        <div style="font-size:11px; color:var(--mut);"><?= htmlspecialchars($s['faculty']) ?> · Intake <?= $s['intake'] ?> · Last active <?= htmlspecialchars($s['last_active']) ?></div>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <?php if (!$s['resume']): ?><span class="chip chip-rejected">No resume</span><?php endif; ?>
                        <?php if ($s['applications'] === 0): ?><span class="chip" style="color:var(--org); background:rgba(249,115,22,0.1); border-color:rgba(249,115,22,0.25);">0 applications</span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Full Student Roster -->
    <div class="panel">
        <div class="panel-title">📋 Student Roster</div>
        <div style="overflow-x:auto; border-radius:10px; border:1px solid var(--bdr);">
            <table class="table" style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:var(--surf); text-align:left; border-bottom:1px solid var(--bdr);">
                        <th style="padding:10px 14px;">Student</th>
                        <th style="padding:10px 14px;">Faculty</th>
                        <th style="padding:10px 14px; text-align:center;">Intake</th>
                        <th style="padding:10px 14px; text-align:center;">Resume</th>
                        <th style="padding:10px 14px; text-align:center;">Applications</th>
                        <th style="padding:10px 14px; text-align:center;">Readiness Score</th>
                        <th style="padding:10px 14px;">Last Active</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sample_students as $s): ?>
                        <tr style="border-bottom:1px solid var(--bdr);">
                            <td style="padding:10px 14px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($s['name']) ?></td>
                            <td style="padding:10px 14px; color:var(--mut);"><?= htmlspecialchars($s['faculty']) ?></td>
                            <td style="padding:10px 14px; text-align:center; color:var(--txt);"><?= $s['intake'] ?></td>
                            <td style="padding:10px 14px; text-align:center;">
                                <?php if ($s['resume']): ?><span class="chip chip-shortlisted">Complete</span>
                                <?php else: ?><span class="chip chip-rejected">Missing</span><?php endif; ?>
                            </td>
                            <td style="padding:10px 14px; text-align:center; font-weight:700; color:var(--pur);"><?= $s['applications'] ?></td>
                            <td style="padding:10px 14px; text-align:center;">
                                <?php if ($s['score'] > 0): ?>
                                    <span style="font-weight:700; color:<?= $s['score'] >= 75 ? 'var(--grn)' : ($s['score'] >= 50 ? 'var(--gold)' : 'var(--red)') ?>;"><?= $s['score'] ?>%</span>
                                <?php else: ?>
                                    <span style="color:var(--mut);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 14px; color:var(--mut);"><?= htmlspecialchars($s['last_active']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
<script src="theme.js"></script>
</body>
</html>
