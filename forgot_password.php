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
    <title>Forgot Password - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px 0;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    
    <div class="panel" style="max-width:420px; width:100%; text-align:center; position:relative; z-index:2;">
        <div style="margin-bottom:12px; display:flex; justify-content:center;">
            <div style="display:flex; align-items:center; justify-content:center;">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="height:72px; width:auto; max-width:100%; object-fit:contain;">
            </div>
        </div>
        <div style="font-size:24px; font-weight:800; margin-bottom:6px;">Reset Password</div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Enter your registered email address to receive a password reset link.</div>

        <?php if(!empty($error)): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:10px; padding:12px; margin-bottom:16px; color:var(--red); font-size:13px; text-align:left;">
                ⚠️ <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if(!empty($info)): ?>
            <div style="background:rgba(0, 232, 122, 0.12); border:1px solid rgba(0, 232, 122, 0.4); border-radius:10px; padding:14px; margin-bottom:20px; color:var(--grn); font-size:13px; text-align:left; line-height:1.5;">
                ✉️ <?= $info ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="email" name="email" placeholder="Email Address" required style="margin-bottom:18px;">
            <button type="submit" class="btn-primary" style="margin-bottom:16px;">Send Reset Link &rarr;</button>
        </form>
        
        <div style="margin-top:16px; font-size:13px; color:var(--mut);">
            Remember your password? <a href="login.php" style="color:var(--acc); font-weight:700;">Back to Login</a>
        </div>
    </div>

    <script src="theme.js"></script>
</body>
</html>
