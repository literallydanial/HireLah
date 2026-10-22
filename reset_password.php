<?php
session_start();
require_once 'db.php';

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$valid_token = false;
$user = null;
$error = '';

if (!empty($token)) {
    $stmt = $pdo->prepare("SELECT id, name, email, reset_token_expires_at FROM users WHERE reset_token = ? AND reset_token_expires_at > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $valid_token = true;
    } else {
        $error = "This password reset link is invalid or has expired. Please request a new password reset link.";
    }
} else {
    $error = "No reset token provided. Please use the reset link sent to your email.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token && $user) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($password) || empty($confirm_password)) {
        $error = "Please fill in all password fields.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match. Please try again.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        
        $up = $pdo->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires_at = NULL WHERE id = ?");
        $up->execute([$hash, $user['id']]);

        $_SESSION['toast'] = "Password updated successfully! Please log in with your new password.";
        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - Keria</title>
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
            max-width: 420px;
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
        [data-theme="dark"] .auth-title.auth-title-danger {
            color: var(--red);
        }
        .auth-title-danger { color: var(--red); }
        .auth-subtitle {
            font-size: 13.5px;
            color: var(--mut);
            margin-bottom: 26px;
            line-height: 1.5;
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
            text-decoration: none;
            margin-bottom: 16px;
        }
        .auth-submit-btn:hover {
            background: var(--keria-lime-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(210, 255, 58, 0.45);
        }
        .auth-bottom-text {
            margin-top: 16px;
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
        <div class="auth-card">
            <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" class="auth-logo">

            <?php if(!$valid_token): ?>
                <div class="auth-title auth-title-danger">Invalid Link</div>
                <div class="auth-subtitle"><?= htmlspecialchars($error) ?></div>
                <a href="forgot_password.php" class="auth-submit-btn">Request New Reset Link &rarr;</a>
            <?php else: ?>
                <div class="auth-title">Set New Password</div>
                <div class="auth-subtitle">Setting new password for <strong><?= htmlspecialchars($user['email']) ?></strong></div>

                <?php if(!empty($error)): ?>
                    <div class="auth-banner" style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); color:var(--red);">
                        ⚠️ <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="auth-input-wrap">
                        <span class="auth-input-icon">🔒</span>
                        <input type="password" name="password" id="resetPasswordField" placeholder="New password" required style="padding-right:42px;">
                        <button type="button" class="auth-password-toggle" onclick="toggleResetPasswordVisibility()" title="Show/Hide Password">👁️</button>
                    </div>
                    <div class="auth-input-wrap">
                        <span class="auth-input-icon">🔒</span>
                        <input type="password" name="confirm_password" id="resetConfirmPasswordField" placeholder="Confirm new password" required style="padding-right:42px;">
                        <button type="button" class="auth-password-toggle" onclick="toggleResetConfirmPasswordVisibility()" title="Show/Hide Password">👁️</button>
                    </div>

                    <button type="submit" class="auth-submit-btn">Update Password &rarr;</button>
                </form>
            <?php endif; ?>

            <div class="auth-bottom-text">
                Back to <a href="login.php">Login Page</a>
            </div>
        </div>
    </div>

<script>
    function toggleResetPasswordVisibility() {
        var pwd = document.getElementById('resetPasswordField');
        if (pwd) pwd.type = pwd.type === 'password' ? 'text' : 'password';
    }
    function toggleResetConfirmPasswordVisibility() {
        var pwd = document.getElementById('resetConfirmPasswordField');
        if (pwd) pwd.type = pwd.type === 'password' ? 'text' : 'password';
    }
</script>
<script src="theme.js"></script>
</body>
</html>
