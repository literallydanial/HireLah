<?php
require_once 'auth.php';
require_role('employer');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header("Location: job_dashboard.php?open_post=1");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['job_title']);
    $dept = trim($_POST['department']);
    $type = trim($_POST['employment_type']);
    $mode = trim($_POST['work_mode']);
    $require_video = isset($_POST['require_video']) ? 1 : 0;
    $desc = trim($_POST['description']);
    $employer_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("INSERT INTO jobs (employer_id, job_title, department, employment_type, work_mode, require_video, description, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
    if ($stmt->execute([$employer_id, $title, $dept, $type, $mode, $require_video, $desc])) {
        $_SESSION['toast'] = "Job posted successfully!";
        header("Location: job_dashboard.php");
        exit;
    } else {
        $error = "Failed to post job. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Post a Job - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
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
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Title</label>
                <input type="text" name="job_title" placeholder="e.g. Senior Backend Engineer" required>
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

            <div style="margin-bottom:16px; padding:12px; background:var(--surf); border:1px solid var(--bdr); border-radius:8px; display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="require_video" id="require_video" value="1" style="width:18px; height:18px; cursor:pointer;">
                <label for="require_video" style="font-size:13px; font-weight:600; color:var(--txt); cursor:pointer;">
                    📹 Require YouTube Video Introduction Link from Applicants
                </label>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Description & Requirements</label>
                <textarea name="description" rows="10" placeholder="Paste the full job description and requirements here. Anthropic Claude will use this to screen candidates and generate structured recruiter reports..." required style="resize:vertical;"></textarea>
            </div>
            
            <button type="submit" class="btn-primary">Publish Job Posting &rarr;</button>
        </form>
    </div>
<script src="theme.js"></script></body>
</html>
