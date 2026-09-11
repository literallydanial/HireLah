<?php
require_once 'auth.php';
require_once 'ai.php';
require_once 'notifications_helper.php';
require_once 'company_helpers.php';
require_login();

$user_id = $_SESSION['user_id'];
$userNotifs = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate') 
    ? getCandidateNotifications($pdo, $user_id) 
    : getEmployerNotifications($pdo, $user_id);
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        
        if (!empty($name) && !empty($email)) {
            // Check if email is already taken by another user
            $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_email->execute([$email, $user_id]);
            if ($check_email->fetch()) {
                $_SESSION['error'] = "The email address '$email' is already registered by another account.";
            } else {
                $up = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                if ($up->execute([$name, $email, $user_id])) {
                    $_SESSION['user_name'] = $name;
                    $_SESSION['toast'] = "Account name and email address updated successfully!";
                } else {
                    $_SESSION['error'] = "Failed to update profile information.";
                }
            }
        } else {
            $_SESSION['error'] = "Name and Email cannot be empty.";
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'update_api_key') {
        if ($user['role'] !== 'admin') {
            $_SESSION['error'] = "Access denied. Only administrators can update these settings.";
            header("Location: profile.php");
            exit;
        }
        $api_key = trim($_POST['api_key'] ?? '');
        $model = trim($_POST['ai_model'] ?? 'gemini-2.5-flash');
        $smtp_host = trim($_POST['smtp_host'] ?? '');
        $smtp_user = trim($_POST['smtp_user'] ?? '');
        $smtp_pass = trim($_POST['smtp_pass'] ?? '');
        $smtp_port = trim($_POST['smtp_port'] ?? '587');
        $smtp_from = trim($_POST['smtp_from'] ?? '');

        $config_file = __DIR__ . '/config.json';
        $curr_config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
        if (!is_array($curr_config)) $curr_config = [];

        if (!empty($api_key)) {
            $curr_config['api_key'] = $api_key;
            $curr_config['ai_model'] = $model;
        }
        $curr_config['smtp_host'] = $smtp_host;
        $curr_config['smtp_user'] = $smtp_user;
        if (!empty($smtp_pass)) {
            $curr_config['smtp_pass'] = $smtp_pass;
        }
        $curr_config['smtp_port'] = $smtp_port;
        $curr_config['smtp_from'] = $smtp_from;

        file_put_contents($config_file, json_encode($curr_config, JSON_PRETTY_PRINT));
        $_SESSION['toast'] = "Settings & PHPMailer Mailer Config saved!";
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'test_smtp') {
        if ($user['role'] !== 'admin') {
            $_SESSION['error'] = "Access denied. Only administrators can perform this action.";
            header("Location: profile.php");
            exit;
        }
        $target_email = trim($_POST['test_target_email'] ?? '');
        if (empty($target_email)) {
            $target_email = 'nuriman.kadir01@s.unikl.edu.my';
        }
        
        require_once __DIR__ . '/mailer.php';
        $res = send_questionnaire_email(
            $user['name'],
            $target_email,
            "Software Engineer Position",
            "Screening Questionnaire Assessment",
            "test_token_" . time(),
            [
                "What is your expected salary and notice period?",
                "What relevant technical experience do you bring to this role?"
            ]
        );
        if (!empty($res['success'])) {
            $_SESSION['toast'] = "Test questionnaire email dispatched to " . htmlspecialchars($target_email) . " via PHPMailer!";
        } else {
            $_SESSION['error'] = "PHPMailer SMTP Test Failed: " . ($res['error'] ?? 'Unknown error');
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'update_password') {
        $current_pw = $_POST['current_password'];
        $new_pw = $_POST['new_password'];
        
        if (password_verify($current_pw, $user['password_hash'])) {
            $hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            if ($stmt->execute([$hash, $user_id])) {
                $_SESSION['toast'] = "Password updated successfully.";
            } else {
                $_SESSION['error'] = "Failed to update password.";
            }
        } else {
            $_SESSION['error'] = "Current password is incorrect.";
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'upload_picture') {
        $file = $_FILES['profile_picture'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            $_SESSION['error'] = "Uploaded image exceeds the maximum allowed size limit (10MB).";
        } elseif ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $upload_dir = 'uploads/profiles/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                
                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                    $_SESSION['error'] = "Directory 'uploads/profiles/' is not writable. Please check FTP permissions.";
                } else {
                    $path = $upload_dir . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $path)) {
                        $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                        $stmt->execute([$path, $user_id]);
                        $_SESSION['toast'] = "Profile picture updated.";
                    } else {
                        $_SESSION['error'] = "Failed to save profile picture.";
                    }
                }
            } else {
                $_SESSION['error'] = "Invalid image format. Use JPG, PNG or WEBP.";
            }
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'update_company_info') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can update company settings.";
            header("Location: profile.php");
            exit;
        }
        // Company branding is shared across every HR teammate now, so only the
        // company's admin (the first person who registered, or whoever they've
        // promoted) may change it.
        $company_id = require_company_admin($pdo);

        $company_name = trim($_POST['company_name'] ?? '');
        $company_website = trim($_POST['company_website'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');

        if ($contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "'" . htmlspecialchars($contact_email) . "' is not a valid email address.";
            header("Location: profile.php");
            exit;
        }

        $stmt = $pdo->prepare("UPDATE companies SET name = ?, website = ?, address = ?, contact_email = ? WHERE id = ?");
        if ($stmt->execute([$company_name, $company_website, $company_address, $contact_email, $company_id])) {
            $_SESSION['toast'] = "Company settings saved.";
        } else {
            $_SESSION['error'] = "Failed to save company settings.";
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'upload_company_logo') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can update company settings.";
            header("Location: profile.php");
            exit;
        }
        $company_id = require_company_admin($pdo);

        $file = $_FILES['company_logo'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            $_SESSION['error'] = "Uploaded logo exceeds the maximum allowed size limit (2MB).";
        } elseif ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'])) {
                $upload_dir = 'uploads/company_logos/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                    $_SESSION['error'] = "Directory 'uploads/company_logos/' is not writable. Please check FTP permissions.";
                } else {
                    $path = $upload_dir . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $path)) {
                        $stmt = $pdo->prepare("UPDATE companies SET logo = ? WHERE id = ?");
                        $stmt->execute([$path, $company_id]);
                        $_SESSION['toast'] = "Company logo updated.";
                    } else {
                        $_SESSION['error'] = "Failed to save company logo.";
                    }
                }
            } else {
                $_SESSION['error'] = "Invalid image format. Use JPG, PNG, SVG, WEBP or GIF.";
            }
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'regenerate_invite_link') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can manage the team.";
            header("Location: profile.php");
            exit;
        }
        $company_id = require_company_admin($pdo);
        regenerate_invite_token($pdo, $company_id);
        $_SESSION['toast'] = "Invite link refreshed. The old link no longer works.";
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'remove_team_member') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can manage the team.";
            header("Location: profile.php");
            exit;
        }
        $company_id = require_company_admin($pdo);
        $member_id = (int)($_POST['member_id'] ?? 0);

        if ($member_id === (int)$user_id) {
            $_SESSION['error'] = "You can't remove yourself from the team. Ask another admin, or transfer admin rights first.";
        } else {
            $del = $pdo->prepare("DELETE FROM company_members WHERE company_id = ? AND user_id = ? AND role != 'admin'");
            $del->execute([$company_id, $member_id]);
            if ($del->rowCount() > 0) {
                $_SESSION['toast'] = "Teammate removed from the company.";
            } else {
                $_SESSION['error'] = "Couldn't remove that teammate (they may already be gone, or are an admin).";
            }
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'upload_company_media') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can update company settings.";
            header("Location: profile.php");
            exit;
        }

        try {
            $max_total = 6;
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM company_media WHERE user_id = ?");
            $count_stmt->execute([$user_id]);
            $existing_count = (int)$count_stmt->fetchColumn();

            $image_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $files = $_FILES['company_media'] ?? null;
            $uploaded = 0;
            $skipped = 0;

            if ($files && !empty($files['name'][0])) {
                $upload_dir = 'uploads/company_media/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($existing_count + $uploaded >= $max_total) { $skipped++; continue; }
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) { $skipped++; continue; }

                    $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                    if (!in_array($ext, $image_exts)) { $skipped++; continue; }

                    $max_size = 5 * 1024 * 1024;
                    if ($files['size'][$i] > $max_size) { $skipped++; continue; }

                    if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                        $_SESSION['error'] = "Directory 'uploads/company_media/' is not writable. Please check FTP permissions.";
                        break;
                    }

                    $path = $upload_dir . uniqid() . '.' . $ext;
                    if (move_uploaded_file($files['tmp_name'][$i], $path)) {
                        $ins = $pdo->prepare("INSERT INTO company_media (user_id, media_type, file_path, sort_order) VALUES (?, ?, ?, ?)");
                        $ins->execute([$user_id, 'image', $path, $existing_count + $uploaded]);
                        $uploaded++;
                    } else {
                        $skipped++;
                    }
                }
            }

            if ($uploaded > 0) {
                $_SESSION['toast'] = "Added $uploaded item(s) to your company gallery." . ($skipped > 0 ? " $skipped file(s) were skipped (gallery limit of $max_total, unsupported format, or too large)." : "");
            } elseif (!isset($_SESSION['error'])) {
                $_SESSION['error'] = "No files were added. Check the file type, size (up to 5MB per image), and the 6-item gallery limit.";
            }
        } catch (\PDOException $e) {
            error_log("upload_company_media DB error: " . $e->getMessage());
            if (strpos($e->getMessage(), "doesn't exist") !== false || $e->getCode() === '42S02') {
                $_SESSION['error'] = "The company_media table hasn't been created yet. Please run add_company_media.sql in phpMyAdmin, then try again.";
            } else {
                $_SESSION['error'] = "Something went wrong saving your gallery images. Please try again.";
            }
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'delete_company_media') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can update company settings.";
            header("Location: profile.php");
            exit;
        }
        try {
            $media_id = (int)($_POST['media_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM company_media WHERE id = ? AND user_id = ?");
            $stmt->execute([$media_id, $user_id]);
            $media = $stmt->fetch();
            if ($media) {
                if (!empty($media['file_path']) && file_exists($media['file_path'])) {
                    @unlink($media['file_path']);
                }
                $del = $pdo->prepare("DELETE FROM company_media WHERE id = ? AND user_id = ?");
                $del->execute([$media_id, $user_id]);
                $_SESSION['toast'] = "Gallery item removed.";
            }
        } catch (\PDOException $e) {
            error_log("delete_company_media DB error: " . $e->getMessage());
            $_SESSION['error'] = "Something went wrong removing that item. Please try again.";
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'upload_resume') {
        $file = $_FILES['default_resume'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            $_SESSION['error'] = "Uploaded resume exceeds the maximum allowed size limit (10MB).";
        } elseif ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $upload_dir = 'uploads/resumes/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                
                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                    $_SESSION['error'] = "Directory 'uploads/resumes/' is not writable. Please check FTP permissions.";
                } else {
                    $path = $upload_dir . uniqid() . '_' . basename($file['name']);
                    if (move_uploaded_file($file['tmp_name'], $path)) {
                        $stmt = $pdo->prepare("UPDATE users SET default_resume = ? WHERE id = ?");
                        $stmt->execute([$path, $user_id]);
                        $_SESSION['toast'] = "Default PDF resume saved successfully.";
                    } else {
                        $_SESSION['error'] = "Failed to save uploaded resume file.";
                    }
                }
            } else {
                $_SESSION['error'] = "Only PDF resumes are supported.";
            }
        }
        header("Location: profile.php");
        exit;
    }
    elseif ($action === 'delete_account') {
        $confirm_text = trim($_POST['confirm_delete'] ?? '');
        if ($confirm_text === 'DELETE') {
            // Unlink candidate profile picture if exists
            if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                @unlink($user['profile_picture']);
            }
            // Unlink candidate default resume if exists
            if (!empty($user['default_resume']) && file_exists($user['default_resume'])) {
                @unlink($user['default_resume']);
            }
            // Delete candidate applications
            $del_cand = $pdo->prepare("DELETE FROM candidates WHERE user_id = ? OR (email = ? AND email IS NOT NULL AND email != '')");
            $del_cand->execute([$user_id, $user['email']]);

            // Delete user record from users table
            $del_user = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $del_user->execute([$user_id]);

            // Clear session and redirect with toast
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['toast'] = "Your candidate account has been permanently deleted.";
            header("Location: index.php");
            exit;
        } else {
            $_SESSION['error'] = "Please type 'DELETE' in capital letters to confirm account deletion.";
            header("Location: profile.php");
            exit;
        }
    }
}

$company_media = [];
if ($user['role'] === 'employer') {
    try {
        $cm_stmt = $pdo->prepare("SELECT * FROM company_media WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
        $cm_stmt->execute([$user_id]);
        $company_media = $cm_stmt->fetchAll();
    } catch (\Throwable $e) {
        $company_media = [];
    }
}

// Multi-HR team data — which company this HR is currently acting as, their
// role in it, everyone else on the team, and the invite link/QR to grow it.
$user_companies = [];
$active_company_id = null;
$active_company = null;
$is_company_admin = false;
$company_members = [];
$invite_link = null;
if ($user['role'] === 'employer') {
    $user_companies = get_user_companies($pdo, $user_id);
    $active_company_id = get_active_company_id($pdo);
    foreach ($user_companies as $c) {
        if ((int)$c['id'] === (int)$active_company_id) { $active_company = $c; break; }
    }
    $is_company_admin = $active_company && $active_company['role'] === 'admin';
    if ($active_company_id) {
        $company_members = get_company_members($pdo, $active_company_id);
        if ($is_company_admin) {
            $tok_stmt = $pdo->prepare("SELECT invite_token FROM companies WHERE id = ?");
            $tok_stmt->execute([$active_company_id]);
            $invite_token = $tok_stmt->fetchColumn();
            if ($invite_token) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $base = $scheme . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                $invite_link = $base . '/join_company.php?invite=' . urlencode($invite_token);
            }
        }
    }
}

$cand_applications = [];
if ($user['role'] === 'candidate') {
    try {
        $c_stmt = $pdo->prepare("
            SELECT c.*, j.job_title, j.department, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name
            FROM candidates c 
            JOIN jobs j ON c.job_id = j.id 
            LEFT JOIN users u ON j.employer_id = u.id 
            WHERE c.user_id = ? 
            ORDER BY c.created_at DESC
        ");
        $c_stmt->execute([$user_id]);
        $cand_applications = $c_stmt->fetchAll();
    } catch (\Throwable $e) {
        $cand_applications = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile & Account Settings - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .profile-pic {
            width: 96px;
            height: 96px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--acc);
            margin-bottom: 12px;
            background: var(--dim);
            box-shadow: var(--shadow-md);
        }
    </style>
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>
            
            <nav style="display:flex; gap:4px; margin-left:24px">
                <?php if($user['role'] === 'candidate'): ?>
                    <a href="jobs.php">📋 Job Board</a>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="profile.php" class="active">⚙️ Profile Settings</a>
                <?php elseif($user['role'] === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications & Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="profile.php" class="active">⚙️ Settings</a>
                <?php else: ?>
                    <a href="admin_dashboard.php">⚙️ Dashboard</a>
                    <a href="profile.php" class="active">⚙️ Profile Settings</a>
                <?php endif; ?>
            </nav>
            <?php include 'company_switcher.php'; ?>

            <div class="header-right-actions">
                <?php if($user['role'] === 'candidate'): ?>
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

                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main style="max-width:1180px; width:100%; box-sizing:border-box; margin:36px auto; padding:0 24px;">
        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:12px 20px; margin-bottom:24px; color:var(--red); font-size:13.5px; display:flex; align-items:center; gap:10px;">
                <span>⚠️</span>
                <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Desktop Hero Header Card -->
        <div class="panel" style="margin-bottom:28px; padding:28px 36px; background:linear-gradient(135deg, var(--card) 0%, var(--surf) 100%); border-radius:18px; box-shadow:var(--shadow-md);">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:24px;">
                <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap;">
                    <div style="position:relative; flex-shrink:0;">
                        <?php if(!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                            <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile Picture" class="profile-pic" style="width:84px; height:84px; margin-bottom:0; box-shadow:0 0 20px rgba(107, 138, 0, 0.2);">
                        <?php else: ?>
                            <div class="profile-pic" style="width:84px; height:84px; margin-bottom:0; display:flex; align-items:center; justify-content:center; font-size:32px; font-weight:800; color:#fff; background:var(--grad-purple); box-shadow:0 0 20px rgba(0, 0, 0, 0.25);">
                                <?= strtoupper(substr($user['name'] ?: 'U', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" enctype="multipart/form-data" style="position:absolute; bottom:-4px; right:-4px;">
                            <input type="hidden" name="action" value="upload_picture">
                            <input type="file" name="profile_picture" id="pic_input" accept="image/png, image/jpeg, image/webp" style="display:none" onchange="this.form.submit()">
                            <button type="button" class="btn-secondary" style="padding:4px 8px; font-size:11px; border-radius:50%; width:30px; height:30px; display:flex; align-items:center; justify-content:center; box-shadow:var(--shadow-md);" onclick="document.getElementById('pic_input').click()" title="Change Avatar">📷</button>
                        </form>
                    </div>

                    <div>
                        <div style="display:flex; align-items:center; gap:12px; margin-bottom:4px; flex-wrap:wrap;">
                            <h1 style="font-size:24px; font-weight:800; margin:0; letter-spacing:-0.025em; color:var(--txt);"><?= htmlspecialchars($user['name']) ?></h1>
                            <span class="chip" style="background:rgba(107, 138, 0, 0.12); border-color:rgba(107, 138, 0, 0.25); color:var(--acc); font-weight:700; font-size:11px; padding:3px 10px;">
                                🛡️ <?= strtoupper(htmlspecialchars($user['role'])) ?>
                            </span>
                        </div>
                        <div style="font-size:14px; color:var(--mut); font-weight:500;"><?= htmlspecialchars($user['email']) ?></div>
                    </div>
                </div>

                <div style="display:flex; gap:12px; align-items:center;">
                    <div style="text-align:right;" class="user-info-text">
                        <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.8px; color:var(--mut); font-weight:700;">Account Status</div>
                        <div style="font-size:13px; font-weight:700; color:var(--grn); display:flex; align-items:center; gap:5px; justify-content:flex-end; margin-top:2px;">
                            <span style="width:7px; height:7px; border-radius:50%; background:var(--grn); display:inline-block;"></span> Active & Verified
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:28px; align-items:start;">
            <!-- Profile Info Form -->
            <div class="panel" style="padding:28px 32px;">
                <div class="panel-title" style="display:flex; align-items:center; gap:8px;">
                    <span>👤</span> Account Details
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Full Name / Company Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required placeholder="e.g. Alex Tan">
                    </div>
                    
                    <div style="margin-bottom:24px;">
                        <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required placeholder="alex@example.com">
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width:100%;">Save Profile Info &rarr;</button>
                </form>
            </div>

            <!-- Password Security Form -->
            <div class="panel" style="padding:28px 32px;">
                <div class="panel-title" style="display:flex; align-items:center; gap:8px;">
                    <span>🔒</span> Security & Password
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_password">
                    
                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Current Password</label>
                        <input type="password" name="current_password" required placeholder="••••••••">
                    </div>
                    
                    <div style="margin-bottom:24px;">
                        <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">New Password</label>
                        <input type="password" name="new_password" required minlength="6" placeholder="At least 6 characters">
                    </div>
                    
                    <button type="submit" class="btn-primary" style="width:100%;">Update Password &rarr;</button>
                </form>
            </div>

            <?php if($user['role'] === 'employer'): ?>
                <!-- Company Settings Panel -->
                <div class="panel" style="grid-column: 1 / -1; padding:32px;">
                    <div class="panel-title" style="display:flex; align-items:center; gap:8px;">
                        <span>🏢</span> Company Profile & Candidate Branding
                    </div>
                    <p style="font-size:13px; color:var(--mut); margin-bottom:20px;">This information is shared by everyone on your company's HR team and is displayed on candidate-facing job postings, application receipts, and interview communications.</p>

                    <?php if(!$is_company_admin): ?>
                        <div style="background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.25); border-radius:10px; padding:10px 14px; margin-bottom:18px; font-size:12.5px; color:var(--mut);">
                            ℹ️ Only your company's admin can change these details. You can view them below.
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_company_info">
                        <fieldset <?= $is_company_admin ? '' : 'disabled' ?> style="border:none; padding:0; margin:0;">

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:18px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Company Name</label>
                                <input type="text" name="company_name" placeholder="Acme Corporation Sdn Bhd" value="<?= htmlspecialchars($active_company['name'] ?? '') ?>">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Official Website</label>
                                <input type="url" name="company_website" placeholder="https://acme.com" value="<?= htmlspecialchars($active_company['website'] ?? '') ?>">
                            </div>
                        </div>

                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Candidate Contact Email (Optional)</label>
                            <input type="email" name="contact_email" placeholder="careers@acme.com &mdash; leave blank to use account email" value="<?= htmlspecialchars($active_company['contact_email'] ?? '') ?>">
                        </div>

                        <div style="margin-bottom:24px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Company Address</label>
                            <textarea name="company_address" rows="3" placeholder="Level 18, Menara Acme, Jalan Sultan Ismail, 50250 Kuala Lumpur"><?= htmlspecialchars($active_company['address'] ?? '') ?></textarea>
                        </div>

                        <?php if($is_company_admin): ?>
                        <div style="display:flex; justify-content:flex-end;">
                            <button type="submit" class="btn-primary" style="width:auto; padding:11px 28px;">Save Company Info &rarr;</button>
                        </div>
                        <?php endif; ?>
                        </fieldset>
                    </form>

                    <?php if($is_company_admin): ?>
                    <div style="border-top:1px dashed var(--bdr); margin-top:24px; padding-top:20px;">
                        <label style="display:block; font-size:13px; font-weight:700; color:var(--txt); margin-bottom:10px;">Company Logo</label>
                        <form method="POST" enctype="multipart/form-data" style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
                            <input type="hidden" name="action" value="upload_company_logo">
                            <div style="width:72px; height:72px; border-radius:12px; background:var(--surf); border:1px solid var(--bdr); display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0;">
                                <?php if(!empty($active_company['logo'])): ?>
                                    <img src="<?= htmlspecialchars($active_company['logo']) ?>" alt="Company logo" style="width:100%; height:100%; object-fit:contain;">
                                <?php else: ?>
                                    <span style="font-size:28px;">🏢</span>
                                <?php endif; ?>
                            </div>
                            <div style="flex:1; min-width:260px;">
                                <input type="file" name="company_logo" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" required style="margin-bottom:8px;">
                                <div style="font-size:11.5px; color:var(--mut);">Supported formats: PNG, JPG, SVG, WebP (Max 2MB)</div>
                            </div>
                            <button type="submit" class="btn-secondary" style="padding:9px 18px; font-size:12.5px;">Upload Logo</button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div style="border-top:1px dashed var(--bdr); margin-top:24px; padding-top:20px;">
                        <label style="display:block; font-size:13px; font-weight:700; color:var(--txt); margin-bottom:4px;">Company Culture Gallery</label>
                        <p style="font-size:12.5px; color:var(--mut); margin:0 0 16px 0;">Showcase workplace photos to candidate applicants (Up to 6 images).</p>

                        <?php if(!empty($company_media)): ?>
                            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(110px, 1fr)); gap:14px; margin-bottom:20px;">
                                <?php foreach($company_media as $m): ?>
                                    <div style="position:relative; width:100%; aspect-ratio:1/1; border-radius:12px; overflow:hidden; background:var(--surf); border:1px solid var(--bdr); box-shadow:var(--shadow-sm);">
                                        <img src="<?= htmlspecialchars($m['file_path']) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                                        <form method="POST" onsubmit="return confirm('Remove this item from your gallery?');" style="position:absolute; top:6px; right:6px;">
                                            <input type="hidden" name="action" value="delete_company_media">
                                            <input type="hidden" name="media_id" value="<?= (int)$m['id'] ?>">
                                            <button type="submit" title="Remove" style="width:22px; height:22px; border-radius:50%; border:none; background:rgba(0,0,0,0.7); color:#fff; font-size:12px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; transition:transform 0.2s ease;">&times;</button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php $media_remaining = 6 - count($company_media); ?>
                        <?php if($media_remaining > 0): ?>
                            <form method="POST" enctype="multipart/form-data" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                                <input type="hidden" name="action" value="upload_company_media">
                                <div style="flex:1; min-width:260px;">
                                    <input type="file" name="company_media[]" accept=".png,.jpg,.jpeg,.webp,.gif" multiple required style="margin-bottom:6px;">
                                    <div style="font-size:11.5px; color:var(--mut);">PNG, JPG, WebP (Max 5MB each) &middot; <strong><?= $media_remaining ?></strong> slot<?= $media_remaining === 1 ? '' : 's' ?> remaining</div>
                                </div>
                                <button type="submit" class="btn-secondary" style="padding:9px 18px; font-size:12.5px;">Add Photos</button>
                            </form>
                        <?php else: ?>
                            <div style="font-size:12.5px; color:var(--mut);">Gallery has reached capacity (6/6). Remove a photo above to upload a new one.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if($user['role'] === 'employer' && $active_company_id): ?>
                <!-- Team Management Panel -->
                <div class="panel" style="grid-column: 1 / -1; padding:32px;">
                    <div class="panel-title" style="display:flex; align-items:center; gap:8px;">
                        <span>👥</span> Team — <?= htmlspecialchars($active_company['name'] ?? 'Your Company') ?>
                    </div>
                    <p style="font-size:13px; color:var(--mut); margin-bottom:20px;">
                        Everyone below shares this company's jobs, candidates, and questionnaires.
                        <?php if(count($user_companies) > 1): ?>
                            You belong to <?= count($user_companies) ?> companies — use the company switcher to jump between them.
                        <?php endif; ?>
                    </p>

                    <?php if($is_company_admin): ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:20px; margin-bottom:24px;">
                        <label style="display:block; font-size:13px; font-weight:700; color:var(--txt); margin-bottom:10px;">Invite Teammates</label>
                        <p style="font-size:12.5px; color:var(--mut); margin:0 0 14px 0;">Share this link, or have them scan the QR code — anyone who opens it joins your team instantly as an HR teammate.</p>

                        <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
                            <div style="flex:1; min-width:260px;">
                                <div style="display:flex; gap:8px; margin-bottom:10px;">
                                    <input type="text" readonly id="inviteLinkInput" value="<?= htmlspecialchars($invite_link ?? '') ?>" style="flex:1; font-family:monospace; font-size:12px;">
                                    <button type="button" class="btn-secondary" style="padding:9px 16px; font-size:12.5px; white-space:nowrap;" onclick="copyInviteLink()">📋 Copy</button>
                                </div>
                                <form method="POST" onsubmit="return confirm('Generate a new invite link? The old link and QR code will stop working immediately.');">
                                    <input type="hidden" name="action" value="regenerate_invite_link">
                                    <button type="submit" class="btn-secondary" style="padding:8px 16px; font-size:12px;">🔄 Regenerate Link</button>
                                </form>
                            </div>
                            <div style="text-align:center;">
                                <div id="inviteQrCode" style="width:150px; height:150px; display:flex; align-items:center; justify-content:center; background:#fff; border-radius:10px; border:1px solid var(--bdr); padding:8px;"></div>
                                <div style="font-size:11px; color:var(--mut); margin-top:6px;">Scan to join</div>
                            </div>
                        </div>
                    </div>

                    <script src="qrcode.js?v=<?php echo @filemtime(__DIR__.'/qrcode.js'); ?>"></script>
                    <script>
                        (function() {
                            var link = document.getElementById('inviteLinkInput').value;
                            if (!link) return;
                            try {
                                var qr = qrcode(0, 'M');
                                qr.addData(link);
                                qr.make();
                                document.getElementById('inviteQrCode').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
                            } catch (e) {
                                document.getElementById('inviteQrCode').textContent = 'QR unavailable';
                            }
                        })();
                        function copyInviteLink() {
                            var input = document.getElementById('inviteLinkInput');
                            input.select();
                            input.setSelectionRange(0, 99999);
                            navigator.clipboard && navigator.clipboard.writeText(input.value).catch(function(){ document.execCommand('copy'); });
                        }
                    </script>
                    <?php endif; ?>

                    <label style="display:block; font-size:13px; font-weight:700; color:var(--txt); margin-bottom:10px;">Members (<?= count($company_members) ?>)</label>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <?php foreach($company_members as $m): ?>
                            <div style="display:flex; align-items:center; gap:14px; padding:12px 14px; background:var(--surf); border:1px solid var(--bdr); border-radius:10px;">
                                <div style="width:38px; height:38px; border-radius:50%; background:var(--acc); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:14px; flex-shrink:0; overflow:hidden;">
                                    <?php if(!empty($m['profile_picture'])): ?>
                                        <img src="<?= htmlspecialchars($m['profile_picture']) ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                                    <?php else: ?>
                                        <?= htmlspecialchars(strtoupper(substr($m['name'], 0, 1))) ?>
                                    <?php endif; ?>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div style="font-size:13.5px; font-weight:600; color:var(--txt);"><?= htmlspecialchars($m['name']) ?><?= (int)$m['id'] === (int)$user_id ? ' (You)' : '' ?></div>
                                    <div style="font-size:12px; color:var(--mut);"><?= htmlspecialchars($m['email']) ?></div>
                                </div>
                                <span style="font-size:11px; font-weight:700; padding:4px 10px; border-radius:999px; <?= $m['role'] === 'admin' ? 'background:rgba(59,130,246,0.15); color:var(--acc);' : 'background:var(--bg); color:var(--mut); border:1px solid var(--bdr);' ?>">
                                    <?= $m['role'] === 'admin' ? '⭐ Admin' : 'HR' ?>
                                </span>
                                <?php if($is_company_admin && $m['role'] !== 'admin'): ?>
                                    <form method="POST" onsubmit="return confirm('Remove <?= htmlspecialchars(addslashes($m['name'])) ?> from this company?');">
                                        <input type="hidden" name="action" value="remove_team_member">
                                        <input type="hidden" name="member_id" value="<?= (int)$m['id'] ?>">
                                        <button type="submit" title="Remove" style="width:28px; height:28px; border-radius:50%; border:1px solid var(--bdr); background:var(--bg); color:var(--red); font-size:14px; cursor:pointer;">&times;</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if($user['role'] === 'admin'): ?>
                <?php
                    $c_file = __DIR__ . '/config.json';
                    $cfg = file_exists($c_file) ? json_decode(file_get_contents($c_file), true) : [];
                    if (!is_array($cfg)) $cfg = [];

                    $existing_key = get_api_key();
                    $api_key_hint = $existing_key
                        ? ('Key on file: AIzaSy••••••••' . substr($existing_key, -4) . ' (leave blank to keep)')
                        : 'AIzaSy...';
                    $existing_smtp_pass = $cfg['smtp_pass'] ?? '';
                    $smtp_pass_hint = $existing_smtp_pass
                        ? ('Password on file: ••••' . substr($existing_smtp_pass, -2) . ' (leave blank to keep)')
                        : '16-character App Password';
                ?>
                <!-- Admin API & Mailer Configuration Card -->
                <div class="panel" style="grid-column: 1 / -1; padding:32px;">
                    <div class="panel-title" style="display:flex; align-items:center; gap:8px;">
                        <span>🔑</span> Google Gemini AI & PHPMailer System Configuration
                    </div>
                    <p style="font-size:13px; color:var(--mut); margin-bottom:20px;">Manage backend Google Gemini AI model parameters and PHPMailer SMTP credentials for candidate email dispatches.</p>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="update_api_key">
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Google Gemini API Key</label>
                                <input type="password" name="api_key" placeholder="<?= htmlspecialchars($api_key_hint) ?>" value="" autocomplete="off">
                            </div>
                            
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Gemini AI Engine Model</label>
                                <select name="ai_model">
                                    <option value="gemini-3.7-flash" <?= (($_SESSION['ai_model'] ?? 'gemini-3.7-flash') === 'gemini-3.7-flash') ? 'selected' : '' ?>>Gemini 3.7 Flash (Recommended)</option>
                                    <option value="gemini-3.6-flash" <?= (($_SESSION['ai_model'] ?? '') === 'gemini-3.6-flash') ? 'selected' : '' ?>>Gemini 3.6 Flash (Fast & Reliable)</option>
                                    <option value="gemini-flash-latest" <?= (($_SESSION['ai_model'] ?? '') === 'gemini-flash-latest') ? 'selected' : '' ?>>Gemini Flash Latest</option>
                                    <option value="gemini-3.1-pro-preview" <?= (($_SESSION['ai_model'] ?? '') === 'gemini-3.1-pro-preview') ? 'selected' : '' ?>>Gemini 3.1 Pro Preview (Most Capable)</option>
                                </select>
                            </div>
                        </div>

                        <div style="border-top:1px dashed var(--bdr); padding-top:20px; margin-top:20px;">
                            <div style="font-size:14px; font-weight:700; color:var(--txt); margin-bottom:8px; display:flex; align-items:center; gap:6px;">
                                <span>📧</span> PHPMailer SMTP Dispatcher Settings
                            </div>
                            <div style="background:rgba(107, 138, 0, 0.08); border:1px solid rgba(107, 138, 0, 0.25); border-radius:12px; padding:14px 18px; margin-bottom:20px; font-size:12.5px; color:var(--txt); line-height:1.5;">
                                💡 <strong>Gmail Users Note:</strong> Google requires a 16-character <strong>App Password</strong>. Enable 2-Step Verification on your Google Account and generate one at <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color:var(--acc); font-weight:700;">myaccount.google.com/apppasswords</a>.
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:16px;">
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP Host Server</label>
                                    <input type="text" name="smtp_host" placeholder="smtp.gmail.com" value="<?= htmlspecialchars($cfg['smtp_host'] ?? '') ?>">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP User / Email</label>
                                    <input type="text" name="smtp_user" placeholder="your.email@gmail.com" value="<?= htmlspecialchars($cfg['smtp_user'] ?? '') ?>">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP App Password</label>
                                    <input type="password" name="smtp_pass" placeholder="<?= htmlspecialchars($smtp_pass_hint) ?>" value="" autocomplete="off">
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP Port</label>
                                    <input type="number" name="smtp_port" placeholder="587" value="<?= htmlspecialchars($cfg['smtp_port'] ?? '587') ?>">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">Sender Email (From Header)</label>
                                    <input type="email" name="smtp_from" placeholder="your.email@gmail.com" value="<?= htmlspecialchars($cfg['smtp_from'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div style="display:flex; justify-content:flex-end;">
                            <button type="submit" class="btn-primary" style="width:auto; padding:11px 28px;">Save System Configuration &rarr;</button>
                        </div>
                    </form>

                    <form method="POST" onsubmit="return confirm('Send test questionnaire email to ' + this.test_target_email.value + '?');" style="margin-top:20px; border-top:1px dashed var(--bdr); padding-top:20px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="test_smtp">
                        <div style="flex:1; min-width:280px;">
                            <label style="display:block; font-size:11.5px; font-weight:700; color:var(--mut); margin-bottom:6px;">Test Email Target Recipient</label>
                            <input type="email" name="test_target_email" value="nuriman.kadir01@s.unikl.edu.my" required onkeydown="if(event.key === 'Enter'){ event.preventDefault(); return false; }">
                        </div>
                        <div>
                            <button type="submit" class="btn-secondary" style="padding:11px 20px; font-weight:700; white-space:nowrap;">🧪 Send Test Email &rarr;</button>
                        </div>
                    </form>
                </div>
            <?php elseif($user['role'] === 'candidate'): ?>
                <!-- Candidate Resume Section -->
                <div class="panel" style="grid-column: 1 / -1; padding:32px;">
                    <div class="panel-title" style="display:flex; align-items:center; gap:8px;">
                        <span>📄</span> Saved Default Resume
                    </div>
                    <p style="font-size:13px; color:var(--mut); margin-bottom:18px;">Upload a default PDF resume to apply for open job roles with 1-click speed.</p>
                    
                    <?php if(!empty($user['default_resume'])): ?>
                        <div style="background:var(--toast-bg); border:1px solid var(--toast-bdr); border-radius:12px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
                            <div style="display:flex; align-items:center; gap:16px;">
                                <span style="font-size:28px;">📄</span>
                                <div>
                                    <div style="font-size:13.5px; font-weight:700; color:var(--toast-txt);">Default Resume Saved</div>
                                    <div style="font-size:12px; color:var(--mut);"><?= htmlspecialchars(basename($user['default_resume'])) ?></div>
                                </div>
                            </div>
                            <div style="display:flex; gap:10px; align-items:center;">
                                <button type="button" class="btn-secondary" onclick="openResumeModal('<?= htmlspecialchars($user['default_resume']) ?>', '<?= htmlspecialchars(basename($user['default_resume'])) ?>')" style="padding:9px 18px; font-size:12.5px; font-weight:700; border-radius:8px; display:inline-flex; align-items:center; gap:6px;">
                                    👁️ Preview Resume
                                </button>
                                <a href="<?= htmlspecialchars($user['default_resume']) ?>" download class="btn-primary" style="padding:9px 16px; font-size:12.5px; font-weight:700; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; width:auto;">
                                    📥 Download
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="upload_resume">
                        <input type="file" name="default_resume" accept=".pdf" required style="flex:1; min-width:280px;">
                        <button type="submit" class="btn-primary" style="width:auto; padding:11px 28px;">Upload Resume</button>
                    </form>
                </div>



                <!-- Danger Zone: Account Deletion Panel -->
                <div class="panel" style="grid-column: 1 / -1; border-color: rgba(255, 77, 106, 0.35); background: rgba(255, 77, 106, 0.03); padding:32px;">
                    <div class="panel-title" style="color: var(--red); display:flex; align-items:center; gap:8px;">
                        <span>⚠️</span> Danger Zone: Account Deletion
                    </div>
                    <p style="font-size:13px; color:var(--mut); margin-bottom:20px; line-height:1.5;">
                        Deleting your candidate account will permanently purge your candidate profile, uploaded resume documents, profile avatar, and all active job applications. 
                        <strong>This operation is permanent and non-reversible.</strong>
                    </p>

                    <form method="POST" onsubmit="return confirm('Are you completely sure you want to permanently delete your candidate account? All your data will be removed forever.');">
                        <input type="hidden" name="action" value="delete_account">
                        <div style="margin-bottom:18px; max-width:440px;">
                            <label style="display:block; font-size:11.5px; font-weight:700; color:var(--red); margin-bottom:6px;">Type DELETE to confirm account deletion:</label>
                            <input type="text" name="confirm_delete" placeholder="DELETE" required style="padding:10px 14px; font-size:13px; font-weight:700; letter-spacing:1px; border-color: rgba(255, 77, 106, 0.4);">
                        </div>
                        <button type="submit" class="btn-secondary" style="padding:11px 24px; font-size:13px; font-weight:800; color:var(--red); border-color:rgba(255, 77, 106, 0.5); background:rgba(255, 77, 106, 0.1);">
                            🗑️ Permanently Delete My Account
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- FOOTER -->
    <footer class="keria-footer">
        <div class="footer-inner">
            <a href="index.php" class="keria-logo">
                <img src="logo/logo_white.png?v=<?php echo @filemtime(__DIR__.'/logo/logo_white.png'); ?>" alt="keria" class="keria-logo-img" style="height:40px; mix-blend-mode:normal;">
            </a>

            <div class="footer-nav-links" style="display:flex; gap:28px; align-items:center; flex-wrap:wrap;">
                <a href="jobs.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Jobs</a>
                <a href="resume_check.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">AI Resume Check</a>
                <a href="resume_builder.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Resume Builder</a>
                <a href="register.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">For Employers</a>
                <a href="terms.php" style="color:rgba(255,255,255,0.7); text-decoration:none; font-size:14px;">Terms & Conditions</a>
            </div>
        </div>

        <div class="footer-bottom-copy">
            <p style="margin:0;">&copy; <?= date('Y') ?> keria. All rights reserved.</p>
            <p style="margin:0;">Made with ❤️ in Kuala Lumpur, Malaysia</p>
        </div>
    </footer>


    <!-- Resume PDF Preview Popup Modal Overlay -->
    <div id="resumePreviewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(10px); z-index:9000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:920px; width:100%; max-height:92vh; display:flex; flex-direction:column; padding:0; border-radius:18px; overflow:hidden; box-shadow:var(--shadow-lg); border:1px solid var(--bdr); animation:modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
            
            <!-- Modal Header -->
            <div style="padding:16px 24px; background:var(--surf); border-bottom:1px solid var(--bdr); display:flex; justify-content:space-between; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                    <div style="font-size:24px;">📄</div>
                    <div style="min-width:0;">
                        <div id="resumeModalTitle" style="font-size:15px; font-weight:800; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Candidate Resume Preview</div>
                        <div id="resumeModalFilename" style="font-size:11.5px; color:var(--mut); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">PDF Document</div>
                    </div>
                </div>
                
                <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                    <a id="resumeModalDownloadBtn" href="#" download class="btn-primary" style="padding:8px 16px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; border-radius:8px; width:auto;">
                        📥 Download PDF
                    </a>
                    <button type="button" onclick="closeResumeModal()" style="background:none; border:none; color:var(--mut); font-size:24px; cursor:pointer; line-height:1; padding:4px 8px;" title="Close Modal">✕</button>
                </div>
            </div>

            <!-- Modal PDF Viewer Iframe Body -->
            <div style="flex:1; background:#181825; position:relative; min-height:580px; display:flex; align-items:center; justify-content:center;">
                <iframe id="resumeModalIframe" src="" style="width:100%; height:100%; min-height:580px; border:none;"></iframe>
            </div>
        </div>
    </div>

    <script>
        function openResumeModal(pdfUrl, title) {
            const modal = document.getElementById('resumePreviewModal');
            const iframe = document.getElementById('resumeModalIframe');
            const titleEl = document.getElementById('resumeModalTitle');
            const fileEl = document.getElementById('resumeModalFilename');
            const downloadBtn = document.getElementById('resumeModalDownloadBtn');

            if (!modal || !iframe) return;

            titleEl.textContent = title ? ('📄 ' + title) : '📄 Candidate Resume Preview';
            fileEl.textContent = pdfUrl;
            downloadBtn.href = pdfUrl;
            iframe.src = pdfUrl;

            modal.style.display = 'flex';
        }

        function closeResumeModal() {
            const modal = document.getElementById('resumePreviewModal');
            const iframe = document.getElementById('resumeModalIframe');
            if (modal) modal.style.display = 'none';
            if (iframe) iframe.src = '';
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeResumeModal();
        });

        document.getElementById('resumePreviewModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeResumeModal();
        });

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

