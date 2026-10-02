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
    $role = $invite_company ? 'employer' : ($is_university_signup ? 'university' : $_POST['role']);
    $institution_name = null;
    $university_id = null;
    $matric_number = null;

    if (!isset($_POST['agree_terms'])) {
        $error = "You must agree to the Terms & Conditions and PDPA Act 2010 Policy to create an account.";
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
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px 0;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    <div class="panel" style="max-width:400px; width:100%; text-align:center; position:relative; z-index:2;">
        <div style="margin-bottom:12px; display:flex; justify-content:center;">
            <div style="display:flex; align-items:center; justify-content:center;">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="height:72px; width:auto; max-width:100%; object-fit:contain;">
            </div>
        </div>
        <div style="font-size:24px; font-weight:800; margin-bottom:6px;"><?= $is_university_signup ? 'Register Your University' : 'Create an Account' ?></div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;"><?= $is_university_signup ? 'Get a live view of your students\' career-readiness' : 'Join Keria today' ?></div>

        <?php if ($invite_company): ?>
            <div style="background:rgba(0,232,122,0.1); border:1px solid rgba(0,232,122,0.35); border-radius:8px; padding:12px; margin-bottom:16px; text-align:left; font-size:13px; color:var(--txt);">
                🤝 You're joining <strong><?= htmlspecialchars($invite_company['name']) ?></strong> as an HR teammate. We'll add you to their team automatically once you verify your email.
            </div>
        <?php elseif ($is_university_signup): ?>
            <div style="background:rgba(217,255,79,0.1); border:1px solid rgba(217,255,79,0.35); border-radius:8px; padding:12px; margin-bottom:16px; text-align:left; font-size:13px; color:var(--txt);">
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
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
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
                    <div style="margin-bottom:14px; text-align:left;">
                        <label style="display:block; font-size:11.5px; color:var(--mut); font-weight:700; margin-bottom:5px;">University / Institution</label>
                        <div style="display:flex; align-items:center; gap:8px; padding:11px 14px; background:var(--surf); border:1px solid var(--bdr); border-radius:10px; font-size:13.5px; font-weight:700; color:var(--txt);">
                            <span><?= $admin_preset_uni_type === 'private' ? '🏫' : '🏛️' ?></span>
                            <span style="flex:1;"><?= htmlspecialchars($admin_preset_uni_name) ?></span>
                            <span style="font-size:10px; font-weight:700; color:var(--mut); text-transform:uppercase;"><?= htmlspecialchars($admin_preset_uni_type) ?></span>
                            <span style="font-size:10.5px; font-weight:700; color:var(--acc); background:rgba(217,255,79,0.15); padding:2px 8px; border-radius:6px;">Pre-set</span>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Generic signup: select Public or Private, and enter university name -->
                    <div style="margin-bottom:12px; text-align:left;">
                        <label style="display:block; font-size:11.5px; color:var(--mut); font-weight:700; margin-bottom:5px;">University Classification</label>
                        <select name="university_type" required style="margin-bottom:0;">
                            <option value="public" <?= (($_POST['university_type'] ?? '') === 'public') ? 'selected' : '' ?>>🏛️ Public University</option>
                            <option value="private" <?= (($_POST['university_type'] ?? '') === 'private') ? 'selected' : '' ?>>🏫 Private University</option>
                        </select>
                    </div>
                    <div style="margin-bottom:12px; text-align:left;">
                        <label style="display:block; font-size:11.5px; color:var(--mut); font-weight:700; margin-bottom:5px;">University Name</label>
                        <input type="text" name="institution_name" list="existingUniversitiesDatalist" placeholder="e.g. Universiti Malaya" required value="<?= htmlspecialchars($_POST['institution_name'] ?? '') ?>" style="margin-bottom:0;">
                    </div>
                <?php endif; ?>
                <input type="text" name="name" placeholder="Contact Person Name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" style="margin-bottom:12px;">
            <?php else: ?>
                <input type="text" name="name" placeholder="Full Name" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" style="margin-bottom:12px;">
            <?php endif; ?>
            <input type="email" name="email" placeholder="Email Address" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" style="margin-bottom:12px;">
            <input type="password" name="password" placeholder="Password" required style="margin-bottom:12px;">
            <?php if ($invite_company): ?>
                <input type="hidden" name="role" value="employer">
                <input type="hidden" name="invite_token" value="<?= htmlspecialchars($_SESSION['pending_invite_token']) ?>">
                <div style="margin-bottom:16px; text-align:left; font-size:12.5px; color:var(--mut); padding:8px 0;">Account type: <strong style="color:var(--txt);">Employer (HR)</strong> — fixed by the invite link.</div>
            <?php elseif ($is_university_signup): ?>
                <input type="hidden" name="role" value="university">
                <input type="hidden" name="account_type" value="university">
                <div style="margin-bottom:16px; text-align:left; font-size:12.5px; color:var(--mut); padding:8px 0;">Account type: <strong style="color:var(--txt);">University / Career Center</strong><?php if ($admin_preset_uni_name !== ''): ?> &mdash; pre-set for <strong style="color:var(--txt);"><?= htmlspecialchars($admin_preset_uni_name) ?></strong><?php endif; ?>.</div>
            <?php else: ?>
                <select name="role" id="registerRoleSelect" required style="margin-bottom:12px;" onchange="toggleStudentFields()">
                    <option value="candidate">I am a Candidate</option>
                    <option value="employer">I am an Employer</option>
                </select>
                <div id="studentFieldsBlock" style="margin-bottom:16px; text-align:left;">
                    <label style="display:block; font-size:11.5px; color:var(--mut); font-weight:700; margin-bottom:5px;">My University (optional — students only)</label>
                    <input type="text" name="candidate_university_name" list="existingUniversitiesDatalist" placeholder="e.g. Universiti Malaya, UniKL..." value="<?= htmlspecialchars($_POST['candidate_university_name'] ?? '') ?>" style="margin-bottom:12px;">
                    <input type="text" name="matric_number" placeholder="Matric / Student ID Number (optional)" value="<?= htmlspecialchars($_POST['matric_number'] ?? '') ?>">
                    <div style="font-size:11px; color:var(--mut); margin-top:6px; text-align:left;">Only fill this in if you're a student — it lets your university see your career-readiness activity on their dashboard.</div>
                </div>
                <script>
                    function toggleStudentFields() {
                        var role = document.getElementById('registerRoleSelect').value;
                        document.getElementById('studentFieldsBlock').style.display = (role === 'candidate') ? 'block' : 'none';
                    }
                    document.addEventListener('DOMContentLoaded', toggleStudentFields);
                </script>
            <?php endif; ?>

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
            <?php if ($invite_company): ?>&mdash; you'll be added to <?= htmlspecialchars($invite_company['name']) ?> right after you log in.<?php endif; ?>
        </div>
    </div>

    <datalist id="existingUniversitiesDatalist">
        <?php foreach ($universities_list as $u): ?>
            <option value="<?= htmlspecialchars($u['name']) ?>"><?= htmlspecialchars($u['name']) ?> (<?= ucfirst($u['type']) ?>)</option>
        <?php endforeach; ?>
    </datalist>

<script src="theme.js"></script></body>
</html>
