<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

$info = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            
            $up = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?");
            $up->execute([$token, $user['id']]);

            $mail_res = send_password_reset_email($user['name'], $user['email'], $token);

            if ($mail_res['success']) {
                $info = "If an account exists for <strong>" . htmlspecialchars($email) . "</strong>, a password reset link has been sent to your email inbox. Please check your email.";
            } else {
                $error = "Failed to send reset email: " . htmlspecialchars($mail_res['error']);
            }
        } else {
            // Show generic message for security
            $info = "If an account exists for <strong>" . htmlspecialchars($email) . "</strong>, a password reset link has been sent to your email inbox. Please check your email.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Keria</title>
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
            <div class="auth-title">Forgot Password?</div>
            <div class="auth-subtitle">Enter your registered email address to receive a password reset link.</div>

            <?php if(!empty($error)): ?>
                <div class="auth-banner" style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); color:var(--red);">
                    ⚠️ <?= $error ?>
                </div>
            <?php endif; ?>

            <?php if(!empty($info)): ?>
                <div class="auth-banner" style="background:var(--toast-bg); border:1px solid var(--toast-bdr); color:var(--toast-txt); line-height:1.5;">
                    ✉️ <?= $info ?>
                    <br><br>💡 Don't see it? Check your <strong>Spam/Junk folder</strong> too.
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="auth-input-wrap">
                    <span class="auth-input-icon">✉️</span>
                    <input type="email" name="email" placeholder="Email address" required>
                </div>
                <button type="submit" class="auth-submit-btn">Send Reset Link &rarr;</button>
            </form>

            <div class="auth-bottom-text">
                Remember your password? <a href="login.php">Back to Login</a>
            </div>
        </div>
    </div>

    <script src="theme.js"></script>
</body>
</html>
