<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $role = $_POST['role'];
    
    if (!isset($_POST['agree_terms'])) {
        $error = "You must agree to the Terms & Conditions and PDPA Act 2010 Policy to create an account.";
    } elseif (!in_array($role, ['candidate', 'employer'])) {
        $role = 'candidate';
    }

    // Check if email exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        $error = "Email is already registered.";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $otp = sprintf("%06d", mt_rand(100000, 999999));
        $otp_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_verified, otp_code, otp_expires_at) VALUES (?, ?, ?, ?, 0, ?, ?)");
        if ($stmt->execute([$name, $email, $hash, $role, $otp, $otp_expires])) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px 0;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <div class="panel" style="max-width:400px; width:100%; text-align:center; position:relative; z-index:2;">
        <div style="margin-bottom:12px; display:flex; justify-content:center;">
            <div style="display:flex; align-items:center; justify-content:center;">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="height:72px; width:auto; max-width:100%; object-fit:contain;">
            </div>
        </div>
        <div style="font-size:24px; font-weight:800; margin-bottom:6px;">Create an Account</div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Join HireLah today</div>

        <?php if(isset($_SESSION['toast'])): ?>
            <div style="background:rgba(0, 232, 122, 0.12); border:1px solid rgba(0, 232, 122, 0.4); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--grn); font-size:13px; font-weight:600;">
                <?= htmlspecialchars($_SESSION['toast']) ?>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($error)): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="name" placeholder="Full Name" required style="margin-bottom:12px;">
            <input type="email" name="email" placeholder="Email Address" required style="margin-bottom:12px;">
            <input type="password" name="password" placeholder="Password" required style="margin-bottom:12px;">
            <select name="role" required style="margin-bottom:16px;">
                <option value="candidate">I am a Candidate</option>
                <option value="employer">I am an Employer</option>
            </select>

            <div style="margin-bottom:20px; text-align:left; font-size:12px; color:var(--mut); display:flex; gap:10px; align-items:flex-start;">
                <input type="checkbox" name="agree_terms" id="agree_terms" required style="width:18px; height:18px; min-height:18px; margin-top:1px; cursor:pointer; flex-shrink:0;">
                <label for="agree_terms" style="line-height:1.45; cursor:pointer; color:var(--txt);">
                    I agree to the <a href="terms.php" target="_blank" style="color:var(--acc); font-weight:700; text-decoration:underline;">Terms & Conditions</a> and <a href="terms.php#pdpa" target="_blank" style="color:var(--acc); font-weight:700; text-decoration:underline;">PDPA Act 2010 Policy</a>.
                </label>
            </div>

            <button type="submit" class="btn-primary">Register &rarr;</button>
        </form>
        
        <div style="margin-top:20px; font-size:12px; color:var(--mut);">
            Already have an account? <a href="login.php" style="color:var(--acc); font-weight:700;">Login here</a>
        </div>
    </div>
<script src="theme.js"></script></body>
</html>
