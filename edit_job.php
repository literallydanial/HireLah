<?php
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';

$job_id = $_GET['id'] ?? null;
if (!$job_id) {
    header("Location: job_dashboard.php");
    exit;
}

$active_company_id = get_active_company_id($pdo);

// Fetch job ensuring it belongs to this employer's company team
if ($active_company_id) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
    $stmt->execute([$job_id, $active_company_id, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
    $stmt->execute([$job_id, $_SESSION['user_id']]);
}
$job = $stmt->fetch();

if (!$job) {
    $_SESSION['toast'] = "Job posting not found or access denied.";
    header("Location: job_dashboard.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['job_title'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $type = trim($_POST['employment_type'] ?? '');
    $mode = trim($_POST['work_mode'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $require_video = isset($_POST['require_video']) ? 1 : 0;
    $desc = trim($_POST['description'] ?? '');

    if (empty($title) || empty($desc)) {
        $error = "Job title and description are required.";
    } else {
        if ($active_company_id) {
            $update_stmt = $pdo->prepare("UPDATE jobs SET job_title = ?, department = ?, employment_type = ?, work_mode = ?, status = ?, require_video = ?, description = ? WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
            $update_ok = $update_stmt->execute([$title, $dept, $type, $mode, $status, $require_video, $desc, $job_id, $active_company_id, $_SESSION['user_id']]);
        } else {
            $update_stmt = $pdo->prepare("UPDATE jobs SET job_title = ?, department = ?, employment_type = ?, work_mode = ?, status = ?, require_video = ?, description = ? WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
            $update_ok = $update_stmt->execute([$title, $dept, $type, $mode, $status, $require_video, $desc, $job_id, $_SESSION['user_id']]);
        }
        if ($update_ok) {
            $_SESSION['toast'] = "Job posting updated successfully!";
            header("Location: job_dashboard.php");
            exit;
        } else {
            $error = "Failed to update job posting. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Job Posting - HireLah</title>
    <link rel="stylesheet" href="style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding: 40px 20px;">
    <div class="panel" style="max-width:650px; width:100%;">
        <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
            <a href="job_dashboard.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; text-decoration:none;">&larr; Back to Jobs</a>
        </div>
        <div style="font-size:24px; font-weight:800; color:var(--txt); margin-bottom:6px;">Edit Job Posting</div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Update role requirements, title, or job description.</div>

        <?php if($error): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Title</label>
                <input type="text" name="job_title" value="<?= htmlspecialchars($job['job_title']) ?>" required>
            </div>
            
            <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Department</label>
                    <input type="text" name="department" value="<?= htmlspecialchars($job['department']) ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Employment Type</label>
                    <select name="employment_type">
                        <option value="Full-time" <?= $job['employment_type'] === 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                        <option value="Part-time" <?= $job['employment_type'] === 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                        <option value="Contract" <?= $job['employment_type'] === 'Contract' ? 'selected' : '' ?>>Contract</option>
                        <option value="Internship" <?= $job['employment_type'] === 'Internship' ? 'selected' : '' ?>>Internship</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Work Mode</label>
                    <select name="work_mode">
                        <option value="Remote" <?= $job['work_mode'] === 'Remote' ? 'selected' : '' ?>>Remote</option>
                        <option value="On-site" <?= $job['work_mode'] === 'On-site' ? 'selected' : '' ?>>On-site</option>
                        <option value="Hybrid" <?= $job['work_mode'] === 'Hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Status</label>
                    <select name="status">
                        <option value="Active" <?= $job['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Closed" <?= $job['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:16px; padding:12px; background:var(--surf); border:1px solid var(--bdr); border-radius:8px; display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="require_video" id="require_video" value="1" <?= !empty($job['require_video']) ? 'checked' : '' ?> style="width:18px; height:18px; cursor:pointer;">
                <label for="require_video" style="font-size:13px; font-weight:600; color:var(--txt); cursor:pointer;">
                    📹 Require YouTube Video Introduction Link from Applicants
                </label>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Description & Requirements</label>
                <textarea name="description" rows="8" placeholder="Enter full job details..." required><?= htmlspecialchars($job['description']) ?></textarea>
            </div>

            <div style="display:flex; gap:12px;">
                <button type="submit" class="btn-primary" style="padding:12px 24px; font-size:14px; flex:1;">Save Changes</button>
                <a href="job_dashboard.php" class="btn-secondary" style="padding:12px 24px; font-size:14px; text-decoration:none; text-align:center;">Cancel</a>
            </div>
        </form>
    </div>
    <script src="theme.js"></script>
</body>
</html>
