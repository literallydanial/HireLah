<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';
require_once 'company_helpers.php';

// Universities for the candidate-signup "school" dropdown (self-selected, no verification).
$universities_list = $pdo->query("SELECT id, name, type FROM universities ORDER BY FIELD(type,'public','private','other'), name")->fetchAll();

// A ?invite=TOKEN link (from a company's Settings > Team page, or its QR
// code) always registers the new account as an employer joining that
// specific company as HR — remembered across the OTP step via session so it
// still applies even if the token isn't resubmitted with the POST.
$invite_token = $_GET['invite'] ?? $_POST['invite_token'] ?? null;
if ($invite_token) {
    $_SESSION['pending_invite_token'] = $invite_token;
}
$invite_company = !empty($_SESSION['pending_invite_token']) ? get_company_by_invite_token($pdo, $_SESSION['pending_invite_token']) : null;
if ($invite_token && !$invite_company) {
    // token was present but doesn't resolve to anything (revoked/rotated/typo)
    unset($_SESSION['pending_invite_token']);
}

// A ?type=university link (from the "For Universities" page) forces the
// account type to university and swaps the form's copy below, the same way
// an invite link forces 'employer'.
$account_type = $_GET['type'] ?? $_POST['account_type'] ?? null;
$is_university_signup = ($account_type === 'university') && !$invite_company;

// Resolve preset university if admin provided it in the link
$admin_preset_uni_name = trim($_GET['university_name'] ?? $_GET['uni_name'] ?? $_POST['institution_name'] ?? '');
$admin_preset_uni_type = trim($_GET['university_type'] ?? $_GET['uni_type'] ?? $_POST['university_type'] ?? 'public');
$admin_preset_uni_id = (string) ($_GET['university_id'] ?? $_POST['university_id'] ?? '');

if ($is_university_signup) {
    if ($admin_preset_uni_id !== '' && $admin_preset_uni_id !== 'other' && $admin_preset_uni_name === '') {
        foreach ($universities_list as $u) {
            if ((string)$u['id'] === $admin_preset_uni_id) {
                $admin_preset_uni_name = $u['name'];
                $admin_preset_uni_type = $u['type'];
                break;
            }
        }
    }
    if ($admin_preset_uni_name !== '' && $admin_preset_uni_id === '') {
        foreach ($universities_list as $u) {
            if (strcasecmp($u['name'], $admin_preset_uni_name) === 0) {
                $admin_preset_uni_id = (string)$u['id'];
                $admin_preset_uni_name = $u['name'];
                $admin_preset_uni_type = $u['type'];
                break;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'] ?? '';
    $role = $invite_company ? 'employer' : ($is_university_signup ? 'university' : $_POST['role']);
    $institution_name = null;
    $university_id = null;
    $matric_number = null;

    if (!isset($_POST['agree_terms'])) {
        $error = "You must agree to the Terms & Conditions and PDPA Act 2010 Policy to create an account.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match. Please try again.";
    } elseif (!in_array($role, ['candidate', 'employer', 'university'])) {
        $role = 'candidate';
    }

    if ($role === 'university' && !isset($error)) {
        $uni_type = in_array($_POST['university_type'] ?? '', ['public', 'private'], true) ? $_POST['university_type'] : 'public';
        $institution_name = trim($_POST['institution_name'] ?? $admin_preset_uni_name);
        if ($institution_name === '') {
            $error = "Please provide your university name.";
        } else {
            $uni_check = $pdo->prepare("SELECT id, name FROM universities WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $uni_check->execute([$institution_name]);
            $uni_row = $uni_check->fetch();
            if ($uni_row) {
                $university_id = (int)$uni_row['id'];
                $upd_uni = $pdo->prepare("UPDATE universities SET type = ? WHERE id = ?");
                $upd_uni->execute([$uni_type, $university_id]);
            } else {
                $ins_uni = $pdo->prepare("INSERT INTO universities (name, type) VALUES (?, ?)");
                $ins_uni->execute([$institution_name, $uni_type]);
                $university_id = (int)$pdo->lastInsertId();
            }
        }
    }

    // A candidate may optionally self-link to their own university + matric number
    if ($role === 'candidate' && !isset($error)) {
        $candidate_uni = trim($_POST['candidate_university_name'] ?? $_POST['university_name'] ?? '');
        if ($candidate_uni !== '') {
            $uni_check = $pdo->prepare("SELECT id, name FROM universities WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $uni_check->execute([$candidate_uni]);
            $uni_row = $uni_check->fetch();
            if ($uni_row) {
                $university_id = (int)$uni_row['id'];
                $institution_name = $uni_row['name'];
            } else {
                $institution_name = $candidate_uni;
            }
        }
        $matric_number = trim($_POST['matric_number'] ?? '') ?: null;
    }

    // Check if email exists
    if (!isset($error)) {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $error = "Email is already registered.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $otp = sprintf("%06d", mt_rand(100000, 999999));
        $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, company_name, university_id, matric_number, is_verified, otp_code, otp_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)");
        if ($stmt->execute([$name, $email, $hash, $role, $institution_name, $university_id, $matric_number, $otp, $otp_expires])) {
            $user_id = $pdo->lastInsertId();
            $_SESSION['pending_otp_user_id'] = $user_id;

            // Dispatch OTP email
            $mail_res = send_otp_email($name, $email, $otp);
            if (!empty($mail_res['success'])) {
                $_SESSION['toast'] = "Account created! Please enter the 6-digit OTP code sent to $email.";
            } else {
                $_SESSION['toast'] = "Account created! OTP Code: $otp (Email note: " . htmlspecialchars($mail_res['error'] ?? 'Configure SMTP') . ")";
            }

            header("Location: verify_otp.php?user_id=" . urlencode($user_id));
            exit;
        } else {
            $error = "Registration failed. Please try again.";
        }
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Keria</title>
    <meta name="description" content="Create your free Keria account to apply for jobs with an AI-built resume, or post job openings as an employer.">
    <link rel="canonical" href="https://thekeria.com/register.php">
    <meta name="robots" content="noindex, follow">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Keria">
    <meta property="og:title" content="Register - Keria">
    <meta property="og:description" content="Create your free Keria account to apply for jobs with an AI-built resume, or post job openings as an employer.">
    <meta property="og:url" content="https://thekeria.com/register.php">
    <meta property="og:image" content="https://thekeria.com/logo/logo.png">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Register - Keria">
    <meta name="twitter:description" content="Create your free Keria account to apply for jobs with an AI-built resume, or post job openings as an employer.">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
    <style>
        :root {
            --keria-lime: #D2FF3A;
            --keria-lime-hover: #C2F025;
            --font-hand: 'Caveat', cursive;
        }
        body.auth-page {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            box-sizing: border-box;
            background: #F3F6E8;
            background-image:
                radial-gradient(circle at 8% 12%, rgba(210, 255, 58, 0.30) 0%, transparent 42%),
                radial-gradient(circle at 94% 88%, rgba(210, 255, 58, 0.26) 0%, transparent 45%);
            overflow-x: hidden;
            position: relative;
        }
        [data-theme="dark"] body.auth-page {
            background: #18191C;
            background-image:
                radial-gradient(circle at 8% 12%, rgba(210, 255, 58, 0.14) 0%, transparent 42%),
                radial-gradient(circle at 94% 88%, rgba(210, 255, 58, 0.12) 0%, transparent 45%);
        }
        .auth-doodle {
            position: absolute;
            width: 90px;
            height: 90px;
            opacity: 0.6;
            pointer-events: none;
        }
        .auth-doodle-tl { top: 6%; left: 4%; }
        .auth-doodle-br { bottom: 6%; right: 4%; transform: rotate(180deg); }
        .auth-card-wrap {
            position: relative;
            max-width: 460px;
            width: 100%;
            z-index: 2;
        }
        .auth-card {
            background: var(--card-solid, #FFFFFF);
            border-radius: 28px;
            box-shadow: 0 20px 50px -10px rgba(0, 0, 0, 0.12), 0 4px 14px rgba(0, 0, 0, 0.04);
            padding: 40px 36px;
            text-align: center;
            position: relative;
            z-index: 2;
        }
        .auth-mascot-full {
            position: absolute;
            width: 210px;
            top: 50%;
            z-index: 1;
            pointer-events: none;
            user-select: none;
            animation: auth-mascot-float 3.6s ease-in-out infinite;
        }
        .auth-mascot-full-left { left: -340px; transform: translateY(-50%); animation-delay: 0s; }
        .auth-mascot-full-right { right: -340px; transform: translateY(-50%); animation-delay: -1.8s; }
        @keyframes auth-mascot-float {
            0%, 100% { margin-top: 0; }
            50% { margin-top: -10px; }
        }
        .auth-mascot-img {
            display: block;
            width: 100%;
            height: auto;
            position: relative;
            z-index: 2;
        }
        .auth-mascot-shadow {
            position: absolute;
            left: 50%;
            bottom: 2%;
            transform: translateX(-50%);
            width: 62%;
            height: 16px;
            background: radial-gradient(ellipse at center, rgba(45, 74, 28, 0.28) 0%, rgba(45, 74, 28, 0) 75%);
            z-index: 1;
        }
        .auth-mascot-spark {
            position: absolute;
            width: 34px;
            height: 34px;
            z-index: 3;
            opacity: 0.85;
        }
        .auth-mascot-full-left .auth-mascot-spark { top: -6px; right: -4px; }
        .auth-mascot-full-right .auth-mascot-spark { top: -2px; left: -6px; transform: scaleX(-1); }
        .auth-mascot-star {
            position: absolute;
            z-index: 3;
            fill: #A8D62E;
            pointer-events: none;
        }
        .auth-mascot-star-1 { width: 16px; height: 16px; top: 14%; left: -16px; opacity: 0.8; }
        .auth-mascot-star-2 { width: 11px; height: 11px; top: 42%; right: -10px; opacity: 0.65; }
        .auth-mascot-star-3 { width: 13px; height: 13px; bottom: 8%; left: -12px; opacity: 0.7; }
        @media (max-width: 1150px) {
            .auth-mascot-full { display: none; }
        }
        @media (prefers-reduced-motion: reduce) {
            .auth-mascot-full { animation: none; }
        }
        .auth-logo {
            height: 56px;
            width: auto;
            object-fit: contain;
            margin-bottom: 8px;
        }
        .auth-title {
            font-family: var(--font-hand);
            font-size: 40px;
            font-weight: 700;
            color: #2D4A1C;
            line-height: 1;
            margin: 4px 0 6px;
        }
        [data-theme="dark"] .auth-title {
            color: var(--keria-lime);
        }
        .auth-subtitle {
            font-size: 13.5px;
            color: var(--mut);
            margin-bottom: 26px;
        }
        .auth-field-label {
            display: block;
            font-size: 11.5px;
            color: var(--mut);
            font-weight: 700;
            margin-bottom: 5px;
            text-align: left;
        }
        .auth-input-wrap {
            position: relative;
            margin-bottom: 14px;
            text-align: left;
        }
        .auth-input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 15px;
            opacity: 0.55;
            pointer-events: none;
            z-index: 1;
        }
        .auth-input-wrap input,
        .auth-input-wrap select {
            width: 100%;
            box-sizing: border-box;
            padding: 14px 18px 14px 44px;
            border-radius: 999px;
            border: 1.5px solid var(--bdr);
            background: var(--dim);
            font-size: 14px;
            font-family: inherit;
            color: var(--txt);
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            appearance: none;
            -webkit-appearance: none;
        }
        .auth-input-wrap select {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 18px center;
            padding-right: 36px;
        }
        .auth-input-wrap input:focus,
        .auth-input-wrap select:focus {
            border-color: var(--keria-lime-hover);
            box-shadow: 0 0 0 3px rgba(210, 255, 58, 0.25);
            background-color: var(--card-solid, #FFFFFF);
        }
        .auth-password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 15px;
            opacity: 0.55;
            padding: 4px;
            line-height: 1;
            z-index: 1;
        }
        .auth-password-toggle:hover { opacity: 0.9; }
        .auth-banner {
            border-radius: 14px;
            padding: 12px;
            margin-bottom: 16px;
            text-align: left;
            font-size: 13px;
        }
        .auth-checkbox-row {
            margin-bottom: 20px;
            text-align: left;
            font-size: 12px;
            color: var(--mut);
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .auth-submit-btn {
            width: 100%;
            background: var(--keria-lime);
            color: #0A0A0A;
            border: none;
            border-radius: 999px;
            padding: 15px 24px;
            font-size: 15px;
            font-weight: 800;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(210, 255, 58, 0.35);
            transition: all 0.2s ease;
        }
        .auth-submit-btn:hover {
            background: var(--keria-lime-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(210, 255, 58, 0.45);
        }
        .auth-bottom-text {
            margin-top: 22px;
            font-size: 13px;
            color: var(--mut);
        }
        .auth-bottom-text a {
            color: var(--acc);
            font-weight: 800;
            text-decoration: none;
        }
    </style>
</head>
<body class="auth-page">
    <svg class="auth-doodle auth-doodle-tl" viewBox="0 0 100 100" fill="none">
        <path d="M15 70 Q 10 45, 30 35 Q 50 25, 45 50 Q 42 65, 60 60" stroke="#A8D62E" stroke-width="3.5" stroke-linecap="round" opacity="0.7"/>
    </svg>
    <svg class="auth-doodle auth-doodle-br" viewBox="0 0 100 100" fill="none">
        <path d="M15 70 Q 10 45, 30 35 Q 50 25, 45 50 Q 42 65, 60 60" stroke="#A8D62E" stroke-width="3.5" stroke-linecap="round" opacity="0.7"/>
    </svg>

    <div class="auth-card-wrap">
        <div class="auth-mascot-full auth-mascot-full-left">
            <svg class="auth-mascot-spark" viewBox="0 0 40 40" fill="none">
                <path d="M9 7 L15 15" stroke="#A8D62E" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M4 21 L13 23" stroke="#A8D62E" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M11 31 L17 26" stroke="#A8D62E" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
            <svg class="auth-mascot-star auth-mascot-star-1" viewBox="0 0 24 24"><path d="M12 0 L14 10 L24 12 L14 14 L12 24 L10 14 L0 12 L10 10 Z"/></svg>
            <svg class="auth-mascot-star auth-mascot-star-2" viewBox="0 0 24 24"><path d="M12 0 L14 10 L24 12 L14 14 L12 24 L10 14 L0 12 L10 10 Z"/></svg>
            <svg class="auth-mascot-star auth-mascot-star-3" viewBox="0 0 24 24"><path d="M12 0 L14 10 L24 12 L14 14 L12 24 L10 14 L0 12 L10 10 Z"/></svg>
            <img src="assets/register_mascot_left.png?v=<?php echo @filemtime(__DIR__.'/assets/register_mascot_left.png'); ?>" alt="" class="auth-mascot-img">
            <div class="auth-mascot-shadow"></div>
        </div>
        <div class="auth-mascot-full auth-mascot-full-right">
            <svg class="auth-mascot-spark" viewBox="0 0 40 40" fill="none">
                <path d="M9 7 L15 15" stroke="#A8D62E" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M4 21 L13 23" stroke="#A8D62E" stroke-width="2.5" stroke-linecap="round"/>
                <path d="M11 31 L17 26" stroke="#A8D62E" stroke-width="2.5" stroke-linecap="round"/>
            </svg>
            <svg class="auth-mascot-star auth-mascot-star-1" viewBox="0 0 24 24"><path d="M12 0 L14 10 L24 12 L14 14 L12 24 L10 14 L0 12 L10 10 Z"/></svg>
            <svg class="auth-mascot-star auth-mascot-star-2" viewBox="0 0 24 24"><path d="M12 0 L14 10 L24 12 L14 14 L12 24 L10 14 L0 12 L10 10 Z"/></svg>
            <svg class="auth-mascot-star auth-mascot-star-3" viewBox="0 0 24 24"><path d="M12 0 L14 10 L24 12 L14 14 L12 24 L10 14 L0 12 L10 10 Z"/></svg>
            <img src="assets/register_mascot_right.png?v=<?php echo @filemtime(__DIR__.'/assets/register_mascot_right.png'); ?>" alt="" class="auth-mascot-img">
            <div class="auth-mascot-shadow"></div>
        </div>

        <div class="auth-card">
            <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" class="auth-logo">
            <div class="auth-title"><?= $is_university_signup ? 'Register Your University' : "Let's Get Started" ?></div>
            <div class="auth-subtitle"><?= $is_university_signup ? 'Get a live view of your students\' career-readiness' : 'Create your Keria account' ?></div>

            <?php if ($invite_company): ?>
                <div class="auth-banner" style="background:rgba(0,232,122,0.1); border:1px solid rgba(0,232,122,0.35); color:var(--txt);">
                    🤝 You're joining <strong><?= htmlspecialchars($invite_company['name']) ?></strong> as an HR teammate. We'll add you to their team automatically once you verify your email.
                </div>
            <?php elseif ($is_university_signup): ?>
                <div class="auth-banner" style="background:rgba(217,255,79,0.1); border:1px solid rgba(217,255,79,0.35); color:var(--txt);">
                    <?php if ($admin_preset_uni_name !== ''): ?>
                        🎓 Registering for <strong><?= htmlspecialchars($admin_preset_uni_name) ?></strong> (University / Career Center).
                    <?php else: ?>
                        🎓 Registering as a <strong>University / Career Center</strong>. You'll get access to the university dashboard once your email is verified.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['toast'])): ?>
                <div class="toast-notification">
                    <span class="toast-icon-badge">🌿</span>
                    <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                    <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                    <?php unset($_SESSION['toast']); ?>
                </div>
            <?php endif; ?>

            <?php if(isset($error)): ?>
                <div class="auth-banner" style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); color:var(--red);">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST"<?= $is_university_signup ? ' action="register.php?type=university' . ($admin_preset_uni_name !== '' ? '&university_name=' . urlencode($admin_preset_uni_name) . ($admin_preset_uni_id !== '' ? '&university_id=' . urlencode($admin_preset_uni_id) : '') : '') . '"' : '' ?>>
                <?php if ($is_university_signup): ?>
                    <?php if ($admin_preset_uni_name !== ''): ?>
                        <!-- Pre-set by admin: Just use the one that admin put -->
                        <input type="hidden" name="university_id" value="<?= htmlspecialchars($admin_preset_uni_id) ?>">
                        <input type="hidden" name="university_type" value="<?= htmlspecialchars($admin_preset_uni_type) ?>">
                        <input type="hidden" name="institution_name" value="<?= htmlspecialchars($admin_preset_uni_name) ?>">
                        <div style="margin-bottom:14px;">
                            <label class="auth-field-label">University / Institution</label>
                            <div style="display:flex; align-items:center; gap:8px; padding:11px 14px; background:var(--dim); border:1px solid var(--bdr); border-radius:14px; font-size:13.5px; font-weight:700; color:var(--txt);">
                                <span><?= $admin_preset_uni_type === 'private' ? '🏫' : '🏛️' ?></span>
                                <span style="flex:1;"><?= htmlspecialchars($admin_preset_uni_name) ?></span>
                                <span style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase;"><?= htmlspecialchars($admin_preset_uni_type) ?></span>
                                <span style="font-size:10.5px; font-weight:700; color:var(--acc); background:rgba(217,255,79,0.15); padding:2px 8px; border-radius:6px;">Pre-set</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Generic signup: select Public or Private, and enter university name -->
                        <div style="margin-bottom:12px;">
                            <label class="auth-field-label">University Classification</label>
                            <div class="auth-input-wrap" style="margin-bottom:0;">
                                <span class="auth-input-icon">🏫</span>
                                <select name="university_type" required>
                                    <option value="public" <?= (($_POST['university_type'] ?? '') === 'public') ? 'selected' : '' ?>>Public University</option>
                                    <option value="private" <?= (($_POST['university_type'] ?? '') === 'private') ? 'selected' : '' ?>>Private University</option>
                                </select>
                            </div>
                        </div>
                        <div style="margin-bottom:12px;">
                            <label class="auth-field-label">University Name</label>
                            <div class="auth-input-wrap" style="margin-bottom:0;">
                                <span class="auth-input-icon">🎓</span>
                                <input type="text" name="institution_name" list="existingUniversitiesDatalist" placeholder="e.g. Universiti Malaya" required value="<?= htmlspecialchars($_POST['institution_name'] ?? '') ?>">
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon">👤</span>
                        <input type="text" name="name" placeholder="Contact Person Name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                <?php else: ?>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon">👤</span>
                        <input type="text" name="name" placeholder="Full name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                    </div>
                <?php endif; ?>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">✉️</span>
                    <input type="email" name="email" placeholder="Email address" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">🔒</span>
                    <input type="password" name="password" id="registerPasswordField" placeholder="Password" required style="padding-right:42px;">
                    <button type="button" class="auth-password-toggle" onclick="toggleRegisterPasswordVisibility()" title="Show/Hide Password">👁️</button>
                </div>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">🔒</span>
                    <input type="password" name="confirm_password" id="registerConfirmPasswordField" placeholder="Confirm password" required style="padding-right:42px;">
                    <button type="button" class="auth-password-toggle" onclick="toggleRegisterConfirmPasswordVisibility()" title="Show/Hide Password">👁️</button>
                </div>
                <?php if ($invite_company): ?>
                    <input type="hidden" name="role" value="employer">
                    <input type="hidden" name="invite_token" value="<?= htmlspecialchars($_SESSION['pending_invite_token']) ?>">
                    <div style="margin-bottom:16px; text-align:left; font-size:12.5px; color:var(--mut); padding:8px 0;">Account type: <strong style="color:var(--txt);">Employer (HR)</strong> — fixed by the invite link.</div>
                <?php elseif ($is_university_signup): ?>
                    <input type="hidden" name="role" value="university">
                    <input type="hidden" name="account_type" value="university">
                    <div style="margin-bottom:16px; text-align:left; font-size:12.5px; color:var(--mut); padding:8px 0;">Account type: <strong style="color:var(--txt);">University / Career Center</strong><?php if ($admin_preset_uni_name !== ''): ?> &mdash; pre-set for <strong style="color:var(--txt);"><?= htmlspecialchars($admin_preset_uni_name) ?></strong><?php endif; ?>.</div>
                <?php else: ?>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon">👤</span>
                        <select name="role" id="registerRoleSelect" required onchange="toggleStudentFields()">
                            <option value="candidate">I am a Candidate</option>
                            <option value="employer">I am an Employer</option>
                        </select>
                    </div>
                    <div id="studentFieldsBlock">
                        <label class="auth-field-label">My University (optional — students only)</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">🎓</span>
                            <input type="text" name="candidate_university_name" list="existingUniversitiesDatalist" placeholder="e.g. Universiti Malaya, UniKL..." value="<?= htmlspecialchars($_POST['candidate_university_name'] ?? '') ?>">
                        </div>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">🪪</span>
                            <input type="text" name="matric_number" placeholder="Matric / Student ID Number (optional)" value="<?= htmlspecialchars($_POST['matric_number'] ?? '') ?>">
                        </div>
                        <div style="font-size:11px; color:var(--mut); margin-top:-6px; margin-bottom:14px; text-align:left;">Only fill this in if you're a student — it lets your university see your career-readiness activity on their dashboard.</div>
                    </div>
                    <script>
                        function toggleStudentFields() {
                            var role = document.getElementById('registerRoleSelect').value;
                            document.getElementById('studentFieldsBlock').style.display = (role === 'candidate') ? 'block' : 'none';
                        }
                        document.addEventListener('DOMContentLoaded', toggleStudentFields);
                    </script>
                <?php endif; ?>

                <div class="auth-checkbox-row">
                    <input type="checkbox" name="agree_terms" id="agree_terms" required style="width:18px; height:18px; min-height:18px; margin-top:1px; cursor:pointer; flex-shrink:0;">
                    <label for="agree_terms" style="line-height:1.45; cursor:pointer; color:var(--txt);">
                        I agree to the <a href="terms.php" target="_blank" style="color:var(--acc); font-weight:700; text-decoration:underline;">Terms & Conditions</a> and <a href="terms.php#pdpa" target="_blank" style="color:var(--acc); font-weight:700; text-decoration:underline;">PDPA Act 2010 Policy</a>.
                    </label>
                </div>

                <button type="submit" class="auth-submit-btn">Register &rarr;</button>
            </form>

            <div class="auth-bottom-text">
                Already have an account? <a href="login.php">Login here</a>
                <?php if ($invite_company): ?>&mdash; you'll be added to <?= htmlspecialchars($invite_company['name']) ?> right after you log in.<?php endif; ?>
            </div>
        </div>
    </div>

    <datalist id="existingUniversitiesDatalist">
        <?php foreach ($universities_list as $u): ?>
            <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['type']) ?>)</option>
        <?php endforeach; ?>
    </datalist>

<script>
    function toggleRegisterPasswordVisibility() {
        var pwd = document.getElementById('registerPasswordField');
        if (pwd) pwd.type = pwd.type === 'password' ? 'text' : 'password';
    }
    function toggleRegisterConfirmPasswordVisibility() {
        var pwd = document.getElementById('registerConfirmPasswordField');
        if (pwd) pwd.type = pwd.type === 'password' ? 'text' : 'password';
    }
</script>
<script src="theme.js"></script></body>
</html>
