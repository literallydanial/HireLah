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
        } elseif ($user['role'] === 'employer') {
            header("Location: employer_dashboard.php");
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
    <title>Login - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <div class="panel" style="max-width:400px; width:100%; text-align:center; position:relative; z-index:2;">
        <div style="margin-bottom:12px; display:flex; justify-content:center;">
            <div style="display:flex; align-items:center; justify-content:center;">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="height:72px; width:auto; max-width:100%; object-fit:contain;">
            </div>
        </div>
        <div style="font-size:24px; font-weight:800; margin-bottom:6px;">Welcome Back</div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Login to your HireLah account</div>

        <?php if(isset($error)): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="email" name="email" placeholder="Email Address" required style="margin-bottom:12px;">
            <input type="password" name="password" placeholder="Password" required style="margin-bottom:8px;">
            
            <div style="text-align:right; margin-bottom:18px;">
                <a href="forgot_password.php" style="font-size:12px; color:var(--acc); font-weight:600; text-decoration:none;">Forgot password?</a>
            </div>

            <button type="submit" class="btn-primary">Login &rarr;</button>
        </form>
        
        <div style="margin-top:20px; font-size:12px; color:var(--mut);">
            Don't have an account? <a href="register.php" style="color:var(--acc); font-weight:700;">Register here</a>
        </div>
    </div>
<script src="theme.js"></script></body>
</html>
