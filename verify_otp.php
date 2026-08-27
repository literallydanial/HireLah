<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

$user_id = $_SESSION['pending_otp_user_id'] ?? ($_GET['user_id'] ?? null);

if (!$user_id) {
    header("Location: login.php");
    exit;
}

// Fetch pending user details
$stmt = $pdo->prepare("SELECT id, name, email, role, is_verified, otp_code, otp_expires_at FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: register.php");
    exit;
}

if ($user['is_verified']) {
    $_SESSION['toast'] = "Your email is already verified. Please log in.";
    header("Location: login.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify_otp';

    if ($action === 'verify_otp') {
        $entered_otp = trim($_POST['otp_code'] ?? '');

        if (empty($entered_otp)) {
            $error = "Please enter the 6-digit verification code.";
        } elseif ($entered_otp !== $user['otp_code']) {
            $error = "Incorrect verification code. Please try again.";
        } elseif (strtotime($user['otp_expires_at']) < time()) {
            $error = "Verification code has expired. Please click 'Resend OTP' to get a new code.";
        } else {
            // Activate User
            $up = $pdo->prepare("UPDATE users SET is_verified = 1, otp_code = NULL, otp_expires_at = NULL WHERE id = ?");
            $up->execute([$user_id]);

            unset($_SESSION['pending_otp_user_id']);
            
            // Auto login user after successful OTP verification
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];

            if ($user['role'] === 'candidate' && !empty($_SESSION['redirect_after_login'])) {
                $target = $_SESSION['redirect_after_login'];
                unset($_SESSION['redirect_after_login']);
                $_SESSION['toast'] = "Account verified & logged in! Continuing to your job application.";
                header("Location: " . $target);
                exit;
            }

            $_SESSION['toast'] = "Account verified successfully! Welcome to HireLah.";
            header("Location: " . ($user['role'] === 'employer' ? "employer_dashboard.php" : "jobs.php"));
            exit;
        }
    } elseif ($action === 'resend_otp') {
        $new_otp = sprintf("%06d", mt_rand(100000, 999999));
        $new_expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $up = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ?");
        $up->execute([$new_otp, $new_expires, $user_id]);

        $res = send_otp_email($user['name'], $user['email'], $new_otp);
        if (!empty($res['success'])) {
            $_SESSION['toast'] = "A fresh 6-digit OTP code has been sent to " . htmlspecialchars($user['email']) . "!";
        } else {
            $_SESSION['toast'] = "New OTP generated: $new_otp (Email delivery note: " . htmlspecialchars($res['error'] ?? 'Configure SMTP') . ")";
        }
        header("Location: verify_otp.php?user_id=" . urlencode($user_id));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email OTP - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .otp-input-group {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin: 20px 0;
        }
        .otp-field {
            width: 100%;
            padding: 14px;
            font-size: 24px;
            font-weight: 800;
            text-align: center;
            letter-spacing: 12px;
            font-family: monospace;
            border-radius: 12px;
            border: 2px solid var(--bdr);
            background: var(--surf);
            color: var(--txt);
        }
        .otp-field:focus {
            border-color: var(--acc);
            outline: none;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }
    </style>
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg);">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <main style="max-width:440px; width:100%; padding:20px; position:relative; z-index:2;">
        <?php if(isset($_SESSION['toast'])): ?>
            <div style="position:fixed; top:20px; right:20px; z-index:3000; background:rgba(0, 232, 122, 0.18); border:1px solid rgba(0, 232, 122, 0.5); border-radius:10px; padding:10px 18px; color:var(--grn); font-size:13px; font-weight:700;">
                <?= htmlspecialchars($_SESSION['toast']) ?>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <div class="panel" style="text-align:center; padding:32px 28px; border-radius:18px; box-shadow:var(--shadow-lg);">
            <div style="margin-bottom:12px; display:flex; justify-content:center;">
                <div style="display:flex; align-items:center; justify-content:center;">
                    <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="height:72px; width:auto; max-width:100%; object-fit:contain;">
                </div>
            </div>
            <h1 style="font-size:22px; font-weight:800; color:var(--txt); margin-bottom:6px;">Email Verification</h1>
            <p style="font-size:13px; color:var(--mut); margin-bottom:20px;">
                We emailed a 6-digit verification code to:<br>
                <strong style="color:var(--txt);"><?= htmlspecialchars($user['email']) ?></strong>
            </p>

            <?php if(isset($error)): ?>
                <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:10px; padding:10px 14px; margin-bottom:18px; color:var(--red); font-size:13px; text-align:left;">
                    ⚠️ <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="verify_otp">
                
                <div style="margin-bottom:20px;">
                    <input type="text" name="otp_code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" class="otp-field" required autofocus autocomplete="off">
                </div>

                <button type="submit" class="btn-primary" style="padding:13px; font-size:15px; font-weight:800; width:100%; border-radius:10px; margin-bottom:16px;">
                    Verify Account &rarr;
                </button>
            </form>

            <form method="POST" style="margin-top:14px; border-top:1px dashed var(--bdr); padding-top:16px;">
                <input type="hidden" name="action" value="resend_otp">
                <span style="font-size:12px; color:var(--mut);">Didn't receive the code?</span>
                <button type="submit" class="btn-secondary" style="margin-top:6px; padding:7px 16px; font-size:12px; font-weight:700;">🔄 Resend OTP Code</button>
            </form>

            <div style="margin-top:20px; font-size:12px; color:var(--mut);">
                Wrong email address? <a href="register.php" style="color:var(--acc); font-weight:700;">Back to Signup</a>
            </div>
        </div>
    </main>
    <script src="theme.js"></script>
</body>
</html>
