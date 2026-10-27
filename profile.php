<?php
require_once 'auth.php';
require_once 'ai.php';
require_once 'config_manager.php';
require_once 'notifications_helper.php';
require_once 'company_helpers.php';
require_login();

if (($_SESSION['user_role'] ?? '') === 'university') {
    header("Location: university_settings.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$userNotifs = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate') 
    ? getCandidateNotifications($pdo, $user_id) 
    : getEmployerNotifications($pdo, $user_id);
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$universities_list = ($user['role'] === 'candidate')
    ? $pdo->query("SELECT id, name, type FROM universities ORDER BY FIELD(type,'public','private','other'), name")->fetchAll()
    : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle oversized post payload before PHP discards $_POST
    if (empty($_POST) && empty($_FILES) && isset($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
        $_SESSION['error'] = "Uploaded file was too large for the server. Please select a smaller photo or retry with auto-compression.";
        header("Location: profile.php");
        exit;
    }

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
                // A candidate may self-link (or change) their university + matric number here too.
                $set_sql = "name = ?, email = ?";
                $params = [$name, $email];
                if ($user['role'] === 'candidate') {
                    $posted_uni_id = $_POST['university_id'] ?? '';
                    $university_id = null;
                    if ($posted_uni_id !== '') {
                        $uni_check = $pdo->prepare("SELECT id FROM universities WHERE id = ?");
                        $uni_check->execute([$posted_uni_id]);
                        if ($uni_check->fetch()) {
                            $university_id = (int) $posted_uni_id;
                        }
                    }
                    $matric_number = trim($_POST['matric_number'] ?? '') ?: null;
                    $set_sql .= ", university_id = ?, matric_number = ?";
                    $params[] = $university_id;
                    $params[] = $matric_number;
                }
                $params[] = $user_id;
                $up = $pdo->prepare("UPDATE users SET $set_sql WHERE id = ?");
                if ($up->execute($params)) {
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
        $smtp_secure = trim($_POST['smtp_secure'] ?? 'tls');
        $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');

        $curr_config = load_app_config();

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
        $curr_config['smtp_secure'] = $smtp_secure;
        $curr_config['smtp_from_name'] = $smtp_from_name;

        save_app_config($curr_config);
        $_SESSION['toast'] = "Settings & PHPMailer SMTP Configuration saved!";
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
        $res = send_test_email($target_email);
        if (!empty($res['success'])) {
            $transport_note = !empty($res['is_smtp']) ? "via Authenticated SMTP" : "via Native mail() [Warning: High spam risk!]";
            $_SESSION['toast'] = "Test deliverability email dispatched to " . htmlspecialchars($target_email) . " ({$transport_note})! Check your inbox.";
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
        $base64_data = $_POST['profile_picture_base64'] ?? '';
        $file = $_FILES['profile_picture'] ?? null;
        
        if (!empty($base64_data)) {
            // Handled via client-side compressed image payload
            if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $type)) {
                $base64_clean = substr($base64_data, strpos($base64_data, ',') + 1);
                $decoded = base64_decode($base64_clean);
                if ($decoded !== false) {
                    $ext = strtolower($type[1]);
                    if ($ext === 'jpeg') $ext = 'jpg';
                    if (!in_array($ext, ['jpg', 'png', 'webp'])) $ext = 'jpg';
                    
                    $upload_dir = 'uploads/profiles/';
                    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                    
                    if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                        $_SESSION['error'] = "Directory 'uploads/profiles/' is not writable. Please check permissions.";
                    } else {
                        $path = $upload_dir . uniqid() . '.' . $ext;
                        if (file_put_contents($path, $decoded)) {
                            // Remove previous custom avatar if exists
                            if (!empty($user['profile_picture']) && file_exists($user['profile_picture']) && strpos($user['profile_picture'], 'uploads/profiles/') === 0) {
                                @unlink($user['profile_picture']);
                            }
                            $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                            $stmt->execute([$path, $user_id]);
                            $_SESSION['toast'] = "Profile picture updated successfully!";
                        } else {
                            $_SESSION['error'] = "Failed to save profile picture.";
                        }
                    }
                } else {
                    $_SESSION['error'] = "Invalid image data received.";
                }
            } else {
                $_SESSION['error'] = "Invalid image format received.";
            }
        } elseif ($file && $file['error'] === UPLOAD_ERR_OK) {
            if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
                $_SESSION['error'] = "Uploaded image exceeds the maximum allowed size limit (10MB).";
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $upload_dir = 'uploads/profiles/';
                    if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
                    
                    if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                        $_SESSION['error'] = "Directory 'uploads/profiles/' is not writable. Please check permissions.";
                    } else {
                        $path = $upload_dir . uniqid() . '.' . $ext;
                        if (move_uploaded_file($file['tmp_name'], $path)) {
                            // Remove previous custom avatar if exists
                            if (!empty($user['profile_picture']) && file_exists($user['profile_picture']) && strpos($user['profile_picture'], 'uploads/profiles/') === 0) {
                                @unlink($user['profile_picture']);
                            }
                            $stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                            $stmt->execute([$path, $user_id]);
                            $_SESSION['toast'] = "Profile picture updated successfully!";
                        } else {
                            $_SESSION['error'] = "Failed to save profile picture.";
                        }
                    }
                } else {
                    $_SESSION['error'] = "Invalid image format. Use JPG, PNG or WEBP.";
                }
            }
        } elseif ($file && $file['error'] === UPLOAD_ERR_INI_SIZE) {
            $_SESSION['error'] = "Uploaded image exceeds the server upload limit.";
        } else {
            $_SESSION['error'] = "Please select a valid image file.";
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
        $ssm_number = trim($_POST['ssm_number'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');
        $business_type = trim($_POST['business_type'] ?? 'Private Limited (Sdn. Bhd.)');
        $industry = trim($_POST['industry'] ?? 'Information Technology');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $company_size = trim($_POST['company_size'] ?? '11-50 employees');

        if ($contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "'" . htmlspecialchars($contact_email) . "' is not a valid email address.";
            header("Location: profile.php");
            exit;
        }

        $stmt = $pdo->prepare("UPDATE companies SET name = ?, website = ?, address = ?, contact_email = ?, ssm_number = ?, business_type = ?, industry = ?, contact_number = ?, company_size = ? WHERE id = ?");
        if ($stmt->execute([$company_name, $company_website, $company_address, $contact_email, $ssm_number, $business_type, $industry, $contact_number, $company_size, $company_id])) {
            try {
                $upd_user = $pdo->prepare("UPDATE users SET ssm_number = ?, company_name = ?, company_website = ?, company_address = ?, contact_email = ?, business_type = ?, industry = ?, contact_number = ?, company_size = ? WHERE id = ?");
                $upd_user->execute([$ssm_number, $company_name, $company_website, $company_address, $contact_email, $business_type, $industry, $contact_number, $company_size, $user_id]);
            } catch (\Throwable $t) {}

            $_SESSION['toast'] = "Company settings saved successfully.";
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
            // Unlink profile picture, common to every role
            if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])) {
                @unlink($user['profile_picture']);
            }

            if ($user['role'] === 'candidate') {
                // Unlink candidate default resume if exists
                if (!empty($user['default_resume']) && file_exists($user['default_resume'])) {
                    @unlink($user['default_resume']);
                }
                // Delete candidate applications
                $del_cand = $pdo->prepare("DELETE FROM candidates WHERE user_id = ? OR (email = ? AND email IS NOT NULL AND email != '')");
                $del_cand->execute([$user_id, $user['email']]);
            } elseif ($user['role'] === 'employer') {
                // This employer's own Company Culture Gallery uploads always
                // go with them; a surviving company's logo is handled inside
                // process_employer_departure() only when it's fully wiped.
                $media_stmt = $pdo->prepare("SELECT file_path FROM company_media WHERE user_id = ?");
                $media_stmt->execute([$user_id]);
                foreach ($media_stmt->fetchAll(PDO::FETCH_COLUMN) as $media_path) {
                    if (!empty($media_path) && file_exists($media_path)) @unlink($media_path);
                }

                // Reassign/clean up jobs and company membership BEFORE the
                // user row disappears — see company_helpers.php for the
                // per-company policy (delete solo companies, auto-promote a
                // successor if they're the sole admin, or just leave a team
                // they don't run).
                $impact = get_employer_deletion_impact($pdo, $user_id);
                process_employer_departure($pdo, $user_id, $impact);
            }

            // Delete user record from users table
            $del_user = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $del_user->execute([$user_id]);

            // Clear session and redirect with toast
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['toast'] = "Your account has been permanently deleted.";
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
    <title>Profile &amp; Account Settings — Keria</title>

    <!-- Google Fonts matching jobs.php & candidate_dashboard.php -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">

    <style>
        :root {
            --keria-lime: #D2FF3A;
            --keria-lime-hover: #C2F025;
            --keria-lime-light: #EBFFAA;
            --keria-green-text: #15803D;
            --keria-green-bg: #DCFCE7;
            --keria-dark: #0A0A0A;
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-hand: 'Caveat', cursive;
        }

        body {
            font-family: var(--font-sans);
            background: #F8FAF8 !important;
            color: var(--keria-dark, #0A0A0A);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* Screen-filling Header Width matching jobs.php */
        header .header-inner {
            max-width: 1560px !important;
            width: 100% !important;
            padding: 0 16px !important;
        }

        /* ================= HERO BANNER SECTION (matching jobs.php) ================= */
        .keria-hero-banner {
            position: relative;
            width: 100%;
            background-image: url('assets/Keria_banner_v2.jpg?v=<?php echo @filemtime(__DIR__.'/assets/Keria_banner_v2.jpg'); ?>');
            background-size: cover;
            background-position: center top;
            background-repeat: no-repeat;
            /* This section sits directly after <header>, outside <main>,
               so it didn't get style.css's main{margin-top} compensation
               for the header now being position:fixed. Clearance is
               margin-top, not padding -- padding would grow this box
               and force background-size:cover to zoom in further to
               still fill it, cropping the mascots. margin sits outside
               the box, so the image keeps its original sizing/crop. */
            margin-top: 72px;
            padding: 36px 32px 40px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        @media (max-width: 840px) {
            /* Below 840px style.css's header switches from a fixed
               72px to auto-height (its nav can wrap to a second line),
               so 72px of clearance stops being enough and the title
               gets covered. 170px errs generous, same estimate used
               for main{margin-top} at this breakpoint. */
            .keria-hero-banner {
                margin-top: 170px;
                padding: 24px 20px 28px;
            }
            /* style.css also forces main{margin-top:170px!important} at
               this breakpoint, assuming main sits directly under the
               fixed header. Here the hero banner above already provides
               that clearance, so without this override the two stack
               and leave a large empty gap between the banner and the
               page content. */
            .keria-main-container {
                margin-top: 0 !important;
            }
        }

        .banner-content-inner {
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            position: relative;
            z-index: 5;
        }

        .banner-left-intro {
            flex: 1;
            max-width: 720px;
            position: relative;
        }

        .banner-title-text {
            font-size: 38px;
            font-weight: 900;
            color: #0A0A0A;
            line-height: 1.1;
            letter-spacing: -1.2px;
            margin-bottom: 10px;
        }

        .banner-sub-text {
            font-size: 14.5px;
            font-weight: 500;
            color: #374151;
            line-height: 1.45;
            max-width: 580px;
            margin-bottom: 6px;
        }

        .banner-sub-smile {
            font-family: var(--font-hand);
            font-size: 26px;
            font-weight: 700;
            color: #0A0A0A;
            display: inline-block;
            transform: rotate(6deg);
            margin-top: -2px;
        }

        /* Right Banner Doodle Area */
        .banner-right-doodle-box {
            flex: 0 0 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            /* Pushed well left of its old 40px, which sat right on top
               of the mascots with the new banner photo -- they sit much
               further right in this image than the old illustration
               did, and the box's own height matches its text (no extra
               room inside it), so nothing short of actually moving the
               whole box clears them. This lands it over the open lake/
               sky area instead, left of the mascots entirely. */
            margin-right: 600px;
            user-select: none;
        }

        .banner-doodle-text {
            font-family: var(--font-hand);
            font-size: 25px;
            font-weight: 700;
            line-height: 1.15;
            color: #0A0A0A;
            transform: rotate(6deg);
            position: relative;
        }

        .banner-doodle-sparks {
            position: absolute;
            top: -12px;
            right: -16px;
            width: 26px;
            height: 26px;
        }

        /* ================= MAIN CONTAINER ================= */
        .keria-main-container {
            flex: 1 0 auto;
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            padding: 28px 24px 64px;
            box-sizing: border-box;
        }

        /* Modern Keria Cards */
        .keria-card, .panel {
            background: #FFFFFF !important;
            border: 1px solid #E5E7EB !important;
            border-radius: 20px !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02) !important;
            transition: all 0.2s ease;
            position: relative;
        }

        .keria-card:hover, .panel:hover {
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.04) !important;
            border-color: #D1D5DB !important;
        }

        /* Profile Header Overview Card */
        .profile-overview-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 20px;
            padding: 24px 32px;
            margin-bottom: 28px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 24px;
        }

        .profile-avatar-wrap {
            position: relative;
            flex-shrink: 0;
            width: 88px;
            height: 88px;
        }

        .profile-pic {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #E5E7EB;
            background: #F3F4F6;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            display: block;
            margin-bottom: 0;
        }

        .profile-avatar-placeholder {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            font-weight: 800;
            color: #0A0A0A;
            background: var(--keria-lime, #D2FF3A);
            border: 3px solid #E5E7EB;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.06);
            margin-bottom: 0;
        }

        .avatar-cam-btn {
            position: absolute;
            bottom: -2px;
            right: -2px;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #0A0A0A;
            color: #FFFFFF;
            border: 2px solid #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
            padding: 0;
        }

        .avatar-cam-btn:hover {
            transform: scale(1.08);
            background: #262626;
        }

        .profile-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .profile-name-text {
            font-size: 24px;
            font-weight: 900;
            color: #0A0A0A;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .role-chip {
            background: #DCFCE7;
            color: #15803D;
            border: 1px solid #BBF7D0;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 4px 12px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .role-chip-employer {
            background: #EFF6FF;
            color: #1D4ED8;
            border-color: #BFDBFE;
        }

        .role-chip-admin {
            background: #FEF3C7;
            color: #B45309;
            border-color: #FDE68A;
        }

        .profile-email-text {
            font-size: 13.5px;
            font-weight: 500;
            color: #6B7280;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .status-badge-verified {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #F0FDF4;
            border: 1px solid #DCFCE7;
            color: #15803D;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 999px;
        }

        .status-pulse-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #16A34A;
            display: inline-block;
        }

        /* Settings Grid Layout */
        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
            align-items: start;
        }

        @media (max-width: 992px) {
            .settings-grid {
                grid-template-columns: 1fr !important;
                gap: 20px !important;
            }
            .banner-right-doodle-box {
                display: none;
            }
        }

        /* Form elements */
        .settings-grid label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: #374151;
            margin-bottom: 6px;
        }

        .settings-grid input[type="text"],
        .settings-grid input[type="email"],
        .settings-grid input[type="password"],
        .settings-grid input[type="number"],
        .settings-grid input[type="url"],
        .settings-grid select,
        .settings-grid textarea {
            width: 100%;
            box-sizing: border-box;
            background: #FFFFFF;
            border: 1.5px solid #E5E7EB;
            border-radius: 12px;
            padding: 11px 14px;
            font-size: 13.5px;
            font-family: var(--font-sans);
            color: #0A0A0A;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .settings-grid input:focus,
        .settings-grid select:focus,
        .settings-grid textarea:focus {
            border-color: #0A0A0A;
            box-shadow: 0 0 0 3px rgba(10, 10, 10, 0.05);
        }

        .btn-primary {
            background: #0A0A0A !important;
            color: #FFFFFF !important;
            font-family: var(--font-sans);
            font-size: 13.5px;
            font-weight: 700;
            padding: 11px 22px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-primary:hover {
            background: #262626 !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.15);
        }

        .btn-secondary {
            background: #FFFFFF !important;
            color: #0A0A0A !important;
            border: 1.5px solid #E5E7EB !important;
            font-family: var(--font-sans);
            font-size: 13px;
            font-weight: 700;
            padding: 9px 18px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-secondary:hover {
            border-color: #0A0A0A !important;
            background: #F9FAFB !important;
            transform: translateY(-1px);
        }

        .panel-title {
            font-size: 16.5px;
            font-weight: 800;
            color: var(--txt);
            letter-spacing: -0.3px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .danger-zone-panel {
            background: #FEF2F2 !important;
            border: 1px solid #FECACA !important;
            border-radius: 20px !important;
        }

        .btn-danger {
            background: #DC2626 !important;
            color: #FFFFFF !important;
            border: none !important;
            padding: 11px 24px;
            font-size: 13px;
            font-weight: 800;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-danger:hover {
            background: #B91C1C !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.28);
        }

        @media (max-width: 700px) {
            html, body {
                overflow-x: hidden !important;
                width: 100% !important;
                max-width: 100vw !important;
            }
            .settings-grid {
                grid-template-columns: 1fr !important;
                gap: 20px !important;
            }
            .settings-grid .panel {
                padding: 20px 18px !important;
            }
        }

        /* Dark mode cohesion -- this page had no dark-mode rules at all,
           so its cards/inputs were hardcoded white and stayed that way
           regardless of theme. Matches the palette already used on
           resume_check.php etc. (#18191C body, #26272C card, #383940
           border, #F9FAFB text) so it's consistent across pages. */
        [data-theme="dark"] body {
            background: #18191C !important;
            color: #F9FAFB;
        }
        [data-theme="dark"] .keria-card,
        [data-theme="dark"] .panel,
        [data-theme="dark"] .profile-overview-card {
            background: #26272C !important;
            border-color: #383940 !important;
        }
        [data-theme="dark"] .profile-name-text {
            color: #F9FAFB;
        }
        [data-theme="dark"] .profile-email-text,
        [data-theme="dark"] .settings-grid label {
            color: #A5ACB8;
        }
        [data-theme="dark"] .profile-pic {
            background: #1F2024;
            border-color: #383940;
        }
        [data-theme="dark"] .avatar-cam-btn {
            border-color: #26272C;
        }
        [data-theme="dark"] .settings-grid input[type="text"],
        [data-theme="dark"] .settings-grid input[type="email"],
        [data-theme="dark"] .settings-grid input[type="password"],
        [data-theme="dark"] .settings-grid input[type="number"],
        [data-theme="dark"] .settings-grid input[type="url"],
        [data-theme="dark"] .settings-grid select,
        [data-theme="dark"] .settings-grid textarea {
            background: #1F2024;
            border-color: #383940;
            color: #F9FAFB;
        }
        [data-theme="dark"] .settings-grid input:focus,
        [data-theme="dark"] .settings-grid select:focus,
        [data-theme="dark"] .settings-grid textarea:focus {
            border-color: #D2FF3A;
        }
        [data-theme="dark"] .btn-secondary {
            background: #26272C !important;
            color: #F9FAFB !important;
            border-color: #383940 !important;
        }
    </style>
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
</head>
<body>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box">
                    <a href="index.php" style="display:flex; align-items:center;">
                        <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;">
                    </a>
                </div>
            </div>
            
            <nav style="display:flex; gap:4px; margin-left:24px">
                <?php if($user['role'] === 'candidate'): ?>
                    <a href="jobs.php">📋 Job Board</a>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="resume_check.php">✨ AI Resume Check</a>
                    <a href="profile.php" class="active">⚙️ Profile Settings</a>
                <?php elseif($user['role'] === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications & Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="profile.php" class="active">⚙️ Settings</a>
                <?php elseif($user['role'] === 'management'): ?>
                    <a href="management_dashboard.php">📊 Management Dashboard</a>
                    <a href="profile.php" class="active">⚙️ Settings</a>
                <?php elseif($user['role'] === 'university'): ?>
                    <a href="university_dashboard.php">🎓 University Dashboard</a>
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

    <!-- ================= FULL-WIDTH KERIA HERO BANNER ================= -->
    <section class="keria-hero-banner">
        <div class="banner-content-inner">
            <div class="banner-left-intro">
                <h1 class="banner-title-text">Profile &amp;<br>Account Settings <span class="banner-sub-smile">⚙️</span></h1>
                <p class="banner-sub-text">Manage your personal credentials, university affiliation, team workspace, and application preferences seamlessly.</p>
            </div>

            <div class="banner-right-doodle-box">
                <div class="banner-doodle-text">
                    Keep<br>It Fresh<br>&amp; Updated! ☺
                    <svg class="banner-doodle-sparks" viewBox="0 0 24 24" fill="none" stroke="#0A0A0A" stroke-width="2.5" stroke-linecap="round"><path d="M12 3v3m0 12v3M3 12h3m12 0h3m-2.6-6.4l-2.1 2.1m-8.6 8.6l-2.1 2.1m0-12.8l2.1 2.1m8.6 8.6l2.1 2.1"/></svg>
                </div>
            </div>
        </div>
    </section>

    <main class="keria-main-container">
        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification" style="background:#ECFDF5; border:1px solid #A7F3D0; border-radius:14px; padding:14px 20px; margin-bottom:24px; color:#065F46; font-size:13.5px; font-weight:600; display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 10px rgba(0,0,0,0.02);">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:16px;">🌿</span>
                    <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                </div>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()" style="background:none; border:none; color:#065F46; font-size:16px; cursor:pointer;">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:#FEF2F2; border:1px solid #FECACA; border-radius:14px; padding:14px 20px; margin-bottom:24px; color:#991B1B; font-size:13.5px; font-weight:600; display:flex; align-items:center; gap:10px; box-shadow:0 2px 10px rgba(0,0,0,0.02);">
                <span style="font-size:16px;">⚠️</span>
                <span><?= htmlspecialchars($_SESSION['error']) ?></span>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Modern Profile Overview Card -->
        <div class="profile-overview-card">
            <div style="display:flex; gap:22px; align-items:center; flex-wrap:wrap;">
                <div class="profile-avatar-wrap">
                    <?php if(!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="Profile Picture" class="profile-pic">
                    <?php else: ?>
                        <div class="profile-avatar-placeholder">
                            <?= strtoupper(substr($user['name'] ?: 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    
                    <form id="avatarForm" method="POST" enctype="multipart/form-data" style="margin:0;">
                        <input type="hidden" name="action" value="upload_picture">
                        <input type="hidden" name="profile_picture_base64" id="pic_base64">
                        <input type="file" name="profile_picture" id="pic_input" accept="image/png, image/jpeg, image/webp" style="display:none" onchange="handleAvatarUpload(this)">
                        <button type="button" class="avatar-cam-btn" onclick="document.getElementById('pic_input').click()" title="Change Profile Photo">📷</button>
                    </form>
                </div>

                <div>
                    <div class="profile-title-row">
                        <h2 class="profile-name-text"><?= htmlspecialchars($user['name']) ?></h2>
                        <?php if($user['role'] === 'candidate'): ?>
                            <span class="role-chip">🌿 Candidate</span>
                        <?php elseif($user['role'] === 'employer'): ?>
                            <span class="role-chip role-chip-employer">💼 Employer</span>
                        <?php else: ?>
                            <span class="role-chip role-chip-admin">👑 Admin</span>
                        <?php endif; ?>
                    </div>
                    <div class="profile-email-text">
                        <span>✉️ <?= htmlspecialchars($user['email']) ?></span>
                        <?php if(!empty($active_company['name'])): ?>
                            <span style="color:#D1D5DB;">&bull;</span>
                            <span style="color:var(--mut); font-weight:600;">🏢 <?= htmlspecialchars($active_company['name']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                <div style="text-align:right;">
                    <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.8px; color:var(--mut); font-weight:700; margin-bottom:4px;">Account Status</div>
                    <div class="status-badge-verified">
                        <span class="status-pulse-dot"></span> Active &amp; Verified
                    </div>
                </div>
            </div>
        </div>

        <div class="settings-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap:28px; align-items:start;">
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
                    
                    <div style="margin-bottom:<?= $user['role'] === 'candidate' ? '18px' : '24px' ?>;">
                        <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required placeholder="alex@example.com">
                    </div>

                    <?php if ($user['role'] === 'candidate'): ?>
                        <div style="margin-bottom:18px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">My University (optional)</label>
                            <select name="university_id">
                                <option value="">— Not a student / none —</option>
                                <?php $current_uni_type = null; foreach ($universities_list as $u): ?>
                                    <?php if ($u['type'] !== $current_uni_type): ?>
                                        <?php if ($current_uni_type !== null): ?></optgroup><?php endif; ?>
                                        <optgroup label="<?= $u['type'] === 'public' ? 'Public Universities' : ($u['type'] === 'private' ? 'Private Universities' : 'Other') ?>">
                                        <?php $current_uni_type = $u['type']; ?>
                                    <?php endif; ?>
                                    <option value="<?= $u['id'] ?>" <?= ((int)($user['university_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?></option>
                                <?php endforeach; ?>
                                <?php if ($current_uni_type !== null): ?></optgroup><?php endif; ?>
                            </select>
                        </div>
                        <div style="margin-bottom:24px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Matric / Student ID Number (optional)</label>
                            <input type="text" name="matric_number" value="<?= htmlspecialchars($user['matric_number'] ?? '') ?>" placeholder="e.g. A12345678">
                        </div>
                    <?php endif; ?>
                    
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
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Company Name <span style="color:var(--red);">*</span></label>
                                <input type="text" name="company_name" placeholder="Acme Corporation Sdn Bhd" value="<?= htmlspecialchars($active_company['name'] ?? '') ?>" required>
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">SSM Registration Number <span style="color:var(--red);">* (Compulsory for job postings)</span></label>
                                <input type="text" name="ssm_number" placeholder="e.g. 202301012345 (1234567-X)" value="<?= htmlspecialchars($active_company['ssm_number'] ?? $user['ssm_number'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:18px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Business Type</label>
                                <select name="business_type" style="padding:10px 12px; font-size:13px; border-radius:8px; border:1px solid var(--bdr); width:100%; background:var(--card-solid); color:var(--txt);">
                                    <?php 
                                        $curr_bt = $active_company['business_type'] ?? $user['business_type'] ?? 'Private Limited (Sdn. Bhd.)';
                                        $bt_options = ['Private Limited (Sdn. Bhd.)', 'Sole Proprietorship', 'Partnership', 'Public Limited (Bhd.)', 'Limited Liability Partnership (PLT)', 'Government / Statutory Body', 'Non-Profit / NGO'];
                                        foreach($bt_options as $opt):
                                    ?>
                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $curr_bt === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Industry</label>
                                <input type="text" name="industry" placeholder="e.g. Information Technology" value="<?= htmlspecialchars($active_company['industry'] ?? $user['industry'] ?? 'Information Technology') ?>">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:18px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Official Website</label>
                                <input type="url" name="company_website" placeholder="https://acme.com" value="<?= htmlspecialchars($active_company['website'] ?? '') ?>">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Official Candidate Contact Email</label>
                                <input type="email" name="contact_email" placeholder="careers@acme.com &mdash; leave blank to use account email" value="<?= htmlspecialchars($active_company['contact_email'] ?? '') ?>">
                            </div>
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:18px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Contact Number</label>
                                <input type="text" name="contact_number" placeholder="e.g. +60 3-1234 5678" value="<?= htmlspecialchars($active_company['contact_number'] ?? $user['contact_number'] ?? '+60 3-1234 5678') ?>">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Company Size</label>
                                <select name="company_size" style="padding:10px 12px; font-size:13px; border-radius:8px; border:1px solid var(--bdr); width:100%; background:var(--card-solid); color:var(--txt);">
                                    <?php 
                                        $curr_cs = $active_company['company_size'] ?? $user['company_size'] ?? '11-50 employees';
                                        $cs_options = ['1-10 employees', '11-50 employees', '51-200 employees', '201-500 employees', '500+ employees'];
                                        foreach($cs_options as $cs_opt):
                                    ?>
                                        <option value="<?= htmlspecialchars($cs_opt) ?>" <?= $curr_cs === $cs_opt ? 'selected' : '' ?>><?= htmlspecialchars($cs_opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="margin-bottom:24px;">
                            <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Office Address</label>
                            <textarea name="company_address" rows="3" placeholder="Cyberjaya, Selangor, Malaysia"><?= htmlspecialchars($active_company['address'] ?? '') ?></textarea>
                        </div>

                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                            <?php if(!empty($active_company['id'])): ?>
                                <a href="company_profile.php?id=<?= $active_company['id'] ?>" target="_blank" class="btn-secondary" style="padding:10px 18px; text-decoration:none; font-size:13px; font-weight:700; display:inline-flex; align-items:center; gap:6px;">
                                    <span>👁️</span> <span>Preview Public Company Profile &rarr;</span>
                                </a>
                            <?php else: ?>
                                <div></div>
                            <?php endif; ?>

                            <?php if($is_company_admin): ?>
                                <button type="submit" class="btn-primary" style="width:auto; padding:11px 28px;">Save Company Info &rarr;</button>
                            <?php endif; ?>
                        </div>
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

            <?php if($user['role'] === 'employer'): ?>
                <?php $deletion_impact = get_employer_deletion_impact($pdo, $user_id); ?>
                <!-- Danger Zone: Account Deletion Panel -->
                <div class="panel danger-zone-panel" style="grid-column: 1 / -1; padding:32px;">
                    <div class="panel-title" style="color: #DC2626; display:flex; align-items:center; gap:8px;">
                        <span>⚠️</span> Danger Zone: Account Deletion
                    </div>
                    <p style="font-size:13px; color:var(--mut); margin-bottom:16px; line-height:1.5;">
                        Deleting your employer account will permanently remove your profile and Company Culture Gallery uploads.
                        <strong>This operation is permanent and non-reversible.</strong>
                    </p>

                    <?php if(!empty($deletion_impact)): ?>
                        <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px 18px; margin-bottom:20px;">
                            <div style="font-size:11.5px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">What happens to your companies</div>
                            <ul style="margin:0; padding-left:18px; font-size:12.5px; color:var(--txt); line-height:1.7;">
                                <?php foreach($deletion_impact as $imp): ?>
                                    <li>
                                        <strong><?= htmlspecialchars($imp['name']) ?></strong> &mdash;
                                        <?php if($imp['action'] === 'delete_company'): ?>
                                            you're its only member, so it will be <strong style="color:var(--red);">permanently deleted</strong> along with its job postings.
                                        <?php elseif($imp['action'] === 'promote_successor'): ?>
                                            you're the sole admin; <strong><?= htmlspecialchars($imp['successor_name']) ?></strong> will automatically become admin and inherit any jobs you posted.
                                        <?php else: ?>
                                            you'll simply be removed from the team; the company and its jobs carry on normally.
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="POST" onsubmit="return confirm('Are you completely sure you want to permanently delete your employer account? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_account">
                        <div style="margin-bottom:18px; max-width:440px;">
                            <label style="display:block; font-size:11.5px; font-weight:700; color:var(--red); margin-bottom:6px;">Type DELETE to confirm account deletion:</label>
                            <input type="text" name="confirm_delete" placeholder="DELETE" required style="padding:10px 14px; font-size:13px; font-weight:700; letter-spacing:1px; border-color: rgba(255, 77, 106, 0.4);">
                        </div>
                        <button type="submit" class="btn-danger">
                            🗑️ Permanently Delete My Account
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if($user['role'] === 'admin'): ?>
                <?php
                    $cfg = load_app_config();

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
                    
                    <form method="POST" id="smtpConfigForm">
                        <input type="hidden" name="action" value="update_api_key">
                        
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:24px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Google Gemini API Key</label>
                                <input type="password" name="api_key" placeholder="<?= htmlspecialchars($api_key_hint) ?>" value="" autocomplete="off">
                            </div>
                            
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Gemini AI Engine Model</label>
                                <select name="ai_model">
                                    <option value="gemini-3.6-flash" <?= (($_SESSION['ai_model'] ?? 'gemini-3.6-flash') === 'gemini-3.6-flash') ? 'selected' : '' ?>>Gemini 3.6 Flash (Recommended)</option>
                                    <option value="gemini-3.8-flash" <?= (($_SESSION['ai_model'] ?? '') === 'gemini-3.8-flash') ? 'selected' : '' ?>>Gemini 3.8 Flash (Next Gen)</option>
                                    <option value="gemini-3.7-flash" <?= (($_SESSION['ai_model'] ?? '') === 'gemini-3.7-flash') ? 'selected' : '' ?>>Gemini 3.7 Flash</option>
                                    <option value="gemini-flash-latest" <?= (($_SESSION['ai_model'] ?? '') === 'gemini-flash-latest') ? 'selected' : '' ?>>Gemini Flash Latest</option>
                                </select>
                            </div>
                        </div>

                        <div style="border-top:1px dashed var(--bdr); padding-top:20px; margin-top:20px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                                <div style="font-size:14px; font-weight:700; color:var(--txt); display:flex; align-items:center; gap:6px;">
                                    <span>📧</span> PHPMailer SMTP Dispatcher & Anti-Spam Settings
                                </div>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="button" onclick="applySmtpPreset('gmail')" class="btn-secondary" style="padding:5px 12px; font-size:11.5px; font-weight:700; cursor:pointer;">⚡ Gmail Preset</button>
                                    <button type="button" onclick="applySmtpPreset('brevo')" class="btn-secondary" style="padding:5px 12px; font-size:11.5px; font-weight:700; cursor:pointer;">⚡ Brevo Preset</button>
                                    <button type="button" onclick="applySmtpPreset('sendgrid')" class="btn-secondary" style="padding:5px 12px; font-size:11.5px; font-weight:700; cursor:pointer;">⚡ SendGrid Preset</button>
                                </div>
                            </div>

                            <?php if (empty($cfg['smtp_host'])): ?>
                                <!-- High Spam Warning Banner -->
                                <div style="background:#FEF2F2; border:1px solid #FCA5A5; border-left:5px solid #EF4444; border-radius:10px; padding:14px 18px; margin-bottom:20px; font-size:12.5px; color:#991B1B; line-height:1.6;">
                                    <div style="font-weight:700; font-size:13.5px; margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                                        ⚠️ Emails are currently going to Spam / Junk!
                                    </div>
                                    SMTP is not configured. Keria is currently defaulting to PHP's unauthenticated native <code>mail()</code> on localhost. Major providers like <strong>Gmail, Outlook, Yahoo, and iCloud</strong> immediately flag unauthenticated localhost emails as Spam/Junk.
                                    <br><strong>Solution:</strong> Fill in an authenticated SMTP service below (e.g. Gmail with an App Password or SendGrid/Brevo) to ensure 100% primary inbox delivery!
                                </div>
                            <?php else: ?>
                                <!-- Active SMTP Status Banner -->
                                <div style="background:#ECFDF5; border:1px solid #A7F3D0; border-left:5px solid #10B981; border-radius:10px; padding:14px 18px; margin-bottom:20px; font-size:12.5px; color:#065F46; line-height:1.5;">
                                    <strong>✅ Authenticated SMTP Active:</strong> Sending via <code><?= htmlspecialchars($cfg['smtp_host']) ?>:<?= htmlspecialchars($cfg['smtp_port'] ?? '587') ?></code>.
                                    Anti-spam envelope alignment (Return-Path), FQDN Message-ID, and RFC transactional headers are actively attached.
                                </div>
                            <?php endif; ?>

                            <!-- Deliverability & Anti-Spam Guidance Card -->
                            <div style="background:rgba(107, 138, 0, 0.08); border:1px solid rgba(107, 138, 0, 0.25); border-radius:12px; padding:16px 20px; margin-bottom:20px; font-size:12.5px; color:var(--txt); line-height:1.6;">
                                <div style="font-weight:700; margin-bottom:6px; color:var(--txt); font-size:13px;">🛡️ 4 Golden Rules to Prevent Emails from Landing in Junk/Spam:</div>
                                <ul style="margin:0; padding-left:20px;">
                                    <li><strong>1. Always use Authenticated SMTP:</strong> Never use local unauthenticated mail. Use Gmail, Brevo, SendGrid, Mailgun, or your domain's SMTP.</li>
                                    <li><strong>2. From Address Alignment:</strong> The <em>Sender Email (From Header)</em> <u>MUST match</u> your SMTP Username (e.g. your Gmail address). If they differ, Gmail and Outlook flag it as phishing/spoofing.</li>
                                    <li><strong>3. Gmail Users:</strong> You MUST use a 16-character <strong>Google App Password</strong> (not your normal Google account password). Generate one at <a href="https://myaccount.google.com/apppasswords" target="_blank" style="color:var(--acc); font-weight:700;">myaccount.google.com/apppasswords</a>.</li>
                                    <li><strong>4. Custom Domain DNS (SPF & DKIM):</strong> If using a custom domain (e.g. <code>@company.com</code>), ensure your DNS has an SPF record (<code>v=spf1 include:... ~all</code>) and DKIM keys configured.</li>
                                </ul>
                            </div>

                            <div style="display:grid; grid-template-columns:1.5fr 1fr 1fr; gap:16px; margin-bottom:16px;">
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP Host Server</label>
                                    <input type="text" name="smtp_host" id="smtp_host" placeholder="smtp.gmail.com" value="<?= htmlspecialchars($cfg['smtp_host'] ?? '') ?>">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP Port</label>
                                    <input type="number" name="smtp_port" id="smtp_port" placeholder="587" value="<?= htmlspecialchars($cfg['smtp_port'] ?? '587') ?>">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">Encryption Protocol</label>
                                    <select name="smtp_secure" id="smtp_secure">
                                        <option value="tls" <?= (($cfg['smtp_secure'] ?? 'tls') === 'tls') ? 'selected' : '' ?>>TLS / STARTTLS (Port 587 - Recommended)</option>
                                        <option value="ssl" <?= (($cfg['smtp_secure'] ?? '') === 'ssl') ? 'selected' : '' ?>>SSL / SMTPS (Port 465)</option>
                                        <option value="none" <?= (($cfg['smtp_secure'] ?? '') === 'none') ? 'selected' : '' ?>>None / Plain (Port 25)</option>
                                    </select>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP User / Login Email</label>
                                    <input type="text" name="smtp_user" id="smtp_user" placeholder="your.name@gmail.com" value="<?= htmlspecialchars($cfg['smtp_user'] ?? '') ?>" oninput="syncSenderEmailIfEmpty(this.value)">
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">SMTP App Password / Token</label>
                                    <input type="password" name="smtp_pass" id="smtp_pass" placeholder="<?= htmlspecialchars($smtp_pass_hint) ?>" value="" autocomplete="off">
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px;">
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">Sender Email (From Header)</label>
                                    <input type="email" name="smtp_from" id="smtp_from" placeholder="your.name@gmail.com" value="<?= htmlspecialchars($cfg['smtp_from'] ?? '') ?>">
                                    <div style="font-size:11px; color:var(--mut); margin-top:4px;">⚠️ Must match your SMTP user or domain to pass anti-spam checks.</div>
                                </div>
                                <div>
                                    <label style="display:block; font-size:11.5px; font-weight:600; color:var(--mut); margin-bottom:6px;">Sender Display Name</label>
                                    <input type="text" name="smtp_from_name" id="smtp_from_name" placeholder="Keria Recruitment" value="<?= htmlspecialchars($cfg['smtp_from_name'] ?? 'Keria Recruitment Team') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div style="display:flex; justify-content:flex-end;">
                            <button type="submit" class="btn-primary" style="width:auto; padding:11px 28px;">Save System Configuration &rarr;</button>
                        </div>
                    </form>

                    <script>
                        function applySmtpPreset(type) {
                            const host = document.getElementById('smtp_host');
                            const port = document.getElementById('smtp_port');
                            const secure = document.getElementById('smtp_secure');
                            const fromName = document.getElementById('smtp_from_name');
                            
                            if (type === 'gmail') {
                                host.value = 'smtp.gmail.com';
                                port.value = '587';
                                secure.value = 'tls';
                                if (!fromName.value) fromName.value = 'Keria Recruitment Team';
                                alert('Gmail preset applied! Remember to enter your Gmail address in SMTP User and use a 16-character App Password from myaccount.google.com/apppasswords.');
                            } else if (type === 'brevo') {
                                host.value = 'smtp-relay.brevo.com';
                                port.value = '587';
                                secure.value = 'tls';
                            } else if (type === 'sendgrid') {
                                host.value = 'smtp.sendgrid.net';
                                port.value = '587';
                                secure.value = 'tls';
                            }
                        }

                        function syncSenderEmailIfEmpty(val) {
                            const fromInput = document.getElementById('smtp_from');
                            if (!fromInput.value || fromInput.value.includes('@gmail.com') || fromInput.dataset.synced === 'true') {
                                fromInput.value = val;
                                fromInput.dataset.synced = 'true';
                            }
                        }
                    </script>

                    <form method="POST" onsubmit="return confirm('Send test deliverability email to ' + this.test_target_email.value + '?');" style="margin-top:20px; border-top:1px dashed var(--bdr); padding-top:20px; display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
                        <input type="hidden" name="action" value="test_smtp">
                        <div style="flex:1; min-width:280px;">
                            <label style="display:block; font-size:11.5px; font-weight:700; color:var(--mut); margin-bottom:6px;">Deliverability Test Target Recipient</label>
                            <input type="email" name="test_target_email" value="nuriman.kadir01@s.unikl.edu.my" required onkeydown="if(event.key === 'Enter'){ event.preventDefault(); return false; }">
                        </div>
                        <div>
                            <button type="submit" class="btn-secondary" style="padding:11px 20px; font-weight:700; white-space:nowrap;">🧪 Test Inbox Deliverability &rarr;</button>
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
                <div class="panel danger-zone-panel" style="grid-column: 1 / -1; padding:32px;">
                    <div class="panel-title" style="color: #DC2626; display:flex; align-items:center; gap:8px;">
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
                        <button type="submit" class="btn-danger">
                            🗑️ Permanently Delete My Account
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Avatar Crop/Preview Modal: lets the person reposition and zoom
         their photo inside the circle before it's saved, instead of the
         old behaviour of auto-cropping the center and uploading
         immediately with no way to fix a bad crop. Kept as a top-level
         sibling of <main> (not nested inside .panel) because .panel uses
         backdrop-filter, which makes this modal's position:fixed anchor
         to that small panel box instead of the full viewport. -->
    <div class="modal-overlay" id="avatarCropModal" style="display:none;">
        <div class="modal" style="max-width:380px; text-align:center;">
            <h3 style="margin:0 0 4px; font-size:18px; font-weight:800; color:var(--txt);">Adjust Your Photo</h3>
            <p style="margin:0 0 20px; font-size:13px; color:var(--mut);">Drag to reposition, use the slider to zoom.</p>

            <div id="cropFrame" style="position:relative; width:220px; height:220px; margin:0 auto 18px; border-radius:50%; overflow:hidden; background:var(--surf); border:2px solid var(--bdr); cursor:grab;">
                <img id="cropImg" src="" alt="" style="position:absolute; user-select:none; -webkit-user-drag:none;" draggable="false">
            </div>

            <input type="range" id="cropZoom" min="100" max="300" value="100" style="width:100%; margin-bottom:20px;">

            <div style="display:flex; gap:10px; justify-content:center;">
                <button type="button" class="btn-secondary" style="width:auto; padding:10px 22px;" onclick="cancelAvatarCrop()">Cancel</button>
                <button type="button" class="btn-primary" style="width:auto; padding:10px 22px;" onclick="confirmAvatarCrop()">✓ Use This Photo</button>
            </div>
        </div>
    </div>

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

        // Avatar crop/preview: instead of auto-cropping the image center and
        // uploading immediately, open a modal where the person can drag to
        // reposition and use a slider to zoom, then confirm before it saves.
        const CROP_FRAME = 220;
        let cropState = { naturalWidth: 0, naturalHeight: 0, baseScale: 1, scale: 1, offsetX: 0, offsetY: 0, dragging: false, startX: 0, startY: 0, startOffsetX: 0, startOffsetY: 0 };

        function handleAvatarUpload(input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];

            if (!file.type.match(/^image\/(png|jpeg|jpg|webp)$/i)) {
                alert('Please select a valid PNG, JPG, or WEBP image.');
                input.value = '';
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const img = new Image();
                img.onload = function() {
                    cropState.naturalWidth = img.naturalWidth;
                    cropState.naturalHeight = img.naturalHeight;
                    cropState.baseScale = Math.max(CROP_FRAME / img.naturalWidth, CROP_FRAME / img.naturalHeight);
                    cropState.scale = cropState.baseScale;

                    const cropImg = document.getElementById('cropImg');
                    cropImg.src = e.target.result;
                    applyCropTransform();
                    cropState.offsetX = (CROP_FRAME - cropState.naturalWidth * cropState.scale) / 2;
                    cropState.offsetY = (CROP_FRAME - cropState.naturalHeight * cropState.scale) / 2;
                    applyCropTransform();

                    document.getElementById('cropZoom').value = 100;
                    document.getElementById('avatarCropModal').style.display = 'flex';
                };
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        }

        function applyCropTransform() {
            const cropImg = document.getElementById('cropImg');
            cropImg.style.width = (cropState.naturalWidth * cropState.scale) + 'px';
            cropImg.style.height = (cropState.naturalHeight * cropState.scale) + 'px';
            cropImg.style.left = cropState.offsetX + 'px';
            cropImg.style.top = cropState.offsetY + 'px';
        }

        function clampCropOffsets() {
            const dispW = cropState.naturalWidth * cropState.scale;
            const dispH = cropState.naturalHeight * cropState.scale;
            cropState.offsetX = Math.min(0, Math.max(CROP_FRAME - dispW, cropState.offsetX));
            cropState.offsetY = Math.min(0, Math.max(CROP_FRAME - dispH, cropState.offsetY));
        }

        document.addEventListener('DOMContentLoaded', function() {
            const frame = document.getElementById('cropFrame');
            const zoomSlider = document.getElementById('cropZoom');
            if (!frame || !zoomSlider) return;

            function dragStart(clientX, clientY) {
                cropState.dragging = true;
                cropState.startX = clientX;
                cropState.startY = clientY;
                cropState.startOffsetX = cropState.offsetX;
                cropState.startOffsetY = cropState.offsetY;
                frame.style.cursor = 'grabbing';
            }
            function dragMove(clientX, clientY) {
                if (!cropState.dragging) return;
                cropState.offsetX = cropState.startOffsetX + (clientX - cropState.startX);
                cropState.offsetY = cropState.startOffsetY + (clientY - cropState.startY);
                clampCropOffsets();
                applyCropTransform();
            }
            function dragEnd() {
                cropState.dragging = false;
                frame.style.cursor = 'grab';
            }

            frame.addEventListener('mousedown', e => dragStart(e.clientX, e.clientY));
            window.addEventListener('mousemove', e => dragMove(e.clientX, e.clientY));
            window.addEventListener('mouseup', dragEnd);

            frame.addEventListener('touchstart', e => { dragStart(e.touches[0].clientX, e.touches[0].clientY); }, { passive: true });
            frame.addEventListener('touchmove', e => { dragMove(e.touches[0].clientX, e.touches[0].clientY); e.preventDefault(); }, { passive: false });
            frame.addEventListener('touchend', dragEnd);

            zoomSlider.addEventListener('input', function() {
                const centerImgX = (CROP_FRAME / 2 - cropState.offsetX) / cropState.scale;
                const centerImgY = (CROP_FRAME / 2 - cropState.offsetY) / cropState.scale;
                cropState.scale = cropState.baseScale * (this.value / 100);
                cropState.offsetX = CROP_FRAME / 2 - centerImgX * cropState.scale;
                cropState.offsetY = CROP_FRAME / 2 - centerImgY * cropState.scale;
                clampCropOffsets();
                applyCropTransform();
            });
        });

        function cancelAvatarCrop() {
            document.getElementById('avatarCropModal').style.display = 'none';
            document.getElementById('pic_input').value = '';
        }

        function confirmAvatarCrop() {
            const cropImg = document.getElementById('cropImg');
            const OUTPUT = 500;
            const cropX = -cropState.offsetX / cropState.scale;
            const cropY = -cropState.offsetY / cropState.scale;
            const cropSize = CROP_FRAME / cropState.scale;

            const canvas = document.createElement('canvas');
            canvas.width = OUTPUT;
            canvas.height = OUTPUT;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(cropImg, cropX, cropY, cropSize, cropSize, 0, 0, OUTPUT, OUTPUT);

            document.getElementById('pic_base64').value = canvas.toDataURL('image/jpeg', 0.88);
            document.getElementById('pic_input').value = '';
            document.getElementById('avatarCropModal').style.display = 'none';
            document.getElementById('avatarForm').submit();
        }
    </script>
    <script src="theme.js"></script>
</body>
</html>

