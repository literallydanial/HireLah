<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'admin_logs_helper.php';
require_once 'ai.php';

// Ensure user is logged in as admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    $_SESSION['error'] = "Access denied. Admin privileges required.";
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// ----------------------------------------------------
// 1. POST ACTIONS: Add User / Delete User / Toggle Verify / Delete Job
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action: Add New User or Admin
    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'candidate';
        $is_verified = isset($_POST['is_verified']) ? 1 : 0;

        if (empty($name) || empty($email) || empty($password)) {
            $_SESSION['error'] = "All fields (Name, Email, Password) are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid email format.";
        } else {
            // Check duplicate email
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $_SESSION['error'] = "An account with this email address already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_verified, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $ins->execute([$name, $email, $hash, $role, $is_verified]);
                $new_user_id = $pdo->lastInsertId();

                log_admin_action($pdo, $admin_id, $admin_name, 'add_user', 'user', $new_user_id, "Created new $role account for '$name' ($email)");
                $_SESSION['toast'] = "New user account created successfully (" . ucfirst($role) . ")!";
            }
        }
        header("Location: admin_dashboard.php");
        exit;
    }

    // Action: Toggle Email Verification
    if ($action === 'toggle_verify') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET is_verified = CASE WHEN is_verified = 1 THEN 0 ELSE 1 END WHERE id = ?");
            $stmt->execute([$target_id]);

            $u_stmt = $pdo->prepare("SELECT name, email, is_verified FROM users WHERE id = ?");
            $u_stmt->execute([$target_id]);
            $target_user = $u_stmt->fetch();
            $ver_status = $target_user['is_verified'] ? 'Verified' : 'Unverified';

            log_admin_action($pdo, $admin_id, $admin_name, 'toggle_verify', 'user', $target_id, "Toggled verification for '{$target_user['name']}' to $ver_status");
            $_SESSION['toast'] = "User verification status updated.";
        }
        header("Location: admin_dashboard.php");
        exit;
    }

    // Action: Change User Role
    if ($action === 'change_role') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $new_role = $_POST['new_role'] ?? 'candidate';
        if ($target_id > 0 && in_array($new_role, ['candidate', 'employer', 'admin'])) {
            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
            $stmt->execute([$new_role, $target_id]);

            $u_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $u_stmt->execute([$target_id]);
            $target_name = $u_stmt->fetchColumn() ?: 'User';

            log_admin_action($pdo, $admin_id, $admin_name, 'change_role', 'user', $target_id, "Changed role of '$target_name' to " . ucfirst($new_role));
            $_SESSION['toast'] = "User role changed to " . ucfirst($new_role) . ".";
        }
        header("Location: admin_dashboard.php");
        exit;
    }

    // Action: Delete User Account
    if ($action === 'delete_user') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        if ($target_id > 0) {
            if ($target_id === (int)$_SESSION['user_id']) {
                $_SESSION['error'] = "You cannot delete your own admin account while logged in!";
            } else {
                $u_stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
                $u_stmt->execute([$target_id]);
                $target_user = $u_stmt->fetch();

                $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $del->execute([$target_id]);

                log_admin_action($pdo, $admin_id, $admin_name, 'delete_user', 'user', $target_id, "Deleted user account '{$target_user['name']}' ({$target_user['email']})");
                $_SESSION['toast'] = "User account deleted successfully.";
            }
        }
        header("Location: admin_dashboard.php");
        exit;
    }

    // Action: Delete Job Posting
    if ($action === 'delete_job') {
        $job_id = (int)($_POST['job_id'] ?? 0);
        if ($job_id > 0) {
            $j_stmt = $pdo->prepare("SELECT job_title FROM jobs WHERE id = ?");
            $j_stmt->execute([$job_id]);
            $job_title = $j_stmt->fetchColumn() ?: 'Job';

            $del = $pdo->prepare("DELETE FROM jobs WHERE id = ?");
            $del->execute([$job_id]);

            log_admin_action($pdo, $admin_id, $admin_name, 'delete_job', 'job', $job_id, "Deleted job posting '$job_title'");
            $_SESSION['toast'] = "Job posting deleted successfully.";
        }
        header("Location: admin_dashboard.php");
        exit;
    }
}

// ----------------------------------------------------
// 2. DIAGNOSTICS & SYSTEM HEALTH COMPUTATION
// ----------------------------------------------------

// Database Ping & Latency Test
$db_start_time = microtime(true);
try {
    $pdo->query("SELECT 1");
    $db_ping_ms = round((microtime(true) - $db_start_time) * 1000, 2);
    $db_status = "Operational";
    $db_status_color = "#00E87A";
} catch (\Throwable $e) {
    $db_ping_ms = 0;
    $db_status = "Connection Error";
    $db_status_color = "#FF4D6A";
}

// MySQL Server Version & Database Stats
$mysql_version = "Unknown";
try {
    $mysql_version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (\Throwable $e) {}

// Table Counts & System Volumes
$total_users = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_candidates_role = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'candidate'")->fetchColumn();
$total_employers_role = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'employer'")->fetchColumn();
$total_admins_role = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$unverified_users = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_verified = 0")->fetchColumn();

$total_jobs = (int)$pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
$active_jobs = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'Active'")->fetchColumn();
$total_candidates_eval = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
$total_questionnaires = (int)$pdo->query("SELECT COUNT(*) FROM questionnaires")->fetchColumn();

// Claude AI Screening Diagnostics
$env_api_key = getenv('CLAUDE_API_KEY') ?: (getenv('ANTHROPIC_API_KEY') ?: '');
$claude_key_configured = !empty($env_api_key) || true; // Built-in Claude engine enabled

// Claude API Key Health Check (actually tests the configured key/model against
// the live Anthropic API, rather than just pinging the base URL unauthenticated —
// a bare network ping can't detect an invalid key, a disabled organization, or a
// dead model name, since those all return a normal HTTP response, not a connection failure)
$configured_api_key = get_api_key();
$claude_start_time = microtime(true);
$key_check = check_api_key_status($configured_api_key, $_SESSION['ai_model'] ?? 'claude-sonnet-5');
$claude_latency_ms = round((microtime(true) - $claude_start_time) * 1000, 2);

$key_status_map = [
    'active'          => ['label' => 'API Key Active',         'color' => '#00E87A'],
    'not_configured'  => ['label' => 'Not Configured',         'color' => '#9CA3AF'],
    'invalid_key'      => ['label' => 'Invalid API Key',        'color' => '#FF4D6A'],
    'org_disabled'    => ['label' => 'Organization Disabled',  'color' => '#FF4D6A'],
    'model_not_found' => ['label' => 'Model Not Available',    'color' => '#F59E0B'],
    'unreachable'     => ['label' => 'Unreachable',             'color' => '#FF4D6A'],
    'error'           => ['label' => 'Error',                   'color' => '#FF4D6A'],
];
$claude_status = $key_status_map[$key_check['status']]['label'];
$claude_status_color = $key_status_map[$key_check['status']]['color'];
$claude_status_detail = $key_check['message'];

// Candidate Match Analytics (AI Screening Stats)
$avg_match_score = (int)$pdo->query("SELECT COALESCE(AVG(overall_score), 0) FROM candidates")->fetchColumn();
$strong_hires = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE overall_score >= 80")->fetchColumn();
$good_hires = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE overall_score >= 68 AND overall_score < 80")->fetchColumn();
$maybe_hires = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE overall_score >= 50 AND overall_score < 68")->fetchColumn();
$rejected_hires = (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE overall_score < 50")->fetchColumn();

$avg_skills = (int)$pdo->query("SELECT COALESCE(AVG(skills_match), 0) FROM candidates")->fetchColumn();
$avg_exp = (int)$pdo->query("SELECT COALESCE(AVG(exp_match), 0) FROM candidates")->fetchColumn();
$avg_edu = (int)$pdo->query("SELECT COALESCE(AVG(edu_match), 0) FROM candidates")->fetchColumn();

// Compute Platform-Wide Hiring Funnel Analytics
$admin_funnel = [
    'Applied' => $total_candidates_eval,
    'Review' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE LOWER(COALESCE(status, '')) IN ('review', 'under review', 'reviewing', 'new', '')")->fetchColumn(),
    'Shortlisted' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE LOWER(COALESCE(status, '')) IN ('shortlisted', 'shortlist')")->fetchColumn(),
    'Interviewing' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE interview_status IN ('Proposed', 'Confirmed')")->fetchColumn(),
    'Rejected' => (int)$pdo->query("SELECT COUNT(*) FROM candidates WHERE LOWER(COALESCE(status, '')) = 'rejected'")->fetchColumn(),
];

// Query candidate status breakdown per job for platform-wide monitoring
$admin_job_funnels = $pdo->query("
    SELECT j.id as job_id, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name,
           COUNT(c.id) as applied,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) IN ('review', 'under review', 'reviewing', 'new', '') THEN 1 ELSE 0 END) as review,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) IN ('shortlisted', 'shortlist') THEN 1 ELSE 0 END) as shortlisted,
           SUM(CASE WHEN c.interview_status IN ('Proposed', 'Confirmed') THEN 1 ELSE 0 END) as interviewing,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM jobs j
    LEFT JOIN users u ON j.employer_id = u.id
    LEFT JOIN candidates c ON c.job_id = j.id
    GROUP BY j.id, j.job_title, u.name, u.company_name
    ORDER BY applied DESC, j.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------------------------------
// 3. FETCH USERS & JOBS LIST FOR TABLES
// ----------------------------------------------------
$users_list = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
$jobs_list = $pdo->query("SELECT j.*, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id ORDER BY j.created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard & Diagnostics - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        :root {
            --grad-admin-purple: linear-gradient(135deg, #1F1F1F, #000000);
            --grad-violet: linear-gradient(135deg, #6366F1, #4338CA);
            --grad-green:  linear-gradient(135deg, #10B981, #047857);
            --grad-amber:  linear-gradient(135deg, #F59E0B, #B45309);
            --grad-red:    linear-gradient(135deg, #F43F5E, #BE123C);
            --glow-purple: 0 8px 20px rgba(180, 214, 0, 0.25);
            --glow-violet: 0 8px 20px rgba(99, 102, 241, 0.25);
            --glow-green:  0 8px 20px rgba(16, 185, 129, 0.25);
            --glow-amber:  0 8px 20px rgba(245, 158, 11, 0.25);
            --glow-red:    0 8px 20px rgba(244, 63, 94, 0.25);
        }
        [data-theme="dark"] {
            --grad-admin-purple: linear-gradient(135deg, #2A2A2A, #000000);
            --grad-violet: linear-gradient(135deg, #4F46E5, #3730A3);
            --grad-green:  linear-gradient(135deg, #059669, #064E3B);
            --grad-amber:  linear-gradient(135deg, #D97706, #78350F);
            --grad-red:    linear-gradient(135deg, #E11D48, #881337);
            --glow-purple: 0 8px 24px rgba(85, 112, 0, 0.4);
            --glow-violet: 0 8px 24px rgba(79, 70, 229, 0.4);
            --glow-green:  0 8px 24px rgba(5, 150, 105, 0.35);
            --glow-amber:  0 8px 24px rgba(217, 119, 6, 0.35);
            --glow-red:    0 8px 24px rgba(225, 29, 72, 0.35);
        }
        .stat-grad-purple { border-left: 3px solid #6B8A00; }
        .stat-grad-violet { border-left: 3px solid #6366F1; }
        .stat-grad-green  { border-left: 3px solid #10B981; }
        .stat-grad-amber  { border-left: 3px solid #F59E0B; }
        .avatar-chip {
            width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: #fff;
            flex-shrink: 0;
        }
        .stats-summary-grid .stat-box .logo-box {
            background: var(--dim) !important;
            border-color: transparent !important;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        .health-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }
        .health-card {
            background: var(--surf);
            border: var(--glass-border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: var(--shadow-sm);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            position: relative;
            z-index: 1;
        }
        .badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .tab-controls {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            padding-bottom: 4px;
        }
        .tab-btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            background: transparent;
            color: var(--mut);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .tab-btn.active {
            color: #fff;
            background: var(--grad-admin-purple);
            box-shadow: var(--glow-purple);
        }
        .admin-table {
            background: var(--surf);
            border: var(--glass-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
            width: 100%;
        }
        .admin-tr {
            display: grid;
            grid-template-columns: 60px 1.4fr 1.6fr 110px 110px 120px 140px;
            gap: 8px;
            padding: 12px 16px;
            align-items: center;
            border-bottom: 1px solid var(--bdr);
            font-size: 12px;
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
        .admin-tr {
            border-left: 3px solid transparent;
        }
        .admin-tr:not(.admin-th):hover {
            background: var(--dim);
        }
        .admin-tr.accent-red   { border-left-color: #F43F5E; }
        .admin-tr.accent-amber { border-left-color: #F59E0B; }
        .admin-tr.accent-green { border-left-color: #10B981; }
        .admin-tr .chip-rejected {
            background: var(--grad-red) !important;
            color: #fff !important;
            border-color: transparent !important;
        }
        .admin-tr .chip-review {
            background: var(--grad-amber) !important;
            color: #fff !important;
            border-color: transparent !important;
        }
        .admin-tr .chip-shortlisted {
            background: var(--grad-green) !important;
            color: #fff !important;
            border-color: transparent !important;
        }
        @media (max-width: 1024px) {
            .stats-summary-grid { grid-template-columns: 1fr 1fr; }
            .health-grid { grid-template-columns: 1fr; }
            .admin-tr { grid-template-columns: 1fr 1.2fr 100px 110px; }
            .admin-th-hide-mobile { display: none; }
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
                <a href="admin_dashboard.php" class="active">🛡️ Admin Control Panel</a>
                <a href="admin_logs.php">📜 Audit Logs</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>

            <div class="header-right-actions" style="margin-left:auto; display:flex; align-items:center; gap:10px;">
                <span class="user-info-text" style="font-size:12px; color:var(--mut);">Logged in as <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong> (System Admin)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main style="max-width:1300px; padding:28px 20px;">
        <?php if(isset($_SESSION['toast'])): ?>
            <div style="position:fixed; top:20px; right:20px; z-index:3000; background:rgba(0, 232, 122, 0.18); border:1px solid rgba(0, 232, 122, 0.5); border-radius:10px; padding:10px 18px; color:var(--grn); font-size:13px; font-weight:700;">
                <?= htmlspecialchars($_SESSION['toast']) ?>
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
        <div class="page-header">
            <div>
                <h1 style="font-size:26px; font-weight:800; color:var(--txt); margin:0;">🛡️ System Administration & Diagnostics</h1>
                <p style="font-size:13px; color:var(--mut); margin-top:4px; margin-bottom:0;">Monitor user accounts, database integrity, and Claude AI resume screening engine health.</p>
            </div>
            <button type="button" onclick="openAddUserModal()" class="btn-primary" style="padding:11px 22px; font-size:14px; width:auto; display:inline-flex; gap:8px; align-items:center; border-radius:10px; cursor:pointer;">
                <span>+ Add User / Admin</span>
            </button>
        </div>

        <!-- Metric Overview Banner -->
        <div class="stats-summary-grid">
            <div class="stat-box stat-grad-purple">
                <div class="logo-box">👥</div>
                <div><div class="stat-val"><?= $total_users ?></div><div class="stat-lbl">Registered Accounts</div></div>
            </div>
            <div class="stat-box stat-grad-green">
                <div class="logo-box">💼</div>
                <div><div class="stat-val"><?= $total_jobs ?></div><div class="stat-lbl">Total Job Openings</div></div>
            </div>
            <div class="stat-box stat-grad-violet">
                <div class="logo-box">🤖</div>
                <div><div class="stat-val"><?= $total_candidates_eval ?></div><div class="stat-lbl">Resumes Evaluated</div></div>
            </div>
            <div class="stat-box stat-grad-amber">
                <div class="logo-box">⏳</div>
                <div><div class="stat-val"><?= $unverified_users ?></div><div class="stat-lbl">Pending OTP Users</div></div>
            </div>
        </div>

        <!-- System Health & Engine Diagnostics Panel -->
        <div class="health-grid">
            
            <!-- Database Health Card -->
            <div class="health-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:22px;">🗄️</span>
                        <div>
                            <div style="font-size:16px; font-weight:800; color:var(--txt);">Database Health & Integrity</div>
                            <div style="font-size:11px; color:var(--mut);">MySQL PDO Connection Monitor</div>
                        </div>
                    </div>
                    <span class="badge-pill" style="background:linear-gradient(135deg, <?= $db_status_color ?>, <?= $db_status_color ?>CC); color:#fff; border:none; box-shadow:0 4px 12px <?= $db_status_color ?>40;">
                        ● <?= $db_status ?>
                    </span>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:var(--dim); padding:16px; border-radius:12px; font-size:12px; margin-bottom:16px;">
                    <div><span style="color:var(--mut);">Connection Latency:</span> <strong style="background:var(--grad-green); -webkit-background-clip:text; background-clip:text; color:transparent;"><?= $db_ping_ms ?> ms</strong></div>
                    <div><span style="color:var(--mut);">MySQL Server Ver:</span> <strong><?= htmlspecialchars(substr($mysql_version, 0, 18)) ?></strong></div>
                    <div><span style="color:var(--mut);">Active User Accounts:</span> <strong><?= $total_users ?></strong></div>
                    <div><span style="color:var(--mut);">Saved Questionnaires:</span> <strong><?= $total_questionnaires ?></strong></div>
                </div>

                <div style="font-size:11px; color:var(--mut); display:flex; justify-content:space-between; align-items:center;">
                    <span>Candidate Accounts: <strong><?= $total_candidates_role ?></strong> &bull; Employers: <strong><?= $total_employers_role ?></strong> &bull; Admins: <strong><?= $total_admins_role ?></strong></span>
                    <span style="color:var(--grn); font-weight:700;">✓ Healthy</span>
                </div>
            </div>

            <!-- Claude AI Screening Engine Health Card -->
            <div class="health-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:22px;">🤖</span>
                        <div>
                            <div style="font-size:16px; font-weight:800; color:var(--txt);">Claude AI Screening Engine</div>
                            <div style="font-size:11px; color:var(--mut);">Model: <?= htmlspecialchars($_SESSION['ai_model'] ?? 'claude-sonnet-5') ?></div>
                        </div>
                    </div>
                    <span class="badge-pill" style="background:linear-gradient(135deg, <?= $claude_status_color ?>, <?= $claude_status_color ?>CC); color:#fff; border:none; box-shadow:0 4px 12px <?= $claude_status_color ?>40;">
                        ● <?= $claude_status ?>
                    </span>
                </div>

                <div style="font-size:11px; color:<?= $claude_status_color ?>; background:<?= $claude_status_color ?>1A; border:1px solid <?= $claude_status_color ?>; border-radius:8px; padding:8px 12px; margin-bottom:14px;">
                    <?= htmlspecialchars($claude_status_detail) ?>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:var(--dim); padding:16px; border-radius:12px; font-size:12px; margin-bottom:16px;">
                    <div><span style="color:var(--mut);">Live Key Check:</span> <strong style="background:var(--grad-violet); -webkit-background-clip:text; background-clip:text; color:transparent;"><?= $claude_latency_ms ?> ms</strong></div>
                    <div><span style="color:var(--mut);">Average Match Score:</span> <strong style="color:var(--acc);"><?= $avg_match_score ?>%</strong></div>
                    <div><span style="color:var(--mut);">Strong Hires (&ge;80%):</span> <strong style="color:var(--grn);"><?= $strong_hires ?></strong></div>
                    <div><span style="color:var(--mut);">Scanned Resumes:</span> <strong><?= $total_candidates_eval ?></strong></div>
                </div>

                <!-- Match Score Distribution Progress Bar -->
                <div style="width:100%; height:8px; background:rgba(255,255,255,0.08); border-radius:4px; overflow:hidden; display:flex; margin-bottom:8px;">
                    <?php 
                        $denom = max(1, $total_candidates_eval);
                        $pct_strong = round(($strong_hires / $denom) * 100);
                        $pct_good = round(($good_hires / $denom) * 100);
                        $pct_maybe = round(($maybe_hires / $denom) * 100);
                        $pct_rej = round(($rejected_hires / $denom) * 100);
                    ?>
                    <div style="width:<?= $pct_strong ?>%; background:#00E87A;" title="Strong Hires (<?= $pct_strong ?>%)"></div>
                    <div style="width:<?= $pct_good ?>%; background:#3B82F6;" title="Hires (<?= $pct_good ?>%)"></div>
                    <div style="width:<?= $pct_maybe ?>%; background:#F59E0B;" title="Maybe (<?= $pct_maybe ?>%)"></div>
                    <div style="width:<?= $pct_rej ?>%; background:#FF4D6A;" title="Low Match (<?= $pct_rej ?>%)"></div>
                </div>
                
                <div style="display:flex; justify-content:space-between; font-size:10px; color:var(--mut);">
                    <span>🎯 Skills Avg: <strong><?= $avg_skills ?>%</strong></span>
                    <span>💼 Experience Avg: <strong><?= $avg_exp ?>%</strong></span>
                    <span>🎓 Education Avg: <strong><?= $avg_edu ?>%</strong></span>
                </div>
            </div>
        </div>

        <!-- Section Tabs: User Management vs Job Moderation -->
        <div class="tab-controls">
            <button type="button" class="tab-btn active" onclick="switchAdminTab('usersTab', this)">👥 Registered Accounts (<?= count($users_list) ?>)</button>
            <button type="button" class="tab-btn" onclick="switchAdminTab('jobsTab', this)">💼 Job Postings Moderation (<?= count($jobs_list) ?>)</button>
        </div>

        <!-- Platform Hiring Funnel Analytics Panel -->
        <div class="panel admin-tab-pane" style="margin-bottom:24px;">
            <div style="font-size:16px; font-weight:800; color:var(--txt); margin-bottom:14px; display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span>🔻 Platform-Wide Hiring Funnel Analytics</span>
                    <span class="chip" style="font-size:10px; background:rgba(217, 255, 79, 0.15); color:var(--acc); border-color:transparent;">System-Wide Metrics</span>
                </div>
            </div>

            <!-- Visual Funnel Conversion Bars -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px;">
                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">1. Total Applications</div>
                    <div style="font-size:24px; font-weight:800; color:var(--txt); margin:4px 0;"><?= $admin_funnel['Applied'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:100%; height:100%; background:linear-gradient(90deg, #B9D600, #0A0A0A);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;">100% Platform Volume</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">2. Under Review</div>
                    <div style="font-size:24px; font-weight:800; color:var(--acc); margin:4px 0;"><?= $admin_funnel['Review'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Review']/$admin_funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--acc);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Review']/$admin_funnel['Applied'])*100) : 0 ?>% Conversion</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">3. Shortlisted</div>
                    <div style="font-size:24px; font-weight:800; color:var(--grn); margin:4px 0;"><?= $admin_funnel['Shortlisted'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Shortlisted']/$admin_funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--grn);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Shortlisted']/$admin_funnel['Applied'])*100) : 0 ?>% Conversion</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">4. Interviewing</div>
                    <div style="font-size:24px; font-weight:800; color:var(--pur); margin:4px 0;"><?= $admin_funnel['Interviewing'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Interviewing']/$admin_funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--pur);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Interviewing']/$admin_funnel['Applied'])*100) : 0 ?>% Conversion</div>
                </div>

                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px;">5. Rejected</div>
                    <div style="font-size:24px; font-weight:800; color:var(--red); margin:4px 0;"><?= $admin_funnel['Rejected'] ?></div>
                    <div style="height:6px; background:var(--dim); border-radius:3px; overflow:hidden;">
                        <div style="width:<?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Rejected']/$admin_funnel['Applied'])*100) : 0 ?>%; height:100%; background:var(--red);"></div>
                    </div>
                    <div style="font-size:10px; color:var(--mut); margin-top:4px;"><?= $admin_funnel['Applied'] > 0 ? round(($admin_funnel['Rejected']/$admin_funnel['Applied'])*100) : 0 ?>% Rate</div>
                </div>
            </div>

            <!-- Platform Per-Job Status Table -->
            <div style="font-size:13px; font-weight:800; color:var(--txt); margin-bottom:10px;">Platform Candidate Status Breakdown Per Job</div>
            <div style="overflow-x:auto; border-radius:10px; border:1px solid var(--bdr);">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:var(--surf); text-align:left; border-bottom:1px solid var(--bdr);">
                            <th style="padding:10px 14px;">Job Position</th>
                            <th style="padding:10px 14px;">Employer</th>
                            <th style="padding:10px 14px; text-align:center;">Applied</th>
                            <th style="padding:10px 14px; text-align:center;">Review</th>
                            <th style="padding:10px 14px; text-align:center;">Shortlisted</th>
                            <th style="padding:10px 14px; text-align:center;">Interviewing</th>
                            <th style="padding:10px 14px; text-align:center;">Rejected</th>
                            <th style="padding:10px 14px; text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($admin_job_funnels)): ?>
                            <tr><td colspan="8" style="padding:14px; text-align:center; color:var(--mut);">No job openings registered</td></tr>
                        <?php else: ?>
                            <?php foreach($admin_job_funnels as $ajf): ?>
                                <tr style="border-bottom:1px solid var(--bdr);">
                                    <td style="padding:10px 14px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($ajf['job_title']) ?></td>
                                    <td style="padding:10px 14px; color:var(--mut);"><?= htmlspecialchars($ajf['employer_name'] ?: 'System') ?></td>
                                    <td style="padding:10px 14px; text-align:center; font-weight:700; color:var(--txt);"><?= (int)$ajf['applied'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--acc); font-weight:700;"><?= (int)$ajf['review'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--grn); font-weight:700;"><?= (int)$ajf['shortlisted'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--pur); font-weight:700;"><?= (int)$ajf['interviewing'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--red); font-weight:700;"><?= (int)$ajf['rejected'] ?></td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <a href="compare_candidates.php?job_id=<?= $ajf['job_id'] ?>" class="btn-secondary" style="padding:4px 8px; font-size:11px; text-decoration:none;">⚖️ Compare</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Tab 1: User Account Monitoring & Management -->
        <div id="usersTab" class="admin-tab-pane">
            <div class="panel" style="padding:20px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                    <input type="text" id="userSearchInput" onkeyup="filterUsersTable()" placeholder="🔍 Search user name, email, or role..." style="max-width:360px; padding:9px 14px; font-size:13px; margin:0;">
                    
                    <div style="display:flex; gap:8px;">
                        <select id="roleFilterSelect" onchange="filterUsersTable()" style="padding:9px 12px; font-size:13px; margin:0; width:auto;">
                            <option value="">All Roles</option>
                            <option value="candidate">Candidates</option>
                            <option value="employer">Employers</option>
                            <option value="admin">Admins</option>
                        </select>
                    </div>
                </div>

                <div class="admin-table">
                    <div class="admin-tr admin-th">
                        <div>ID</div>
                        <div>Name</div>
                        <div>Email</div>
                        <div>Role</div>
                        <div>Status</div>
                        <div class="admin-th-hide-mobile">Joined</div>
                        <div>Action</div>
                    </div>

                    <?php foreach($users_list as $u): ?>
                        <?php
                            $u_accent = $u['role'] === 'admin' ? 'accent-red' : ($u['role'] === 'employer' ? 'accent-amber' : 'accent-green');
                            $u_grad = $u['role'] === 'admin' ? 'var(--grad-red)' : ($u['role'] === 'employer' ? 'var(--grad-amber)' : 'var(--grad-green)');
                        ?>
                        <div class="admin-tr user-row-item <?= $u_accent ?>" data-search="<?= strtolower(htmlspecialchars($u['name'] . ' ' . $u['email'] . ' ' . $u['role'])) ?>" data-role="<?= htmlspecialchars($u['role']) ?>">
                            <div style="font-weight:700; color:var(--mut);">#<?= $u['id'] ?></div>
                            <div style="font-weight:800; color:var(--txt); display:flex; align-items:center; gap:8px;">
                                <span class="avatar-chip" style="background:<?= $u_grad ?>;"><?= strtoupper(substr($u['name'] ?: 'U', 0, 1)) ?></span>
                                <span><?= htmlspecialchars($u['name']) ?></span>
                            </div>
                            <div style="color:var(--mut); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($u['email']) ?></div>
                            <div>
                                <span class="chip <?= $u['role'] === 'admin' ? 'chip-rejected' : ($u['role'] === 'employer' ? 'chip-review' : 'chip-shortlisted') ?>" style="font-size:10px;">
                                    <?= ucfirst(htmlspecialchars($u['role'])) ?>
                                </span>
                            </div>
                            <div>
                                <?php if($u['is_verified']): ?>
                                    <span style="font-size:11px; font-weight:700; color:var(--grn);">✓ Verified</span>
                                <?php else: ?>
                                    <span style="font-size:11px; font-weight:700; color:var(--gold);">⏳ Pending OTP</span>
                                <?php endif; ?>
                            </div>
                            <div class="admin-th-hide-mobile" style="color:var(--mut); font-size:11px;"><?= date('M j, Y', strtotime($u['created_at'])) ?></div>
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <!-- Toggle Verify Form -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_verify">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn-secondary" style="padding:4px 8px; font-size:10px;" title="Toggle Verification Status">
                                        <?= $u['is_verified'] ? 'Unverify' : 'Verify' ?>
                                    </button>
                                </form>

                                <!-- Delete Account Form -->
                                <?php if((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete account for <?= htmlspecialchars($u['name']) ?>?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" style="background:rgba(255, 77, 106, 0.12); border:1px solid rgba(255, 77, 106, 0.35); border-radius:6px; color:var(--red); padding:4px 8px; font-size:10px; font-weight:700; cursor:pointer;" title="Delete User Account">🗑️</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Tab 2: Job Postings Moderation -->
        <div id="jobsTab" class="admin-tab-pane" style="display:none;">
            <div class="panel" style="padding:20px;">
                <div class="admin-table">
                    <div class="admin-tr admin-th" style="grid-template-columns: 60px 1.8fr 1.4fr 110px 110px 100px;">
                        <div>ID</div>
                        <div>Job Title</div>
                        <div>Employer</div>
                        <div>Department</div>
                        <div>Status</div>
                        <div>Action</div>
                    </div>

                    <?php foreach($jobs_list as $j): ?>
                        <div class="admin-tr accent-green" style="grid-template-columns: 60px 1.8fr 1.4fr 110px 110px 100px;">
                            <div style="font-weight:700; color:var(--mut);">#<?= $j['id'] ?></div>
                            <div style="font-weight:800; color:var(--txt);"><?= htmlspecialchars($j['job_title']) ?></div>
                            <div style="color:var(--mut);"><?= htmlspecialchars($j['employer_name'] ?? 'System') ?></div>
                            <div style="color:var(--mut); font-size:11px;"><?= htmlspecialchars($j['department'] ?: 'General') ?></div>
                            <div>
                                <span class="chip chip-shortlisted" style="font-size:10px;"><?= htmlspecialchars($j['status'] ?? 'Active') ?></span>
                            </div>
                            <div>
                                <form method="POST" onsubmit="return confirm('Delete job posting for <?= htmlspecialchars($j['job_title']) ?>?');">
                                    <input type="hidden" name="action" value="delete_job">
                                    <input type="hidden" name="job_id" value="<?= $j['id'] ?>">
                                    <button type="submit" style="background:rgba(255, 77, 106, 0.12); border:1px solid rgba(255, 77, 106, 0.35); border-radius:6px; color:var(--red); padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer;">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal: Add New User or Admin -->
    <div id="addUserModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:480px; width:100%; position:relative; border-radius:18px; box-shadow:var(--shadow-lg);">
            <button onclick="closeAddUserModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:22px;">👤</div>
                <div style="font-size:20px; font-weight:800; color:var(--txt);">Add New User or Admin</div>
            </div>
            <p style="font-size:12px; color:var(--mut); margin-bottom:20px;">Create a new Candidate, Employer, or System Admin account.</p>

            <form method="POST">
                <input type="hidden" name="action" value="add_user">

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Full Name</label>
                    <input type="text" name="name" placeholder="e.g. John Doe" required style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Email Address</label>
                    <input type="email" name="email" placeholder="e.g. user@domain.com" required style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Password</label>
                    <input type="password" name="password" placeholder="Account Password" required style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Account Role</label>
                    <select name="role" required style="padding:10px 12px; font-size:13px;">
                        <option value="candidate">Candidate</option>
                        <option value="employer">Employer</option>
                        <option value="admin">System Admin</option>
                    </select>
                </div>

                <div style="margin-bottom:20px; padding:10px 14px; background:var(--dim); border-radius:10px; display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="is_verified" id="modal_is_verified" value="1" checked style="width:18px; height:18px; cursor:pointer;">
                    <label for="modal_is_verified" style="font-size:12px; font-weight:600; color:var(--txt); cursor:pointer;">
                        ✓ Mark Account Email as Verified Immediately
                    </label>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeAddUserModal()" class="btn-secondary" style="padding:10px 18px; font-size:13px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding:10px 22px; width:auto; font-size:13px; border-radius:10px;">Create Account &rarr;</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddUserModal() {
            document.getElementById('addUserModal').style.display = 'flex';
        }
        function closeAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
        }

        function switchAdminTab(tabId, btn) {
            document.querySelectorAll('.admin-tab-pane').forEach(el => el.style.display = 'none');
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            document.getElementById(tabId).style.display = 'block';
            btn.classList.add('active');
        }

        function filterUsersTable() {
            var search = document.getElementById('userSearchInput').value.toLowerCase().trim();
            var role = document.getElementById('roleFilterSelect').value.toLowerCase();
            var rows = document.getElementsByClassName('user-row-item');

            for (var i = 0; i < rows.length; i++) {
                var searchData = rows[i].getAttribute('data-search') || '';
                var userRole = rows[i].getAttribute('data-role') || '';

                var matchesSearch = !search || searchData.indexOf(search) > -1;
                var matchesRole = !role || userRole === role;

                if (matchesSearch && matchesRole) {
                    rows[i].style.display = 'grid';
                } else {
                    rows[i].style.display = 'none';
                }
            }
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeAddUserModal();
        });
    </script>
    <script src="theme.js"></script>
</body>
</html>
