<?php
session_start();
require_once 'db.php';
require_once 'faculty_helpers.php';

// Only reachable via login.php's own redirect (never trusts a bare ?user_id=
// like verify_otp.php does, since there's no secondary secret like an OTP
// code protecting this page -- the session flag IS the proof of login).
$user_id = $_SESSION['pending_profile_user_id'] ?? null;
if (!$user_id) {
    header("Location: login.php");
    exit;
}

$stmt = $pdo->prepare("SELECT id, name, role, university_id, faculty, intake_year FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

if (!$user || $user['role'] !== 'candidate') {
    unset($_SESSION['pending_profile_user_id']);
    header("Location: login.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_faculty = resolve_posted_faculty($_POST);
    $posted_intake = trim($_POST['intake_year'] ?? '');

    if ($posted_faculty === '' || $posted_intake === '' || !ctype_digit($posted_intake)) {
        $error = "Please select your faculty and intake year to continue.";
    } else {
        $upd = $pdo->prepare("UPDATE users SET faculty = ?, intake_year = ? WHERE id = ?");
        $upd->execute([$posted_faculty, (int)$posted_intake, $user['id']]);

        // Finalize the login login.php paused -- same session vars it
        // would have set itself, now that the one-time prompt is answered.
        unset($_SESSION['pending_profile_user_id']);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['toast'] = "Thanks! Welcome back, " . $user['name'] . "!";

        if (!empty($_SESSION['redirect_after_login'])) {
            $target = $_SESSION['redirect_after_login'];
            unset($_SESSION['redirect_after_login']);
            header("Location: " . $target);
        } else {
            header("Location: jobs.php");
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Your Profile - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; background:var(--bg);">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    <main style="max-width:440px; width:100%; padding:20px; position:relative; z-index:2;">
        <div class="panel" style="text-align:center; padding:32px 28px; border-radius:18px; box-shadow:var(--shadow-lg);">
            <div style="margin-bottom:12px; display:flex; justify-content:center;">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="height:72px; width:auto; max-width:100%; object-fit:contain;">
            </div>
            <h1 style="font-size:22px; font-weight:800; color:var(--txt); margin-bottom:6px;">Just 2 Quick Details</h1>
            <p style="font-size:13px; color:var(--mut); margin-bottom:20px;">
                Your university uses this to show which faculties and intake years are doing well. Takes 5 seconds.
            </p>

            <?php if ($error): ?>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.28); border-radius:10px; padding:10px 14px; margin-bottom:16px; font-size:12.5px; color:#EF4444; text-align:left;"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" style="text-align:left;">
                <div class="auth-input-wrap" style="margin-bottom:14px;">
                    <label class="auth-field-label" style="display:block; font-size:12.5px; font-weight:700; color:var(--txt); margin-bottom:6px;">Faculty</label>
                    <select name="faculty" id="cpFacultySelect" class="rb-input" onchange="document.getElementById('cpFacultyOtherWrap').style.display = (this.value === 'Other') ? 'block' : 'none';" required>
                        <option value="">Select your faculty</option>
                        <?php foreach (faculty_categories() as $fac): ?>
                            <option value="<?= htmlspecialchars($fac) ?>"><?= htmlspecialchars($fac) ?></option>
                        <?php endforeach; ?>
                        <option value="Other">Other (not listed)</option>
                    </select>
                </div>
                <div id="cpFacultyOtherWrap" style="display:none; margin-bottom:14px;">
                    <input type="text" name="faculty_other" class="rb-input" placeholder="Enter your faculty">
                </div>
                <div class="auth-input-wrap" style="margin-bottom:20px;">
                    <label class="auth-field-label" style="display:block; font-size:12.5px; font-weight:700; color:var(--txt); margin-bottom:6px;">Intake Year</label>
                    <select name="intake_year" class="rb-input" required>
                        <option value="">Select your intake year</option>
                        <?php $cur_year = (int)date('Y'); for ($y = $cur_year + 1; $y >= $cur_year - 10; $y--): ?>
                            <option value="<?= $y ?>"><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="padding:13px; font-size:15px; font-weight:800; width:100%; border-radius:10px;">Continue to Dashboard</button>
            </form>
        </div>
    </main>
    <script src="theme.js"></script>
</body>
</html>
