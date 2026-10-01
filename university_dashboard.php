<?php
require_once 'auth.php';
require_once 'db.php';

// ============================================================
// PROTOTYPE — University Monitoring Dashboard
// ------------------------------------------------------------
// A real `university` role now exists (see register.php / login.php),
// and a logged-in university account lands here directly. Admins can
// also open this page to preview it. There is still no `institutions`
// table, though, so the data below is hardcoded sample data (clearly
// labeled) rather than real per-institution numbers.
//
// To make this real: add an `institutions` table, link `candidates`
// to an institution (verified via student email domain or a claimed
// matriculation number), and replace the $sample_students array below
// with real queries scoped to WHERE institution_id = <this university's id>.
// ============================================================
require_login();
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'university'], true)) {
    $_SESSION['error'] = "Access denied. You do not have the required role.";
    header("Location: index.php");
    exit;
}

$is_university_account = ($_SESSION['user_role'] ?? '') === 'university';

// ------------------------------------------------------------
// Institution icon upload
// ------------------------------------------------------------
// Only a real university account has its own row to store this on —
// it's saved directly to users.company_logo (the same column an
// employer account uses), keyed by that account's own user id.
if ($is_university_account && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['dashboard_action'] ?? '') === 'upload_university_icon') {
    $file = $_FILES['university_icon'] ?? null;
    $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    $max_bytes = 2 * 1024 * 1024; // 2MB

    if (!$file || $file['error'] === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > $max_bytes) {
        $_SESSION['error'] = "Uploaded icon exceeds the maximum allowed size (2MB).";
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "Upload failed. Please try again.";
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $image_info = @getimagesize($file['tmp_name']);
        if (!in_array($ext, $allowed_ext) || $image_info === false) {
            $_SESSION['error'] = "Invalid image. Use JPG, PNG, WEBP or GIF.";
        } else {
            $icon_dir = 'uploads/university_logos/';
            if (!is_dir($icon_dir)) @mkdir($icon_dir, 0755, true);
            if (!is_dir($icon_dir) || !is_writable($icon_dir)) {
                $_SESSION['error'] = "Directory '$icon_dir' is not writable. Please check permissions.";
            } else {
                // Remove any previous icon for this account (it may have had
                // a different extension) before saving the new one.
                foreach (glob($icon_dir . 'u' . $_SESSION['user_id'] . '.*') as $old_file) {
                    @unlink($old_file);
                }
                $path = $icon_dir . 'u' . $_SESSION['user_id'] . '.' . $ext;
                if (move_uploaded_file($file['tmp_name'], $path)) {
                    $stmt = $pdo->prepare("UPDATE users SET company_logo = ? WHERE id = ?");
                    $stmt->execute([$path, $_SESSION['user_id']]);
                    $_SESSION['toast'] = "University icon updated.";
                } else {
                    $_SESSION['error'] = "Failed to save icon.";
                }
            }
        }
    }
    header("Location: university_dashboard.php");
    exit;
}

// A real university account shows its own institution name and icon
// (stored in company_name / company_logo at sign-up or via the upload
// above, the same columns an employer account uses). It also carries a
// real university_id (same universities table candidates self-link to),
// which is how "my students" below is actually scoped.
$institution_logo = null;
$university_filter_id = null;
$legacy_name_fallback = false;
if ($is_university_account) {
    $stmt = $pdo->prepare("SELECT company_name, company_logo, university_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();
    $institution_name = !empty($row['company_name']) ? $row['company_name'] : $_SESSION['user_name'];
    if (!empty($row['company_logo']) && file_exists($row['company_logo'])) {
        $institution_logo = $row['company_logo'] . '?v=' . @filemtime($row['company_logo']);
    }
    if (!empty($row['university_id'])) {
        $university_filter_id = (int) $row['university_id'];
    } else {
        // Legacy/admin-created university account from before this account
        // itself carried a university_id -- fall back to matching by name
        // against the same table students pick from, so it isn't just empty.
        $name_match = $pdo->prepare("SELECT id FROM universities WHERE name = ?");
        $name_match->execute([$institution_name]);
        $found = $name_match->fetchColumn();
        if ($found) {
            $university_filter_id = (int) $found;
            $legacy_name_fallback = true;
        }
    }
} else {
    $institution_name = 'All Universities (Admin Preview)';
}

// ------------------------------------------------------------
// Helper: human-friendly relative time, used for "Last Active"
// ------------------------------------------------------------
function uni_time_ago($timestamp) {
    if (!$timestamp) return '—';
    $diff = time() - $timestamp;
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) { $m = round($diff / 60); return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago'; }
    if ($diff < 86400) { $h = round($diff / 3600); return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago'; }
    $d = round($diff / 86400);
    if ($d === 0) return 'Today';
    if ($d === 1) return 'Yesterday';
    return $d . ' days ago';
}

// ------------------------------------------------------------
// REAL DATA — students are existing Keria candidate accounts that have
// self-linked to this university (users.university_id), the same way a
// candidate picks a university at signup or later in Profile. There is
// no verification step, the same way there's no verification on a
// resume's claimed work history.
// ------------------------------------------------------------
if ($university_filter_id !== null) {
    $students_stmt = $pdo->prepare("SELECT id, name, email, default_resume, created_at FROM users WHERE role = 'candidate' AND university_id = ? ORDER BY name");
    $students_stmt->execute([$university_filter_id]);
    $students_raw = $students_stmt->fetchAll();
} elseif (!$is_university_account) {
    // Admin preview with no single institution selected -- show every
    // student who has self-linked any university, across all of them.
    $students_raw = $pdo->query("SELECT id, name, email, default_resume, created_at FROM users WHERE role = 'candidate' AND university_id IS NOT NULL ORDER BY name")->fetchAll();
} else {
    // A real university account with no matching students yet.
    $students_raw = [];
}

$student_ids = array_column($students_raw, 'id');

$applications_by_user = [];
$readiness_by_user    = [];
$last_build_by_user    = [];
$placements_by_user    = [];

if (!empty($student_ids)) {
    $in = implode(',', array_fill(0, count($student_ids), '?'));

    // Applications: one row per job application in `candidates`, keyed by
    // the candidate's own account (user_id) -- this is the real application
    // model Keria uses (see candidate.php / employer_dashboard.php).
    $app_stmt = $pdo->prepare("SELECT user_id, COUNT(*) AS cnt, SUM(status = 'Rejected') AS rejected_cnt, MAX(created_at) AS last_app FROM candidates WHERE user_id IN ($in) GROUP BY user_id");
    $app_stmt->execute($student_ids);
    foreach ($app_stmt->fetchAll() as $r) {
        $applications_by_user[$r['user_id']] = $r;
    }

    // Readiness score: latest AI resume review score for that student
    // (resume_reviews.overall_score), not a per-job match score.
    $rr_stmt = $pdo->prepare("
        SELECT rr.user_id, rr.overall_score, rr.created_at
        FROM resume_reviews rr
        INNER JOIN (
            SELECT user_id, MAX(created_at) AS max_created
            FROM resume_reviews
            WHERE user_id IN ($in)
            GROUP BY user_id
        ) latest ON latest.user_id = rr.user_id AND latest.max_created = rr.created_at
    ");
    $rr_stmt->execute($student_ids);
    foreach ($rr_stmt->fetchAll() as $r) {
        $readiness_by_user[$r['user_id']] = (int) $r['overall_score'];
    }

    $rb_stmt = $pdo->prepare("SELECT user_id, MAX(created_at) AS last_build FROM resume_builds WHERE user_id IN ($in) GROUP BY user_id");
    $rb_stmt->execute($student_ids);
    foreach ($rb_stmt->fetchAll() as $r) {
        $last_build_by_user[$r['user_id']] = $r['last_build'];
    }

    // Self-reported internship placement (candidate_dashboard.php's "My
    // Internship Placement" panel) -- the only source of "hired" status,
    // since no employer-settable "Hired" application status actually exists.
    $pl_stmt = $pdo->prepare("SELECT * FROM internship_placements WHERE candidate_user_id IN ($in)");
    $pl_stmt->execute($student_ids);
    foreach ($pl_stmt->fetchAll() as $r) {
        $placements_by_user[$r['candidate_user_id']] = $r;
    }
}

$students = [];
foreach ($students_raw as $u) {
    $app = $applications_by_user[$u['id']] ?? null;
    $app_count = $app ? (int) $app['cnt'] : 0;
    $has_rejected = $app ? ((int) $app['rejected_cnt'] > 0) : false;
    $placement = $placements_by_user[$u['id']] ?? null;

    // Outcome logic (confirmed): hired = has a self-reported placement;
    // rejected = has a real rejected application and no placement;
    // searching = neither. There is deliberately no employer-settable
    // "Hired" status to key off of -- it doesn't exist in this app.
    if ($placement) {
        $outcome = 'hired';
    } elseif ($has_rejected) {
        $outcome = 'rejected';
    } else {
        $outcome = 'searching';
    }

    $activity_dates = array_filter([
        $u['created_at'] ?? null,
        $app['last_app'] ?? null,
        $last_build_by_user[$u['id']] ?? null,
    ]);
    $last_active_ts = !empty($activity_dates) ? max(array_map('strtotime', $activity_dates)) : null;

    $students[] = [
        'id' => (int) $u['id'],
        'name' => $u['name'],
        'email' => $u['email'],
        'resume' => !empty($u['default_resume']),
        'applications' => $app_count,
        'score' => $readiness_by_user[$u['id']] ?? null,
        'last_active_ts' => $last_active_ts,
        'outcome' => $outcome,
        'company' => $placement['company'] ?? null,
        'supervisor' => $placement['supervisor'] ?? null,
        'supervisor_contact' => $placement['supervisor_contact'] ?? null,
        'start_date' => $placement['start_date'] ?? null,
        'end_date' => $placement['end_date'] ?? null,
        'placement_type' => $placement['placement_type'] ?? null,
    ];
}

// ---- Derived stats (all real now) ----
$total_students  = count($students);
$with_resume     = count(array_filter($students, fn($s) => $s['resume']));
$resume_rate     = $total_students > 0 ? round(($with_resume / $total_students) * 100) : 0;
$total_apps      = array_sum(array_column($students, 'applications'));
$scored          = array_filter($students, fn($s) => $s['score'] !== null);
$avg_score       = count($scored) > 0 ? round(array_sum(array_column($scored, 'score')) / count($scored)) : 0;
$at_risk         = array_filter($students, fn($s) => !$s['resume'] || $s['applications'] === 0);
$at_risk_count   = count($at_risk);
$hired_count     = count(array_filter($students, fn($s) => $s['outcome'] === 'hired'));
$rejected_count  = count(array_filter($students, fn($s) => $s['outcome'] === 'rejected'));
$placed_students = array_filter($students, fn($s) => !empty($s['company']));

// Monthly engagement trend -- real counts of this university's students'
// resume activity (builds + AI reviews) and job applications, last 6 months.
$monthly_trend = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthly_trend[$ym] = ['month' => date('M', strtotime($ym . '-01')), 'resumes' => 0, 'applications' => 0];
}
if (!empty($student_ids)) {
    $in = implode(',', array_fill(0, count($student_ids), '?'));

    $rb_m = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) cnt FROM resume_builds WHERE user_id IN ($in) AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY ym");
    $rb_m->execute($student_ids);
    foreach ($rb_m->fetchAll() as $r) {
        if (isset($monthly_trend[$r['ym']])) $monthly_trend[$r['ym']]['resumes'] += (int) $r['cnt'];
    }

    $rr_m = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) cnt FROM resume_reviews WHERE user_id IN ($in) AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY ym");
    $rr_m->execute($student_ids);
    foreach ($rr_m->fetchAll() as $r) {
        if (isset($monthly_trend[$r['ym']])) $monthly_trend[$r['ym']]['resumes'] += (int) $r['cnt'];
    }

    $app_m = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) cnt FROM candidates WHERE user_id IN ($in) AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY ym");
    $app_m->execute($student_ids);
    foreach ($app_m->fetchAll() as $r) {
        if (isset($monthly_trend[$r['ym']])) $monthly_trend[$r['ym']]['applications'] += (int) $r['cnt'];
    }
}
$monthly_trend = array_values($monthly_trend);
$max_trend = max(1, max(array_merge(array_column($monthly_trend, 'resumes'), array_column($monthly_trend, 'applications'))));

// Employer directory -- aggregated from these students' own self-reported placements.
$companies = [];
foreach ($placed_students as $s) {
    $c = $s['company'];
    if (!isset($companies[$c])) {
        $companies[$c] = ['students' => 0, 'types' => []];
    }
    $companies[$c]['students']++;
    if (!empty($s['placement_type'])) $companies[$c]['types'][] = $s['placement_type'];
}

// ------------------------------------------------------------
// CSV export — real per-student data, scoped the same way the page is.
// ------------------------------------------------------------
if (($_GET['dashboard_action'] ?? '') === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="student_roster_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Resume', 'Applications', 'Readiness Score', 'Last Active', 'Outcome', 'Company', 'Supervisor', 'Supervisor Contact', 'Start Date', 'End Date', 'Placement Type']);
    foreach ($students as $s) {
        fputcsv($out, [
            $s['name'], $s['email'],
            $s['resume'] ? 'Complete' : 'Missing', $s['applications'],
            $s['score'] !== null ? $s['score'] : '',
            uni_time_ago($s['last_active_ts']), $s['outcome'],
            $s['company'], $s['supervisor'], $s['supervisor_contact'],
            $s['start_date'], $s['end_date'], $s['placement_type'],
        ]);
    }
    fclose($out);
    exit;
}
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
    .stats-grid-uni { display:grid; grid-template-columns:repeat(6, 1fr); gap:12px; margin-bottom:20px; }
    .trend-bars-row { display:flex; align-items:flex-end; gap:14px; height:140px; padding:0 4px; }
    .trend-bar-col { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%; gap:4px; }
    .trend-bar-pair { display:flex; align-items:flex-end; gap:3px; height:100%; width:100%; justify-content:center; }
    .trend-bar { width:12px; border-radius:4px 4px 0 0; transition:height 0.4s ease; }
    .risk-row { display:flex; align-items:center; justify-content:space-between; padding:10px 14px; border:1px solid var(--bdr); border-radius:10px; background:var(--surf); margin-bottom:8px; }
    .faculty-row { display:grid; grid-template-columns:1.4fr 0.8fr 0.8fr; gap:10px; align-items:center; padding:10px 14px; border-bottom:1px solid var(--bdr); font-size:12.5px; }
    .faculty-row:last-child { border-bottom:none; }

    @media (max-width: 1100px) {
        .stats-grid-uni { grid-template-columns: repeat(3, 1fr) !important; gap: 10px !important; }
    }
    @media (max-width: 900px) {
        .stats-grid-uni { grid-template-columns: repeat(2, 1fr) !important; }
    }
    @media (max-width: 960px) {
        .analytics-grid { grid-template-columns: 1fr !important; }
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
            <a href="#at-risk">⚠️ At-Risk</a>
            <a href="#placements">📌 Placements</a>
        </nav>
        <div class="header-right-actions">
            <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
            <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
        </div>
    </div>
</header>

<main>

    <?php if ($is_university_account && $university_filter_id === null): ?>
        <div style="background:rgba(255, 196, 0, 0.1); border:1px solid rgba(255, 196, 0, 0.3); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); display:flex; align-items:center; gap:10px;">
            <span style="font-size:16px;">ℹ️</span>
            <div><strong><?= htmlspecialchars($institution_name) ?></strong> doesn't match any university in Keria's list yet, so no students are linked. Ask an admin to set this account's university from the same list students choose from in Admin → Registered Accounts.</div>
        </div>
    <?php elseif ($is_university_account && $legacy_name_fallback): ?>
        <div style="background:rgba(80,160,255,0.08); border:1px solid rgba(80,160,255,0.25); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); display:flex; align-items:center; gap:10px;">
            <span style="font-size:16px;">ℹ️</span>
            <div>Matched to <strong><?= htmlspecialchars($institution_name) ?></strong> by name. An admin can link this account directly for a more reliable match.</div>
        </div>
    <?php elseif (!$is_university_account): ?>
        <div style="background:rgba(217, 255, 79, 0.1); border:1px solid rgba(217, 255, 79, 0.3); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); display:flex; align-items:center; gap:10px;">
            <span style="font-size:16px;">🧪</span>
            <div><strong>Admin preview</strong> — showing every student across all universities, since no single institution is selected. A real university account only sees its own linked students.</div>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['toast'])): ?>
        <div style="background:rgba(34,197,94,0.1); border:1px solid rgba(34,197,94,0.3); border-radius:10px; padding:10px 16px; margin-bottom:16px; font-size:12.5px; color:var(--grn);">
            <?= htmlspecialchars($_SESSION['toast']) ?>
        </div>
        <?php unset($_SESSION['toast']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div style="background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.3); border-radius:10px; padding:10px 16px; margin-bottom:16px; font-size:12.5px; color:var(--red);">
            <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div style="display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:14px;">
            <div style="position:relative;">
                <div style="width:52px; height:52px; border-radius:12px; border:1px solid var(--bdr); background:var(--surf); display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:24px; color:var(--acc);">
                    <?php if ($institution_logo): ?>
                        <img src="<?= htmlspecialchars($institution_logo) ?>" alt="<?= htmlspecialchars($institution_name) ?> icon" style="width:100%; height:100%; object-fit:contain;">
                    <?php else: ?>
                        🎓
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <div style="font-size:22px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($institution_name) ?></div>
                <div style="font-size:12.5px; color:var(--mut); margin-top:2px;">Monitoring student career-readiness and job-search activity on Keria</div>
                <?php if ($is_university_account): ?>
                    <form method="post" enctype="multipart/form-data" style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                        <input type="hidden" name="dashboard_action" value="upload_university_icon">
                        <label class="btn-secondary" style="padding:5px 12px; font-size:11px; cursor:pointer;">
                            🖼️ <?= $institution_logo ? 'Change Icon' : 'Upload Icon' ?>
                            <input type="file" name="university_icon" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="this.form.submit()" style="display:none;">
                        </label>
                        <span style="font-size:10.5px; color:var(--mut);">JPG, PNG, WEBP or GIF — up to 2MB</span>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <select class="rb-input" id="outcomeFilter" onchange="filterRoster()" style="width:auto; padding:8px 36px 8px 12px; font-size:12.5px;">
                <option value="">All Outcomes</option>
                <option value="hired">Placed / Hired</option>
                <option value="searching">Still Searching</option>
                <option value="rejected">Rejected</option>
            </select>
            <a href="?dashboard_action=export_csv" class="btn-secondary" style="padding:8px 16px; font-size:12.5px; text-decoration:none; display:inline-flex; align-items:center;">⬇️ Export Report (CSV)</a>
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
            <div><div class="stat-val" style="color:var(--gold)"><?= $avg_score ?>%</div><div class="stat-lbl">Avg Readiness Score</div></div>
        </div>
        <div class="stat-box">
            <div class="logo-box" style="color:var(--red); border-color:var(--red);">⚠️</div>
            <div><div class="stat-val" style="color:var(--red)"><?= $at_risk_count ?></div><div class="stat-lbl">At-Risk Students</div></div>
        </div>
        <div class="stat-box">
            <div class="logo-box" style="color:var(--grn); border-color:var(--grn);">💼</div>
            <div><div class="stat-val" style="color:var(--grn)"><?= $hired_count ?></div><div class="stat-lbl">Students Placed</div></div>
        </div>
    </div>

    <!-- Monthly Engagement Trend -->
    <div class="panel" style="margin-bottom:24px;">
        <div class="panel-title">📈 Monthly Engagement Trend</div>
        <div style="display:flex; gap:14px; font-size:11px; color:var(--mut); margin-bottom:10px;">
            <span><span style="display:inline-block; width:8px; height:8px; border-radius:2px; background:var(--acc); margin-right:4px;"></span>Resume Activity</span>
            <span><span style="display:inline-block; width:8px; height:8px; border-radius:2px; background:var(--pur); margin-right:4px;"></span>Applications</span>
        </div>
        <div class="trend-bars-row">
            <?php foreach ($monthly_trend as $m): ?>
                <div class="trend-bar-col">
                    <div class="trend-bar-pair">
                        <div class="trend-bar" style="height:<?= round(($m['resumes']/$max_trend)*100) ?>%; background:var(--acc);" title="<?= $m['resumes'] ?> resume activity"></div>
                        <div class="trend-bar" style="height:<?= round(($m['applications']/$max_trend)*100) ?>%; background:var(--pur);" title="<?= $m['applications'] ?> applications"></div>
                    </div>
                    <div style="font-size:10.5px; color:var(--mut); font-weight:700;"><?= $m['month'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- At-Risk Students -->
    <div class="panel" style="margin-bottom:24px;" id="at-risk">
        <div class="panel-title" style="display:flex; align-items:center; justify-content:space-between;">
            <span>⚠️ At-Risk Students <span class="chip" style="font-size:10px; background:rgba(239, 68, 68, 0.1); color:var(--red); border-color:rgba(239,68,68,0.25);"><?= $at_risk_count ?> flagged</span></span>
        </div>
        <?php if (empty($students)): ?>
            <div style="padding:14px; text-align:center; color:var(--mut); font-size:12.5px;">No linked students yet.</div>
        <?php elseif (empty($at_risk)): ?>
            <div style="padding:14px; text-align:center; color:var(--mut); font-size:12.5px;">No students currently flagged — nice work!</div>
        <?php else: ?>
            <?php foreach ($at_risk as $s): ?>
                <div class="risk-row">
                    <div>
                        <div style="font-weight:700; color:var(--txt); font-size:13px;"><?= htmlspecialchars($s['name']) ?></div>
                        <div style="font-size:11px; color:var(--mut);">Last active <?= htmlspecialchars(uni_time_ago($s['last_active_ts'])) ?></div>
                    </div>
                    <div style="display:flex; gap:6px;">
                        <?php if (!$s['resume']): ?><span class="chip chip-rejected">No resume</span><?php endif; ?>
                        <?php if ($s['applications'] === 0): ?><span class="chip" style="color:var(--org); background:rgba(249,115,22,0.1); border-color:rgba(249,115,22,0.25);">0 applications</span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Internship Placements -->
    <div class="panel" style="margin-bottom:24px;" id="placements">
        <div class="panel-title">📌 Internship Placements</div>
        <?php if (empty($placed_students)): ?>
            <div style="padding:14px; text-align:center; color:var(--mut); font-size:12.5px;">No students have self-reported a placement yet.</div>
        <?php else: ?>
            <div style="overflow-x:auto; border-radius:10px; border:1px solid var(--bdr);">
                <table class="table" style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:var(--surf); text-align:left; border-bottom:1px solid var(--bdr);">
                            <th style="padding:10px 14px;">Student</th>
                            <th style="padding:10px 14px;">Company</th>
                            <th style="padding:10px 14px;">Supervisor</th>
                            <th style="padding:10px 14px;">Period</th>
                            <th style="padding:10px 14px; text-align:center;">Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($placed_students as $s): ?>
                            <tr style="border-bottom:1px solid var(--bdr);">
                                <td style="padding:10px 14px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($s['name']) ?></td>
                                <td style="padding:10px 14px; color:var(--txt);"><?= htmlspecialchars($s['company']) ?></td>
                                <td style="padding:10px 14px; color:var(--mut);">
                                    <?= $s['supervisor'] ? htmlspecialchars($s['supervisor']) : '—' ?>
                                    <?php if (!empty($s['supervisor_contact'])): ?><div style="font-size:10.5px;"><?= htmlspecialchars($s['supervisor_contact']) ?></div><?php endif; ?>
                                </td>
                                <td style="padding:10px 14px; color:var(--mut);"><?= $s['start_date'] ? htmlspecialchars($s['start_date']) : '—' ?> &rarr; <?= $s['end_date'] ? htmlspecialchars($s['end_date']) : '—' ?></td>
                                <td style="padding:10px 14px; text-align:center;">
                                    <span class="chip" style="color:var(--acc); background:rgba(80,160,255,0.1); border-color:rgba(80,160,255,0.25); text-transform:capitalize;"><?= htmlspecialchars($s['placement_type'] ?: '—') ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Employer Directory -->
    <div class="panel" style="margin-bottom:24px;" id="employer-directory">
        <div class="panel-title">🏢 Employer Directory</div>
        <?php if (empty($companies)): ?>
            <div style="padding:14px; text-align:center; color:var(--mut); font-size:12.5px;">No employer placements recorded yet.</div>
        <?php else: ?>
            <div class="faculty-row" style="font-weight:700; color:var(--mut); font-size:10.5px; text-transform:uppercase; letter-spacing:0.5px;">
                <div>Company</div><div>Students</div><div>Placement Type</div>
            </div>
            <?php foreach ($companies as $cname => $c): ?>
                <div class="faculty-row">
                    <div style="font-weight:700; color:var(--txt);"><?= htmlspecialchars($cname) ?></div>
                    <div style="color:var(--txt);"><?= $c['students'] ?></div>
                    <div style="color:var(--acc); font-weight:700; text-transform:capitalize;"><?= htmlspecialchars(implode(', ', array_unique($c['types'])) ?: '—') ?></div>
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
                        <th style="padding:10px 14px; text-align:center;">Resume</th>
                        <th style="padding:10px 14px; text-align:center;">Applications</th>
                        <th style="padding:10px 14px; text-align:center;">Readiness Score</th>
                        <th style="padding:10px 14px;">Last Active</th>
                        <th style="padding:10px 14px; text-align:center;">Outcome</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr><td colspan="6" style="padding:20px; text-align:center; color:var(--mut);">No linked students yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($students as $s): ?>
                        <tr class="roster-row" data-outcome="<?= htmlspecialchars($s['outcome']) ?>" style="border-bottom:1px solid var(--bdr);">
                            <td style="padding:10px 14px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($s['name']) ?><div style="font-weight:400; font-size:10.5px; color:var(--mut);"><?= htmlspecialchars($s['email']) ?></div></td>
                            <td style="padding:10px 14px; text-align:center;">
                                <?php if ($s['resume']): ?><span class="chip chip-shortlisted">Complete</span>
                                <?php else: ?><span class="chip chip-rejected">Missing</span><?php endif; ?>
                            </td>
                            <td style="padding:10px 14px; text-align:center; font-weight:700; color:var(--pur);"><?= $s['applications'] ?></td>
                            <td style="padding:10px 14px; text-align:center;">
                                <?php if ($s['score'] !== null): ?>
                                    <span style="font-weight:700; color:<?= $s['score'] >= 75 ? 'var(--grn)' : ($s['score'] >= 50 ? 'var(--gold)' : 'var(--red)') ?>;"><?= $s['score'] ?>%</span>
                                <?php else: ?>
                                    <span style="color:var(--mut);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:10px 14px; color:var(--mut);"><?= htmlspecialchars(uni_time_ago($s['last_active_ts'])) ?></td>
                            <td style="padding:10px 14px; text-align:center;">
                                <?php if ($s['outcome'] === 'hired'): ?><span class="chip chip-shortlisted">💼 Placed</span>
                                <?php elseif ($s['outcome'] === 'rejected'): ?><span class="chip chip-rejected">Rejected</span>
                                <?php else: ?><span class="chip chip-review">Searching</span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
<script>
    function filterRoster() {
        var val = document.getElementById('outcomeFilter').value;
        var rows = document.getElementsByClassName('roster-row');
        for (var i = 0; i < rows.length; i++) {
            rows[i].style.display = (!val || rows[i].dataset.outcome === val) ? '' : 'none';
        }
    }
</script>
<script src="theme.js"></script>
</body>
</html>
