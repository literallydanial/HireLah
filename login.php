<?php
session_start();
require_once 'db.php';
require_once 'company_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // Require OTP Email Verification ONLY for newly registered accounts with pending OTP
        if (isset($user['is_verified']) && (int)$user['is_verified'] === 0 && !empty($user['otp_code'])) {
            $_SESSION['pending_otp_user_id'] = $user['id'];
            $_SESSION['toast'] = "Please verify your email address with the 6-digit OTP code to complete registration.";
            header("Location: verify_otp.php?user_id=" . urlencode($user['id']));
            exit;
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];

        $_SESSION['toast'] = "Welcome back, " . $user['name'] . "!";

        // If they arrived via a company invite link/QR (and logged in with an
        // existing employer account rather than registering fresh), join that
        // company now — instant join, the link itself is the permission.
        if ($user['role'] === 'employer' && !empty($_SESSION['pending_invite_token'])) {
            $invite_company = get_company_by_invite_token($pdo, $_SESSION['pending_invite_token']);
            if ($invite_company) {
                join_company($pdo, $user['id'], $invite_company['id']);
                $_SESSION['toast'] = "Welcome back! You've joined " . $invite_company['name'] . " as an HR teammate.";
            }
            unset($_SESSION['pending_invite_token']);
        }

        // Redirect back to intended job application page if candidate was applying before login
        if ($user['role'] === 'candidate' && !empty($_SESSION['redirect_after_login'])) {
            $target = $_SESSION['redirect_after_login'];
            unset($_SESSION['redirect_after_login']);
            header("Location: " . $target);
            exit;
        }
        
        // Redirect based on role
        if ($user['role'] === 'admin') {
            header("Location: admin_dashboard.php");
        } elseif ($user['role'] === 'management') {
            header("Location: management_dashboard.php");
        } elseif ($user['role'] === 'employer') {
            header("Location: employer_dashboard.php");
        } elseif ($user['role'] === 'university') {
            header("Location: university_dashboard.php");
        } else {
            header("Location: jobs.php"); // candidate dashboard
        }
        exit;
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Keria</title>
    <meta name="description" content="Log in to your Keria account to manage job applications, resumes, or job postings.">
    <link rel="canonical" href="https://thekeria.com/login.php">
    <meta name="robots" content="noindex, follow">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Keria">
    <meta property="og:title" content="Login - Keria">
    <meta property="og:description" content="Log in to your Keria account to manage job applications, resumes, or job postings.">
    <meta property="og:url" content="https://thekeria.com/login.php">
    <meta property="og:image" content="https://thekeria.com/logo/logo.png">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Login - Keria">
    <meta name="twitter:description" content="Log in to your Keria account to manage job applications, resumes, or job postings.">
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
        .auth-grip-accent {
            position: absolute;
            width: 7px;
            background: var(--keria-lime);
            border-radius: 999px;
            z-index: 2;
        }
        .auth-grip-accent-left {
            left: -7px;
        }
        .auth-grip-accent-right {
            right: -7px;
        }
        .auth-grip-accent-left-top { top: 40px; height: 145px; }
        .auth-grip-accent-left-bottom { top: 278px; height: 44px; }
        .auth-grip-accent-right-top { top: 40px; height: 130px; }
        .auth-grip-accent-right-bottom { top: 232px; height: 46px; }
        .auth-mascot {
            position: absolute;
            top: 55px;
            width: 165px;
            height: auto;
            z-index: 3;
            pointer-events: none;
            user-select: none;
        }
        .auth-mascot-left {
            left: -147px;
        }
        .auth-mascot-right {
            right: -147px;
        }
        @media (max-width: 640px) {
            .auth-mascot, .auth-grip-accent, .auth-grip-backing { display: none; }
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
        .auth-input-wrap {
            position: relative;
            margin-bottom: 14px;
        }
        .auth-input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 15px;
            opacity: 0.55;
            pointer-events: none;
        }
        .auth-input-wrap input {
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
        }
        .auth-input-wrap input:focus {
            border-color: var(--keria-lime-hover);
            box-shadow: 0 0 0 3px rgba(210, 255, 58, 0.25);
            background: var(--card-solid, #FFFFFF);
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
        }
        .auth-password-toggle:hover { opacity: 0.9; }
        .auth-forgot-row {
            text-align: right;
            margin-bottom: 20px;
        }
        .auth-forgot-row a {
            font-size: 12.5px;
            color: var(--acc);
            font-weight: 700;
            text-decoration: none;
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
        <div class="auth-grip-backing auth-grip-backing-left"></div>
        <div class="auth-grip-backing auth-grip-backing-right"></div>
        <div class="auth-grip-accent auth-grip-accent-left auth-grip-accent-left-top"></div>
        <div class="auth-grip-accent auth-grip-accent-left auth-grip-accent-left-bottom"></div>
        <div class="auth-grip-accent auth-grip-accent-right auth-grip-accent-right-top"></div>
        <div class="auth-grip-accent auth-grip-accent-right auth-grip-accent-right-bottom"></div>
        <img src="assets/login_mascot_left.png?v=<?php echo @filemtime(__DIR__.'/assets/login_mascot_left.png'); ?>" alt="" class="auth-mascot auth-mascot-left">
        <img src="assets/login_mascot_right.png?v=<?php echo @filemtime(__DIR__.'/assets/login_mascot_right.png'); ?>" alt="" class="auth-mascot auth-mascot-right">

        <div class="auth-card">
            <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" class="auth-logo">
            <div class="auth-title">Welcome Back</div>
            <div class="auth-subtitle">Login to your Keria account</div>

            <?php if(isset($error)): ?>
                <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['error'])): ?>
                <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                    <?= htmlspecialchars($_SESSION['error']) ?>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">✉️</span>
                    <input type="email" name="email" placeholder="Email address" required>
                </div>
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">🔒</span>
                    <input type="password" name="password" id="loginPasswordField" placeholder="Password" required style="padding-right:42px;">
                    <button type="button" class="auth-password-toggle" onclick="toggleLoginPasswordVisibility()" title="Show/Hide Password">👁️</button>
                </div>

                <div class="auth-forgot-row">
                    <a href="forgot_password.php">Forgot password?</a>
                </div>

                <button type="submit" class="auth-submit-btn">Login &rarr;</button>
            </form>

            <div class="auth-bottom-text">
                Don't have an account? <a href="register.php">Register here</a>
            </div>
        </div>
    </div>

<script>
    function toggleLoginPasswordVisibility() {
        var pwd = document.getElementById('loginPasswordField');
        if (pwd) pwd.type = pwd.type === 'password' ? 'text' : 'password';
    }
</script>
<script src="theme.js"></script></body>
</html>
