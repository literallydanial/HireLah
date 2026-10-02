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

    /* ---- Left sidebar shell (replaces the old top nav bar) ---- */
    /* position:fixed instead of sticky: it's pinned to the actual browser
       viewport directly, permanently, regardless of scroll position, page
       length, or any flex/overflow quirks elsewhere on the page -- which
       is what sticky kept almost-but-not-quite doing. .uni-main-area gets
       margin-left to make room for it since fixed elements leave no gap
       of their own in the normal document flow. */
    .uni-shell { display: flex; min-height: 100vh; }
    /* Bottom padding of 76px (not the usual 20px) leaves clear room for
       theme.js's global dark-mode toggle button, which is fixed at the
       same bottom-left corner (bottom:20px; left:20px; 44px) on every
       page -- without it, the institution card sat right under/behind it. */
    .uni-sidebar { position: fixed; top: 0; left: 0; width: 280px; height: 100vh; overflow-y: auto; background: #F2FAE0; border-right: 1px solid rgba(10,10,10,0.06); display: flex; flex-direction: column; padding: 20px 16px 76px; box-sizing: border-box; z-index: 10; }
    .uni-sidebar-logo { display: flex; align-items: center; gap: 10px; padding: 4px 10px 22px; }
    .uni-sidebar-logo img { width: 34px; height: 34px; object-fit: contain; flex-shrink: 0; }
    /* Text colors below are fixed, not var(--txt)/var(--mut): those flip
       to light-on-dark in dark mode, but this sidebar's lime background
       never does, which was making the text unreadable in dark mode. */
    .uni-sidebar-logo .uni-logo-title { font-size: 14px; font-weight: 800; color: #0F1300; line-height: 1.2; }
    .uni-sidebar-logo .uni-logo-subtitle { font-size: 9px; color: #5B6B3A; letter-spacing: 0.8px; font-weight: 700; }
    .uni-menu-label { font-size: 10px; font-weight: 800; letter-spacing: 1px; color: #5B6B3A; padding: 4px 10px 10px; }
    .uni-menu-group { margin-bottom: 6px; }
    .uni-menu-parent { width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 11px 14px; border-radius: 10px; border: none; background: transparent; color: #0F1300; font-size: 13.5px; font-weight: 700; font-family: inherit; cursor: pointer; text-align: left; }
    .uni-menu-parent.active { background: #0A0A0A; color: #FFFFFF; }
    .uni-menu-parent:not(.active):hover { background: rgba(10,10,10,0.05); }
    .uni-menu-parent-left { display: flex; align-items: center; gap: 10px; }
    .uni-menu-chevron { font-size: 10px; opacity: 0.55; transition: transform 0.2s ease; }
    .uni-menu-group.open .uni-menu-chevron { transform: rotate(180deg); }
    .uni-menu-children { display: flex; flex-direction: column; padding: 6px 6px 6px 18px; gap: 2px; }
    .uni-menu-group:not(.open) .uni-menu-children { display: none; }
    .uni-menu-child { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 9px 12px; border-radius: 8px; color: #5B6B3A; font-size: 12.5px; font-weight: 600; text-decoration: none; }
    .uni-menu-child:hover { background: rgba(10,10,10,0.05); color: #0F1300; }
    .uni-menu-badge { font-size: 10px; font-weight: 800; padding: 1px 7px; border-radius: 999px; background: rgba(10,10,10,0.06); color: #5B6B3A; }
    .uni-menu-badge.danger { background: rgba(239,68,68,0.12); color: #B91C1C; }
    .uni-menu-badge.success { background: rgba(16,185,129,0.12); color: #047857; }
    .uni-sidebar-bottom { margin-top: auto; padding-top: 16px; border-top: 1px solid rgba(10,10,10,0.07); }
    .uni-sidebar-institution-card { display: flex; align-items: center; gap: 10px; padding: 10px 8px; }
    .uni-sidebar-institution-icon { width: 36px; height: 36px; border-radius: 9px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #FFFFFF; flex-shrink: 0; font-size: 17px; box-shadow: 0 1px 3px rgba(10,10,10,0.08); }
    .uni-sidebar-institution-icon img { width: 100%; height: 100%; object-fit: contain; }
    .uni-sidebar-institution-name { font-size: 12px; font-weight: 800; color: #0F1300; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 170px; }
    .uni-sidebar-institution-label { font-size: 10px; color: #5B6B3A; }

    .uni-main-area { flex: 1; min-width: 0; margin-left: 280px; display: flex; flex-direction: column; }
    .uni-topbar { display: flex; align-items: center; justify-content: space-between; padding: 18px 32px; border-bottom: 1px solid var(--bdr); gap: 16px; flex-wrap: wrap; }
    .uni-topbar-title { font-size: 15px; font-weight: 800; color: var(--txt); }
    .uni-topbar-right { display: flex; align-items: center; gap: 12px; }
    .uni-user-avatar { width: 32px; height: 32px; border-radius: 50%; background: #0A0A0A; color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; flex-shrink: 0; }

    @media (max-width: 900px) {
        .uni-shell { flex-direction: column; }
        .uni-sidebar { position: static; width: 100%; height: auto; overflow-y: visible; border-right: none; border-bottom: 1px solid rgba(10,10,10,0.06); }
        .uni-main-area { margin-left: 0; }
        .uni-topbar { padding: 16px 18px; }
    }
</style>
</head>
<body>
<div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

<div class="uni-shell">
    <aside class="uni-sidebar">
        <div class="uni-sidebar-logo">
            <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo">
            <div>
                <div class="uni-logo-title">Keria Job Portal</div>
                <div class="uni-logo-subtitle">UNIVERSITY PORTAL</div>
            </div>
        </div>

        <div class="uni-menu-label">MENU</div>

        <div class="uni-menu-group open">
            <button type="button" class="uni-menu-parent active" onclick="toggleUniMenu(this)">
                <span class="uni-menu-parent-left"><span>🎓</span> Student Activity</span>
                <span class="uni-menu-chevron">&#9662;</span>
            </button>
            <div class="uni-menu-children">
                <a href="#at-risk" class="uni-menu-child"><span>⚠️ At-Risk</span> <span class="uni-menu-badge danger"><?= $at_risk_count ?></span></a>
                <a href="#placements" class="uni-menu-child"><span>📌 Placements</span> <span class="uni-menu-badge success"><?= $hired_count ?></span></a>
            </div>
        </div>

        <div class="uni-menu-group open">
            <button type="button" class="uni-menu-parent" onclick="toggleUniMenu(this)">
                <span class="uni-menu-parent-left"><span>⚙️</span> Settings</span>
                <span class="uni-menu-chevron">&#9662;</span>
            </button>
            <div class="uni-menu-children">
                <a href="university_settings.php#institutionTab" class="uni-menu-child"><span>🏛️ Institution Profile</span></a>
                <a href="university_settings.php#liaisonTab" class="uni-menu-child"><span>👤 Career Officer Liaison</span></a>
                <a href="university_settings.php#qrTab" class="uni-menu-child"><span>▦ Career Fair QR &amp; Link</span></a>
                <a href="university_settings.php#securityTab" class="uni-menu-child"><span>🔒 Security &amp; Password</span></a>
                <a href="university_settings.php#complianceTab" class="uni-menu-child"><span>📄 Tracer &amp; PDPA Policy</span></a>
            </div>
        </div>

        <div class="uni-sidebar-bottom">
            <div class="uni-sidebar-institution-card">
                <div class="uni-sidebar-institution-icon">
                    <?php if ($institution_logo): ?>
                        <img src="<?= htmlspecialchars($institution_logo) ?>" alt="<?= htmlspecialchars($institution_name) ?>">
                    <?php else: ?>
                        🎓
                    <?php endif; ?>
                </div>
                <div>
                    <div class="uni-sidebar-institution-name"><?= htmlspecialchars($institution_name) ?></div>
                    <div class="uni-sidebar-institution-label">University Portal</div>
                </div>
            </div>
        </div>
    </aside>

    <div class="uni-main-area">
        <div class="uni-topbar">
            <div class="uni-topbar-title">Student Activity</div>
            <div class="uni-topbar-right">
                <div class="uni-user-avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?></div>
                <span class="user-info-text" style="font-size:12px; color:var(--mut);">Logged in as <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>

<main>

    <?php if ($is_university_account && $university_filter_id === null): ?>
        <div style="background:rgba(255, 196, 0, 0.1); border:1px solid rgba(255, 196, 0, 0.3); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:16px;">ℹ️</span>
                <div><strong><?= htmlspecialchars($institution_name) ?></strong> is not matched to an institution in Keria's database yet. Set your university name in Settings to begin tracking student analytics.</div>
            </div>
            <a href="university_settings.php" class="btn-primary" style="padding:6px 14px; font-size:11.5px; text-decoration:none;">Open Settings &rarr;</a>
        </div>
    <?php elseif ($is_university_account && $legacy_name_fallback): ?>
        <div style="background:rgba(80,160,255,0.08); border:1px solid rgba(80,160,255,0.25); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); display:flex; align-items:center; gap:10px;">
            <span style="font-size:16px;">ℹ️</span>
            <div>Matched to <strong><?= htmlspecialchars($institution_name) ?></strong> by name. You can customize your institution branding anytime in <a href="university_settings.php" style="color:var(--acc); font-weight:700;">Settings</a>.</div>
        </div>
    <?php elseif (!$is_university_account): ?>
        <div style="background:rgba(217, 255, 79, 0.1); border:1px solid rgba(217, 255, 79, 0.3); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:16px;">🧪</span>
                <div><strong>Admin preview</strong> — showing every student across all universities. A real university account only sees its own linked students.</div>
            </div>
            <a href="university_settings.php" class="btn-secondary" style="padding:6px 12px; font-size:11.5px; text-decoration:none;">Manage University Settings &rarr;</a>
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
                    <div style="display:flex; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap;">
                        <a href="university_settings.php#institutionTab" class="btn-secondary" style="padding:4px 10px; font-size:11px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <span>⚙️ Edit Institution & Crest</span>
                        </a>
                        <a href="university_settings.php#qrTab" class="btn-secondary" style="padding:4px 10px; font-size:11px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">
                            <span>📱 Student Fair QR</span>
                        </a>
                    </div>
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
    </div>
</div>
<script>
    function toggleUniMenu(btn) {
        var group = btn.closest('.uni-menu-group');
        if (group) group.classList.toggle('open');
    }

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
