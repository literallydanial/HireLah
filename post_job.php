<?php
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Location: job_dashboard.php?open_post=1");
    exit;
}

$employer_id = $_SESSION['user_id'];
$active_company_id = get_active_company_id($pdo);

$curr_ssm = '';
if ($active_company_id) {
    $c_stmt = $pdo->prepare("SELECT ssm_number FROM companies WHERE id = ?");
    $c_stmt->execute([$active_company_id]);
    $curr_ssm = trim($c_stmt->fetchColumn() ?: '');
}
if (empty($curr_ssm)) {
    $u_stmt = $pdo->prepare("SELECT ssm_number FROM users WHERE id = ?");
    $u_stmt->execute([$employer_id]);
    $curr_ssm = trim($u_stmt->fetchColumn() ?: '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['job_title'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $type = trim($_POST['employment_type'] ?? 'Full-time');
    $mode = trim($_POST['work_mode'] ?? 'Hybrid');
    $salary_min = (!empty($_POST['salary_min']) && is_numeric($_POST['salary_min'])) ? (int)$_POST['salary_min'] : null;
    $salary_max = (!empty($_POST['salary_max']) && is_numeric($_POST['salary_max'])) ? (int)$_POST['salary_max'] : null;
    $salary_text = !empty($_POST['salary_text']) ? trim($_POST['salary_text']) : null;
    $perks = !empty($_POST['perks']) ? trim($_POST['perks']) : null;
    $require_video = isset($_POST['require_video']) ? 1 : 0;
    $desc = trim($_POST['description'] ?? '');
    $ssm_number = trim($_POST['ssm_number'] ?? '');

    if (empty($ssm_number)) {
        $ssm_number = $curr_ssm;
    }

    if (empty($ssm_number)) {
        $error = "SSM Registration Number is compulsory before a job can be posted.";
    } elseif (empty($title) || empty($desc)) {
        $error = "Job title and description are required.";
    } else {
        if ($active_company_id && !empty($ssm_number)) {
            $pdo->prepare("UPDATE companies SET ssm_number = ? WHERE id = ?")->execute([$ssm_number, $active_company_id]);
        }
        $pdo->prepare("UPDATE users SET ssm_number = ? WHERE id = ?")->execute([$ssm_number, $employer_id]);

        $stmt = $pdo->prepare("INSERT INTO jobs (employer_id, company_id, job_title, department, employment_type, work_mode, salary_min, salary_max, salary_text, perks, require_video, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");
        if ($stmt->execute([$employer_id, $active_company_id, $title, $dept, $type, $mode, $salary_min, $salary_max, $salary_text, $perks, $require_video, $desc])) {
            $_SESSION['toast'] = "Job posted successfully with verified SSM registration number!";
            header("Location: job_dashboard.php");
            exit;
        } else {
            $error = "Failed to post job. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Post a Job - Keria</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
    <div class="panel" style="max-width:600px; width:100%;">
        <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
            <a href="employer_dashboard.php" class="btn-secondary" style="padding:6px 12px; font-size:12px;">&larr; Back</a>
        </div>
        <div style="font-size:24px; font-weight:800; margin-bottom:6px;">Post a New Job</div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Create a new role for candidates to apply to.</div>

        <?php if(isset($error)): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Title <span style="color:var(--red);">*</span></label>
                <input type="text" name="job_title" placeholder="e.g. Senior Backend Engineer" required>
            </div>

            <div style="margin-bottom:14px; background:rgba(245, 158, 11, 0.05); border:1px solid rgba(245, 158, 11, 0.25); border-radius:10px; padding:12px 14px;">
                <label style="display:flex; justify-content:space-between; align-items:center; font-size:12px; color:var(--txt); margin-bottom:6px; font-weight:700;">
                    <span>🏢 SSM Registration Number <span style="color:var(--red);">* (Compulsory)</span></span>
                    <span style="font-size:11px; font-weight:700; color:#B45309;">Required for verification</span>
                </label>
                <input type="text" name="ssm_number" value="<?= htmlspecialchars($curr_ssm) ?>" placeholder="e.g. 202301012345 (1234567-X)" required>
                <div style="font-size:11px; color:var(--mut); margin-top:4px;">Official SSM registration number is compulsory before posting.</div>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:16px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Department</label>
                    <input type="text" name="department" placeholder="e.g. Engineering">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Work Type</label>
                    <select name="employment_type">
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                        <option value="Contract">Contract</option>
                        <option value="Internship">Internship</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Work Mode</label>
                    <select name="work_mode">
                        <option value="Hybrid">Hybrid</option>
                        <option value="On-site">On-site</option>
                        <option value="Remote">Remote</option>
                    </select>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:12px;">
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Min Salary (RM/mo) <span style="font-size:11px; font-weight:normal; opacity:0.7;">(Optional)</span></label>
                    <input type="number" name="salary_min" placeholder="e.g. 4000">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Max Salary (RM/mo) <span style="font-size:11px; font-weight:normal; opacity:0.7;">(Optional)</span></label>
                    <input type="number" name="salary_max" placeholder="e.g. 7500">
                </div>
            </div>

            <div style="margin-bottom:16px; padding:12px; background:var(--surf); border:1px solid var(--bdr); border-radius:8px; display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="require_video" id="require_video" value="1" style="width:18px; height:18px; cursor:pointer;">
                <label for="require_video" style="font-size:13px; font-weight:600; color:var(--txt); cursor:pointer;">
                    📹 Require YouTube Video Introduction Link from Applicants
                </label>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Company Perks & Benefits <span style="font-size:11px; font-weight:normal; opacity:0.7;">(Optional)</span></label>
                <textarea name="perks" rows="3" placeholder="e.g. Health insurance, 20 days annual leave, remote work allowance, performance bonus..." style="resize:vertical;"></textarea>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Description & Requirements</label>
                <textarea name="description" rows="8" placeholder="Paste the full job description and requirements here. Our AI will use this to screen candidates and generate structured recruiter reports..." required style="resize:vertical;"></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Publish Job Posting &rarr;</button>
        </form>
    </div>
<script src="theme.js"></script></body>
</html>
