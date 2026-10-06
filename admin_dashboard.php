<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'admin_logs_helper.php';
require_once 'ai.php';
require_once 'company_helpers.php';

// Ensure user is logged in as admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    $_SESSION['error'] = "Access denied. Admin privileges required.";
    header("Location: login.php");
    exit;
}

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// Universities list reused for the Add/Edit User modals' university_id dropdown
// (same table candidates self-link to), so a university account created or
// edited here can be scoped by a real id rather than free-text matching.
$universities_list = $pdo->query("SELECT id, name, type FROM universities ORDER BY FIELD(type,'public','private','other'), name")->fetchAll();

// Base link for the "University Sign-Up QR Code" tab -- same scheme+host
// convention used for the employer team-invite link in profile.php.
$uqr_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$uqr_base = $uqr_scheme . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$university_signup_link = $uqr_base . '/register.php?type=university';

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
        $institution_name = ($role === 'university') ? trim($_POST['institution_name'] ?? '') : (($role === 'employer') ? trim($_POST['company_name'] ?? '') : null);
        $ssm_number = in_array($role, ['university', 'employer'], true) ? trim($_POST['ssm_number'] ?? '') : null;
        $university_id = null;
        if ($role === 'university') {
            $uni_type = in_array($_POST['university_type'] ?? '', ['public', 'private'], true) ? $_POST['university_type'] : 'public';
            if ($institution_name !== '') {
                $chk_uni = $pdo->prepare("SELECT id FROM universities WHERE LOWER(name) = LOWER(?) LIMIT 1");
                $chk_uni->execute([$institution_name]);
                $existing_uni_id = $chk_uni->fetchColumn();
                if ($existing_uni_id) {
                    $university_id = (int)$existing_uni_id;
                    $upd_uni = $pdo->prepare("UPDATE universities SET type = ?, ssm_number = ? WHERE id = ?");
                    $upd_uni->execute([$uni_type, $ssm_number, $university_id]);
                } else {
                    $ins_uni = $pdo->prepare("INSERT INTO universities (name, type, ssm_number) VALUES (?, ?, ?)");
                    $ins_uni->execute([$institution_name, $uni_type, $ssm_number]);
                    $university_id = (int)$pdo->lastInsertId();
                }
            }
        }

        if (empty($name) || empty($email) || empty($password)) {
            $_SESSION['error'] = "All fields (Name, Email, Password) are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid email format.";
        } elseif ($role === 'university' && $institution_name === '') {
            $_SESSION['error'] = "Institution name is required for a university account.";
        } else {
            // Check duplicate email
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $_SESSION['error'] = "An account with this email address already exists.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, company_name, university_id, is_verified, ssm_number, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $ins->execute([$name, $email, $hash, $role, $institution_name, $university_id, $is_verified, $ssm_number]);
                $new_user_id = $pdo->lastInsertId();

                if ($role === 'employer') {
                    try {
                        create_company_for_new_employer($pdo, [
                            'id' => $new_user_id,
                            'name' => $name,
                            'company_name' => $institution_name,
                            'ssm_number' => $ssm_number
                        ]);
                    } catch (\Throwable $t) {}
                }

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
        if ($target_id > 0 && in_array($new_role, ['candidate', 'employer', 'admin', 'university'])) {
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

    // Action: Edit User Account (Name, Email, Password, Role, Verification)
    if ($action === 'edit_user') {
        $target_id = (int)($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'candidate';
        $is_verified = isset($_POST['is_verified']) ? 1 : 0;

        if ($target_id <= 0 || empty($name) || empty($email)) {
            $_SESSION['error'] = "User ID, Name, and Email are required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "Invalid email format.";
        } else {
            // Check if email belongs to another user
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $chk->execute([$email, $target_id]);
            if ($chk->fetch()) {
                $_SESSION['error'] = "The email address '$email' is already in use by another account.";
            } else {
                $prev_stmt = $pdo->prepare("SELECT name, email, role, is_verified FROM users WHERE id = ?");
                $prev_stmt->execute([$target_id]);
                $prev_user = $prev_stmt->fetch();

                if (!$prev_user) {
                    $_SESSION['error'] = "Account not found.";
                } else {
                    $updates = ["name = ?", "email = ?", "is_verified = ?"];
                    $params = [$name, $email, $is_verified];
                    $edit_valid = true;

                    if (in_array($role, ['candidate', 'employer', 'admin', 'university'], true)) {
                        $updates[] = "role = ?";
                        $params[] = $role;
                    }

                    // Institution name, classification & icon (university accounts only) -- gives admin
                    // full control over a university account's profile without needing
                    // to log in as that account.
                    if ($role === 'university') {
                        $uni_type = in_array($_POST['university_type'] ?? '', ['public', 'private'], true) ? $_POST['university_type'] : 'public';
                        $institution_name = trim($_POST['institution_name'] ?? '');
                        $ssm_number = trim($_POST['ssm_number'] ?? '');
                        if ($institution_name === '') {
                            $_SESSION['error'] = "University name is required for a university account.";
                            $edit_valid = false;
                        } else {
                            $chk_uni = $pdo->prepare("SELECT id FROM universities WHERE LOWER(name) = LOWER(?) LIMIT 1");
                            $chk_uni->execute([$institution_name]);
                            $existing_uni_id = $chk_uni->fetchColumn();
                            if ($existing_uni_id) {
                                $university_id = (int)$existing_uni_id;
                                $upd_uni = $pdo->prepare("UPDATE universities SET type = ?, ssm_number = ? WHERE id = ?");
                                $upd_uni->execute([$uni_type, $ssm_number, $university_id]);
                            } else {
                                $ins_uni = $pdo->prepare("INSERT INTO universities (name, type, ssm_number) VALUES (?, ?, ?)");
                                $ins_uni->execute([$institution_name, $uni_type, $ssm_number]);
                                $university_id = (int)$pdo->lastInsertId();
                            }
                            $updates[] = "company_name = ?";
                            $params[] = $institution_name;
                            $updates[] = "university_id = ?";
                            $params[] = $university_id;
                            $updates[] = "ssm_number = ?";
                            $params[] = $ssm_number;
                        }

                        $icon_file = $_FILES['university_icon'] ?? null;
                        if ($edit_valid && $icon_file && ($icon_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                            $allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
                            $max_bytes = 2 * 1024 * 1024;
                            if ($icon_file['error'] === UPLOAD_ERR_INI_SIZE || ($icon_file['size'] ?? 0) > $max_bytes) {
                                $_SESSION['error'] = "Uploaded icon exceeds the maximum allowed size (2MB).";
                                $edit_valid = false;
                            } elseif ($icon_file['error'] !== UPLOAD_ERR_OK) {
                                $_SESSION['error'] = "Icon upload failed. Please try again.";
                                $edit_valid = false;
                            } else {
                                $ext = strtolower(pathinfo($icon_file['name'], PATHINFO_EXTENSION));
                                $image_info = @getimagesize($icon_file['tmp_name']);
                                if (!in_array($ext, $allowed_ext) || $image_info === false) {
                                    $_SESSION['error'] = "Invalid icon image. Use JPG, PNG, WEBP or GIF.";
                                    $edit_valid = false;
                                } else {
                                    $icon_dir = 'uploads/university_logos/';
                                    if (!is_dir($icon_dir)) @mkdir($icon_dir, 0755, true);
                                    if (!is_dir($icon_dir) || !is_writable($icon_dir)) {
                                        $_SESSION['error'] = "Directory '$icon_dir' is not writable. Please check permissions.";
                                        $edit_valid = false;
                                    } else {
                                        foreach (glob($icon_dir . 'u' . $target_id . '.*') as $old_icon_file) {
                                            @unlink($old_icon_file);
                                        }
                                        $icon_path = $icon_dir . 'u' . $target_id . '.' . $ext;
                                        if (move_uploaded_file($icon_file['tmp_name'], $icon_path)) {
                                            $updates[] = "company_logo = ?";
                                            $params[] = $icon_path;
                                        } else {
                                            $_SESSION['error'] = "Failed to save icon.";
                                            $edit_valid = false;
                                        }
                                    }
                                }
                            }
                        }
                    }

                    if ($role === 'employer') {
                        $employer_company = trim($_POST['company_name'] ?? '');
                        $employer_ssm = trim($_POST['ssm_number'] ?? '');
                        $updates[] = "company_name = ?";
                        $params[] = $employer_company;
                        $updates[] = "ssm_number = ?";
                        $params[] = $employer_ssm;

                        // Sync with companies table if employer belongs to one
                        try {
                            $c_chk = $pdo->prepare("
                                SELECT c.id FROM companies c
                                JOIN company_members cm ON c.id = cm.company_id
                                WHERE cm.user_id = ?
                                ORDER BY cm.joined_at ASC LIMIT 1
                            ");
                            $c_chk->execute([$target_id]);
                            $c_id = $c_chk->fetchColumn();
                            if ($c_id) {
                                if ($employer_company !== '') {
                                    $pdo->prepare("UPDATE companies SET name = ?, ssm_number = ? WHERE id = ?")->execute([$employer_company, $employer_ssm, $c_id]);
                                } else {
                                    $pdo->prepare("UPDATE companies SET ssm_number = ? WHERE id = ?")->execute([$employer_ssm, $c_id]);
                                }
                            }
                        } catch (\Throwable $t) {}
                    }

                    $pw_changed = false;
                    if ($edit_valid && !empty($password)) {
                        $updates[] = "password_hash = ?";
                        $params[] = password_hash($password, PASSWORD_DEFAULT);
                        $pw_changed = true;
                    }

                    if ($edit_valid) {
                        $params[] = $target_id;
                        $upd = $pdo->prepare("UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?");
                        $upd->execute($params);

                        // If admin edited their own account, synchronize active session
                        if ($target_id === (int)$_SESSION['user_id']) {
                            $_SESSION['user_name'] = $name;
                            $_SESSION['user_email'] = $email;
                            if (in_array($role, ['candidate', 'employer', 'admin', 'university'], true)) {
                                $_SESSION['user_role'] = $role;
                            }
                        }

                        $log_detail = "Edited account #$target_id for '$name' ($email)" . ($pw_changed ? " with password update" : "");
                        log_admin_action($pdo, $admin_id, $admin_name, 'edit_user', 'user', $target_id, $log_detail);
                        $_SESSION['toast'] = "Account for '$name' updated successfully" . ($pw_changed ? " (password updated)" : "") . "!";
                    }
                }
            }
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

    // Action: Bulk Delete User Accounts
    if ($action === 'bulk_delete_users') {
        $target_ids = $_POST['user_ids'] ?? [];
        $target_ids = array_unique(array_map('intval', (array)$target_ids));
        $target_ids = array_filter($target_ids, fn($id) => $id > 0 && $id !== (int)$_SESSION['user_id']);

        if (empty($target_ids)) {
            $_SESSION['error'] = "No valid accounts selected for deletion.";
        } else {
            $placeholders = implode(',', array_fill(0, count($target_ids), '?'));
            $u_stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id IN ($placeholders)");
            $u_stmt->execute(array_values($target_ids));
            $target_users = $u_stmt->fetchAll();

            $del = $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)");
            $del->execute(array_values($target_ids));

            foreach ($target_users as $target_user) {
                log_admin_action($pdo, $admin_id, $admin_name, 'delete_user', 'user', $target_user['id'], "Deleted user account '{$target_user['name']}' ({$target_user['email']}) via bulk delete");
            }

            $count = count($target_users);
            $_SESSION['toast'] = "$count user account" . ($count === 1 ? '' : 's') . " deleted successfully.";
        }
        header("Location: admin_dashboard.php");
        exit;
    }

    // Action: Edit Job Posting (admin override — bypasses edit_job.php's
    // require_role('employer') gate, which would otherwise lock admins out)
    if ($action === 'admin_edit_job') {
        $job_id = (int)($_POST['job_id'] ?? 0);
        $new_title = trim($_POST['job_title'] ?? '');
        $new_dept = trim($_POST['department'] ?? '');
        $new_status = trim($_POST['status'] ?? 'Active');
        if (!in_array($new_status, ['Active', 'Closed'], true)) {
            $new_status = 'Active';
        }
        if ($job_id > 0 && $new_title !== '') {
            $upd = $pdo->prepare("UPDATE jobs SET job_title = ?, department = ?, status = ? WHERE id = ?");
            $upd->execute([$new_title, $new_dept, $new_status, $job_id]);

            log_admin_action($pdo, $admin_id, $admin_name, 'edit_job', 'job', $job_id, "Edited job posting '$new_title'");
            $_SESSION['toast'] = "Job posting updated successfully.";
        } else {
            $_SESSION['error'] = "Job title is required.";
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
// New signups in the last 48 hours — surfaced as a stat tile plus a "New"
// badge on each row so admins can spot fresh registrations at a glance
// without any email/SMTP dependency.
$new_signups_48h = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE created_at >= NOW() - INTERVAL 48 HOUR")->fetchColumn();

$total_jobs = (int)$pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
$active_jobs = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'Active'")->fetchColumn();
$total_candidates_eval = (int)$pdo->query("SELECT COUNT(*) FROM candidates")->fetchColumn();
$total_questionnaires = (int)$pdo->query("SELECT COUNT(*) FROM questionnaires")->fetchColumn();

// Google Gemini AI Screening Diagnostics
$env_api_key = getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: '');
$gemini_key_configured = !empty($env_api_key) || true; // Built-in Gemini engine enabled

// Gemini API Key Health Check (tests the configured key/model against live Gemini API)
$configured_api_key = get_api_key();
$gemini_start_time = microtime(true);
$key_check = check_api_key_status($configured_api_key, $_SESSION['ai_model'] ?? 'gemini-3.7-flash');
$gemini_latency_ms = round((microtime(true) - $gemini_start_time) * 1000, 2);

$key_status_map = [
    'active'          => ['label' => 'API Key Active',         'color' => '#00E87A'],
    'not_configured'  => ['label' => 'Not Configured',         'color' => '#9CA3AF'],
    'invalid_key'      => ['label' => 'Invalid API Key',        'color' => '#FF4D6A'],
    'model_not_found' => ['label' => 'Model Not Available',    'color' => '#F59E0B'],
    'unreachable'     => ['label' => 'Unreachable',             'color' => '#FF4D6A'],
    'error'           => ['label' => 'Error',                   'color' => '#FF4D6A'],
];
$gemini_status = $key_status_map[$key_check['status']]['label'] ?? 'Unknown';
$gemini_status_color = $key_status_map[$key_check['status']]['color'] ?? '#9CA3AF';
$gemini_status_detail = $key_check['message'] ?? '';

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
    SELECT j.id as job_id, j.job_title, j.department, j.status as job_status, 
           COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name,
           u.ssm_number as ssm_display,
           COUNT(cand.id) as applied,
           SUM(CASE WHEN LOWER(COALESCE(cand.status, '')) IN ('review', 'under review', 'reviewing', 'new', '') THEN 1 ELSE 0 END) as review,
           SUM(CASE WHEN LOWER(COALESCE(cand.status, '')) IN ('shortlisted', 'shortlist') THEN 1 ELSE 0 END) as shortlisted,
           SUM(CASE WHEN cand.interview_status IN ('Proposed', 'Confirmed') THEN 1 ELSE 0 END) as interviewing,
           SUM(CASE WHEN LOWER(COALESCE(cand.status, '')) = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM jobs j
    LEFT JOIN users u ON j.employer_id = u.id
    LEFT JOIN candidates cand ON cand.job_id = j.id
    GROUP BY j.id, j.job_title, j.department, j.status, u.name, u.company_name, u.ssm_number
    ORDER BY applied DESC, j.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ----------------------------------------------------
// 3. FETCH USERS & JOBS LIST FOR TABLES
// ----------------------------------------------------
$users_list = $pdo->query("
    SELECT u.*, 
           un.type as university_type,
           u.company_name as company_display_name,
           COALESCE(NULLIF(u.ssm_number, ''), NULLIF(un.ssm_number, '')) as ssm_display
    FROM users u 
    LEFT JOIN universities un ON u.university_id = un.id 
    ORDER BY u.created_at DESC
")->fetchAll();
$university_users_list = $pdo->query("
    SELECT u.*, 
           COALESCE(NULLIF(un.name, ''), NULLIF(u.company_name, ''), 'Unspecified') as institution_display,
           COALESCE(un.type, 'other') as university_type,
           COALESCE(NULLIF(u.ssm_number, ''), NULLIF(un.ssm_number, '')) as ssm_display,
           (SELECT COUNT(*) FROM users s WHERE s.role = 'candidate' AND (s.university_id = u.university_id OR (u.university_id IS NULL AND u.company_name IS NOT NULL AND LOWER(s.company_name) = LOWER(u.company_name)))) as linked_students_count
    FROM users u 
    LEFT JOIN universities un ON u.university_id = un.id 
    WHERE u.role = 'university' 
    ORDER BY u.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$new_uni_signups_48h = 0;
foreach ($university_users_list as $uu) {
    if (strtotime($uu['created_at']) >= strtotime('-48 hours')) {
        $new_uni_signups_48h++;
    }
}

$jobs_list = $pdo->query("
    SELECT j.*, 
           COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name,
           u.ssm_number as ssm_display
    FROM jobs j 
    LEFT JOIN users u ON j.employer_id = u.id 
    ORDER BY j.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard & Diagnostics - Keria</title>
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
            grid-template-columns: repeat(5, 1fr);
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
            flex-wrap: wrap;
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
            grid-template-columns: 34px 60px 1.4fr 1.6fr 110px 110px 120px 175px;
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
        .user-selection-bar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            background: var(--surf);
            border: 1px solid var(--acc);
            border-radius: 12px;
            padding: 10px 16px;
            margin-bottom: 14px;
            box-shadow: 0 4px 18px rgba(107, 138, 0, 0.12);
            transition: all 0.2s ease;
            backdrop-filter: var(--glass-blur);
            -webkit-backdrop-filter: var(--glass-blur);
        }
        .admin-tr.user-row-item {
            cursor: pointer;
            transition: background 0.15s ease, box-shadow 0.15s ease;
        }
        .admin-tr.user-row-item.is-selected {
            background: rgba(107, 138, 0, 0.08) !important;
            border-left-color: var(--acc) !important;
            box-shadow: inset 0 0 0 1px rgba(107, 138, 0, 0.35);
        }
        [data-theme="dark"] .admin-tr.user-row-item.is-selected {
            background: rgba(217, 255, 79, 0.08) !important;
            border-left-color: var(--acc) !important;
            box-shadow: inset 0 0 0 1px rgba(217, 255, 79, 0.3);
        }
        .admin-tr.accent-red    { border-left-color: #F43F5E; }
        .admin-tr.accent-amber  { border-left-color: #F59E0B; }
        .admin-tr.accent-green  { border-left-color: #10B981; }
        .admin-tr.accent-violet { border-left-color: #6366F1; }
        .admin-tr.uni-row-item {
            cursor: pointer;
            transition: background 0.15s ease, box-shadow 0.15s ease;
        }
        .admin-tr.uni-row-item.is-selected {
            background: rgba(99, 102, 241, 0.08) !important;
            border-left-color: #6366F1 !important;
            box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.35);
        }
        [data-theme="dark"] .admin-tr.uni-row-item.is-selected {
            background: rgba(99, 102, 241, 0.15) !important;
            border-left-color: #818CF8 !important;
            box-shadow: inset 0 0 0 1px rgba(129, 140, 248, 0.4);
        }
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
        .header-inner {
            max-width: 100% !important;
            padding: 0 36px !important;
            box-sizing: border-box;
        }
        main {
            max-width: 100% !important;
            width: 100% !important;
            padding: 24px 36px 40px !important;
            margin: 0 auto !important;
            box-sizing: border-box !important;
        }
        .toast-notification {
            top: 86px !important;
            z-index: 999999 !important;
        }
        @media (max-width: 640px) {
            .toast-notification {
                top: 80px !important;
                right: 16px !important;
                left: 16px !important;
                max-width: calc(100vw - 32px) !important;
            }
        }
        /* Admin tables (Registered Accounts, Job Postings Moderation) don't
           fit a phone screen no matter how the columns are sized — instead
           of squeezing them (which was the bug: the old mobile override
           only listed 5 column-tracks for a row that actually has 7
           visible cells, so Grid auto-generated unpredictable extra
           tracks and the checkbox/badges/buttons wrapped and scattered),
           each table scrolls sideways at its natural width so every
           column stays legible and aligned with its header. */
        .admin-table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        @media (max-width: 1024px) {
            .header-inner { padding: 0 16px !important; }
            main { padding: 16px 16px 32px !important; }
            .stats-summary-grid { grid-template-columns: 1fr 1fr; }
            .health-grid { grid-template-columns: 1fr; }
            .admin-table { width: max-content; min-width: 100%; }
            .admin-tr { grid-template-columns: 34px 50px 190px 190px 85px 100px 110px 185px; min-width: 750px; }
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
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="admin_dashboard.php" class="active">🛡️ Admin Control Panel</a>
                <a href="admin_live_counter.php" target="_blank" style="color:#10B981; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                    <span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:#10B981;"></span>📺 TV Live Counter
                </a>
                <a href="admin_resumes.php">📄 Resumes & Export</a>
                <a href="admin_logs.php">📜 Audit Logs</a>
                <a href="university_dashboard.php">🎓 University Dashboard</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>

            <div class="header-right-actions" style="margin-left:auto; display:flex; align-items:center; gap:10px;">
                <span class="user-info-text" style="font-size:12px; color:var(--mut);">Logged in as <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong> (System Admin)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <?php if(isset($_SESSION['toast'])): ?>
        <div class="toast-notification">
            <span class="toast-icon-badge">🌿</span>
            <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
            <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
            <?php unset($_SESSION['toast']); ?>
        </div>
    <?php endif; ?>

    <main>
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
                <p style="font-size:13px; color:var(--mut); margin-top:4px; margin-bottom:0;">Monitor user accounts, database integrity, and Google Gemini AI resume screening engine health.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                <button type="button" onclick="openUniversityQrModal()" class="btn-secondary" style="padding:11px 18px; font-size:13.5px; width:auto; display:inline-flex; gap:8px; align-items:center; border-radius:10px; cursor:pointer; font-weight:700; border:1px solid var(--bdr); background:var(--surf); color:var(--txt);">
                    <span>🎓 University Sign-Up Code</span>
                </button>
                <a href="admin_resumes.php" class="btn-secondary" style="padding:11px 20px; font-size:13.5px; width:auto; display:inline-flex; gap:8px; align-items:center; border-radius:10px; cursor:pointer; font-weight:700; border:1px solid var(--bdr); background:var(--surf); text-decoration:none;">
                    <span>📄 Resumes & Export Hub</span>
                </a>
                <button type="button" onclick="openAddUserModal()" class="btn-primary" style="padding:11px 22px; font-size:14px; width:auto; display:inline-flex; gap:8px; align-items:center; border-radius:10px; cursor:pointer;">
                    <span>+ Add User / Admin</span>
                </button>
            </div>
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
            <div class="stat-box" style="border-left:3px solid #EC4899;">
                <div class="logo-box">🆕</div>
                <div><div class="stat-val"><?= $new_signups_48h ?></div><div class="stat-lbl">New Signups (48h)</div></div>
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

            <!-- Google Gemini AI Screening Engine Health Card -->
            <div class="health-card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span style="font-size:22px;">🤖</span>
                        <div>
                            <div style="font-size:16px; font-weight:800; color:var(--txt);">Google Gemini AI Engine</div>
                            <div style="font-size:11px; color:var(--mut);">Model: <?= htmlspecialchars($_SESSION['ai_model'] ?? 'gemini-3.7-flash') ?></div>
                        </div>
                    </div>
                    <span class="badge-pill" style="background:linear-gradient(135deg, <?= $gemini_status_color ?>, <?= $gemini_status_color ?>CC); color:#fff; border:none; box-shadow:0 4px 12px <?= $gemini_status_color ?>40;">
                        ● <?= $gemini_status ?>
                    </span>
                </div>

                <div style="font-size:11px; color:<?= $gemini_status_color ?>; background:<?= $gemini_status_color ?>1A; border:1px solid <?= $gemini_status_color ?>; border-radius:8px; padding:8px 12px; margin-bottom:14px;">
                    <?= htmlspecialchars($gemini_status_detail) ?>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:var(--dim); padding:16px; border-radius:12px; font-size:12px; margin-bottom:16px;">
                    <div><span style="color:var(--mut);">Live Key Check:</span> <strong style="background:var(--grad-violet); -webkit-background-clip:text; background-clip:text; color:transparent;"><?= $gemini_latency_ms ?> ms</strong></div>
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

        <!-- Section Tabs: User Management vs University Accounts vs Job Moderation vs Funnel Analytics -->
        <div class="tab-controls">
            <button type="button" class="tab-btn active" onclick="switchAdminTab('usersTab', this)">👥 Registered Accounts (<?= count($users_list) ?>)<?php if($new_signups_48h > 0): ?> <span style="background:#EC4899; color:#fff; border-radius:8px; padding:1px 7px; font-size:10px; font-weight:800; margin-left:4px;">🆕 <?= $new_signups_48h ?> new</span><?php endif; ?></button>
            <button type="button" class="tab-btn" onclick="switchAdminTab('universitiesTab', this)">🎓 Registered Universities (<?= count($university_users_list) ?>)<?php if($new_uni_signups_48h > 0): ?> <span style="background:#EC4899; color:#fff; border-radius:8px; padding:1px 7px; font-size:10px; font-weight:800; margin-left:4px;">🆕 <?= $new_uni_signups_48h ?> new</span><?php endif; ?></button>
            <button type="button" class="tab-btn" onclick="switchAdminTab('jobsTab', this)">💼 Job Postings Moderation (<?= count($jobs_list) ?>)</button>
            <button type="button" class="tab-btn" onclick="switchAdminTab('funnelTab', this)">🔻 Platform-Wide Hiring Funnel Analytics</button>
        </div>

        <!-- Platform Hiring Funnel Analytics Panel (its own tab now, toggled via
             switchAdminTab() just like Registered Accounts / Job Postings Moderation) -->
        <div id="funnelTab" class="panel admin-tab-pane" style="margin-bottom:24px; display:none;">
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
                                    <td style="padding:10px 14px; color:var(--mut);">
                                        <div><?= htmlspecialchars($ajf['employer_name'] ?: 'System') ?></div>
                                        <?php if(!empty($ajf['ssm_display'])): ?>
                                            <div style="font-size:10px; color:var(--gold); font-weight:700; margin-top:2px; display:inline-flex; align-items:center; gap:3px;" title="SSM Registration Number">
                                                <span style="opacity:0.8;">🏢 SSM:</span> <span><?= htmlspecialchars($ajf['ssm_display']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:10px 14px; text-align:center; font-weight:700; color:var(--txt);"><?= (int)$ajf['applied'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--acc); font-weight:700;"><?= (int)$ajf['review'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--grn); font-weight:700;"><?= (int)$ajf['shortlisted'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--pur); font-weight:700;"><?= (int)$ajf['interviewing'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; color:var(--red); font-weight:700;"><?= (int)$ajf['rejected'] ?></td>
                                    <td style="padding:10px 14px; text-align:center; white-space:nowrap;">
                                        <button type="button" onclick='openEditJobModal(<?= json_encode([
                                            "job_id" => $ajf["job_id"],
                                            "job_title" => $ajf["job_title"],
                                            "department" => $ajf["department"],
                                            "status" => $ajf["job_status"],
                                        ]) ?>)' class="btn-secondary" style="padding:4px 8px; font-size:11px; margin-right:6px;">✏️ Edit</button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete the job posting &quot;<?= htmlspecialchars(addslashes($ajf['job_title'])) ?>&quot; and all its candidate data?');">
                                            <input type="hidden" name="action" value="delete_job">
                                            <input type="hidden" name="job_id" value="<?= $ajf['job_id'] ?>">
                                            <button type="submit" style="background:rgba(255, 77, 106, 0.12); border:1px solid rgba(255, 77, 106, 0.35); border-radius:6px; color:var(--red); padding:4px 10px; font-size:11px; font-weight:700; cursor:pointer;">🗑️ Delete</button>
                                        </form>
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
                        <select id="roleFilterSelect" onchange="filterUsersTable()" style="padding:9px 36px 9px 12px; font-size:13px; margin:0; width:auto;">
                            <option value="">All Roles</option>
                            <option value="candidate">Candidates</option>
                            <option value="employer">Employers</option>
                            <option value="university">Universities</option>
                            <option value="admin">Admins</option>
                        </select>
                    </div>
                </div>

                <!-- Account Selection Action Bar (Appears when admin selects account) -->
                <div id="userSelectionToolbar" class="user-selection-bar">
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <span id="bulkSelectedCount" style="font-size:12px; font-weight:800; background:var(--dim); border:1px solid var(--bdr); border-radius:20px; padding:4px 12px; color:var(--txt);">0 selected</span>
                        <div id="selectedUserPreview" style="display:none; align-items:center; gap:8px; font-size:12px; font-weight:700; color:var(--txt);">
                            <span id="selectedUserAvatar" class="avatar-chip" style="width:24px; height:24px; font-size:10px; background:var(--grad-purple);"></span>
                            <span id="selectedUserName"></span>
                            <span id="selectedUserEmail" style="color:var(--mut); font-weight:500; font-size:11.5px;"></span>
                            <span id="selectedUserRoleBadge" class="chip" style="font-size:9.5px; padding:2px 8px;"></span>
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <!-- Edit Account Button (visible when 1 account is selected) -->
                        <button type="button" id="editSelectedUserBtn" onclick="editSelectedAccount()" class="btn-primary" style="padding:7px 15px; font-size:12px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; width:auto; margin:0;">
                            ✏️ Edit Account
                        </button>

                        <!-- Delete Account Button (active when account(s) selected) -->
                        <button type="button" id="deleteSelectedUserBtn" onclick="confirmDeleteSelectedAccounts()" style="background:rgba(255, 77, 106, 0.12); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; color:var(--red); padding:7px 15px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                            🗑️ Delete Account
                        </button>

                        <!-- Clear Selection Button -->
                        <button type="button" onclick="clearUserSelection()" class="btn-secondary" style="padding:7px 12px; font-size:12px; border-radius:8px;" title="Clear Selection">
                            ✕ Deselect
                        </button>
                    </div>
                </div>

                <!-- Hidden form for bulk or single delete submission -->
                <form method="POST" id="bulkDeleteForm" style="display:none;">
                    <input type="hidden" name="action" value="bulk_delete_users">
                </form>

                <div class="admin-table-scroll">
                <div class="admin-table">
                    <div class="admin-tr admin-th">
                        <div><input type="checkbox" id="selectAllUsers" onclick="toggleSelectAllUsers(this)" title="Select All" style="width:15px; height:15px; cursor:pointer;"></div>
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
                            $u_is_new = strtotime($u['created_at']) >= strtotime('-48 hours');
                            $u_is_self = (int)$u['id'] === (int)$_SESSION['user_id'];
                        ?>
                        <div class="admin-tr user-row-item <?= $u_accent ?>"
                             data-user-id="<?= $u['id'] ?>"
                             data-user-name="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>"
                             data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>"
                             data-user-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
                             data-user-verified="<?= $u['is_verified'] ? '1' : '0' ?>"
                             data-is-self="<?= $u_is_self ? '1' : '0' ?>"
                             data-search="<?= strtolower(htmlspecialchars($u['name'] . ' ' . $u['email'] . ' ' . $u['role'] . ' ' . ($u['company_display_name'] ?? '') . ' ' . ($u['ssm_display'] ?? ''))) ?>"
                             data-role="<?= htmlspecialchars($u['role']) ?>"
                             onclick="handleUserRowClick(event, this)"
                             <?= $u_is_new ? 'style="background:rgba(236, 72, 153, 0.05);"' : '' ?>>
                            <div onclick="event.stopPropagation()">
                                <input type="checkbox" class="user-select-checkbox" value="<?= $u['id'] ?>"
                                       data-user-id="<?= $u['id'] ?>"
                                       data-user-name="<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>"
                                       data-user-email="<?= htmlspecialchars($u['email'], ENT_QUOTES) ?>"
                                       data-user-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>"
                                       data-user-verified="<?= $u['is_verified'] ? '1' : '0' ?>"
                                       data-is-self="<?= $u_is_self ? '1' : '0' ?>"
                                       data-user-company-name="<?= htmlspecialchars($u['company_display_name'] ?? $u['company_name'] ?? '', ENT_QUOTES) ?>"
                                       data-user-company-logo="<?= htmlspecialchars($u['company_logo'] ?? '', ENT_QUOTES) ?>"
                                       data-user-uni-type="<?= htmlspecialchars($u['university_type'] ?? 'public', ENT_QUOTES) ?>"
                                       data-user-ssm="<?= htmlspecialchars($u['ssm_display'] ?? '', ENT_QUOTES) ?>"
                                       onclick="event.stopPropagation(); updateBulkSelection();" style="width:15px; height:15px; cursor:pointer;">
                            </div>
                            <div style="font-weight:700; color:var(--mut);">#<?= $u['id'] ?></div>
                            <div style="font-weight:800; color:var(--txt); display:flex; flex-direction:column; gap:4px; min-width:0;">
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <span class="avatar-chip" style="background:<?= $u_grad ?>;"><?= strtoupper(substr($u['name'] ?: 'U', 0, 1)) ?></span>
                                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($u['name']) ?></span>
                                    <?php if($u_is_new): ?>
                                        <span title="Registered in the last 48 hours" style="background:#EC4899; color:#fff; border-radius:7px; padding:1px 6px; font-size:9px; font-weight:800; letter-spacing:0.3px;">🆕 NEW</span>
                                    <?php endif; ?>
                                </div>
                                <?php if($u['role'] === 'employer'): ?>
                                    <div style="display:flex; align-items:center; gap:6px; margin-left:34px; font-size:11px; flex-wrap:wrap;">
                                        <span style="color:var(--mut); font-weight:600; display:inline-flex; align-items:center; gap:3px;">
                                            🏢 <?= htmlspecialchars($u['company_display_name'] ?: 'No Company Set') ?>
                                        </span>
                                        <?php if(!empty($u['ssm_display'])): ?>
                                            <span class="chip" style="font-size:9.5px; padding:1px 7px; border-radius:5px; border:1px solid rgba(245, 158, 11, 0.35); background:rgba(245, 158, 11, 0.12); color:var(--gold); font-weight:700; display:inline-flex; align-items:center; gap:3px;" title="Company SSM Registration Number">
                                                <span>SSM:</span> <span><?= htmlspecialchars($u['ssm_display']) ?></span>
                                            </span>
                                        <?php else: ?>
                                            <span class="chip" style="font-size:9.5px; padding:1px 7px; border-radius:5px; border:1px solid rgba(239, 68, 68, 0.25); background:rgba(239, 68, 68, 0.08); color:#EF4444; font-weight:600; display:inline-flex; align-items:center; gap:3px;" title="Company SSM has not been filled yet">
                                                <span>⚠️ No SSM</span>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif($u['role'] === 'university' && !empty($u['ssm_display'])): ?>
                                    <div style="display:flex; align-items:center; gap:6px; margin-left:34px; font-size:11px;">
                                        <span class="chip" style="font-size:9.5px; padding:1px 7px; border-radius:5px; border:1px solid rgba(217, 255, 79, 0.35); background:rgba(217, 255, 79, 0.12); color:var(--txt); font-weight:700;" title="University Registration Number">
                                            <span>SSM:</span> <span><?= htmlspecialchars($u['ssm_display']) ?></span>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div style="color:var(--mut); overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?= htmlspecialchars($u['email']) ?></div>
                            <div>
                                <span class="chip <?= $u['role'] === 'admin' ? 'chip-rejected' : ($u['role'] === 'employer' ? 'chip-review' : ($u['role'] === 'university' ? 'chip-review' : 'chip-shortlisted')) ?>" style="font-size:10px;">
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
                            <div style="display:flex; gap:6px; flex-wrap:wrap;" onclick="event.stopPropagation()">
                                <!-- Edit Account Button -->
                                <button type="button" onclick='openEditUserModal(<?= json_encode([
                                    "id" => $u["id"],
                                    "name" => $u["name"],
                                    "email" => $u["email"],
                                    "role" => $u["role"],
                                    "is_verified" => (int)$u["is_verified"],
                                    "is_self" => $u_is_self,
                                    "university_type" => $u["university_type"] ?? "public",
                                    "company_name" => $u["company_display_name"] ?? $u["company_name"] ?? '',
                                    "company_logo" => $u["company_logo"] ?? '',
                                    "university_id" => $u["university_id"] ?? '',
                                    "ssm_number" => $u["ssm_display"] ?? ''
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)' class="btn-secondary" style="padding:4px 8px; font-size:10px;" title="Edit Account Name, Email, or Password">
                                    ✏️ Edit
                                </button>

                                <!-- Toggle Verify Form -->
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="toggle_verify">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <button type="submit" class="btn-secondary" style="padding:4px 8px; font-size:10px;" title="Toggle Verification Status">
                                        <?= $u['is_verified'] ? 'Unverify' : 'Verify' ?>
                                    </button>
                                </form>

                                <!-- Delete Account Form -->
                                <?php if(!$u_is_self): ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete account for <?= htmlspecialchars(addslashes($u['name'])) ?>?');">
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
        </div>

        <!-- Tab: Registered University Accounts -->
        <div id="universitiesTab" class="admin-tab-pane" style="display:none;">
            <div class="panel" style="padding:20px; margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; flex:1;">
                        <input type="text" id="uniSearchInput" onkeyup="filterUniversitiesTable()" placeholder="🔍 Search university name, contact person, or email..." style="max-width:340px; padding:9px 14px; font-size:13px; margin:0;">
                        
                        <select id="uniTypeFilterSelect" onchange="filterUniversitiesTable()" style="padding:9px 36px 9px 12px; font-size:13px; margin:0; width:auto;">
                            <option value="">All Types</option>
                            <option value="public">🏛️ Public Universities</option>
                            <option value="private">🏫 Private Universities</option>
                        </select>

                        <select id="uniStatusFilterSelect" onchange="filterUniversitiesTable()" style="padding:9px 36px 9px 12px; font-size:13px; margin:0; width:auto;">
                            <option value="">All Statuses</option>
                            <option value="1">Verified</option>
                            <option value="0">Pending OTP</option>
                        </select>
                    </div>

                    <div style="display:flex; gap:8px;">
                        <button type="button" onclick="openAddUniModal()" class="btn-primary" style="padding:9px 18px; font-size:13px; width:auto; display:inline-flex; gap:6px; align-items:center; border-radius:8px; cursor:pointer;">
                            <span>+ Add University Account</span>
                        </button>
                        <button type="button" onclick="openUniversityQrModal()" class="btn-secondary" style="padding:9px 18px; font-size:13px; width:auto; display:inline-flex; gap:6px; align-items:center; border-radius:8px; cursor:pointer;">
                            <span>🎓 University Sign-Up Code</span>
                        </button>
                    </div>
                </div>

                <!-- University Selection Action Bar (Appears when rows selected) -->
                <div id="uniSelectionToolbar" class="user-selection-bar">
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <span id="bulkUniSelectedCount" style="font-size:12px; font-weight:800; background:var(--dim); border:1px solid var(--bdr); border-radius:20px; padding:4px 12px; color:var(--txt);">0 selected</span>
                        <div id="selectedUniPreview" style="display:none; align-items:center; gap:8px; font-size:12px; font-weight:700; color:var(--txt);">
                            <span id="selectedUniName"></span>
                            <span id="selectedUniEmail" style="color:var(--mut); font-weight:500; font-size:11.5px;"></span>
                        </div>
                    </div>

                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <button type="button" id="editSelectedUniBtn" onclick="editSelectedUniAccount()" class="btn-primary" style="padding:7px 15px; font-size:12px; border-radius:8px; display:inline-flex; align-items:center; gap:6px; cursor:pointer; font-weight:700; width:auto; margin:0;">
                            ✏️ Edit University
                        </button>
                        <button type="button" id="deleteSelectedUniBtn" onclick="confirmDeleteSelectedUnis()" style="background:rgba(255, 77, 106, 0.12); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; color:var(--red); padding:7px 15px; font-size:12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
                            🗑️ Delete Account
                        </button>
                        <button type="button" onclick="clearUniSelection()" class="btn-secondary" style="padding:7px 12px; font-size:12px; border-radius:8px;" title="Clear Selection">
                            ✕ Deselect
                        </button>
                    </div>
                </div>

                <!-- Hidden form for bulk delete submission -->
                <form method="POST" id="bulkDeleteUnisForm" style="display:none;">
                    <input type="hidden" name="action" value="bulk_delete_users">
                </form>

                <?php if (empty($university_users_list)): ?>
                    <div style="text-align:center; padding:48px 20px; background:var(--dim); border-radius:14px; border:1px dashed var(--bdr);">
                        <div style="font-size:36px; margin-bottom:12px;">🎓</div>
                        <div style="font-size:16px; font-weight:800; color:var(--txt); margin-bottom:6px;">No University Accounts Registered Yet</div>
                        <p style="font-size:12.5px; color:var(--mut); max-width:440px; margin:0 auto 18px auto;">
                            Share the university sign-up QR code or direct registration link with university career centers or liaisons to begin registering campus accounts.
                        </p>
                        <button type="button" onclick="openUniversityQrModal()" class="btn-primary" style="padding:10px 22px; font-size:13px; display:inline-flex; align-items:center; gap:8px;">
                            <span>🎓 Open Sign-Up QR Code Modal</span>
                        </button>
                    </div>
                <?php else: ?>
                    <div class="admin-table-scroll">
                    <div class="admin-table">
                        <div class="admin-tr admin-th" style="grid-template-columns: 36px 50px 1.5fr 1.3fr 105px 95px 105px 145px; min-width:860px;">
                            <div><input type="checkbox" id="selectAllUnis" onclick="toggleSelectAllUnis(this)" title="Select All" style="width:15px; height:15px; cursor:pointer;"></div>
                            <div>ID</div>
                            <div>University / Institution</div>
                            <div>Representative & Email</div>
                            <div>Status</div>
                            <div>Students</div>
                            <div class="admin-th-hide-mobile">Joined</div>
                            <div>Actions</div>
                        </div>

                        <?php foreach($university_users_list as $uu): ?>
                            <?php
                                $uu_is_new = strtotime($uu['created_at']) >= strtotime('-48 hours');
                                $uu_type_label = $uu['university_type'] === 'public' ? 'Public' : ($uu['university_type'] === 'private' ? 'Private' : 'Other / Custom');
                                $uu_type_badge = $uu['university_type'] === 'public'
                                    ? 'background:rgba(16, 185, 129, 0.12); color:var(--grn);'
                                    : ($uu['university_type'] === 'private'
                                        ? 'background:rgba(99, 102, 241, 0.12); color:#6366F1;'
                                        : 'background:rgba(245, 158, 11, 0.12); color:var(--yel);');
                            ?>
                            <div class="admin-tr uni-row-item accent-violet"
                                 style="grid-template-columns: 36px 50px 1.5fr 1.3fr 105px 95px 105px 145px; min-width:860px; <?= $uu_is_new ? 'background:rgba(236, 72, 153, 0.05);' : '' ?>"
                                 data-uni-id="<?= $uu['id'] ?>"
                                 data-uni-name="<?= htmlspecialchars($uu['name'], ENT_QUOTES) ?>"
                                 data-uni-email="<?= htmlspecialchars($uu['email'], ENT_QUOTES) ?>"
                                 data-uni-institution="<?= htmlspecialchars($uu['institution_display'], ENT_QUOTES) ?>"
                                 data-uni-company-name="<?= htmlspecialchars($uu['company_name'] ?? '', ENT_QUOTES) ?>"
                                 data-uni-company-logo="<?= htmlspecialchars($uu['company_logo'] ?? '', ENT_QUOTES) ?>"
                                 data-uni-university-id="<?= htmlspecialchars($uu['university_id'] ?? '', ENT_QUOTES) ?>"
                                 data-uni-ssm="<?= htmlspecialchars($uu['ssm_display'] ?? '', ENT_QUOTES) ?>"
                                 data-uni-verified="<?= $uu['is_verified'] ? '1' : '0' ?>"
                                 data-type="<?= htmlspecialchars($uu['university_type']) ?>"
                                 data-status="<?= $uu['is_verified'] ? '1' : '0' ?>"
                                 data-search="<?= strtolower(htmlspecialchars($uu['institution_display'] . ' ' . $uu['name'] . ' ' . $uu['email'] . ' ' . $uu_type_label . ' ' . ($uu['ssm_display'] ?? ''))) ?>"
                                 onclick="handleUniRowClick(event, this)">
                                
                                <div onclick="event.stopPropagation()">
                                    <input type="checkbox" class="uni-select-checkbox" value="<?= $uu['id'] ?>"
                                           data-uni-id="<?= $uu['id'] ?>"
                                           data-uni-name="<?= htmlspecialchars($uu['name'], ENT_QUOTES) ?>"
                                           data-uni-email="<?= htmlspecialchars($uu['email'], ENT_QUOTES) ?>"
                                           data-uni-institution="<?= htmlspecialchars($uu['institution_display'], ENT_QUOTES) ?>"
                                           data-uni-company-name="<?= htmlspecialchars($uu['company_name'] ?? '', ENT_QUOTES) ?>"
                                           data-uni-company-logo="<?= htmlspecialchars($uu['company_logo'] ?? '', ENT_QUOTES) ?>"
                                           data-uni-university-id="<?= htmlspecialchars($uu['university_id'] ?? '', ENT_QUOTES) ?>"
                                           data-uni-type="<?= htmlspecialchars($uu['university_type'] ?? 'public', ENT_QUOTES) ?>"
                                           data-uni-ssm="<?= htmlspecialchars($uu['ssm_display'] ?? '', ENT_QUOTES) ?>"
                                           data-uni-verified="<?= $uu['is_verified'] ? '1' : '0' ?>"
                                           onclick="event.stopPropagation(); updateBulkUniSelection();" style="width:15px; height:15px; cursor:pointer;">
                                </div>

                                <div style="font-weight:700; color:var(--mut);">#<?= $uu['id'] ?></div>

                                <div style="font-weight:800; color:var(--txt); display:flex; align-items:center; gap:10px;">
                                    <?php if (!empty($uu['company_logo'])): ?>
                                        <img src="<?= htmlspecialchars($uu['company_logo']) ?>?v=<?= time() ?>" alt="Icon" style="width:30px; height:30px; object-fit:contain; border-radius:8px; background:#fff; padding:3px; border:1px solid var(--bdr); flex-shrink:0;">
                                    <?php else: ?>
                                        <div style="width:30px; height:30px; border-radius:8px; background:rgba(99, 102, 241, 0.12); display:flex; align-items:center; justify-content:center; font-size:15px; border:1px solid rgba(99, 102, 241, 0.25); flex-shrink:0;">🏛️</div>
                                    <?php endif; ?>
                                    <div style="min-width:0;">
                                        <div style="font-size:13px; font-weight:800; color:var(--txt); line-height:1.25; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($uu['institution_display']) ?>">
                                            <?= htmlspecialchars($uu['institution_display']) ?>
                                        </div>
                                        <div style="display:flex; gap:6px; align-items:center; margin-top:3px; flex-wrap:wrap;">
                                            <span class="chip" style="font-size:9.5px; padding:1px 6px; text-transform:uppercase; border-color:transparent; font-weight:700; <?= $uu_type_badge ?>">
                                                <?= htmlspecialchars($uu_type_label) ?>
                                            </span>
                                            <?php if (!empty($uu['ssm_display'])): ?>
                                                <span class="chip" style="font-size:9.5px; padding:1px 7px; border-radius:6px; border:1px solid rgba(217, 255, 79, 0.35); background:rgba(217, 255, 79, 0.12); color:var(--txt); font-weight:700; display:inline-flex; align-items:center; gap:4px;" title="Official SSM / Registration Number">
                                                    <span style="opacity:0.7; font-size:9px;">🏢 SSM:</span> <span><?= htmlspecialchars($uu['ssm_display']) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="chip" style="font-size:9.5px; padding:1px 7px; border-radius:6px; border:1px solid rgba(239, 68, 68, 0.3); background:rgba(239, 68, 68, 0.1); color:#EF4444; font-weight:700; display:inline-flex; align-items:center; gap:3px;" title="SSM Registration Number has not been filled yet">
                                                    <span>⚠️ No SSM</span>
                                                </span>
                                            <?php endif; ?>
                                            <?php if($uu_is_new): ?>
                                                <span title="Registered in the last 48 hours" style="background:#EC4899; color:#fff; border-radius:6px; padding:1px 6px; font-size:9px; font-weight:800; letter-spacing:0.3px;">🆕 NEW</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <div style="font-size:12.5px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($uu['name']) ?></div>
                                    <div style="font-size:11.5px; color:var(--mut); font-family:monospace;"><?= htmlspecialchars($uu['email']) ?></div>
                                </div>

                                <div>
                                    <?php if ($uu['is_verified']): ?>
                                        <span class="chip" style="font-size:10px; background:rgba(16, 185, 129, 0.12); color:var(--grn); border-color:transparent;">✅ Verified</span>
                                    <?php else: ?>
                                        <span class="chip" style="font-size:10px; background:rgba(245, 158, 11, 0.12); color:var(--yel); border-color:transparent;">⏳ Pending OTP</span>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <span class="chip" style="font-size:10.5px; font-weight:700; background:var(--dim); color:var(--txt);">
                                        👥 <?= (int)$uu['linked_students_count'] ?>
                                    </span>
                                </div>

                                <div class="admin-th-hide-mobile" style="font-size:11.5px; color:var(--mut);">
                                    <?= date('M j, Y', strtotime($uu['created_at'])) ?>
                                </div>

                                <div style="display:flex; gap:6px; align-items:center;" onclick="event.stopPropagation()">
                                    <button type="button" class="btn-secondary" style="padding:5px 10px; font-size:11px; border-radius:6px; display:inline-flex; align-items:center; gap:4px; font-weight:700;" onclick="openEditUserModal({
                                        id: <?= (int)$uu['id'] ?>,
                                        name: '<?= addslashes(htmlspecialchars($uu['name'])) ?>',
                                        email: '<?= addslashes(htmlspecialchars($uu['email'])) ?>',
                                        role: 'university',
                                        is_verified: <?= $uu['is_verified'] ? '1' : '0' ?>,
                                        university_type: '<?= htmlspecialchars($uu['university_type'] ?? 'public') ?>',
                                        institution_name: '<?= addslashes(htmlspecialchars($uu['company_name'] ?: $uu['institution_display'])) ?>',
                                        company_name: '<?= addslashes(htmlspecialchars($uu['company_name'] ?? '')) ?>',
                                        company_logo: '<?= addslashes(htmlspecialchars($uu['company_logo'] ?? '')) ?>',
                                        ssm_number: '<?= addslashes(htmlspecialchars($uu['ssm_display'] ?? '')) ?>'
                                    })">✏️ Edit</button>

                                    <form method="POST" onsubmit="return confirm('Permanently delete university account for <?= addslashes(htmlspecialchars($uu['name'])) ?> (<?= addslashes(htmlspecialchars($uu['institution_display'])) ?>)?');" style="margin:0; display:inline;">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $uu['id'] ?>">
                                        <button type="submit" style="background:rgba(255, 77, 106, 0.12); border:1px solid rgba(255, 77, 106, 0.35); border-radius:6px; color:var(--red); padding:5px 9px; font-size:11px; font-weight:700; cursor:pointer;" title="Delete University Account">🗑️</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab 2: Job Postings Moderation -->
        <div id="jobsTab" class="admin-tab-pane" style="display:none;">
            <div class="panel" style="padding:20px;">
                <div class="admin-table-scroll">
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
                            <div style="color:var(--mut);">
                                <div><?= htmlspecialchars($j['employer_name'] ?? 'System') ?></div>
                                <?php if(!empty($j['ssm_display'])): ?>
                                    <div style="font-size:10px; color:var(--gold); font-weight:700; margin-top:2px; display:inline-flex; align-items:center; gap:3px;" title="SSM Registration Number">
                                        <span style="opacity:0.8;">🏢 SSM:</span> <span><?= htmlspecialchars($j['ssm_display']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
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
        </div>

    </main>

    <!-- Modal: University Sign-Up QR Code -->
    <div id="universityQrModal" onclick="if(event.target === this) closeUniversityQrModal()" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:580px; width:100%; position:relative; border-radius:18px; box-shadow:var(--shadow-lg); padding:24px; max-height:90vh; overflow-y:auto;">
            <button type="button" onclick="closeUniversityQrModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>

            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:24px;">🎓</div>
                <div>
                    <div style="font-size:20px; font-weight:800; color:var(--txt);">University Sign-Up Code</div>
                    <div style="font-size:12px; color:var(--mut);">Instant QR code & sign-up link for career fairs and university liaisons</div>
                </div>
            </div>

            <p style="font-size:12.5px; color:var(--mut); margin:8px 0 16px 0; line-height:1.5;">
                Print this at a career fair or share it with a university's career center. Scanning it opens Keria's
                university registration page, pre-set to sign up as a <strong>University / Career Center representative</strong>.
            </p>

            <div style="margin-bottom:16px;">
                <div style="display:grid; grid-template-columns: 140px 1fr; gap:10px; margin-bottom:8px;">
                    <div>
                        <label for="uqrUniType" style="display:block; font-size:12px; color:var(--mut); font-weight:700; margin-bottom:6px;">Classification</label>
                        <select id="uqrUniType" onchange="renderUniversityQr()" style="padding:10px 12px; font-size:13px; width:100%; border-radius:10px;">
                            <option value="public">🏛️ Public</option>
                            <option value="private">🏫 Private</option>
                        </select>
                    </div>
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                            <label for="uqrUniInput" style="font-size:12px; color:var(--mut); font-weight:700;">University Name</label>
                            <span style="font-size:11px; color:var(--mut);">Optional</span>
                        </div>
                        <div style="position:relative;">
                            <input type="text" id="uqrUniInput" list="uqrUniDatalist" placeholder="e.g. Universiti Malaya, Taylor's University..." oninput="renderUniversityQr()" autocomplete="off" style="padding:10px 38px 10px 14px; font-size:13px; width:100%; border-radius:10px;">
                            <button type="button" onclick="clearUniversityQrInput()" id="uqrClearBtn" style="display:none; position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--mut); font-size:14px; cursor:pointer; padding:4px;" title="Clear">✕</button>
                        </div>
                    </div>
                </div>
                <datalist id="uqrUniDatalist">
                    <?php foreach ($universities_list as $u): ?>
                        <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['type']) ?>)</option>
                    <?php endforeach; ?>
                </datalist>
                <div style="font-size:11px; color:var(--mut); margin-top:5px;">Select Public or Private and enter the university name. The link and QR code will update dynamically to pre-fill this on registration.</div>
            </div>

            <div style="display:flex; gap:18px; flex-wrap:wrap; align-items:center; background:var(--dim); padding:16px; border-radius:14px; border:1px solid var(--bdr);">
                <div style="flex:1; min-width:240px;">
                    <label style="display:block; font-size:11.5px; color:var(--mut); margin-bottom:6px; font-weight:700;">Direct Registration Link</label>
                    <div style="display:flex; gap:8px; margin-bottom:12px;">
                        <input type="text" readonly id="uqrLinkInput" value="<?= htmlspecialchars($university_signup_link) ?>" style="flex:1; font-family:monospace; font-size:11.5px; padding:8px 10px;">
                        <button type="button" id="uqrCopyBtn" class="btn-secondary" style="padding:8px 14px; font-size:12px; white-space:nowrap; border-radius:8px;" onclick="copyUniversityQrLink()">📋 Copy</button>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <button type="button" class="btn-secondary" style="padding:8px 14px; font-size:12px; border-radius:8px; display:inline-flex; align-items:center; gap:6px;" onclick="downloadUniversityQr()">
                            <span>⬇️ Download QR (PNG)</span>
                        </button>
                        <a id="uqrOpenLinkBtn" href="<?= htmlspecialchars($university_signup_link) ?>" target="_blank" class="btn-secondary" style="padding:8px 14px; font-size:12px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                            <span>🔗 Test Link &rarr;</span>
                        </a>
                    </div>
                </div>
                <div style="text-align:center; padding:4px;">
                    <div id="uqrCode" style="width:160px; height:160px; display:flex; align-items:center; justify-content:center; background:#fff; border-radius:12px; border:1px solid var(--bdr); padding:8px; margin:0 auto; box-shadow:var(--shadow-xs);"></div>
                    <div style="font-size:11px; color:var(--mut); margin-top:6px; font-weight:600;">Scan to register</div>
                </div>
            </div>

            <div style="margin-top:18px; display:flex; justify-content:flex-end;">
                <button type="button" onclick="closeUniversityQrModal()" class="btn-secondary" style="padding:9px 20px; font-size:13px; border-radius:8px;">Close</button>
            </div>
        </div>
    </div>

    <!-- Modal: Edit Job Posting (admin override) -->
    <div id="editJobModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:440px; width:100%; position:relative; border-radius:18px; box-shadow:var(--shadow-lg);">
            <button type="button" onclick="closeEditJobModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>

            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:22px;">✏️</div>
                <div style="font-size:20px; font-weight:800; color:var(--txt);">Edit Job Posting</div>
            </div>
            <p style="font-size:12px; color:var(--mut); margin-bottom:20px;">Admin override — updates this job posting directly.</p>

            <form method="POST">
                <input type="hidden" name="action" value="admin_edit_job">
                <input type="hidden" name="job_id" id="editJobId" value="">

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Job Title</label>
                    <input type="text" name="job_title" id="editJobTitle" required style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Department</label>
                    <input type="text" name="department" id="editJobDepartment" placeholder="e.g. Engineering" style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:20px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Status</label>
                    <select name="status" id="editJobStatus" style="padding:10px 14px; font-size:13px; width:100%;">
                        <option value="Active">Active</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>

                <button type="submit" class="btn-primary" style="padding:11px; font-size:13.5px; width:100%; border-radius:10px;">Save Changes</button>
            </form>
        </div>
    </div>

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
                    <select name="role" id="addUserRole" required style="padding:10px 12px; font-size:13px;" onchange="toggleAddUserRoleFields()">
                        <option value="candidate">Candidate</option>
                        <option value="employer">Employer</option>
                        <option value="university">University</option>
                        <option value="admin">System Admin</option>
                    </select>
                </div>

                <div id="addUserInstitutionField" style="display:none; margin-bottom:16px;">
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">University Classification</label>
                        <select name="university_type" id="addUserUniType" style="padding:10px 14px; font-size:13px;">
                            <option value="public">🏛️ Public University</option>
                            <option value="private">🏫 Private University</option>
                        </select>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">University Name</label>
                        <input type="text" name="institution_name" id="addUserInstitutionName" list="knownUniversitiesList" placeholder="e.g. Universiti Malaya, Taylor's University" style="padding:10px 14px; font-size:13px;">
                    </div>
                    <div style="margin-bottom:6px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">SSM / Registration Number</label>
                        <input type="text" name="ssm_number" id="addUserInstitutionSsm" placeholder="e.g. 201201012345 (1012345-X)" style="padding:10px 14px; font-size:13px;">
                    </div>
                    <div style="font-size:10.5px; color:var(--mut); margin-top:4px;">The institution icon can be uploaded after creation via Edit Account.</div>
                </div>

                <div id="addUserEmployerField" style="display:none; margin-bottom:16px;">
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Company Name</label>
                        <input type="text" name="company_name" id="addUserEmployerName" placeholder="e.g. Acme Corporation Sdn Bhd" style="padding:10px 14px; font-size:13px;">
                    </div>
                    <div style="margin-bottom:6px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Company SSM Registration Number</label>
                        <input type="text" name="ssm_number" id="addUserEmployerSsm" placeholder="e.g. 201201012345 (1012345-X)" style="padding:10px 14px; font-size:13px;">
                    </div>
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

    <!-- Modal: Edit User Account (Name, Email, Password, Role) -->
    <div id="editUserModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:490px; width:100%; position:relative; border-radius:18px; box-shadow:var(--shadow-lg);">
            <button type="button" onclick="closeEditUserModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:22px;">✏️</div>
                <div>
                    <div style="font-size:20px; font-weight:800; color:var(--txt);">Edit User Account</div>
                    <div id="editModalSubTitle" style="font-size:12px; color:var(--mut);">Update account name, email and password credentials</div>
                </div>
            </div>

            <form method="POST" style="margin-top:16px;" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="editUserId" value="">

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Account Name</label>
                    <input type="text" name="name" id="editUserName" placeholder="Full Name" required style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Email Address</label>
                    <input type="email" name="email" id="editUserEmail" placeholder="user@domain.com" required style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <label style="font-size:12px; color:var(--mut); font-weight:700;">Password</label>
                        <span style="font-size:11px; color:var(--mut);">Leave blank to keep unchanged</span>
                    </div>
                    <div style="position:relative;">
                        <input type="password" name="password" id="editUserPassword" placeholder="Enter new password (optional)" autocomplete="new-password" style="padding:10px 42px 10px 14px; font-size:13px; margin:0;">
                        <button type="button" onclick="toggleEditPasswordVisibility()" style="position:absolute; right:10px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--mut); font-size:15px; cursor:pointer; padding:4px;" title="Toggle password visibility">👁️</button>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Account Role</label>
                    <select name="role" id="editUserRole" required style="padding:10px 12px; font-size:13px;" onchange="toggleEditUserRoleFields()">
                        <option value="candidate">Candidate</option>
                        <option value="employer">Employer</option>
                        <option value="university">University</option>
                        <option value="admin">System Admin</option>
                    </select>
                </div>

                <div id="editUserEmployerBlock" style="display:none;">
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Company Name</label>
                        <input type="text" name="company_name" id="editEmployerCompanyName" placeholder="e.g. Acme Corporation Sdn Bhd" style="padding:10px 14px; font-size:13px;">
                    </div>
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Company SSM Registration Number</label>
                        <input type="text" name="ssm_number" id="editEmployerSsm" placeholder="e.g. 201201012345 (1012345-X)" style="padding:10px 14px; font-size:13px;">
                    </div>
                </div>

                <div id="editUserInstitutionBlock" style="display:none;">
                    <div style="margin-bottom:12px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">University Classification</label>
                        <select name="university_type" id="editUserUniType" style="padding:10px 14px; font-size:13px;">
                            <option value="public">🏛️ Public University</option>
                            <option value="private">🏫 Private University</option>
                        </select>
                    </div>
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">University Name</label>
                        <input type="text" name="institution_name" id="editInstitutionName" list="knownUniversitiesList" placeholder="e.g. Universiti Malaya" style="padding:10px 14px; font-size:13px;">
                    </div>
                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">SSM / Registration Number</label>
                        <input type="text" name="ssm_number" id="editInstitutionSsm" placeholder="e.g. 201201012345 (1012345-X) or DU001(B)" style="padding:10px 14px; font-size:13px;">
                    </div>

                    <div style="margin-bottom:14px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Institution Icon</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div id="editInstitutionIconPreview" style="width:40px; height:40px; border-radius:9px; border:1px solid var(--bdr); background:var(--surf); display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:18px; color:var(--acc); flex-shrink:0;">🎓</div>
                            <label class="btn-secondary" style="padding:7px 14px; font-size:11.5px; cursor:pointer; margin:0;">
                                🖼️ Choose Icon
                                <input type="file" name="university_icon" accept=".jpg,.jpeg,.png,.webp,.gif" style="display:none;">
                            </label>
                            <span style="font-size:10.5px; color:var(--mut);">JPG, PNG, WEBP or GIF -- up to 2MB</span>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom:20px; padding:10px 14px; background:var(--dim); border-radius:10px; display:flex; align-items:center; gap:10px;">
                    <input type="checkbox" name="is_verified" id="editUserVerified" value="1" style="width:18px; height:18px; cursor:pointer;">
                    <label for="editUserVerified" style="font-size:12px; font-weight:600; color:var(--txt); cursor:pointer;">
                        ✓ Mark Account Email as Verified
                    </label>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeEditUserModal()" class="btn-secondary" style="padding:10px 18px; font-size:13px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding:10px 22px; width:auto; font-size:13px; border-radius:10px;">💾 Save Changes &rarr;</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Export Resumes ZIP with Exact / Range Date -->
    <div id="exportResumesModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:520px; width:100%; position:relative; border-radius:18px; box-shadow:var(--shadow-lg);">
            <button type="button" onclick="closeExportResumesModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:24px;">📦</div>
                <div>
                    <div style="font-size:20px; font-weight:800; color:var(--txt);">Export Candidate Resumes</div>
                    <div style="font-size:11px; color:var(--mut);">Package resumes into a ZIP archive with ATS manifest</div>
                </div>
            </div>

            <form method="GET" action="export_resumes.php" style="margin-top:16px;">
                
                <!-- Date Filter Mode Selector -->
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:8px; font-weight:700;">Select Date Criteria</label>
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; background:var(--dim); padding:4px; border-radius:12px; border:1px solid var(--bdr);">
                        <button type="button" id="btnDateModeAll" onclick="setExportDateMode('all')" style="padding:8px 10px; font-size:12px; font-weight:700; border-radius:8px; border:none; cursor:pointer; background:var(--surf); color:var(--txt); box-shadow:var(--shadow-xs); transition:all 0.2s;">
                            🌐 All Time
                        </button>
                        <button type="button" id="btnDateModeExact" onclick="setExportDateMode('exact')" style="padding:8px 10px; font-size:12px; font-weight:700; border-radius:8px; border:none; cursor:pointer; background:transparent; color:var(--mut); transition:all 0.2s;">
                            📅 Exact Date
                        </button>
                        <button type="button" id="btnDateModeRange" onclick="setExportDateMode('range')" style="padding:8px 10px; font-size:12px; font-weight:700; border-radius:8px; border:none; cursor:pointer; background:transparent; color:var(--mut); transition:all 0.2s;">
                            🗓️ Date Range
                        </button>
                    </div>
                    <input type="hidden" name="date_mode" id="exportDateModeInput" value="all">
                </div>

                <!-- Exact Date Picker Container -->
                <div id="exactDateContainer" style="display:none; margin-bottom:16px; background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Exact Application Date</label>
                    <input type="date" name="exact_date" id="exportExactDateInput" value="<?= date('Y-m-d') ?>" style="padding:10px 14px; font-size:13px; margin:0;">
                    <div style="font-size:11px; color:var(--mut); margin-top:6px;">Exports candidates who submitted their resume on this specific date.</div>
                </div>

                <!-- Date Range Pickers Container -->
                <div id="rangeDateContainer" style="display:none; margin-bottom:16px; background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:14px;">
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                        <div>
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">From Date (Start)</label>
                            <input type="date" name="start_date" id="exportStartDateInput" value="<?= date('Y-m-01') ?>" style="padding:10px 14px; font-size:13px; margin:0;">
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">To Date (End)</label>
                            <input type="date" name="end_date" id="exportEndDateInput" value="<?= date('Y-m-d') ?>" style="padding:10px 14px; font-size:13px; margin:0;">
                        </div>
                    </div>
                    <div style="font-size:11px; color:var(--mut); margin-top:8px;">Exports candidates who applied within this inclusive date window.</div>
                </div>

                <!-- Job Filter Dropdown -->
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Job Position Filter</label>
                    <select name="job_id" style="padding:10px 12px; font-size:13px; margin:0;">
                        <option value="all">📁 All Job Openings (Platform-wide)</option>
                        <?php foreach($jobs_list as $job_opt): ?>
                            <option value="<?= $job_opt['id'] ?>">💼 <?= htmlspecialchars($job_opt['job_title']) ?> (<?= htmlspecialchars($job_opt['employer_name'] ?? 'Direct') ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Archive Package Contents Info Box -->
                <div style="background:var(--dim); border:1px solid var(--bdr); border-radius:12px; padding:12px 14px; margin-bottom:20px; font-size:11.5px; color:var(--mut);">
                    <div style="font-weight:700; color:var(--txt); margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                        <span>📦 Included in Archive</span>
                    </div>
                    <ul style="margin:4px 0 0 16px; padding:0; line-height:1.5;">
                        <li>Candidate Resume files (<code>.pdf</code>) organized and clearly labeled</li>
                        <li><code>resume_export_manifest.csv</code> (ATS scores, contact & candidate info)</li>
                        <li><code>README.txt</code> with export parameters and timestamp</li>
                    </ul>
                </div>
                
                <!-- Action Buttons -->
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeExportResumesModal()" class="btn-secondary" style="padding:10px 18px; font-size:13px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding:10px 22px; width:auto; font-size:13px; border-radius:10px; display:inline-flex; align-items:center; gap:8px;">
                        <span>📥 Download ZIP Archive &rarr;</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <datalist id="knownUniversitiesList">
        <?php foreach ($universities_list as $u): ?>
            <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['type']) ?>)</option>
        <?php endforeach; ?>
    </datalist>

    <script src="qrcode.js?v=<?php echo @filemtime(__DIR__.'/qrcode.js'); ?>"></script>
    <script>
        var UQR_BASE_LINK = <?= json_encode($university_signup_link) ?>;
        var UNI_MAP = <?= json_encode(array_column($universities_list, 'id', 'name')) ?>;

        function openUniversityQrModal() {
            var modal = document.getElementById('universityQrModal');
            if (modal) modal.style.display = 'flex';
            renderUniversityQr();
            var input = document.getElementById('uqrUniInput');
            if (input) setTimeout(function(){ input.focus(); }, 60);
        }

        function closeUniversityQrModal() {
            var modal = document.getElementById('universityQrModal');
            if (modal) modal.style.display = 'none';
        }

        function clearUniversityQrInput() {
            var input = document.getElementById('uqrUniInput');
            if (input) {
                input.value = '';
                renderUniversityQr();
                input.focus();
            }
        }

        function buildUniversityQrLink() {
            var input = document.getElementById('uqrUniInput');
            var uniName = input ? input.value.trim() : '';
            var typeEl = document.getElementById('uqrUniType');
            var uniType = typeEl ? typeEl.value : 'public';
            var clearBtn = document.getElementById('uqrClearBtn');
            if (clearBtn) {
                clearBtn.style.display = uniName ? 'block' : 'none';
            }

            if (!uniName) {
                return UQR_BASE_LINK;
            }

            // Check if name matches a known university in UNI_MAP (case-insensitive)
            var lowerName = uniName.toLowerCase();
            var matchedId = null;
            for (var name in UNI_MAP) {
                if (name.toLowerCase() === lowerName) {
                    matchedId = UNI_MAP[name];
                    break;
                }
            }

            var link = UQR_BASE_LINK + '&university_name=' + encodeURIComponent(uniName) + '&university_type=' + encodeURIComponent(uniType);
            if (matchedId) {
                link += '&university_id=' + encodeURIComponent(matchedId);
            }
            return link;
        }

        function renderUniversityQr() {
            var link = buildUniversityQrLink();
            var linkInput = document.getElementById('uqrLinkInput');
            if (linkInput) linkInput.value = link;

            var openBtn = document.getElementById('uqrOpenLinkBtn');
            if (openBtn) openBtn.href = link;

            var holder = document.getElementById('uqrCode');
            if (!holder) return;
            holder.innerHTML = '';
            try {
                if (typeof qrcode === 'function') {
                    var qr = qrcode(0, 'M');
                    qr.addData(link);
                    qr.make();
                    holder.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
                } else {
                    holder.textContent = 'QR unavailable';
                }
            } catch (e) {
                holder.textContent = 'QR unavailable';
            }
        }

        function copyUniversityQrLink() {
            var input = document.getElementById('uqrLinkInput');
            var btn = document.getElementById('uqrCopyBtn');
            if (!input) return;
            input.select();
            input.setSelectionRange(0, 99999);
            var val = input.value;
            function flashCopied() {
                if (btn) {
                    var origText = btn.innerHTML;
                    btn.innerHTML = '✓ Copied!';
                    btn.style.color = 'var(--acc)';
                    setTimeout(function(){ btn.innerHTML = origText; btn.style.color = ''; }, 2000);
                }
            }
            if (navigator.clipboard) {
                navigator.clipboard.writeText(val).then(flashCopied).catch(function(){
                    document.execCommand('copy');
                    flashCopied();
                });
            } else {
                document.execCommand('copy');
                flashCopied();
            }
        }

        function downloadUniversityQr() {
            var svg = document.querySelector('#uqrCode svg');
            if (!svg) return;
            var svgData = new XMLSerializer().serializeToString(svg);
            var img = new Image();
            var svgBlob = new Blob([svgData], { type: 'image/svg+xml;charset=utf-8' });
            var url = URL.createObjectURL(svgBlob);
            img.onload = function() {
                var scale = 4;
                var canvas = document.createElement('canvas');
                canvas.width = img.width * scale;
                canvas.height = img.height * scale;
                var ctx = canvas.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                var a = document.createElement('a');
                a.download = 'keria-university-signup-qr.png';
                a.href = canvas.toDataURL('image/png');
                a.click();
            };
            img.src = url;
        }

        function openEditJobModal(job) {
            document.getElementById('editJobId').value = job.job_id || '';
            document.getElementById('editJobTitle').value = job.job_title || '';
            document.getElementById('editJobDepartment').value = job.department || '';
            document.getElementById('editJobStatus').value = (job.status === 'Closed') ? 'Closed' : 'Active';
            document.getElementById('editJobModal').style.display = 'flex';
        }
        function closeEditJobModal() {
            document.getElementById('editJobModal').style.display = 'none';
        }

        function openAddUserModal() {
            var ssmInput = document.getElementById('addUserInstitutionSsm');
            if (ssmInput) ssmInput.value = '';
            var nameInput = document.getElementById('addUserInstitutionName');
            if (nameInput) nameInput.value = '';
            var empName = document.getElementById('addUserEmployerName');
            if (empName) empName.value = '';
            var empSsm = document.getElementById('addUserEmployerSsm');
            if (empSsm) empSsm.value = '';
            document.getElementById('addUserModal').style.display = 'flex';
        }
        function openAddUniModal() {
            openAddUserModal();
            var role = document.getElementById('addUserRole');
            if (role) {
                role.value = 'university';
                toggleAddUserRoleFields();
            }
            var nameInput = document.getElementById('addUserInstitutionName');
            if (nameInput) setTimeout(function(){ nameInput.focus(); }, 80);
        }
        function closeAddUserModal() {
            document.getElementById('addUserModal').style.display = 'none';
        }
        function toggleAddUserRoleFields() {
            var role = document.getElementById('addUserRole').value;
            var uniField = document.getElementById('addUserInstitutionField');
            var empField = document.getElementById('addUserEmployerField');
            if (uniField) uniField.style.display = (role === 'university') ? 'block' : 'none';
            if (empField) empField.style.display = (role === 'employer') ? 'block' : 'none';
        }

        function openExportResumesModal() {
            document.getElementById('exportResumesModal').style.display = 'flex';
        }
        function closeExportResumesModal() {
            document.getElementById('exportResumesModal').style.display = 'none';
        }

        function setExportDateMode(mode) {
            document.getElementById('exportDateModeInput').value = mode;
            
            var btnAll = document.getElementById('btnDateModeAll');
            var btnExact = document.getElementById('btnDateModeExact');
            var btnRange = document.getElementById('btnDateModeRange');

            var exactBox = document.getElementById('exactDateContainer');
            var rangeBox = document.getElementById('rangeDateContainer');

            // Reset buttons styling
            [btnAll, btnExact, btnRange].forEach(btn => {
                btn.style.background = 'transparent';
                btn.style.color = 'var(--mut)';
                btn.style.boxShadow = 'none';
            });

            if (mode === 'exact') {
                btnExact.style.background = 'var(--surf)';
                btnExact.style.color = 'var(--txt)';
                btnExact.style.boxShadow = 'var(--shadow-xs)';
                exactBox.style.display = 'block';
                rangeBox.style.display = 'none';
            } else if (mode === 'range') {
                btnRange.style.background = 'var(--surf)';
                btnRange.style.color = 'var(--txt)';
                btnRange.style.boxShadow = 'var(--shadow-xs)';
                exactBox.style.display = 'none';
                rangeBox.style.display = 'block';
            } else {
                btnAll.style.background = 'var(--surf)';
                btnAll.style.color = 'var(--txt)';
                btnAll.style.boxShadow = 'var(--shadow-xs)';
                exactBox.style.display = 'none';
                rangeBox.style.display = 'none';
            }
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
                    // Deselect any hidden row so it doesn't get included in a bulk delete unseen
                    var cb = rows[i].querySelector('.user-select-checkbox');
                    if (cb) cb.checked = false;
                }
            }
            updateBulkSelection();
        }

        function toggleSelectAllUsers(sourceCheckbox) {
            var rows = document.getElementsByClassName('user-row-item');
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].style.display === 'none') continue; // respect active search/filter
                var cb = rows[i].querySelector('.user-select-checkbox');
                if (cb) cb.checked = sourceCheckbox.checked;
            }
            updateBulkSelection();
        }

        function openEditUserModal(user) {
            document.getElementById('editUserId').value = user.id || '';
            document.getElementById('editUserName').value = user.name || '';
            document.getElementById('editUserEmail').value = user.email || '';
            document.getElementById('editUserPassword').value = '';
            document.getElementById('editUserRole').value = user.role || 'candidate';
            document.getElementById('editUserVerified').checked = (user.is_verified == 1);
            
            var editUniType = document.getElementById('editUserUniType');
            if (editUniType) {
                editUniType.value = (user.university_type === 'private') ? 'private' : 'public';
            }
            var editInstName = document.getElementById('editInstitutionName');
            if (editInstName) {
                editInstName.value = user.company_name || user.institution_name || user.institution_display || '';
            }

            var editInstSsm = document.getElementById('editInstitutionSsm');
            if (editInstSsm) {
                editInstSsm.value = user.ssm_number || '';
            }

            var editEmpName = document.getElementById('editEmployerCompanyName');
            if (editEmpName) {
                editEmpName.value = user.company_name || user.employer_company_name || '';
            }

            var editEmpSsm = document.getElementById('editEmployerSsm');
            if (editEmpSsm) {
                editEmpSsm.value = user.ssm_number || '';
            }

            var preview = document.getElementById('editInstitutionIconPreview');
            if (preview) {
                if (user.company_logo) {
                    preview.innerHTML = '<img src="' + user.company_logo + '?v=' + Date.now() + '" alt="icon" style="width:100%; height:100%; object-fit:contain;">';
                } else {
                    preview.innerHTML = '🎓';
                }
            }

            toggleEditUserRoleFields();

            var subTitle = document.getElementById('editModalSubTitle');
            if (subTitle) {
                subTitle.textContent = 'Editing ' + (user.name || 'Account') + ' (ID #' + user.id + ')';
            }
            document.getElementById('editUserModal').style.display = 'flex';
        }

        function toggleEditUserRoleFields() {
            var role = document.getElementById('editUserRole').value;
            var uniBlock = document.getElementById('editUserInstitutionBlock');
            var empBlock = document.getElementById('editUserEmployerBlock');
            if (uniBlock) uniBlock.style.display = (role === 'university') ? 'block' : 'none';
            if (empBlock) empBlock.style.display = (role === 'employer') ? 'block' : 'none';
        }

        function closeEditUserModal() {
            document.getElementById('editUserModal').style.display = 'none';
        }

        function toggleEditPasswordVisibility() {
            var pwd = document.getElementById('editUserPassword');
            if (pwd) {
                pwd.type = pwd.type === 'password' ? 'text' : 'password';
            }
        }

        function handleUserRowClick(event, row) {
            var tag = event.target.tagName.toLowerCase();
            if (tag === 'button' || tag === 'input' || tag === 'a' || tag === 'select' || event.target.closest('form') || event.target.closest('button')) {
                return;
            }
            var cb = row.querySelector('.user-select-checkbox');
            if (cb) {
                cb.checked = !cb.checked;
                updateBulkSelection();
            }
        }

        function editSelectedAccount() {
            var checked = document.querySelectorAll('.user-select-checkbox:checked');
            if (checked.length !== 1) return;
            var cb = checked[0];
            openEditUserModal({
                id: cb.getAttribute('data-user-id'),
                name: cb.getAttribute('data-user-name'),
                email: cb.getAttribute('data-user-email'),
                role: cb.getAttribute('data-user-role'),
                is_verified: cb.getAttribute('data-user-verified'),
                is_self: cb.getAttribute('data-is-self') === '1',
                university_type: cb.getAttribute('data-user-uni-type') || 'public',
                company_name: cb.getAttribute('data-user-company-name') || '',
                company_logo: cb.getAttribute('data-user-company-logo') || '',
                ssm_number: cb.getAttribute('data-user-ssm') || ''
            });
        }

        function confirmDeleteSelectedAccounts() {
            var checked = document.querySelectorAll('.user-select-checkbox:checked');
            if (checked.length === 0) return;

            var selfSelected = false;
            checked.forEach(function(cb) {
                if (cb.getAttribute('data-is-self') === '1') selfSelected = true;
            });

            if (selfSelected && checked.length === 1) {
                alert('You cannot delete your own admin account while logged in.');
                return;
            }

            var msg = '';
            if (checked.length === 1) {
                var name = checked[0].getAttribute('data-user-name') || 'this account';
                msg = 'Permanently delete account for "' + name + '"? This cannot be undone.';
            } else {
                msg = 'Permanently delete ' + checked.length + ' selected accounts? This cannot be undone.';
            }

            if (!confirm(msg)) return;

            var form = document.getElementById('bulkDeleteForm');
            form.innerHTML = '<input type="hidden" name="action" value="bulk_delete_users">';
            checked.forEach(function(cb) {
                if (cb.getAttribute('data-is-self') !== '1') {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'user_ids[]';
                    hidden.value = cb.value;
                    form.appendChild(hidden);
                }
            });
            form.submit();
        }

        function clearUserSelection() {
            var checkboxes = document.querySelectorAll('.user-select-checkbox');
            checkboxes.forEach(function(cb) { cb.checked = false; });
            var selectAll = document.getElementById('selectAllUsers');
            if (selectAll) selectAll.checked = false;
            updateBulkSelection();
        }

        function updateBulkSelection() {
            var checked = document.querySelectorAll('.user-select-checkbox:checked');
            var toolbar = document.getElementById('userSelectionToolbar');
            var countEl = document.getElementById('bulkSelectedCount');
            var previewEl = document.getElementById('selectedUserPreview');
            var editBtn = document.getElementById('editSelectedUserBtn');
            var deleteBtn = document.getElementById('deleteSelectedUserBtn');

            // Synchronize visual selected state on rows
            var allRows = document.querySelectorAll('.user-row-item');
            allRows.forEach(function(row) {
                var cb = row.querySelector('.user-select-checkbox');
                if (cb && cb.checked) {
                    row.classList.add('is-selected');
                } else {
                    row.classList.remove('is-selected');
                }
            });

            // Synchronize master checkbox
            var allBoxes = document.querySelectorAll('.user-select-checkbox');
            var selectAll = document.getElementById('selectAllUsers');
            if (selectAll) {
                selectAll.checked = allBoxes.length > 0 && checked.length === allBoxes.length;
            }

            if (checked.length === 0) {
                if (toolbar) toolbar.style.display = 'none';
                return;
            }

            if (toolbar) toolbar.style.display = 'flex';

            if (checked.length === 1) {
                var cb = checked[0];
                var name = cb.getAttribute('data-user-name') || 'User';
                var email = cb.getAttribute('data-user-email') || '';
                var role = cb.getAttribute('data-user-role') || 'candidate';
                var isSelf = cb.getAttribute('data-is-self') === '1';

                if (countEl) countEl.textContent = '1 Selected';
                if (previewEl) {
                    previewEl.style.display = 'flex';
                    var avatar = document.getElementById('selectedUserAvatar');
                    var nameEl = document.getElementById('selectedUserName');
                    var emailEl = document.getElementById('selectedUserEmail');
                    var roleEl = document.getElementById('selectedUserRoleBadge');

                    if (avatar) avatar.textContent = (name.charAt(0) || 'U').toUpperCase();
                    if (nameEl) nameEl.textContent = name;
                    if (emailEl) emailEl.textContent = '(' + email + ')';
                    if (roleEl) {
                        roleEl.textContent = role.charAt(0).toUpperCase() + role.slice(1);
                        roleEl.className = 'chip ' + (role === 'admin' ? 'chip-rejected' : (role === 'employer' ? 'chip-review' : 'chip-shortlisted'));
                    }
                }

                if (editBtn) {
                    editBtn.style.display = 'inline-flex';
                    editBtn.title = 'Edit name, email, or password for ' + name;
                }

                if (deleteBtn) {
                    deleteBtn.textContent = '🗑️ Delete Account';
                    if (isSelf) {
                        deleteBtn.style.opacity = '0.4';
                        deleteBtn.style.cursor = 'not-allowed';
                        deleteBtn.title = 'Cannot delete your own admin account while logged in';
                    } else {
                        deleteBtn.style.opacity = '1';
                        deleteBtn.style.cursor = 'pointer';
                        deleteBtn.title = 'Permanently delete this account';
                    }
                }
            } else {
                if (countEl) countEl.textContent = checked.length + ' Selected';
                if (previewEl) previewEl.style.display = 'none';
                if (editBtn) editBtn.style.display = 'none';

                if (deleteBtn) {
                    deleteBtn.textContent = '🗑️ Delete Selected (' + checked.length + ')';
                    deleteBtn.style.opacity = '1';
                    deleteBtn.style.cursor = 'pointer';
                    deleteBtn.title = 'Delete selected accounts';
                }
            }
        }

        // ----------------------------------------------------
        // Registered Universities Table Actions & Filtering
        // ----------------------------------------------------
        function filterUniversitiesTable() {
            var search = (document.getElementById('uniSearchInput') ? document.getElementById('uniSearchInput').value : '').toLowerCase().trim();
            var type = (document.getElementById('uniTypeFilterSelect') ? document.getElementById('uniTypeFilterSelect').value : '').toLowerCase();
            var status = (document.getElementById('uniStatusFilterSelect') ? document.getElementById('uniStatusFilterSelect').value : '');
            var rows = document.getElementsByClassName('uni-row-item');

            for (var i = 0; i < rows.length; i++) {
                var searchData = rows[i].getAttribute('data-search') || '';
                var uniType = rows[i].getAttribute('data-type') || '';
                var uniStatus = rows[i].getAttribute('data-status') || '';

                var matchesSearch = !search || searchData.indexOf(search) > -1;
                var matchesType = !type || uniType === type;
                var matchesStatus = (status === '') || uniStatus === status;

                if (matchesSearch && matchesType && matchesStatus) {
                    rows[i].style.display = 'grid';
                } else {
                    rows[i].style.display = 'none';
                    var cb = rows[i].querySelector('.uni-select-checkbox');
                    if (cb) cb.checked = false;
                }
            }
            updateBulkUniSelection();
        }

        function toggleSelectAllUnis(master) {
            var checkboxes = document.querySelectorAll('.uni-row-item:not([style*="display: none"]) .uni-select-checkbox');
            checkboxes.forEach(function(cb) {
                cb.checked = master.checked;
            });
            updateBulkUniSelection();
        }

        function updateBulkUniSelection() {
            var allCheckboxes = document.querySelectorAll('.uni-row-item .uni-select-checkbox');
            var checked = document.querySelectorAll('.uni-row-item .uni-select-checkbox:checked');
            var toolbar = document.getElementById('uniSelectionToolbar');
            var countEl = document.getElementById('bulkUniSelectedCount');
            var previewEl = document.getElementById('selectedUniPreview');
            var editBtn = document.getElementById('editSelectedUniBtn');
            var deleteBtn = document.getElementById('deleteSelectedUniBtn');
            var master = document.getElementById('selectAllUnis');

            allCheckboxes.forEach(function(cb) {
                var row = cb.closest('.uni-row-item');
                if (row) {
                    if (cb.checked) row.classList.add('is-selected');
                    else row.classList.remove('is-selected');
                }
            });

            if (master && allCheckboxes.length > 0) {
                master.checked = (checked.length === allCheckboxes.length);
                master.indeterminate = (checked.length > 0 && checked.length < allCheckboxes.length);
            }

            if (!toolbar) return;

            if (checked.length === 0) {
                toolbar.classList.remove('is-visible');
            } else {
                toolbar.classList.add('is-visible');
            }

            if (checked.length === 1) {
                var cb = checked[0];
                if (countEl) countEl.textContent = '1 Selected';
                if (previewEl) {
                    previewEl.style.display = 'flex';
                    var nameEl = document.getElementById('selectedUniName');
                    var emailEl = document.getElementById('selectedUniEmail');
                    if (nameEl) nameEl.textContent = cb.getAttribute('data-uni-institution') || cb.getAttribute('data-uni-name');
                    if (emailEl) emailEl.textContent = cb.getAttribute('data-uni-email');
                }
                if (editBtn) editBtn.style.display = 'inline-flex';
                if (deleteBtn) {
                    deleteBtn.textContent = '🗑️ Delete Account';
                    deleteBtn.title = 'Permanently delete this university account';
                }
            } else {
                if (countEl) countEl.textContent = checked.length + ' Selected';
                if (previewEl) previewEl.style.display = 'none';
                if (editBtn) editBtn.style.display = 'none';
                if (deleteBtn) {
                    deleteBtn.textContent = '🗑️ Delete Selected (' + checked.length + ')';
                    deleteBtn.title = 'Delete selected accounts';
                }
            }
        }

        function clearUniSelection() {
            var checkboxes = document.querySelectorAll('.uni-select-checkbox');
            checkboxes.forEach(function(cb) { cb.checked = false; });
            var master = document.getElementById('selectAllUnis');
            if (master) master.checked = false;
            updateBulkUniSelection();
        }

        function handleUniRowClick(event, row) {
            var tag = event.target.tagName.toLowerCase();
            if (tag === 'button' || tag === 'input' || tag === 'a' || tag === 'select' || event.target.closest('form') || event.target.closest('button')) {
                return;
            }
            var cb = row.querySelector('.uni-select-checkbox');
            if (cb) {
                cb.checked = !cb.checked;
                updateBulkUniSelection();
            }
        }

        function editSelectedUniAccount() {
            var checked = document.querySelectorAll('.uni-select-checkbox:checked');
            if (checked.length !== 1) return;
            var cb = checked[0];
            openEditUserModal({
                id: cb.getAttribute('data-uni-id'),
                name: cb.getAttribute('data-uni-name'),
                email: cb.getAttribute('data-uni-email'),
                role: 'university',
                is_verified: cb.getAttribute('data-uni-verified'),
                university_type: cb.getAttribute('data-uni-type') || 'public',
                institution_name: cb.getAttribute('data-uni-institution') || cb.getAttribute('data-uni-company-name') || '',
                company_name: cb.getAttribute('data-uni-company-name') || '',
                company_logo: cb.getAttribute('data-uni-company-logo') || '',
                ssm_number: cb.getAttribute('data-uni-ssm') || ''
            });
        }

        function confirmDeleteSelectedUnis() {
            var checked = document.querySelectorAll('.uni-select-checkbox:checked');
            if (checked.length === 0) return;

            var msg = (checked.length === 1)
                ? "Are you sure you want to permanently delete this university account? This cannot be undone."
                : "Are you sure you want to permanently delete these " + checked.length + " university accounts? This cannot be undone.";

            if (!confirm(msg)) return;

            var form = document.getElementById('bulkDeleteUnisForm');
            form.innerHTML = '<input type="hidden" name="action" value="bulk_delete_users">';
            checked.forEach(function(cb) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'user_ids[]';
                input.value = cb.value;
                form.appendChild(input);
            });
            form.submit();
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddUserModal();
                closeEditUserModal();
                closeExportResumesModal();
                closeUniversityQrModal();
                clearUniSelection();
            }
        });
    </script>
    <script src="theme.js"></script>
</body>
</html>
