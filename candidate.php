<?php
session_start();
require 'db.php';
require_once 'questionnaire_helpers.php';
require_once 'employer_helpers.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    $redirect = ($_SESSION['user_role'] ?? '') === 'employer' ? "employer_dashboard.php" : "index.php";
    header("Location: $redirect");
    exit;
}

require_once 'ai.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM candidates WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['toast'] = "Candidate application deleted.";
        
        $redirect = ($_SESSION['user_role'] ?? '') === 'employer' ? "employer_dashboard.php" : (($_SESSION['user_role'] ?? '') === 'candidate' ? "candidate_dashboard.php" : "admin_dashboard.php");
        header("Location: $redirect");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'send_questionnaire') {
        $q_id = $_POST['questionnaire_id'] ?? null;
        $mode = $_POST['send_mode'] ?? 'template';
        
        if ($mode === 'custom') {
            $custom_title = trim($_POST['custom_title'] ?? '');
            $custom_questions = $_POST['custom_questions'] ?? [];
            
            $clean_custom = [];
            foreach ($custom_questions as $cq) {
                $cq_t = trim($cq);
                if ($cq_t !== '') $clean_custom[] = $cq_t;
            }

            if (!empty($clean_custom)) {
                $q_title = !empty($custom_title) ? $custom_title : "Custom Screening Questions";
                $q_json = json_encode($clean_custom);
                $ins_q = $pdo->prepare("INSERT INTO questionnaires (employer_id, title, questions_json) VALUES (?, ?, ?)");
                $ins_q->execute([$_SESSION['user_id'], $q_title, $q_json]);
                $q_id = $pdo->lastInsertId();
            }
        }

        if ($q_id) {
            $token = 'qr_' . bin2hex(random_bytes(10));
            $req_stmt = $pdo->prepare("INSERT INTO questionnaire_requests (id, candidate_id, questionnaire_id, status) VALUES (?, ?, ?, 'Pending')");
            $req_stmt->execute([$token, $id, $q_id]);
            
            // Update candidate status to Questionnaire Sent if currently Review or Pending
            $up_c = $pdo->prepare("UPDATE candidates SET status = 'Questionnaire Sent' WHERE id = ? AND status IN ('Review', 'Pending')");
            $up_c->execute([$id]);

            // Fetch registered candidate details (joining candidates & users tables) and questionnaire info
            $cand_info_stmt = $pdo->prepare("SELECT c.name, COALESCE(NULLIF(c.email, ''), u.email) as email, j.job_title 
            FROM candidates c 
            LEFT JOIN jobs j ON c.job_id = j.id 
            LEFT JOIN users u ON (c.user_id = u.id OR (c.user_id IS NULL AND c.email = u.email AND c.email IS NOT NULL AND c.email != '')) 
            WHERE c.id = ?");
            $cand_info_stmt->execute([$id]);
            $cand_info = $cand_info_stmt->fetch();

            $q_info_stmt = $pdo->prepare("SELECT title, questions_json FROM questionnaires WHERE id = ?");
            $q_info_stmt->execute([$q_id]);
            $q_info = $q_info_stmt->fetch();

            if ($cand_info && !empty($cand_info['email']) && $q_info) {
                $q_list = json_decode($q_info['questions_json'], true) ?: [];
                require_once __DIR__ . '/mailer.php';
                $employer_contact = get_employer_contact($pdo, $_SESSION['user_id']);
                $email_res = send_questionnaire_email(
                    $cand_info['name'],
                    $cand_info['email'],
                    $cand_info['job_title'],
                    $q_info['title'],
                    $token,
                    $q_list,
                    $employer_contact['name'],
                    $employer_contact['email']
                );
                if (!empty($email_res['success'])) {
                    $_SESSION['toast'] = "Questionnaire dispatched & emailed to " . htmlspecialchars($cand_info['email']) . "!";
                } else {
                    $err_detail = !empty($email_res['error']) ? $email_res['error'] : "Configure SMTP in Settings";
                    $_SESSION['toast'] = "Questionnaire logged! (Email note: " . htmlspecialchars($err_detail) . ")";
                }
            } else {
                $_SESSION['toast'] = "Questionnaire sent to candidate successfully!";
            }
        } else {
            $_SESSION['toast'] = "Please select a template or add custom questions to send.";
        }
        header("Location: candidate.php?id=$id");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'rescreen_ai') {
        $api_key = get_api_key();
        if (!$api_key) {
            if (($_SESSION['user_role'] ?? '') === 'admin') {
                $_SESSION['toast'] = "Please set your Google Gemini API Key first!";
                header("Location: set_key.php");
                exit;
            }
            $_SESSION['toast'] = "AI screening is not yet configured. Please contact your administrator to set up the Gemini API key.";
            header("Location: candidate.php?id=$id");
            exit;
        }

        // Fetch candidate stripped text and job description
        $c_stmt = $pdo->prepare("SELECT c.*, j.description as job_desc FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id WHERE c.id = ?");
        $c_stmt->execute([$id]);
        $c_data = $c_stmt->fetch();

        if ($c_data && !empty($c_data['stripped_text'])) {
            try {
                $ai_data = generate_snapshot($api_key, $c_data['stripped_text'], $c_data['job_desc'] ?? '');
                
                $up_stmt = $pdo->prepare("UPDATE candidates SET 
                    recommendation = ?, overall_score = ?, skills_match = ?, exp_match = ?, edu_match = ?,
                    summary = ?, education = ?, experience = ?, skills = ?, strengths = ?, gaps = ?,
                    relevance_label = ?, note = ?, suggested_question = ?, screened = 1
                    WHERE id = ?");
                
                $up_stmt->execute([
                    $ai_data['relevance_label'] ?? 'Pending',
                    $ai_data['overall_score'] ?? 0,
                    $ai_data['skills_match'] ?? 0,
                    $ai_data['exp_match'] ?? 0,
                    $ai_data['edu_match'] ?? 0,
                    $ai_data['summary'] ?? null,
                    $ai_data['education'] ?? null,
                    $ai_data['experience'] ?? null,
                    json_encode($ai_data['skills'] ?? []),
                    json_encode($ai_data['strengths'] ?? []),
                    json_encode($ai_data['gaps'] ?? []),
                    $ai_data['relevance_label'] ?? null,
                    $ai_data['note'] ?? null,
                    $ai_data['suggested_question'] ?? null,
                    $id
                ]);

                $_SESSION['toast'] = "Google Gemini AI Screening completed successfully!";
            } catch (Exception $e) {
                $_SESSION['toast'] = "AI Error: " . $e->getMessage();
            }
        }
        header("Location: candidate.php?id=$id");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'schedule_interview') {
        $datetime = trim($_POST['interview_datetime'] ?? '');
        $notes = trim($_POST['interview_notes'] ?? '');

        if (empty($datetime)) {
            $_SESSION['toast'] = "Please select a valid interview date and time.";
        } else {
            $cand_info = $pdo->prepare("SELECT c.*, j.job_title FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id WHERE c.id = ?");
            $cand_info->execute([$id]);
            $cand_data = $cand_info->fetch();

            $token = 'int_' . bin2hex(random_bytes(16));
            
            $up = $pdo->prepare("UPDATE candidates SET interview_status = 'Proposed', interview_datetime = ?, interview_notes = ?, interview_token = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $up->execute([$datetime, $notes, $token, $id]);

            require_once 'mailer.php';
            $employer_contact = get_employer_contact($pdo, $_SESSION['user_id']);
            $mail_res = send_interview_proposal_email($cand_data['name'], $cand_data['email'], $cand_data['job_title'], $employer_contact['name'], $datetime, $notes, $token, $employer_contact['email']);

            if ($mail_res['success']) {
                $_SESSION['toast'] = "Interview proposal sent to " . htmlspecialchars($cand_data['name']) . "!";
            } else {
                $_SESSION['toast'] = "Interview proposed, but email delivery failed: " . htmlspecialchars($mail_res['error']);
            }
        }
        header("Location: candidate.php?id=$id");
        exit;
    }

    if (isset($_POST['action']) && $_POST['action'] === 'send_message') {
        $body = trim($_POST['message_body'] ?? '');
        if (!empty($body)) {
            $sender_role = ($_SESSION['user_role'] ?? '') === 'candidate' ? 'candidate' : 'employer';
            $ins = $pdo->prepare("INSERT INTO messages (candidate_id, sender_role, sender_id, body) VALUES (?, ?, ?, ?)");
            $ins->execute([$id, $sender_role, $_SESSION['user_id'], $body]);
            $_SESSION['toast'] = "Message sent!";
        }
        header("Location: candidate.php?id=$id");
        exit;
    }

    if (isset($_POST['status'])) {
        if (in_array($_SESSION['user_role'] ?? '', ['employer', 'admin'])) {
            $status = $_POST['status'];
            $stmt = $pdo->prepare("UPDATE candidates SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$status, $id]);
            $_SESSION['toast'] = "Status updated to $status.";
        } else {
            $_SESSION['toast'] = "Permission denied. Candidates cannot update their own application status.";
        }
        header("Location: candidate.php?id=$id");
        exit;
    }
}

$stmt = $pdo->prepare("SELECT c.*, j.job_title, j.description as job_desc, u.profile_picture 
FROM candidates c 
LEFT JOIN jobs j ON c.job_id = j.id 
LEFT JOIN users u ON (c.user_id = u.id OR (c.user_id IS NULL AND c.email = u.email AND c.email IS NOT NULL AND c.email != '')) 
WHERE c.id = ?");
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c) {
    $_SESSION['toast'] = "Candidate not found or deleted.";
    $redirect = ($_SESSION['user_role'] ?? '') === 'employer' ? "employer_dashboard.php" : "index.php";
    header("Location: $redirect");
    exit;
}

// Automatic AI Screening on Page Load if candidate is unscreened
if (empty($c['screened']) && !empty($c['stripped_text'])) {
    $auto_api_key = get_api_key();
    if ($auto_api_key) {
        try {
            $ai_data = generate_snapshot($auto_api_key, $c['stripped_text'], $c['job_desc'] ?? '');
            
            $up_stmt = $pdo->prepare("UPDATE candidates SET 
                recommendation = ?, overall_score = ?, skills_match = ?, exp_match = ?, edu_match = ?,
                summary = ?, education = ?, experience = ?, skills = ?, strengths = ?, gaps = ?,
                relevance_label = ?, note = ?, suggested_question = ?, screened = 1
                WHERE id = ?");
            
            $up_stmt->execute([
                $ai_data['relevance_label'] ?? 'Pending',
                $ai_data['overall_score'] ?? 0,
                $ai_data['skills_match'] ?? 0,
                $ai_data['exp_match'] ?? 0,
                $ai_data['edu_match'] ?? 0,
                $ai_data['summary'] ?? null,
                $ai_data['education'] ?? null,
                $ai_data['experience'] ?? null,
                json_encode($ai_data['skills'] ?? []),
                json_encode($ai_data['strengths'] ?? []),
                json_encode($ai_data['gaps'] ?? []),
                $ai_data['relevance_label'] ?? null,
                $ai_data['note'] ?? null,
                $ai_data['suggested_question'] ?? null,
                $id
            ]);

            // Refresh candidate data
            $stmt->execute([$id]);
            $c = $stmt->fetch();
        } catch (Exception $e) {
            error_log("Auto-screen Error: " . $e->getMessage());
        }
    }
}

// Fetch saved questionnaires for employer
$saved_questionnaires = [];
if (($_SESSION['user_role'] ?? '') === 'employer') {
    $sq_stmt = $pdo->prepare("SELECT * FROM questionnaires WHERE (employer_id = ? OR job_id = ?) AND (status IS NULL OR status != 'Draft') ORDER BY created_at DESC");
    $sq_stmt->execute([$_SESSION['user_id'], $c['job_id']]);
    $saved_questionnaires = $sq_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$candidate_q_requests = [];
$c_q_stmt = $pdo->prepare("SELECT qr.*, q.title FROM questionnaire_requests qr JOIN questionnaires q ON qr.questionnaire_id = q.id WHERE qr.candidate_id = ? ORDER BY qr.sent_at DESC");
$c_q_stmt->execute([$id]);
$candidate_q_requests = $c_q_stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark messages as read depending on viewing role
if (($_SESSION['user_role'] ?? '') === 'employer') {
    $mark_read = $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE candidate_id = ? AND sender_role = 'candidate' AND read_at IS NULL");
    $mark_read->execute([$id]);
} elseif (($_SESSION['user_role'] ?? '') === 'candidate') {
    $mark_read = $pdo->prepare("UPDATE messages SET read_at = NOW() WHERE candidate_id = ? AND sender_role = 'employer' AND read_at IS NULL");
    $mark_read->execute([$id]);
}

// Fetch application message thread
$msg_stmt = $pdo->prepare("SELECT m.*, u.name as sender_name FROM messages m LEFT JOIN users u ON m.sender_id = u.id WHERE m.candidate_id = ? ORDER BY m.created_at ASC");
$msg_stmt->execute([$id]);
$application_messages = $msg_stmt->fetchAll(PDO::FETCH_ASSOC);

$score = $c['overall_score'];
$color = $score >= 80 ? 'var(--grn)' : ($score >= 60 ? 'var(--acc)' : ($score >= 40 ? 'var(--org)' : 'var(--red)'));
$skills = json_decode($c['skills'], true) ?: [];
$strengths = json_decode($c['strengths'], true) ?: [];
$gaps = json_decode($c['gaps'], true) ?: [];

$rank_stmt = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE job_id = ? AND overall_score > ?");
$rank_stmt->execute([$c['job_id'], $c['overall_score']]);
$candidate_rank = (int)$rank_stmt->fetchColumn() + 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Candidate Evaluation: <?= htmlspecialchars($c['name']) ?></title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px; }
        .score-circle {
            width: 84px; height: 84px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800; font-family: monospace;
            color: <?= $color ?>;
            background: conic-gradient(<?= $color ?> <?= $score ?>%, var(--dim) <?= $score ?>% 100%);
            box-shadow: 0 0 10px <?= $color ?>40;
            position: relative;
        }
        .score-circle::before {
            content: '';
            position: absolute;
            width: 68px; height: 68px; border-radius: 50%;
            background: var(--card);
        }
        .score-circle span {
            position: relative;
            z-index: 1;
        }
        .bar-bg { flex:1; height:6px; background:var(--dim); border-radius:3px; overflow:hidden; }
        .bar-fill { height:100%; border-radius:3px; }
        pre { white-space: pre-wrap; word-wrap: break-word; font-size: 12px; line-height: 1.5; font-family: inherit;}
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            <nav style="display:flex; gap:4px; margin-left:24px">
                <?php 
                $backLink = ($_SESSION['user_role'] ?? '') === 'employer' ? 'employer_dashboard.php' : (($_SESSION['user_role'] ?? '') === 'candidate' ? 'candidate_dashboard.php' : 'admin_dashboard.php');
                ?>
                <a href="<?= $backLink ?>">&larr; Back to Dashboard</a>
            </nav>
        </div>
    </header>
    
    <main style="max-width:900px;">
        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <div class="panel">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:22px;">
                <div style="display:flex; gap:16px; align-items:center;">
                    <?php if(!empty($c['profile_picture']) && file_exists($c['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($c['profile_picture']) ?>" alt="<?= htmlspecialchars($c['name']) ?>" style="width:68px; height:68px; border-radius:50%; object-fit:cover; border:3px solid var(--acc); box-shadow:0 0 12px rgba(0, 232, 122, 0.3);">
                    <?php else: ?>
                        <div style="width:68px; height:68px; border-radius:50%; background:linear-gradient(135deg, #6B8A00, #00E87A); display:flex; align-items:center; justify-content:center; font-size:26px; font-weight:800; color:#fff; box-shadow:0 0 12px rgba(180, 214, 0, 0.3);">
                            <?= strtoupper(substr($c['name'] ?: 'C', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:21px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($c['name']) ?></div>
                        <div style="font-size:12px; color:var(--acc); font-weight:700; margin-top:2px;">Applied for: <?= htmlspecialchars($c['job_title']) ?></div>
                        <div style="font-size:12px; color:var(--mut); margin-top:3px; display:flex; gap:12px; flex-wrap:wrap;">
                            <?php if($c['email']): ?><span>📧 <?= htmlspecialchars($c['email']) ?></span><?php endif; ?>
                            <?php if($c['phone']): ?><span>📱 <?= htmlspecialchars($c['phone']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex; gap:8px;">
                    <?php if(!empty($c['youtube_url'])): ?>
                        <a href="<?= htmlspecialchars($c['youtube_url']) ?>" target="_blank" class="btn-secondary" style="padding:8px 14px; font-size:12px; width:auto; text-decoration:none; color:#FF0000; border-color:rgba(255,0,0,0.3);">▶️ Watch Video On Youtube↗</a>
                    <?php endif; ?>
                    <?php if(isset($c['resume_path']) && $c['resume_path'] && file_exists($c['resume_path'])): ?>
                        <a href="<?= htmlspecialchars($c['resume_path']) ?>" target="_blank" class="btn-primary" style="padding:8px 16px; font-size:12px; width:auto; text-decoration:none;">📄 View Original PDF</a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Bar (Status, Questionnaire & Delete) above Video -->
            <div style="display:flex; gap:10px; flex-wrap:wrap; background:var(--surf); border:1px solid var(--bdr); padding:12px 16px; border-radius:12px; margin-bottom:20px; align-items:center;">
                <?php if(in_array($_SESSION['user_role'] ?? '', ['employer', 'admin'])): ?>
                    <form method="POST" style="display:flex; gap:10px;">
                        <?php 
                        $status_icons = [
                            'Shortlisted' => '🌟 Shortlisted',
                            'Review' => '🔍 Review',
                            'Rejected' => '❌ Rejected'
                        ];
                        foreach($status_icons as $s => $label): 
                            $active = $c['status'] == $s;
                            $bg = $active ? ($s=='Shortlisted'?'rgba(0,232,122,0.1)':'rgba(255,140,66,0.1)') : 'var(--card)';
                            $bd = $active ? ($s=='Shortlisted'?'var(--grn)':'var(--org)') : 'var(--bdr)';
                            if($s=='Rejected') {
                                $bg = $active ? 'rgba(255,77,106,0.1)' : 'var(--card)';
                                $bd = $active ? 'var(--red)' : 'var(--bdr)';
                            }
                        ?>
                            <button type="submit" name="status" value="<?= $s ?>" style="background:<?= $bg ?>; border:1px solid <?= $bd ?>; border-radius:8px; color:<?= $active ? $bd : 'var(--txt)' ?>; padding:8px 16px; cursor:pointer; font-size:12px; font-weight:700;">
                                <?= $label ?>
                            </button>
                        <?php endforeach; ?>
                    </form>

                    <button type="button" onclick="openQuestionsModal()" style="background:linear-gradient(135deg, #6B8A00, #3B82F6); border:none; border-radius:8px; color:#fff; padding:8px 16px; cursor:pointer; font-size:12px; font-weight:700; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 10px rgba(180, 214, 0, 0.25);">
                        📋 Send Questions
                    </button>
                    <button type="button" onclick="openInterviewModal()" style="background:linear-gradient(135deg, #B9D600, #0A0A0A); border:none; border-radius:8px; color:#fff; padding:8px 16px; cursor:pointer; font-size:12px; font-weight:700; white-space:nowrap; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 10px rgba(217, 255, 79, 0.35);">
                        📅 Schedule Interview
                    </button>
                <?php else: ?>
                    <div style="font-size:13px; font-weight:700; color:var(--txt); display:flex; align-items:center; gap:8px;">
                        <span>Application Status:</span>
                        <span class="chip chip-<?= strtolower($c['status']) ?>" style="font-size:12px; font-weight:800; padding:6px 14px;">
                            <?= htmlspecialchars($c['status']) ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <div style="flex:1"></div>
                
                <?php if(in_array($_SESSION['user_role'] ?? '', ['employer', 'admin'])): ?>
                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this candidate?');" style="margin:0;">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" style="background:rgba(255,77,106,0.12); border:1px solid rgba(255,77,106,0.35); border-radius:8px; color:var(--red); padding:8px 14px; cursor:pointer; font-size:12px; font-weight:700;">
                            🗑️ Delete Application
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Navigation Tabs Bar -->
            <div style="display:flex; gap:8px; margin-bottom:20px; border-bottom:1px solid var(--bdr); padding-bottom:12px; flex-wrap:wrap;">
                <button type="button" id="tab_btn_ai" onclick="switchMainTab('ai')" class="main-tab-btn active" style="padding:10px 18px; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; background:linear-gradient(135deg, #B9D600, #0A0A0A); color:#FFFFFF;">
                    🤖 AI Match & Analysis
                </button>
                <button type="button" id="tab_btn_discussion" onclick="switchMainTab('discussion')" class="main-tab-btn" style="padding:10px 18px; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; background:transparent; color:var(--mut);">
                    💬 Messages & Interview <?= count($application_messages) > 0 ? '('.count($application_messages).')' : '' ?>
                </button>
                <button type="button" id="tab_btn_questionnaires" onclick="switchMainTab('questionnaires')" class="main-tab-btn" style="padding:10px 18px; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; background:transparent; color:var(--mut);">
                    📋 Questionnaires <?= !empty($candidate_q_requests) ? '('.count($candidate_q_requests).')' : '' ?>
                </button>
                <button type="button" id="tab_btn_resume" onclick="switchMainTab('resume')" class="main-tab-btn" style="padding:10px 18px; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; background:transparent; color:var(--mut);">
                    📄 Resume & Video
                </button>
            </div>

            <!-- TAB 1: AI Match & Analysis -->
            <div id="tab_content_ai" class="main-tab-content">
                <!-- Evaluation Summary & Scores -->
                <div style="display:grid; grid-template-columns:auto 1fr; gap:20px; background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:20px; margin-bottom:18px;">
                    <div class="score-circle"><span><?= $score ?></span></div>
                    <div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                            <span class="chip" style="color:var(--org); border-color:var(--org); background:transparent; font-weight:700;">
                                🏆 Rank #<?= $candidate_rank ?>
                            </span>
                            <?php
                            $is_pass = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire');
                            $pass_color = $is_pass ? 'var(--grn)' : 'var(--red)';
                            ?>
                            <span class="chip" style="color:<?= $pass_color ?>; border-color:<?= $pass_color ?>; background:transparent; font-weight:700;">
                                <?= $is_pass ? '✅ Pass' : '❌ Fail' ?>
                            </span>
                            <span class="chip chip-<?= strtolower($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span>
                        </div>
                        
                        <div style="font-size:11px; color:var(--acc); font-weight:700; text-transform:uppercase; letter-spacing:0.8px; margin-bottom:4px;">Candidate Summary</div>
                        <div style="font-size:13px; color:var(--txt); line-height:1.6;"><?= htmlspecialchars($c['summary']) ?></div>
                    </div>
                </div>

                <!-- Match Breakdown -->
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:18px;">
                    <?php 
                    $metrics = [
                        '🔧 Skills Alignment' => $c['skills_match'],
                        '💼 Experience Match' => $c['exp_match'],
                        '🎓 Education Match' => $c['edu_match']
                    ];
                    foreach($metrics as $l => $v): 
                        $vc = $v >= 70 ? 'var(--grn)' : ($v >= 45 ? 'var(--acc)' : 'var(--org)');
                    ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:10px 14px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:7px;"><?= $l ?></div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div class="bar-bg">
                                <div class="bar-fill" style="width:<?= $v ?>%; background:<?= $vc ?>; box-shadow:0 0 6px <?= $vc ?>80;"></div>
                            </div>
                            <span style="color:<?= $vc ?>; font-size:11px; font-weight:700; font-family:monospace;"><?= $v ?>%</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pros & Cons Grid -->
                <div class="detail-grid">
                    <div style="background:rgba(0,232,122,0.04); border:1px solid rgba(0,232,122,0.2); border-radius:12px; padding:16px;">
                        <div style="font-size:11px; color:var(--grn); font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:10px;">✔ Pros (Strengths & Alignment)</div>
                        <?php if(!empty($strengths)): ?>
                            <?php foreach($strengths as $s): ?>
                                <div style="margin-bottom:10px; font-size:13px; color:var(--txt); line-height:1.5;">
                                    <div style="display:flex; gap:8px;">
                                        <span style="color:var(--grn); flex-shrink:0;">•</span> 
                                        <div>
                                            <?php 
                                            $formatted = preg_replace_callback('/\[Resume Evidence:\s*"(.*?)"\]/s', function($m) {
                                                return '</div></div><div style="margin-top:4px; margin-left:16px; font-size:11px; color:var(--grn); background:rgba(0, 232, 122, 0.08); padding:5px 10px; border-left:3px solid var(--grn); border-radius:4px; font-style:italic;">💬 <strong>Resume Evidence:</strong> "' . htmlspecialchars($m[1]) . '"</div>';
                                            }, htmlspecialchars($s));
                                            echo (strpos($formatted, '</div></div>') !== false) ? $formatted : $formatted . '</div></div>';
                                            ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="font-size:12px; color:var(--mut);">No specific pros recorded.</div>
                        <?php endif; ?>
                    </div>
                    
                    <div style="background:rgba(255,140,66,0.04); border:1px solid rgba(255,140,66,0.2); border-radius:12px; padding:16px;">
                        <div style="font-size:11px; color:var(--org); font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:10px;">⚠️ Cons (Gaps & Areas of Concern)</div>
                        <?php if(!empty($gaps)): ?>
                            <?php foreach($gaps as $g): ?>
                                <div style="margin-bottom:10px; font-size:13px; color:var(--txt); line-height:1.5;">
                                    <div style="display:flex; gap:8px;">
                                        <span style="color:var(--org); flex-shrink:0;">•</span> 
                                        <div>
                                            <?php 
                                            $formatted_g = preg_replace_callback('/\[Resume Evidence:\s*"(.*?)"\]/s', function($m) {
                                                return '</div></div><div style="margin-top:4px; margin-left:16px; font-size:11px; color:var(--org); background:rgba(255, 140, 66, 0.08); padding:5px 10px; border-left:3px solid var(--org); border-radius:4px; font-style:italic;">💬 <strong>Resume Evidence:</strong> "' . htmlspecialchars($m[1]) . '"</div>';
                                            }, htmlspecialchars($g));
                                            echo (strpos($formatted_g, '</div></div>') !== false) ? $formatted_g : $formatted_g . '</div></div>';
                                            ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="font-size:12px; color:var(--mut);">No obvious cons found. Probe general domain fit.</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="detail-grid">
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:7px;">🎓 Education History</div>
                        <div style="font-size:13px; color:var(--txt);"><?= htmlspecialchars($c['education'] ?: '-') ?></div>
                    </div>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:7px;">💼 Work Experience</div>
                        <div style="font-size:13px; color:var(--txt);"><?= htmlspecialchars($c['experience'] ?: '-') ?></div>
                    </div>
                </div>

                <?php if (!empty($skills)): ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px; margin-bottom:20px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px;">🧩 Skills</div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ($skills as $skill): ?>
                                <span class="chip" style="color:var(--txt); border-color:var(--bdr); background:var(--dim);"><?= htmlspecialchars($skill) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Why We Should Hire Them (Pitch) -->
                <?php if($c['note']): ?>
                    <?php
                    $v_color = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'var(--grn)' : ($c['recommendation'] == 'Maybe' ? 'var(--org)' : 'var(--red)');
                    $v_bg = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'rgba(0, 232, 122, 0.08)' : ($c['recommendation'] == 'Maybe' ? 'rgba(255, 140, 66, 0.08)' : 'rgba(255, 77, 106, 0.08)');
                    $v_border = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'rgba(0, 232, 122, 0.25)' : ($c['recommendation'] == 'Maybe' ? 'rgba(255, 140, 66, 0.25)' : 'rgba(255, 77, 106, 0.25)');
                    ?>
                    <div style="background:<?= $v_bg ?>; border:1px solid <?= $v_border ?>; border-radius:14px; padding:18px; margin-bottom:20px;">
                        <div style="font-size:11px; color:<?= $v_color ?>; font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:8px;">
                            🚀 Why We Should Hire Them (The Pitch)
                        </div>
                        <div style="font-size:14px; color:var(--txt); line-height:1.6; font-weight:500;">
                            <?= htmlspecialchars($c['note']) ?>
                        </div>

                        <?php if($c['suggested_question']): ?>
                            <div style="margin-top:14px; padding-top:14px; border-top:1px solid <?= $v_border ?>; font-size:13px; color:var(--txt);">
                                <strong style="color:<?= $v_color ?>;">🎯 Suggested Interview Focus:</strong>
                                <div style="margin-top:4px; font-weight:500; line-height:1.5;">
                                    <?= nl2br(htmlspecialchars($c['suggested_question'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2: Messages & Discussion -->
            <div id="tab_content_discussion" class="main-tab-content" style="display:none;">
                <!-- Proposed / Confirmed Interview Banner -->
                <?php if(!empty($c['interview_status']) && $c['interview_status'] !== 'None'): ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:18px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                        <div>
                            <div style="font-size:14px; font-weight:800; color:var(--txt); display:flex; align-items:center; gap:8px;">
                                <span>📅 Interview Status:</span>
                                <span class="chip" style="font-size:11px; background:<?= $c['interview_status']==='Confirmed'?'rgba(16,185,129,0.15)':($c['interview_status']==='Proposed'?'rgba(180, 214, 0, 0.15)':'rgba(239,68,68,0.15)') ?>; color:<?= $c['interview_status']==='Confirmed'?'var(--grn)':($c['interview_status']==='Proposed'?'var(--acc)':'var(--red)') ?>; border-color:transparent;">
                                    <?= htmlspecialchars($c['interview_status']) ?>
                                </span>
                            </div>
                            <?php if(!empty($c['interview_datetime'])): ?>
                                <div style="font-size:13px; font-weight:700; color:var(--acc); margin-top:6px;">
                                    🕒 <?= date('F j, Y \a\t g:i A', strtotime($c['interview_datetime'])) ?>
                                </div>
                            <?php endif; ?>
                            <?php if(!empty($c['interview_notes'])): ?>
                                <div style="font-size:12px; color:var(--mut); margin-top:4px;">
                                    📝 Notes: <?= htmlspecialchars($c['interview_notes']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if(($_SESSION['user_role'] ?? '') === 'employer'): ?>
                            <button type="button" onclick="openInterviewModal()" class="btn-secondary" style="padding:6px 14px; font-size:12px;">
                                ✏️ Reschedule / Edit Slot
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Application Discussion Thread Card -->
                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:18px; margin-bottom:20px;">
                    <div style="font-size:14px; font-weight:800; color:var(--txt); margin-bottom:14px; display:flex; align-items:center; justify-content:space-between;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span>💬 Application Discussion Thread</span>
                            <span class="chip" style="font-size:10px; background:rgba(217, 255, 79, 0.15); color:var(--acc); border-color:transparent;"><?= count($application_messages) ?> Messages</span>
                        </div>
                    </div>

                    <!-- Chat Messages Scroll Container -->
                    <div style="max-height:380px; overflow-y:auto; padding:14px; background:var(--card); border:1px solid var(--bdr); border-radius:10px; margin-bottom:14px;">
                        <?php if(empty($application_messages)): ?>
                            <div style="text-align:center; padding:30px; color:var(--mut); font-size:13px;">
                                💬 No messages sent yet. Send a message below to start the conversation!
                            </div>
                        <?php else: ?>
                            <?php foreach($application_messages as $m): 
                                $is_me = (($_SESSION['user_role'] ?? '') === 'employer' && $m['sender_role'] === 'employer') || (($_SESSION['user_role'] ?? '') === 'candidate' && $m['sender_role'] === 'candidate');
                            ?>
                                <div style="margin-bottom:12px; display:flex; flex-direction:column; align-items:<?= $is_me ? 'flex-end' : 'flex-start' ?>;">
                                    <div style="font-size:10px; color:var(--mut); margin-bottom:3px;">
                                        <strong><?= htmlspecialchars($m['sender_name'] ?: ucfirst($m['sender_role'])) ?></strong> (<?= ucfirst($m['sender_role']) ?>) &bull; <?= date('M j, g:i a', strtotime($m['created_at'])) ?>
                                    </div>
                                    <div style="max-width:80%; padding:10px 14px; border-radius:12px; font-size:13px; line-height:1.45; <?= $is_me ? 'background:var(--grad-purple); color:#FFFFFF; border-bottom-right-radius:2px;' : 'background:var(--dim); color:var(--txt); border-bottom-left-radius:2px;' ?>">
                                        <?= nl2br(htmlspecialchars($m['body'])) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Send Message Input Form -->
                    <form method="POST" style="display:flex; gap:10px; margin:0;">
                        <input type="hidden" name="action" value="send_message">
                        <input type="text" name="message_body" placeholder="Type your message..." required style="margin:0; flex:1; font-size:13px; border-radius:10px; padding:10px 14px;">
                        <button type="submit" class="btn-primary" style="width:auto; padding:10px 20px; font-size:13px; border-radius:10px;">Send &rarr;</button>
                    </form>
                </div>
            </div>

            <!-- TAB 3: Screening Questionnaires -->
            <div id="tab_content_questionnaires" class="main-tab-content" style="display:none;">
                <?php if(!empty($candidate_q_requests)): ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:18px; margin-bottom:20px;">
                        <div style="font-size:14px; font-weight:700; color:var(--txt); margin-bottom:12px;">📋 Screening Questionnaires</div>
                        
                        <?php foreach($candidate_q_requests as $q_req): ?>
                            <div style="background:var(--card); border:1px solid var(--bdr); border-radius:10px; padding:14px; margin-bottom:10px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                                    <div style="font-size:13px; font-weight:700; color:var(--txt);"><?= htmlspecialchars($q_req['title']) ?></div>
                                    <span class="chip" style="background:<?= $q_req['status'] === 'Submitted' ? 'rgba(0,232,122,0.15)' : 'rgba(255,140,66,0.15)' ?>; color:<?= $q_req['status'] === 'Submitted' ? 'var(--grn)' : 'var(--org)' ?>; border-color:transparent; font-size:10px;">
                                        <?= htmlspecialchars($q_req['status']) ?>
                                    </span>
                                </div>

                                <div style="font-size:11px; color:var(--mut); margin-bottom:10px;">
                                    Sent: <?= date('M d, Y H:i', strtotime($q_req['sent_at'])) ?>
                                    <?php if($q_req['status'] === 'Pending'): ?>
                                        | Link: <a href="answer_questionnaire.php?token=<?= $q_req['id'] ?>" target="_blank" style="color:var(--acc); text-decoration:none; font-weight:600;">Candidate Link ↗</a>
                                    <?php endif; ?>
                                </div>

                                <?php if($q_req['status'] === 'Submitted' && !empty($q_req['answers_json'])): ?>
                                    <?php 
                                    $answers = json_decode($q_req['answers_json'], true) ?: [];
                                    $q_stmt_item = $pdo->prepare("SELECT questions_json FROM questionnaires WHERE id = ?");
                                    $q_stmt_item->execute([$q_req['questionnaire_id']]);
                                    $q_item_data = $q_stmt_item->fetch();
                                    $questions_list = json_decode($q_item_data['questions_json'] ?? '[]', true) ?: [];
                                    ?>
                                    <div style="margin-top:10px; border-top:1px solid var(--bdr); padding-top:10px;">
                                        <div style="font-size:11px; font-weight:700; color:var(--acc); margin-bottom:8px;">CANDIDATE ANSWERS:</div>
                                        <?php foreach($questions_list as $q_idx => $q_item): ?>
                                            <?php
                                                $q_norm = normalize_question($q_item);
                                                $ans_val = $answers[$q_idx] ?? '-';
                                                $ans_display = is_array($ans_val) ? (empty($ans_val) ? '-' : implode(', ', $ans_val)) : ($ans_val === '' ? '-' : $ans_val);
                                            ?>
                                            <div style="margin-bottom:8px; font-size:12px;">
                                                <strong style="color:var(--txt);">Q<?= $q_idx + 1 ?>: <?= htmlspecialchars($q_norm['text']) ?></strong>
                                                <div style="background:var(--surf); padding:8px 12px; border-radius:6px; margin-top:4px; color:var(--mut);">
                                                    <?= nl2br(htmlspecialchars($ans_display)) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:30px; text-align:center; color:var(--mut); font-size:13px;">
                        📋 No screening questionnaires sent yet for this candidate.
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 4: Resume & Video Media -->
            <div id="tab_content_resume" class="main-tab-content" style="display:none;">
                <?php
                $youtube_id = null;
                if (!empty($c['youtube_url'])) {
                    if (preg_match('%(?:youtube(?:-nocookie)?\.com/(?:[^/ \n\s]+/\S+/|(?:v|e(?:mbed)?)/|\S*?[?&]v=)|youtu\.be/)([a-zA-Z0-9_-]{11})%i', $c['youtube_url'], $match)) {
                        $youtube_id = $match[1];
                    }
                }
                ?>

                <?php if($youtube_id): ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:18px; margin-bottom:20px;">
                        <div style="font-size:14px; font-weight:700; color:var(--txt); margin-bottom:12px;">
                            📹 Candidate Video Introduction
                        </div>
                        <div style="position:relative; padding-bottom:56.25%; height:0; overflow:hidden; border-radius:10px; background:#000;">
                            <iframe 
                                src="https://www.youtube.com/embed/<?= htmlspecialchars($youtube_id) ?>?rel=0" 
                                title="Candidate Video Introduction" 
                                frameborder="0" 
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" 
                                allowfullscreen 
                                style="position:absolute; top:0; left:0; width:100%; height:100%; border:none;">
                            </iframe>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if(isset($c['resume_path']) && $c['resume_path'] && file_exists($c['resume_path'])): ?>
                    <div style="background:var(--surf); padding:16px; border-radius:14px; border:1px solid var(--bdr); margin-bottom:20px;">
                        <div style="font-size:12px; color:var(--mut); margin-bottom:10px; font-weight:700; display:flex; justify-content:space-between; align-items:center;">
                            <span>📄 ORIGINAL PDF RESUME VIEWER</span>
                            <a href="<?= htmlspecialchars($c['resume_path']) ?>" target="_blank" style="color:var(--acc); font-size:12px; font-weight:700;">Open PDF in Full Screen ↗</a>
                        </div>
                        <iframe src="<?= htmlspecialchars($c['resume_path']) ?>" width="100%" height="700px" style="border:1px solid var(--bdr); border-radius:10px;"></iframe>
                    </div>
                <?php else: ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:14px; padding:30px; text-align:center; color:var(--mut); font-size:13px;">
                        📄 No PDF resume uploaded for this candidate.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    </main>

<!-- Send Questions Modal Overlay -->
<div id="sendQuestionsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center; padding:20px; animation:fadeIn 0.25s ease;">
    <div style="background:var(--card); border:1px solid var(--bdr); border-radius:18px; max-width:650px; width:100%; padding:28px; box-shadow:var(--shadow-lg); max-height:90vh; overflow-y:auto;">
        
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid var(--bdr); padding-bottom:16px;">
            <div>
                <div style="font-size:20px; font-weight:800; color:var(--txt);">📋 Send Questions to Candidate</div>
                <div style="font-size:12px; color:var(--mut); margin-top:2px;">Select a saved questionnaire template or compose custom questions on the fly.</div>
            </div>
            <button type="button" onclick="closeQuestionsModal()" style="background:var(--dim); border:1px solid var(--bdr); color:var(--txt); font-size:16px; font-weight:800; width:32px; height:32px; border-radius:50%; cursor:pointer;">✕</button>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="send_questionnaire">
            
            <!-- Mode Switcher Tabs -->
            <div style="display:flex; gap:10px; margin-bottom:20px; background:var(--surf); padding:6px; border-radius:10px; border:1px solid var(--bdr);">
                <button type="button" id="tabTemplateBtn" onclick="switchQuestionsTab('template')" style="flex:1; padding:8px; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; background:var(--acc); color:#fff;">📁 Choose Saved Template</button>
                <button type="button" id="tabCustomBtn" onclick="switchQuestionsTab('custom')" style="flex:1; padding:8px; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; background:transparent; color:var(--mut);">✍️ Make New Questions</button>
            </div>
            <input type="hidden" name="send_mode" id="sendModeInput" value="template">

            <!-- Tab 1: Saved Template Option -->
            <div id="tabTemplateContent">
                <?php if(!empty($saved_questionnaires)): ?>
                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Select Saved Questionnaire Template</label>
                        <select name="questionnaire_id" id="modalTemplateSelect" onchange="updateTemplatePreview()" style="width:100%;">
                            <?php foreach($saved_questionnaires as $sq): ?>
                                <option value="<?= $sq['id'] ?>" data-questions='<?= htmlspecialchars($sq['questions_json'], ENT_QUOTES, 'UTF-8') ?>'>
                                    📋 <?= htmlspecialchars($sq['title']) ?> (<?= count(json_decode($sq['questions_json'], true) ?: []) ?> questions)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:16px; margin-bottom:20px;">
                        <div style="font-size:11px; font-weight:700; color:var(--acc); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px;">Preview Included Questions:</div>
                        <div id="templateQuestionsPreviewList" style="font-size:13px; color:var(--txt); line-height:1.6;">
                            <!-- Dynamically populated via JS -->
                        </div>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; padding:30px 16px; background:var(--surf); border:1px dashed var(--bdr); border-radius:12px; margin-bottom:20px;">
                        <div style="font-size:32px; margin-bottom:8px;">📋</div>
                        <div style="font-size:14px; font-weight:700; color:var(--txt);">No Saved Questionnaires Found</div>
                        <div style="font-size:12px; color:var(--mut); margin-top:4px; margin-bottom:12px;">Switch to "Make New Questions" tab to send custom questions directly.</div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tab 2: Custom Questions Option -->
            <div id="tabCustomContent" style="display:none;">
                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; font-weight:700; color:var(--mut); margin-bottom:6px;">Questionnaire Title</label>
                    <input type="text" name="custom_title" placeholder="e.g. Technical & Salary Questions" value="Screening Questions for <?= htmlspecialchars($c['name']) ?>">
                </div>

                <div style="margin-bottom:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <label style="font-size:12px; font-weight:700; color:var(--mut);">Custom Questions List</label>
                        <button type="button" onclick="addModalCustomQuestion()" class="btn-secondary" style="padding:4px 10px; font-size:11px;">+ Add Question</button>
                    </div>

                    <div id="modalCustomQuestionsContainer">
                        <div class="q-item" style="background:var(--surf); border:1px solid var(--bdr); padding:10px; border-radius:8px; margin-bottom:8px; display:flex; gap:10px; align-items:center;">
                            <span style="font-size:12px; font-weight:700; color:var(--acc);" class="modal-q-num">Q1.</span>
                            <input type="text" name="custom_questions[]" value="What is your expected salary and availability date?" placeholder="Enter question..." style="margin:0; flex:1; font-size:12px;">
                            <button type="button" onclick="this.parentElement.remove()" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
                        </div>
                        <div class="q-item" style="background:var(--surf); border:1px solid var(--bdr); padding:10px; border-radius:8px; margin-bottom:8px; display:flex; gap:10px; align-items:center;">
                            <span style="font-size:12px; font-weight:700; color:var(--acc);" class="modal-q-num">Q2.</span>
                            <input type="text" name="custom_questions[]" value="Why are you interested in joining our company?" placeholder="Enter question..." style="margin:0; flex:1; font-size:12px;">
                            <button type="button" onclick="this.parentElement.remove()" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer Controls -->
            <div style="display:flex; gap:12px; border-top:1px solid var(--bdr); padding-top:18px; margin-top:20px;">
                <button type="button" onclick="closeQuestionsModal()" class="btn-secondary" style="flex:1; padding:10px;">Cancel</button>
                <button type="submit" class="btn-primary" style="flex:2; padding:10px; font-weight:700; border-radius:10px;">🚀 Send Questions to Candidate</button>
            </div>
        </form>

    </div>
</div>

<script>
function openQuestionsModal() {
    document.getElementById('sendQuestionsModal').style.display = 'flex';
    updateTemplatePreview();
}

function closeQuestionsModal() {
    document.getElementById('sendQuestionsModal').style.display = 'none';
}

function switchQuestionsTab(mode) {
    document.getElementById('sendModeInput').value = mode;
    const tabTemplateBtn = document.getElementById('tabTemplateBtn');
    const tabCustomBtn = document.getElementById('tabCustomBtn');
    const tabTemplateContent = document.getElementById('tabTemplateContent');
    const tabCustomContent = document.getElementById('tabCustomContent');

    if (mode === 'template') {
        tabTemplateBtn.style.background = 'var(--acc)';
        tabTemplateBtn.style.color = '#fff';
        tabCustomBtn.style.background = 'transparent';
        tabCustomBtn.style.color = 'var(--mut)';
        tabTemplateContent.style.display = 'block';
        tabCustomContent.style.display = 'none';
    } else {
        tabCustomBtn.style.background = 'var(--acc)';
        tabCustomBtn.style.color = '#fff';
        tabTemplateBtn.style.background = 'transparent';
        tabTemplateBtn.style.color = 'var(--mut)';
        tabCustomContent.style.display = 'block';
        tabTemplateContent.style.display = 'none';
    }
}

function updateTemplatePreview() {
    const select = document.getElementById('modalTemplateSelect');
    const previewContainer = document.getElementById('templateQuestionsPreviewList');
    if (!select || !previewContainer) return;

    const selectedOpt = select.options[select.selectedIndex];
    if (!selectedOpt) {
        previewContainer.innerHTML = '<em style="color:var(--mut);">No template selected</em>';
        return;
    }

    try {
        const rawJson = selectedOpt.getAttribute('data-questions');
        const questions = JSON.parse(rawJson) || [];
        if (questions.length === 0) {
            previewContainer.innerHTML = '<em style="color:var(--mut);">No questions found in this template</em>';
        } else {
            let html = '';
            questions.forEach((q, idx) => {
                const qText = (q && typeof q === 'object') ? (q.text || '') : q;
                html += `<div style="margin-bottom:6px;"><strong style="color:var(--acc);">Q${idx+1}:</strong> ${escapeHtml(qText)}</div>`;
            });
            previewContainer.innerHTML = html;
        }
    } catch(e) {
        previewContainer.innerHTML = '<em style="color:var(--mut);">Error loading question preview</em>';
    }
}

function addModalCustomQuestion() {
    const container = document.getElementById('modalCustomQuestionsContainer');
    const count = container.children.length + 1;
    const div = document.createElement('div');
    div.className = 'q-item';
    div.style.cssText = 'background:var(--surf); border:1px solid var(--bdr); padding:10px; border-radius:8px; margin-bottom:8px; display:flex; gap:10px; align-items:center;';
    div.innerHTML = `
        <span style="font-size:12px; font-weight:700; color:var(--acc);" class="modal-q-num">Q${count}.</span>
        <input type="text" name="custom_questions[]" placeholder="Enter question..." style="margin:0; flex:1; font-size:12px;">
        <button type="button" onclick="this.parentElement.remove()" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
    `;
    container.appendChild(div);
}

function escapeHtml(str) {
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}
</script>
    <!-- Modal: Schedule Interview -->
    <div id="scheduleInterviewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); backdrop-filter:blur(8px); z-index:4000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:520px; width:100%; position:relative; border-radius:18px; box-shadow:var(--shadow-lg);">
            <button onclick="closeInterviewModal()" style="position:absolute; top:20px; right:20px; background:none; border:none; color:var(--mut); font-size:22px; cursor:pointer; line-height:1;">✕</button>
            
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <div style="font-size:22px;">📅</div>
                <div style="font-size:20px; font-weight:800; color:var(--txt);">Propose Interview Slot</div>
            </div>
            <p style="font-size:12px; color:var(--mut); margin-bottom:20px;">Schedule an interview date, time, and meeting instructions for <strong><?= htmlspecialchars($c['name']) ?></strong>.</p>

            <form method="POST">
                <input type="hidden" name="action" value="schedule_interview">

                <div style="margin-bottom:16px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Interview Date & Time</label>
                    <input type="datetime-local" name="interview_datetime" value="<?= !empty($c['interview_datetime']) ? date('Y-m-d\TH:i', strtotime($c['interview_datetime'])) : '' ?>" required style="padding:10px 14px; font-size:13px;">
                </div>

                <div style="margin-bottom:22px;">
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:700;">Meeting Instructions / Video Call Link / Location Notes</label>
                    <textarea name="interview_notes" rows="4" placeholder="e.g. Google Meet link (https://meet.google.com/xyz), office location, or preparation notes..." style="padding:12px; font-size:13px; resize:vertical;"><?= htmlspecialchars($c['interview_notes'] ?? '') ?></textarea>
                </div>
                
                <div style="display:flex; justify-content:flex-end; gap:10px;">
                    <button type="button" onclick="closeInterviewModal()" class="btn-secondary" style="padding:10px 18px; font-size:13px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="padding:10px 22px; width:auto; font-size:13px; border-radius:10px;">Send Interview Invitation &rarr;</button>
                </div>
            </form>
        </div>
    </div>

<script>
function switchMainTab(tabName) {
    document.querySelectorAll('.main-tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.main-tab-btn').forEach(el => {
        el.classList.remove('active');
        el.style.background = 'transparent';
        el.style.color = 'var(--mut)';
    });
    
    const activeContent = document.getElementById('tab_content_' + tabName);
    const activeBtn = document.getElementById('tab_btn_' + tabName);
    if (activeContent && activeBtn) {
        activeContent.style.display = 'block';
        activeBtn.classList.add('active');
        activeBtn.style.background = 'linear-gradient(135deg, #B9D600, #0A0A0A)';
        activeBtn.style.color = '#FFFFFF';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const tabParam = urlParams.get('tab') || window.location.hash.replace('#', '');
    if (['ai', 'discussion', 'questionnaires', 'resume'].includes(tabParam)) {
        switchMainTab(tabParam);
    }
});

function openInterviewModal() {
    document.getElementById('scheduleInterviewModal').style.display = 'flex';
}
function closeInterviewModal() {
    document.getElementById('scheduleInterviewModal').style.display = 'none';
}
window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeInterviewModal();
});
</script>
<script src="theme.js"></script></body>
</html>
