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
    <title>Set New Password - HireLah</title>
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

        <?php if(!$valid_token): ?>
            <div style="font-size:22px; font-weight:800; margin-bottom:6px; color:var(--red);">Invalid Reset Link</div>
            <div style="font-size:13px; color:var(--mut); margin-bottom:20px; line-height:1.5;">
                <?= htmlspecialchars($error) ?>
            </div>
            <a href="forgot_password.php" class="btn-primary" style="text-decoration:none; display:inline-block;">Request New Reset Link &rarr;</a>
        <?php else: ?>
            <div style="font-size:24px; font-weight:800; margin-bottom:6px;">Set New Password</div>
            <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Setting new password for <strong><?= htmlspecialchars($user['email']) ?></strong></div>

            <?php if(!empty($error)): ?>
                <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:10px; padding:12px; margin-bottom:16px; color:var(--red); font-size:13px; text-align:left;">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                
                <input type="password" name="password" placeholder="New Password" required style="margin-bottom:12px;">
                <input type="password" name="confirm_password" placeholder="Confirm New Password" required style="margin-bottom:20px;">
                
                <button type="submit" class="btn-primary" style="margin-bottom:16px;">Update Password &rarr;</button>
            </form>
        <?php endif; ?>
        
        <div style="margin-top:16px; font-size:13px; color:var(--mut);">
            Back to <a href="login.php" style="color:var(--acc); font-weight:700;">Login Page</a>
        </div>
    </div>

    <script src="theme.js"></script>
</body>
</html>
