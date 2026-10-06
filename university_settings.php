<?php
require_once 'auth.php';
require_once 'db.php';

require_login();
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'university'], true)) {
    $_SESSION['error'] = "Access denied. University or Administrator role required.";
    header("Location: index.php");
    exit;
}

$is_admin = ($_SESSION['user_role'] ?? '') === 'admin';
$is_university = ($_SESSION['user_role'] ?? '') === 'university';

// If admin, they can manage a specific university account or preview
$all_universities_accounts = [];
if ($is_admin) {
    $all_universities_accounts = $pdo->query("
        SELECT u.id, u.name, u.email, u.company_name, u.company_logo, u.university_id, u.ssm_number, un.name AS uni_table_name, un.type AS uni_type, un.ssm_number AS uni_table_ssm
        FROM users u
        LEFT JOIN universities un ON u.university_id = un.id
        WHERE u.role = 'university'
        ORDER BY u.company_name ASC, u.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$target_user_id = (int)$_SESSION['user_id'];
if ($is_admin && isset($_GET['user_id']) && (int)$_GET['user_id'] > 0) {
    $target_user_id = (int)$_GET['user_id'];
} elseif ($is_admin && !empty($all_universities_accounts)) {
    // Default to the first university account for admin preview
    $target_user_id = (int)$all_universities_accounts[0]['id'];
}

// Fetch the targeted user account
$stmt = $pdo->prepare("
    SELECT u.*, un.name AS linked_uni_name, un.type AS linked_uni_type, un.ssm_number AS linked_uni_ssm
    FROM users u
    LEFT JOIN universities un ON u.university_id = un.id
    WHERE u.id = ?
");
$stmt->execute([$target_user_id]);
$target_user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target_user && $is_admin) {
    // If no university account exists yet in the database, fallback gracefully
    $target_user = [
        'id' => $_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? 'University Liaison',
        'email' => $_SESSION['user_email'] ?? 'career@university.edu.my',
        'company_name' => 'Demo University',
        'company_logo' => null,
        'company_website' => 'https://www.demo.edu.my',
        'company_address' => 'Main Campus, Kuala Lumpur',
        'contact_email' => 'career@demo.edu.my',
        'university_id' => null,
        'linked_uni_name' => 'Demo University',
        'linked_uni_type' => 'public',
        'ssm_number' => '',
        'linked_uni_ssm' => '',
        'password_hash' => ''
    ];
} elseif (!$target_user) {
    $_SESSION['error'] = "Account not found.";
    header("Location: index.php");
    exit;
}

// All universities in the system for dropdown / reference
$all_system_unis = $pdo->query("SELECT id, name, type FROM universities ORDER BY FIELD(type, 'public', 'private', 'other'), name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------------------------------------
// POST Actions Handling
// ------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    // Action 1: Update Institution Profile & Logo
    if ($action === 'update_institution') {
        $institution_name = trim($_POST['institution_name'] ?? '');
        $uni_type = in_array($_POST['university_type'] ?? '', ['public', 'private'], true) ? $_POST['university_type'] : 'public';
        $ssm_number = trim($_POST['ssm_number'] ?? '');
        $company_website = trim($_POST['company_website'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $remove_logo = isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1';

        if (empty($institution_name)) {
            $_SESSION['error'] = "Institution name cannot be empty.";
        } elseif (!empty($contact_email) && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid career center contact email format.";
        } else {
            // Find or update the university in universities table
            $university_id = (int)($target_user['university_id'] ?? 0);
            if ($university_id > 0) {
                // Update existing linked university
                $up_uni = $pdo->prepare("UPDATE universities SET name = ?, type = ?, ssm_number = ? WHERE id = ?");
                $up_uni->execute([$institution_name, $uni_type, $ssm_number, $university_id]);
            } else {
                // Check if an existing university matches by name
                $chk_uni = $pdo->prepare("SELECT id FROM universities WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $chk_uni->execute([$institution_name]);
                $found_id = $chk_uni->fetchColumn();
                if ($found_id) {
                    $university_id = (int)$found_id;
                    $up_uni = $pdo->prepare("UPDATE universities SET type = ?, ssm_number = ? WHERE id = ?");
                    $up_uni->execute([$uni_type, $ssm_number, $university_id]);
                } else {
                    $ins_uni = $pdo->prepare("INSERT INTO universities (name, type, ssm_number) VALUES (?, ?, ?)");
                    $ins_uni->execute([$institution_name, $uni_type, $ssm_number]);
                    $university_id = (int)$pdo->lastInsertId();
                }
            }

            // Handle Logo Upload
            $new_logo_path = $target_user['company_logo'];
            if ($remove_logo) {
                if (!empty($target_user['company_logo']) && file_exists($target_user['company_logo'])) {
                    @unlink($target_user['company_logo']);
                }
                $new_logo_path = null;
            } elseif (isset($_FILES['university_icon']) && ($_FILES['university_icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['university_icon'];
                $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                $max_bytes = 2 * 1024 * 1024; // 2MB

                if ($file['error'] !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > $max_bytes) {
                    $_SESSION['error'] = "Logo file exceeds the maximum 2MB size limit or encountered an upload error.";
                } else {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $img_info = @getimagesize($file['tmp_name']);
                    if (!in_array($ext, $allowed_ext) || $img_info === false) {
                        $_SESSION['error'] = "Invalid logo format. Please upload JPG, PNG, WEBP, or GIF.";
                    } else {
                        $dir = 'uploads/university_logos/';
                        if (!is_dir($dir)) @mkdir($dir, 0755, true);
                        foreach (glob($dir . 'u' . $target_user_id . '.*') as $old) {
                            @unlink($old);
                        }
                        $dest = $dir . 'u' . $target_user_id . '.' . $ext;
                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            $new_logo_path = $dest;
                        } else {
                            $_SESSION['error'] = "Failed to store uploaded logo.";
                        }
                    }
                }
            }

            // Update user record
            $stmt = $pdo->prepare("
                UPDATE users 
                SET company_name = ?, university_id = ?, company_website = ?, company_address = ?, contact_email = ?, company_logo = ?, ssm_number = ?
                WHERE id = ?
            ");
            $stmt->execute([$institution_name, $university_id, $company_website, $company_address, $contact_email, $new_logo_path, $ssm_number, $target_user_id]);

            if (empty($_SESSION['error'])) {
                $_SESSION['toast'] = "Institution profile and branding updated successfully!";
            }
        }

        $redir = "university_settings.php" . ($is_admin ? "?user_id=" . $target_user_id : "");
        header("Location: " . $redir);
        exit;
    }

    // Action 2: Update Liaison Officer & Account Email
    if ($action === 'update_officer') {
        $officer_name = trim($_POST['officer_name'] ?? '');
        $officer_email = trim($_POST['officer_email'] ?? '');

        if (empty($officer_name) || empty($officer_email)) {
            $_SESSION['error'] = "Officer name and email address cannot be empty.";
        } elseif (!filter_var($officer_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid officer email format.";
        } else {
            // Check for duplicate email across other users
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$officer_email, $target_user_id]);
            if ($chk->fetch()) {
                $_SESSION['error'] = "The email address '$officer_email' is already in use by another account.";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                $stmt->execute([$officer_name, $officer_email, $target_user_id]);

                if ($target_user_id === (int)$_SESSION['user_id']) {
                    $_SESSION['user_name'] = $officer_name;
                    $_SESSION['user_email'] = $officer_email;
                }
                $_SESSION['toast'] = "Liaison officer and login email updated successfully!";
            }
        }

        $redir = "university_settings.php" . ($is_admin ? "?user_id=" . $target_user_id : "");
        header("Location: " . $redir);
        exit;
    }

    // Action 3: Update Account Password
    if ($action === 'update_password') {
        $current_pw = $_POST['current_password'] ?? '';
        $new_pw = $_POST['new_password'] ?? '';
        $confirm_pw = $_POST['confirm_password'] ?? '';

        if (!$is_admin && !password_verify($current_pw, $target_user['password_hash'] ?? '')) {
            $_SESSION['error'] = "Current password is incorrect.";
        } elseif (strlen($new_pw) < 8) {
            $_SESSION['error'] = "New password must be at least 8 characters long.";
        } elseif ($new_pw !== $confirm_pw) {
            $_SESSION['error'] = "New passwords do not match.";
        } else {
            $hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $target_user_id]);
            $_SESSION['toast'] = "Account password updated successfully!";
        }

        $redir = "university_settings.php" . ($is_admin ? "?user_id=" . $target_user_id : "");
        header("Location: " . $redir);
        exit;
    }
}

// Re-read current values after changes
$stmt = $pdo->prepare("
    SELECT u.*, un.name AS linked_uni_name, un.type AS linked_uni_type, un.ssm_number AS linked_uni_ssm
    FROM users u
    LEFT JOIN universities un ON u.university_id = un.id
    WHERE u.id = ?
");
$stmt->execute([$target_user_id]);
$current = $stmt->fetch(PDO::FETCH_ASSOC) ?: $target_user;

if (empty($current['ssm_number']) && !empty($current['linked_uni_ssm'])) {
    $current['ssm_number'] = $current['linked_uni_ssm'];
}

$ssm_val = trim((string)($current['ssm_number'] ?? ''));
$has_ssm = ($ssm_val !== '');
$ssm_incomplete = !$has_ssm;
$incomplete_count = $ssm_incomplete ? 1 : 0;

$institution_display_name = !empty($current['company_name']) ? $current['company_name'] : ($current['name'] ?? 'University');
$institution_logo = (!empty($current['company_logo']) && file_exists($current['company_logo'])) 
    ? $current['company_logo'] . '?v=' . @filemtime($current['company_logo']) 
    : null;

// Generate Student Onboarding Link
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$base_url = $scheme . $host . $dir;
$uni_link_id = $current['university_id'] ?? null;
$student_signup_url = $base_url . '/register.php' . ($uni_link_id ? '?university_id=' . urlencode((string)$uni_link_id) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>University Settings & Branding — Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
    <style>
        .settings-layout {
            display: block;
        }

        /* .settings-nav-card / .settings-nav-btn: the old in-page tab list,
           removed as a duplicate of the sidebar's own Settings sub-items
           (which now call switchTab() directly). Rules kept only because
           .settings-nav-btn.active below still sets shared panel colors
           referenced elsewhere; harmless if unused. */
        .settings-nav-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--mut);
            text-decoration: none;
            border: none;
            background: transparent;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s ease;
            width: 100%;
        }

        .settings-nav-btn:hover {
            color: var(--txt);
            background: var(--dim);
        }

        .settings-nav-btn.active {
            color: var(--txt);
            background: var(--dim);
            border-left: 3px solid var(--acc);
            font-weight: 800;
        }

        .settings-panel {
            display: none;
            animation: fadeIn 0.25s ease-out;
        }

        .settings-panel.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card-panel {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            margin-bottom: 22px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--bdr);
        }

        .card-title {
            font-size: 17px;
            font-weight: 800;
            color: var(--txt);
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 6px;
        }

        .card-desc {
            font-size: 12.5px;
            color: var(--mut);
            line-height: 1.5;
            margin: 0;
        }

        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--txt);
            margin-bottom: 6px;
        }

        .form-group .hint {
            font-size: 11px;
            color: var(--mut);
            margin-top: 4px;
        }

        .crest-preview-box {
            width: 74px;
            height: 74px;
            border-radius: 16px;
            border: 1px solid var(--bdr);
            background: var(--dim);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 32px;
            color: var(--acc);
        }

        .crest-preview-box img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .qr-card-container {
            display: flex;
            gap: 28px;
            align-items: center;
            flex-wrap: wrap;
            background: var(--dim);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 22px;
        }

        #studentLinkQr {
            width: 160px;
            height: 160px;
            background: #FFFFFF;
            border-radius: 12px;
            padding: 10px;
            border: 1px solid var(--bdr);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }

        #studentLinkQr svg {
            width: 100%;
            height: 100%;
        }

        @media (max-width: 900px) {
            .form-grid-2 {
                grid-template-columns: 1fr;
            }
        }

        /* ---- Left sidebar shell: identical to university_dashboard.php's,
           so navigating here from the dashboard doesn't jump to a page
           with a different, older-style top navbar. Settings is marked
           active here instead of Student Activity. Its 5 sub-items are
           this page's ONLY tab navigation now -- the old in-page
           .settings-nav-card list was a duplicate of these same 5 links
           and has been removed; the sidebar buttons call switchTab()
           directly instead. ---- */
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
        .uni-menu-child { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 9px 12px; border-radius: 8px; color: #5B6B3A; font-size: 12.5px; font-weight: 600; text-decoration: none; width: 100%; border: none; background: transparent; font-family: inherit; cursor: pointer; text-align: left; }
        .uni-menu-child:hover { background: rgba(10,10,10,0.05); color: #0F1300; }
        .uni-menu-child.active { background: #0A0A0A; color: #FFFFFF; }
        .uni-menu-badge { font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 999px; background: rgba(10,10,10,0.06); color: #5B6B3A; display: inline-flex; align-items: center; justify-content: center; line-height: 1.2; }
        .uni-menu-badge.danger { background: #EF4444; color: #FFFFFF; font-weight: 800; box-shadow: 0 1px 3px rgba(239, 68, 68, 0.35); }
        .uni-menu-badge.success { background: rgba(16,185,129,0.14); color: #047857; }
        .uni-menu-parent.active .uni-menu-badge.danger,
        .uni-menu-child.active .uni-menu-badge.danger { background: #EF4444; color: #FFFFFF; }
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
            <button type="button" class="uni-menu-parent" onclick="toggleUniMenu(this)">
                <span class="uni-menu-parent-left"><span>🎓</span> Student Activity</span>
                <span class="uni-menu-chevron">&#9662;</span>
            </button>
            <div class="uni-menu-children">
                <a href="university_dashboard.php#at-risk" class="uni-menu-child"><span>⚠️ At-Risk</span></a>
                <a href="university_dashboard.php#placements" class="uni-menu-child"><span>📌 Placements</span></a>
            </div>
        </div>

        <div class="uni-menu-group open">
            <button type="button" class="uni-menu-parent active" onclick="toggleUniMenu(this)">
                <span class="uni-menu-parent-left">
                    <span>⚙️</span> Settings
                    <?php if ($incomplete_count > 0): ?>
                        <span class="uni-menu-badge danger" style="margin-left:4px;" title="1 incomplete requirement"><?= $incomplete_count ?></span>
                    <?php endif; ?>
                </span>
                <span class="uni-menu-chevron">&#9662;</span>
            </button>
            <div class="uni-menu-children">
                <button type="button" class="uni-menu-child active" onclick="switchTab('institutionTab', this)">
                    <span>🏛️ Institution Profile</span>
                    <?php if ($ssm_incomplete): ?>
                        <span class="uni-menu-badge danger" title="SSM registration number is incomplete">1</span>
                    <?php endif; ?>
                </button>
                <button type="button" class="uni-menu-child" onclick="switchTab('liaisonTab', this)"><span>👤 Career Officer Liaison</span></button>
                <button type="button" class="uni-menu-child" onclick="switchTab('qrTab', this)"><span>▦ Career Fair QR &amp; Link</span></button>
                <button type="button" class="uni-menu-child" onclick="switchTab('securityTab', this)"><span>🔒 Security &amp; Password</span></button>
                <button type="button" class="uni-menu-child" onclick="switchTab('complianceTab', this)"><span>📄 Tracer &amp; PDPA Policy</span></button>
            </div>
        </div>

        <div class="uni-sidebar-bottom">
            <div class="uni-sidebar-institution-card">
                <div class="uni-sidebar-institution-icon">
                    <?php if ($institution_logo): ?>
                        <img src="<?= htmlspecialchars($institution_logo) ?>" alt="<?= htmlspecialchars($institution_display_name) ?>">
                    <?php else: ?>
                        🎓
                    <?php endif; ?>
                </div>
                <div>
                    <div class="uni-sidebar-institution-name"><?= htmlspecialchars($institution_display_name) ?></div>
                    <div class="uni-sidebar-institution-label">University Portal</div>
                </div>
            </div>
        </div>
    </aside>

    <div class="uni-main-area">
        <div class="uni-topbar">
            <div class="uni-topbar-title">Settings</div>
            <div class="uni-topbar-right">
                <div class="uni-user-avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'A', 0, 1)) ?></div>
                <span class="user-info-text" style="font-size:12px; color:var(--mut);">Logged in as <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>

<main style="max-width:1160px; margin:24px auto 80px; padding:0 20px;">

    <!-- Admin Switcher Banner -->
    <?php if ($is_admin): ?>
        <div style="background:rgba(217, 255, 79, 0.12); border:1px solid rgba(217, 255, 79, 0.35); border-radius:14px; padding:16px 20px; margin-bottom:24px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px;">
            <div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:20px;">🛡️</span>
                <div>
                    <strong style="color:var(--txt); font-size:13.5px;">Admin Control Mode: University Settings</strong>
                    <div style="font-size:12px; color:var(--mut); margin-top:2px;">You are managing institutional branding and configuration as an administrator.</div>
                </div>
            </div>
            <?php if (!empty($all_universities_accounts)): ?>
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="font-size:12px; font-weight:700; color:var(--txt);">Switch University:</label>
                    <select class="rb-input" onchange="window.location.href='university_settings.php?user_id=' + this.value" style="width:auto; padding:6px 28px 6px 10px; font-size:12px;">
                        <?php foreach ($all_universities_accounts as $acc): ?>
                            <option value="<?= $acc['id'] ?>" <?= $acc['id'] == $target_user_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars(!empty($acc['company_name']) ? $acc['company_name'] : $acc['name']) ?> (<?= htmlspecialchars($acc['email']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Flash Messages -->
    <?php if (isset($_SESSION['toast'])): ?>
        <div style="background:rgba(34,197,94,0.12); border:1px solid rgba(34,197,94,0.35); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:13px; color:var(--grn); display:flex; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span>✅</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:var(--grn); cursor:pointer; font-weight:bold;">✕</button>
        </div>
        <?php unset($_SESSION['toast']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div style="background:rgba(239,68,68,0.12); border:1px solid rgba(239,68,68,0.35); border-radius:12px; padding:12px 18px; margin-bottom:20px; font-size:13px; color:var(--red); display:flex; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:8px;">
                <span>⚠️</span>
                <span><?= htmlspecialchars($_SESSION['error']) ?></span>
            </div>
            <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; color:var(--red); cursor:pointer; font-weight:bold;">✕</button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Page Header Title -->
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:16px;">
            <div class="crest-preview-box">
                <?php if ($institution_logo): ?>
                    <img src="<?= htmlspecialchars($institution_logo) ?>" alt="University Crest">
                <?php else: ?>
                    🎓
                <?php endif; ?>
            </div>
            <div>
                <h1 style="font-size:24px; font-weight:900; margin:0 0 4px; color:var(--txt);">
                    <?= htmlspecialchars($institution_display_name) ?>
                </h1>
                <div style="font-size:13px; color:var(--mut);">
                    Career Center Administration, Institutional Branding & Student Linkage Settings
                </div>
            </div>
        </div>
        <div>
            <a href="university_dashboard.php" class="btn-secondary" style="padding:10px 18px; text-decoration:none; font-weight:700; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                <span>&larr; Back to Dashboard</span>
            </a>
        </div>
    </div>

    <!-- Main Settings Layout (the sidebar's own Settings sub-items are the
         only tab navigation now; see the removed .settings-nav-card note
         in the <style> block above) -->
    <div class="settings-layout">
        <section>
            <!-- ================= TAB 1: INSTITUTION PROFILE & CREST ================= -->
            <div id="institutionTab" class="settings-panel active">
                <form method="post" enctype="multipart/form-data" class="card-panel">
                    <input type="hidden" name="action" value="update_institution">

                    <div class="card-header">
                        <h2 class="card-title"><span>🏛️</span> Institutional Branding & Profile</h2>
                        <p class="card-desc">Configure your university's official name, higher-education classification, crest logo, and campus contact details.</p>
                    </div>

                    <?php if ($ssm_incomplete): ?>
                        <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.28); border-radius:12px; padding:12px 16px; margin-bottom:18px; display:flex; align-items:center; gap:12px;">
                            <span style="font-size:20px;">⚠️</span>
                            <div style="flex:1;">
                                <div style="font-size:13px; font-weight:800; color:#EF4444;">1 Incomplete Detail: University SSM Number</div>
                                <div style="font-size:12px; color:var(--mut); margin-top:2px;">
                                    Please enter your university's SSM registration number below to complete your institutional verification.
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="institution_name">Institution / University Name *</label>
                            <input type="text" id="institution_name" name="institution_name" class="rb-input" 
                                   value="<?= htmlspecialchars($current['company_name'] ?: ($current['linked_uni_name'] ?: $current['name'])) ?>" required>
                            <div class="hint">The formal name displayed on student dashboards and career reports.</div>
                        </div>

                        <div class="form-group">
                            <label for="university_type">Institution Classification *</label>
                            <?php $current_type = $current['linked_uni_type'] ?: 'public'; ?>
                            <select id="university_type" name="university_type" class="rb-input" required>
                                <option value="public" <?= $current_type === 'public' ? 'selected' : '' ?>>Public University (Universiti Awam - UA)</option>
                                <option value="private" <?= $current_type === 'private' ? 'selected' : '' ?>>Private University / College (IPTS)</option>
                            </select>
                            <div class="hint">Determines Ministry (KPT) tracer classification benchmarks.</div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-top:4px;">
                        <label for="ssm_number" style="display:flex; align-items:center; justify-content:space-between;">
                            <span>SSM / University Registration Number *</span>
                            <?php if ($ssm_incomplete): ?>
                                <span class="uni-menu-badge danger" style="font-size:10px; padding:2px 8px;">1 Incomplete</span>
                            <?php else: ?>
                                <span class="uni-menu-badge success" style="font-size:10px; padding:2px 8px;">✓ Completed</span>
                            <?php endif; ?>
                        </label>
                        <input type="text" id="ssm_number" name="ssm_number" class="rb-input" 
                               placeholder="e.g. 201201012345 (1012345-X) or DU001(B)" 
                               value="<?= htmlspecialchars($current['ssm_number'] ?? '') ?>"
                               style="<?= $ssm_incomplete ? 'border-color: rgba(239,68,68,0.5);' : '' ?>">
                        <div class="hint">Official Companies Commission of Malaysia (SSM) or Ministry entity registration number for institution verification.</div>
                    </div>

                    <!-- Logo / Crest Upload -->
                    <div class="form-group" style="padding:16px; background:var(--dim); border-radius:12px; border:1px solid var(--bdr);">
                        <label>University Crest / Official Icon</label>
                        <div style="display:flex; align-items:center; gap:16px; margin-top:10px; flex-wrap:wrap;">
                            <div class="crest-preview-box">
                                <?php if ($institution_logo): ?>
                                    <img src="<?= htmlspecialchars($institution_logo) ?>" alt="Logo Preview" id="iconPreviewImg">
                                <?php else: ?>
                                    <span id="iconPreviewFallback">🎓</span>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1; min-width:240px;">
                                <input type="file" name="university_icon" accept=".jpg,.jpeg,.png,.webp,.gif" class="rb-input" style="padding:8px;" onchange="previewIcon(this)">
                                <div class="hint">Recommended size: 400x400px. JPG, PNG, WEBP, or GIF (Max 2MB).</div>
                                <?php if ($institution_logo): ?>
                                    <div style="margin-top:8px;">
                                        <label style="font-size:12px; color:var(--red); font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                                            <input type="checkbox" name="remove_logo" value="1">
                                            <span>Remove custom crest and use default symbol</span>
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="company_website">Official Website / Portal</label>
                            <input type="url" id="company_website" name="company_website" class="rb-input" 
                                   placeholder="https://www.university.edu.my" 
                                   value="<?= htmlspecialchars($current['company_website'] ?? '') ?>">
                            <div class="hint">Institution or Career Development Centre URL.</div>
                        </div>

                        <div class="form-group">
                            <label for="contact_email">Career Center General Email</label>
                            <input type="email" id="contact_email" name="contact_email" class="rb-input" 
                                   placeholder="careercenter@university.edu.my" 
                                   value="<?= htmlspecialchars($current['contact_email'] ?? '') ?>">
                            <div class="hint">Public email displayed for student enquiries.</div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="company_address">Main Campus Address / Location</label>
                        <textarea id="company_address" name="company_address" class="rb-input" rows="3" 
                                  placeholder="e.g. Jalan Universiti, 50603 Kuala Lumpur, Wilayah Persekutuan Kuala Lumpur"><?= htmlspecialchars($current['company_address'] ?? '') ?></textarea>
                        <div class="hint">Campus address for official corporate liaison correspondence.</div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                        <button type="submit" class="btn-primary" style="padding:12px 24px;">
                            <span>💾 Save Institution Settings</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ================= TAB 2: CAREER OFFICER LIAISON ================= -->
            <div id="liaisonTab" class="settings-panel">
                <form method="post" class="card-panel">
                    <input type="hidden" name="action" value="update_officer">

                    <div class="card-header">
                        <h2 class="card-title"><span>👤</span> Career Center Officer & Liaison Details</h2>
                        <p class="card-desc">Update the primary university career officer liaison name and login authentication email.</p>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="officer_name">Officer / Liaison Full Name *</label>
                            <input type="text" id="officer_name" name="officer_name" class="rb-input" 
                                   value="<?= htmlspecialchars($current['name'] ?? '') ?>" required>
                            <div class="hint">Name of the verified career counselor or department head.</div>
                        </div>

                        <div class="form-group">
                            <label for="officer_email">Career Center Login Email *</label>
                            <input type="email" id="officer_email" name="officer_email" class="rb-input" 
                                   value="<?= htmlspecialchars($current['email'] ?? '') ?>" required>
                            <div class="hint">Used to sign in to the Career Center Dashboard and receive system alerts.</div>
                        </div>
                    </div>

                    <div style="padding:14px 18px; background:rgba(80, 160, 255, 0.08); border:1px solid rgba(80, 160, 255, 0.25); border-radius:12px; margin-bottom:18px; font-size:12.5px; color:var(--txt);">
                        ℹ️ <strong>Account Security Note:</strong> Changing this email will immediately update your login credentials. If you are signed in, your active session will be updated automatically.
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="submit" class="btn-primary" style="padding:12px 24px;">
                            <span>💾 Save Liaison Information</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ================= TAB 3: CAREER FAIR QR & LINKAGE ================= -->
            <div id="qrTab" class="settings-panel">
                <div class="card-panel">
                    <div class="card-header">
                        <h2 class="card-title"><span>📱</span> Student Career Fair QR & Instant Linkage</h2>
                        <p class="card-desc">Share this QR code or custom link with your students during campus career fairs, resume clinics, or student orientation.</p>
                    </div>

                    <div class="qr-card-container">
                        <div style="text-align:center;">
                            <div id="studentLinkQr"></div>
                            <div style="margin-top:10px;">
                                <button type="button" class="btn-secondary" onclick="downloadQrPng()" style="padding:6px 14px; font-size:11.5px; display:inline-flex; align-items:center; gap:6px;">
                                    <span>⬇️ Download QR (PNG)</span>
                                </button>
                            </div>
                        </div>

                        <div style="flex:1; min-width:280px;">
                            <label style="font-size:13px; font-weight:800; color:var(--txt); margin-bottom:6px; display:block;">
                                Institution Exclusive Student Registration Link
                            </label>
                            <div style="display:flex; gap:8px; margin-bottom:12px;">
                                <input type="text" id="studentLinkInput" class="rb-input" value="<?= htmlspecialchars($student_signup_url) ?>" readonly style="font-family:monospace; font-size:12px;">
                                <button type="button" class="btn-primary" onclick="copyStudentLink()" style="padding:8px 18px; font-size:12px; flex-shrink:0;">
                                    <span id="copyBtnText">📋 Copy Link</span>
                                </button>
                            </div>

                            <div style="font-size:12.5px; color:var(--mut); line-height:1.6;">
                                <p style="margin:0 0 8px;"><strong>How Student Linkage Operates:</strong></p>
                                <ul style="margin:0; padding-left:18px;">
                                    <li>When students register via this link or QR code, they are automatically connected to <strong><?= htmlspecialchars($institution_display_name) ?></strong>.</li>
                                    <li>Students entering their matriculation ID can build their ATS resumes and apply for jobs freely.</li>
                                    <li>Your career center dashboard receives real-time telemetry on resume scores and application counts.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB 4: SECURITY & PASSWORD ================= -->
            <div id="securityTab" class="settings-panel">
                <form method="post" class="card-panel">
                    <input type="hidden" name="action" value="update_password">

                    <div class="card-header">
                        <h2 class="card-title"><span>🔒</span> Career Center Account Security</h2>
                        <p class="card-desc">Update your password to protect student employability telemetry and administrative dashboard access.</p>
                    </div>

                    <?php if (!$is_admin): ?>
                        <div class="form-group">
                            <label for="current_password">Current Password *</label>
                            <input type="password" id="current_password" name="current_password" class="rb-input" required autocomplete="current-password">
                        </div>
                    <?php else: ?>
                        <div style="padding:10px 14px; background:rgba(217, 255, 79, 0.1); border-radius:10px; font-size:12px; color:var(--txt); margin-bottom:16px;">
                            🛡️ <strong>Admin Override:</strong> As an administrator, current password verification is bypassed.
                        </div>
                    <?php endif; ?>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="new_password">New Password *</label>
                            <input type="password" id="new_password" name="new_password" class="rb-input" minlength="8" required autocomplete="new-password">
                            <div class="hint">Minimum 8 characters with letters and numbers.</div>
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Confirm New Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="rb-input" minlength="8" required autocomplete="new-password">
                            <div class="hint">Re-enter your new password to verify.</div>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:10px;">
                        <button type="submit" class="btn-primary" style="padding:12px 24px;">
                            <span>🔐 Update Password</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ================= TAB 5: COMPLIANCE & TRACER STUDY ================= -->
            <div id="complianceTab" class="settings-panel">
                <div class="card-panel">
                    <div class="card-header">
                        <h2 class="card-title"><span>📜</span> Regulatory Compliance & Ministry Tracer Studies</h2>
                        <p class="card-desc">Framework information on KPT SKPG graduate destination exports, MQA accreditation compliance, and PDPA Act 2010 data privacy governance.</p>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:24px;">
                        <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:12px; padding:18px;">
                            <div style="font-size:14px; font-weight:800; color:var(--txt); margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                <span>📊</span> KPT SKPG Tracer Formats
                            </div>
                            <div style="font-size:12.5px; color:var(--mut); line-height:1.6;">
                                Telemetry exported from Keria's dashboard matches standard Ministry of Higher Education (Kementerian Pendidikan Tinggi - KPT) graduate outcome metrics for Senate review and Tracer Study audit.
                            </div>
                            <div style="margin-top:12px;">
                                <a href="university_dashboard.php?dashboard_action=export_csv" class="btn-secondary" style="padding:6px 14px; font-size:12px; text-decoration:none;">
                                    <span>⬇️ Export Full Student CSV</span>
                                </a>
                            </div>
                        </div>

                        <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:12px; padding:18px;">
                            <div style="font-size:14px; font-weight:800; color:var(--txt); margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                <span>🔒</span> PDPA Act 2010 Compliance
                            </div>
                            <div style="font-size:12.5px; color:var(--mut); line-height:1.6;">
                                Student data is governed by the Malaysian Personal Data Protection Act 2010. Students have explicitly opted-in to link their job-search profile to your institution. No third-party data broker access is permitted.
                            </div>
                        </div>
                    </div>

                    <div style="font-size:12px; color:var(--mut); border-top:1px solid var(--bdr); padding-top:16px;">
                        Need custom faculty data mapping or integration with your campus portal? Contact Keria Academic Support at <a href="mailto:support@thekeria.com" style="color:var(--acc);">support@thekeria.com</a>.
                    </div>
                </div>
            </div>
        </section>
    </div>
</main>
    </div>
</div>

<script src="qrcode.js?v=<?php echo @filemtime(__DIR__.'/qrcode.js'); ?>"></script>
<script>
    function toggleUniMenu(btn) {
        var group = btn.closest('.uni-menu-group');
        if (group) group.classList.toggle('open');
    }

    // Tab Switching Logic
    function switchTab(tabId, btn) {
        document.querySelectorAll('.settings-panel').forEach(function(p) {
            p.classList.remove('active');
        });
        document.querySelectorAll('.uni-menu-child[onclick*="switchTab"]').forEach(function(b) {
            b.classList.remove('active');
        });

        var target = document.getElementById(tabId);
        if (target) {
            target.classList.add('active');
        }
        if (btn) {
            btn.classList.add('active');
        }

        // Update URL hash
        if (history.replaceState) {
            history.replaceState(null, null, '#' + tabId);
        }
    }

    // Auto switch based on hash if provided
    window.addEventListener('DOMContentLoaded', function() {
        var hash = window.location.hash.replace('#', '');
        if (hash) {
            var targetPanel = document.getElementById(hash);
            if (targetPanel) {
                var correspondingBtn = document.querySelector(".uni-menu-child[onclick*='" + hash + "']");
                switchTab(hash, correspondingBtn);
            }
        }

        // Initialize QR code
        renderStudentQr();
    });

    // Logo image live preview
    function previewIcon(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                var previewBox = document.querySelector('.crest-preview-box');
                if (previewBox) {
                    previewBox.innerHTML = '<img src="' + e.target.result + '" alt="Preview" style="width:100%; height:100%; object-fit:contain;">';
                }
            };
            reader.readAsDataURL(input.files[0]);
        }
    }

    // QR Code Generation
    function renderStudentQr() {
        var link = document.getElementById('studentLinkInput').value;
        if (!link) return;
        var qrContainer = document.getElementById('studentLinkQr');
        if (!qrContainer) return;

        try {
            var qr = qrcode(0, 'M');
            qr.addData(link);
            qr.make();
            qrContainer.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
        } catch (e) {
            qrContainer.textContent = 'QR Unavailable';
        }
    }

    // Copy Student Link
    function copyStudentLink() {
        var input = document.getElementById('studentLinkInput');
        input.select();
        input.setSelectionRange(0, 99999);
        navigator.clipboard && navigator.clipboard.writeText(input.value).then(function() {
            var btnText = document.getElementById('copyBtnText');
            btnText.textContent = '✅ Copied!';
            setTimeout(function() {
                btnText.textContent = '📋 Copy Link';
            }, 2500);
        }).catch(function() {
            document.execCommand('copy');
            var btnText = document.getElementById('copyBtnText');
            btnText.textContent = '✅ Copied!';
            setTimeout(function() {
                btnText.textContent = '📋 Copy Link';
            }, 2500);
        });
    }

    // Download QR Code as PNG
    function downloadQrPng() {
        var svgEl = document.querySelector('#studentLinkQr svg');
        if (!svgEl) return;

        var svgData = new XMLSerializer().serializeToString(svgEl);
        var canvas = document.createElement('canvas');
        canvas.width = 600;
        canvas.height = 600;
        var ctx = canvas.getContext('2d');
        var img = new Image();

        img.onload = function() {
            ctx.fillStyle = '#FFFFFF';
            ctx.fillRect(0, 0, 600, 600);
            ctx.drawImage(img, 40, 40, 520, 520);
            var a = document.createElement('a');
            a.download = 'university_career_fair_qr.png';
            a.href = canvas.toDataURL('image/png');
            a.click();
        };

        img.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svgData)));
    }
</script>
<script src="theme.js"></script>
</body>
</html>
