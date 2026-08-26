<?php
session_start();
require_once 'db.php';
require_once 'mailer.php';

$token = trim($_GET['token'] ?? '');
$action = trim($_GET['action'] ?? '');
$candidate = null;
$error = '';
$success = '';

if (empty($token)) {
    $error = "No interview token provided. Please use the link provided in your invitation email.";
} else {
    $stmt = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, COALESCE(NULLIF(u.contact_email, ''), u.email) as employer_email
                           FROM candidates c
                           LEFT JOIN jobs j ON c.job_id = j.id 
                           LEFT JOIN users u ON j.employer_id = u.id 
                           WHERE c.interview_token = ?");
    $stmt->execute([$token]);
    $candidate = $stmt->fetch();

    if (!$candidate) {
        $error = "Invalid or expired interview token.";
    } else {
        if ($action === 'confirm') {
            $up = $pdo->prepare("UPDATE candidates SET interview_status = 'Confirmed', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $up->execute([$candidate['id']]);
            $candidate['interview_status'] = 'Confirmed';

            // Send confirmation email to employer
            if (!empty($candidate['employer_email'])) {
                send_interview_confirmed_email(
                    $candidate['employer_name'],
                    $candidate['employer_email'],
                    $candidate['name'],
                    $candidate['job_title'],
                    $candidate['interview_datetime'],
                    $candidate['interview_notes']
                );
            }

            $success = "You have successfully confirmed your interview slot for <strong>" . htmlspecialchars($candidate['job_title'] ?: 'Position') . "</strong>!";
        } elseif ($action === 'decline') {
            $up = $pdo->prepare("UPDATE candidates SET interview_status = 'Declined', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $up->execute([$candidate['id']]);
            $candidate['interview_status'] = 'Declined';

            $success = "You have declined this interview proposal.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interview Response - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:20px 0;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>

    <div class="panel" style="max-width:520px; width:100%; text-align:center; position:relative; z-index:2;">
        <div style="margin-bottom:12px; display:flex; justify-content:center;">
            <div style="display:flex; align-items:center; justify-content:center;">
                <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="height:72px; width:auto; max-width:100%; object-fit:contain;">
            </div>
        </div>

        <?php if(!empty($error)): ?>
            <div style="font-size:22px; font-weight:800; margin-bottom:6px; color:var(--red);">Interview Link Error</div>
            <div style="font-size:13px; color:var(--mut); margin-bottom:20px; line-height:1.5;">
                <?= htmlspecialchars($error) ?>
            </div>
            <a href="candidate_dashboard.php" class="btn-primary" style="text-decoration:none; display:inline-block;">Go to Candidate Dashboard &rarr;</a>
        <?php else: ?>
            <div style="font-size:24px; font-weight:800; margin-bottom:6px; color:<?= $candidate['interview_status']==='Confirmed'?'var(--grn)':'var(--red)' ?>;">
                <?= $candidate['interview_status']==='Confirmed' ? '✓ Interview Confirmed!' : '✕ Interview Declined' ?>
            </div>
            
            <div style="font-size:13px; color:var(--mut); margin-bottom:20px; line-height:1.5;">
                <?= $success ?>
            </div>

            <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:18px; text-align:left; margin-bottom:24px;">
                <div style="font-size:14px; font-weight:800; color:var(--txt); margin-bottom:4px;"><?= htmlspecialchars($candidate['job_title']) ?></div>
                <div style="font-size:12px; color:var(--mut); margin-bottom:12px;">Hiring Team: <strong><?= htmlspecialchars($candidate['employer_name'] ?: 'HireLah Employer') ?></strong></div>

                <?php if(!empty($candidate['interview_datetime'])): ?>
                    <div style="font-size:14px; font-weight:800; color:var(--acc); margin-bottom:8px;">
                        🕒 <?= date('F j, Y \a\t g:i A', strtotime($candidate['interview_datetime'])) ?>
                    </div>
                <?php endif; ?>

                <?php if(!empty($candidate['interview_notes'])): ?>
                    <div style="font-size:12px; color:var(--txt); border-top:1px dashed var(--bdr); padding-top:8px;">
                        <strong>Notes / Instructions:</strong><br>
                        <?= nl2br(htmlspecialchars($candidate['interview_notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>

            <a href="candidate_dashboard.php" class="btn-primary" style="text-decoration:none; display:inline-block;">View Applications & Schedule &rarr;</a>
        <?php endif; ?>
    </div>

    <script src="theme.js"></script>
</body>
</html>
