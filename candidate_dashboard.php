<?php
require_once 'auth.php';
require_role('candidate');
require_once 'notifications_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond_interview') {
    $cand_id = $_POST['candidate_id'] ?? '';
    $resp = $_POST['interview_response'] ?? '';

    if ($cand_id && in_array($resp, ['confirm', 'decline'])) {
        $new_status = ($resp === 'confirm') ? 'Confirmed' : 'Declined';
        $up = $pdo->prepare("UPDATE candidates SET interview_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
        $up->execute([$new_status, $cand_id, $_SESSION['user_id']]);

        if ($resp === 'confirm') {
            $c_info = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, COALESCE(NULLIF(u.contact_email, ''), u.email) as employer_email FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.id = ?");
            $c_info->execute([$cand_id]);
            $cand = $c_info->fetch();

            if ($cand && !empty($cand['employer_email'])) {
                require_once 'mailer.php';
                send_interview_confirmed_email($cand['employer_name'], $cand['employer_email'], $cand['name'], $cand['job_title'], $cand['interview_datetime'], $cand['interview_notes']);
            }
            $_SESSION['toast'] = "Interview slot confirmed! Employer notified.";
        } else {
            $_SESSION['toast'] = "Interview proposal declined.";
        }
    }
    header("Location: candidate_dashboard.php");
    exit;
}

$userNotifs = getCandidateNotifications($pdo, $_SESSION['user_id']);
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];

$stmt = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM candidates c JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.user_id = ? ORDER BY c.created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$applications = $stmt->fetchAll();

// Map questionnaire requests for candidate's applications
$app_ids = array_column($applications, 'id');
$questionnaire_requests = [];
$unread_msgs_counts = [];

if (!empty($app_ids)) {
    $in = str_repeat('?,', count($app_ids) - 1) . '?';
    $q_stmt = $pdo->prepare("SELECT qr.*, q.title FROM questionnaire_requests qr JOIN questionnaires q ON qr.questionnaire_id = q.id WHERE qr.candidate_id IN ($in) ORDER BY qr.sent_at DESC");
    $q_stmt->execute($app_ids);
    $q_list = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($q_list as $q_item) {
        if (!isset($questionnaire_requests[$q_item['candidate_id']])) {
            $questionnaire_requests[$q_item['candidate_id']] = [];
        }
        $questionnaire_requests[$q_item['candidate_id']][] = $q_item;
    }

    // Unread message count per application
    $msg_cnt_stmt = $pdo->prepare("SELECT candidate_id, COUNT(*) as cnt FROM messages WHERE candidate_id IN ($in) AND sender_role = 'employer' AND read_at IS NULL GROUP BY candidate_id");
    $msg_cnt_stmt->execute($app_ids);
    $msg_cnt_list = $msg_cnt_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($msg_cnt_list as $mc) {
        $unread_msgs_counts[$mc['candidate_id']] = (int)$mc['cnt'];
    }
}

// Calculate summary stats
$total_apps = count($applications);
$shortlisted_cnt = 0;
$pending_q_cnt = 0;
$total_score_sum = 0;

foreach ($applications as $a) {
    if (strtolower($a['status']) === 'shortlisted') $shortlisted_cnt++;
    $total_score_sum += (int)($a['overall_score'] ?? 0);
    $a_q_reqs = $questionnaire_requests[$a['id']] ?? [];
    foreach ($a_q_reqs as $q_req) {
        if ($q_req['status'] === 'Pending') $pending_q_cnt++;
    }
}
$avg_score = $total_apps > 0 ? round($total_score_sum / $total_apps) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Applications - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 18px 20px;
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .app-card {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 16px;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .app-card:hover {
            border-color: rgba(107, 138, 0, 0.35);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        .ai-drawer {
            display: none;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed var(--bdr);
            animation: fadeIn 0.25s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="jobs.php">📋 Job Board</a>
                <a href="candidate_dashboard.php" class="active">👤 My Applications</a>
                <a href="resume_builder.php">📝 AI Resume Builder</a>
                <a href="profile.php">⚙️ Profile Settings</a>
            </nav>

            <div class="header-right-actions">
                <div class="notif-bell-wrapper" style="position:relative; margin-right:8px;">
                    <button type="button" class="notif-bell-btn" id="notifBellBtn" onclick="toggleNotifDropdown()" title="Notifications">
                        🔔
                        <?php if($unreadCount > 0): ?>
                            <span class="notif-badge" id="notifBadgeCount"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </button>

                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header">
                            <span style="font-weight:800; font-size:13px; color:var(--txt);">Notifications</span>
                            <?php if($unreadCount > 0): ?>
                                <button type="button" class="notif-mark-all" onclick="markAllNotifsRead()">Mark all as read</button>
                            <?php endif; ?>
                        </div>

                        <div class="notif-list">
                            <?php if(empty($notifItems)): ?>
                                <div style="padding:24px; text-align:center; color:var(--mut); font-size:12px;">
                                    ✨ No notifications right now
                                </div>
                            <?php else: ?>
                                <?php foreach($notifItems as $item): ?>
                                    <a href="<?= htmlspecialchars($item['link']) ?>" class="notif-item <?= $item['is_read'] ? 'read' : 'unread' ?>" onclick="markNotifRead('<?= htmlspecialchars($item['key']) ?>')">
                                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:3px;">
                                            <span class="notif-item-title"><?= $item['title'] ?></span>
                                            <?php if(!$item['is_read']): ?>
                                                <span class="unread-dot"></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notif-item-msg"><?= $item['message'] ?></div>
                                        <div class="notif-item-time"><?= date('M j, g:i a', strtotime($item['time'])) ?></div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?> (<?= ucfirst($_SESSION['user_role']) ?>)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>
    <main>
        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <div>
                <h1 style="font-size:24px; font-weight:800; color:var(--txt); margin:0;">My Job Applications</h1>
                <p style="font-size:13px; color:var(--mut); margin:4px 0 0 0;">Track your application statuses, AI match feedback, and employer questionnaire requests.</p>
            </div>
            <a href="jobs.php" class="btn-primary" style="padding:9px 20px; font-size:13px; text-decoration:none; width:auto;">🔍 Explore More Jobs</a>
        </div>

        <!-- Executive Summary Metrics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div style="font-size:11px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.5px;">Applied Positions</div>
                <div style="font-size:28px; font-weight:800; color:var(--txt); margin-top:4px;"><?= $total_apps ?></div>
            </div>
            <div class="stat-card">
                <div style="font-size:11px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.5px;">Shortlisted Roles</div>
                <div style="font-size:28px; font-weight:800; color:var(--grn); margin-top:4px;"><?= $shortlisted_cnt ?></div>
            </div>
            <div class="stat-card">
                <div style="font-size:11px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.5px;">Avg AI Match Score</div>
                <div style="font-size:28px; font-weight:800; color:var(--acc); margin-top:4px;"><?= $avg_score ?>%</div>
            </div>
            <div class="stat-card" style="<?= $pending_q_cnt > 0 ? 'border-color:rgba(180, 214, 0, 0.4); background:rgba(180, 214, 0, 0.04);' : '' ?>">
                <div style="font-size:11px; font-weight:700; color:var(--mut); text-transform:uppercase; letter-spacing:0.5px;">Pending Questionnaires</div>
                <div style="font-size:28px; font-weight:800; color:<?= $pending_q_cnt > 0 ? '#6B8A00' : 'var(--txt)' ?>; margin-top:4px;"><?= $pending_q_cnt ?></div>
            </div>
        </div>

        <!-- Upcoming & Proposed Interviews Section -->
        <?php 
        $interview_apps = array_filter($applications, function($a) {
            return !empty($a['interview_status']) && in_array($a['interview_status'], ['Proposed', 'Confirmed']);
        });
        ?>
        <?php if(!empty($interview_apps)): ?>
            <div class="panel" style="margin-bottom:24px; border-color:rgba(217, 255, 79, 0.3); background:var(--surf);">
                <div style="font-size:16px; font-weight:800; color:var(--txt); margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                    <span>📅 Upcoming & Proposed Interviews</span>
                    <span class="chip" style="font-size:10px; background:rgba(217, 255, 79, 0.15); color:var(--acc); border-color:transparent;"><?= count($interview_apps) ?> Active</span>
                </div>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:14px;">
                    <?php foreach($interview_apps as $ia): ?>
                        <div style="background:var(--card); border:1px solid var(--bdr); border-radius:12px; padding:16px; position:relative;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <div style="font-size:15px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($ia['job_title']) ?></div>
                                <span class="chip" style="font-size:10px; background:<?= $ia['interview_status']==='Confirmed'?'rgba(16,185,129,0.15)':'rgba(180, 214, 0, 0.15)' ?>; color:<?= $ia['interview_status']==='Confirmed'?'var(--grn)':'var(--acc)' ?>; border-color:transparent;">
                                    <?= htmlspecialchars($ia['interview_status']) ?>
                                </span>
                            </div>

                            <div style="font-size:12px; color:var(--mut); margin-bottom:10px;">Employer: <strong><?= htmlspecialchars($ia['employer_name'] ?: 'Keria Employer') ?></strong></div>

                            <?php if(!empty($ia['interview_datetime'])): ?>
                                <div style="font-size:13px; font-weight:800; color:var(--acc); margin-bottom:10px; background:var(--dim); padding:8px 12px; border-radius:8px; display:inline-block;">
                                    🕒 <?= date('F j, Y \a\t g:i A', strtotime($ia['interview_datetime'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($ia['interview_notes'])): ?>
                                <div style="font-size:12px; color:var(--txt); margin-bottom:14px; background:rgba(255,255,255,0.03); padding:8px 10px; border-radius:6px; border:1px dashed var(--bdr);">
                                    <strong>Notes:</strong> <?= nl2br(htmlspecialchars($ia['interview_notes'])) ?>
                                </div>
                            <?php endif; ?>

                            <?php if($ia['interview_status'] === 'Proposed'): ?>
                                <div style="display:flex; gap:10px; margin-top:10px;">
                                    <form method="POST" style="flex:1; margin:0;">
                                        <input type="hidden" name="action" value="respond_interview">
                                        <input type="hidden" name="candidate_id" value="<?= $ia['id'] ?>">
                                        <input type="hidden" name="interview_response" value="confirm">
                                        <button type="submit" class="btn-primary" style="padding:8px; font-size:12px; border-radius:8px; width:100%;">✓ Confirm Slot</button>
                                    </form>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="respond_interview">
                                        <input type="hidden" name="candidate_id" value="<?= $ia['id'] ?>">
                                        <input type="hidden" name="interview_response" value="decline">
                                        <button type="submit" class="btn-secondary" style="padding:8px 12px; font-size:12px; color:var(--red); border-color:rgba(255,77,106,0.3);">✕ Decline</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div style="font-size:11px; font-weight:700; color:var(--grn); margin-top:6px;">✓ Interview Slot Confirmed</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Live Search Filter Bar -->
        <div style="margin-bottom:20px; display:flex; gap:12px; flex-wrap:wrap;">
            <div style="flex:1; min-width:240px;">
                <input type="text" id="appSearch" placeholder="🔍 Search applications by job title or employer..." onkeyup="filterApplications()" style="margin:0; padding:11px 16px; font-size:13px; border-radius:10px;">
            </div>
        </div>

        <?php if($total_apps === 0): ?>
            <div class="panel" style="text-align:center; padding:70px 40px; border-style:dashed;">
                <div style="font-size:38px; margin-bottom:12px;">📂</div>
                <div style="font-size:18px; font-weight:700; margin-bottom:6px; color:var(--txt);">No job applications submitted yet</div>
                <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Browse open positions on the job board and submit your resume to start tracking your progress.</div>
                <a href="jobs.php" class="btn-primary" style="width:auto; padding:10px 24px; text-decoration:none;">View Job Board &rarr;</a>
            </div>
        <?php else: ?>
            <div id="applicationsList">
                <?php foreach($applications as $app): 
                    $status = strtolower($app['status']);
                    $status_class = $status === 'shortlisted' ? 'chip-shortlisted' : ($status === 'rejected' ? 'chip-rejected' : 'chip-review');
                    $app_q_reqs = $questionnaire_requests[$app['id']] ?? [];
                    $score = (int)($app['overall_score'] ?? 0);
                    $score_color = $score >= 75 ? '#00E87A' : ($score >= 50 ? '#F59E0B' : '#FF4D6A');
                    $strengths = !empty($app['strengths']) ? (json_decode($app['strengths'], true) ?: []) : [];
                ?>
                    <div class="app-card" data-title="<?= htmlspecialchars(strtolower($app['job_title'])) ?>" data-employer="<?= htmlspecialchars(strtolower($app['employer_name'] ?? 'keria')) ?>">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:14px;">
                            <div style="flex:1; min-width:240px;">
                                <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                                    <div style="font-size:18px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($app['job_title']) ?></div>
                                    <span class="chip <?= $status_class ?>"><?= htmlspecialchars($app['status']) ?></span>
                                </div>
                                <div style="font-size:13px; color:var(--mut); display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                                    <span>🏢 <strong><?= htmlspecialchars($app['employer_name'] ?? 'Keria') ?></strong></span>
                                    <span>&bull;</span>
                                    <span>📅 Applied <?= date('M d, Y', strtotime($app['created_at'])) ?></span>
                                </div>
                            </div>

                            <div style="display:flex; align-items:center; gap:16px;">
                                <!-- AI Match Score Badge -->
                                <div style="text-align:right; background:rgba(255,255,255,0.03); border:1px solid var(--bdr); padding:8px 16px; border-radius:10px;">
                                    <div style="font-size:20px; font-weight:800; color:<?= $score_color ?>; line-height:1;"><?= $score ?>%</div>
                                    <div style="font-size:9px; font-weight:700; color:var(--mut); text-transform:uppercase; margin-top:2px;">AI Match</div>
                                </div>

                                <?php 
                                    $app_resume = !empty($app['filename']) ? ('uploads/resumes/' . $app['filename']) : '';
                                ?>
                                <?php if(!empty($app_resume)): ?>
                                    <button type="button" class="btn-secondary" style="padding:8px 14px; font-size:12px; font-weight:700;" onclick="openResumeModal('<?= htmlspecialchars($app_resume) ?>', '<?= htmlspecialchars($app['job_title']) ?> Resume')">
                                        👁️ Preview Resume
                                    </button>
                                <?php endif; ?>

                                <button type="button" class="btn-secondary" style="padding:8px 14px; font-size:12px; font-weight:700;" onclick="toggleDrawer('drawer_<?= $app['id'] ?>')">
                                    🔍 AI Match Breakdown
                                </button>
                                
                                <?php $un_cnt = $unread_msgs_counts[$app['id']] ?? 0; ?>
                                <a href="candidate.php?id=<?= $app['id'] ?>" class="btn-secondary" style="padding:8px 14px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; <?= $un_cnt > 0 ? 'background:rgba(217, 255, 79, 0.18); border-color:var(--acc); color:#fff;' : '' ?>">
                                    💬 Discussion Thread
                                    <?php if($un_cnt > 0): ?>
                                        <span style="background:var(--acc); color:#fff; font-size:10px; font-weight:800; padding:2px 7px; border-radius:10px;"><?= $un_cnt ?> New</span>
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>

                        <!-- Questionnaire Action Banner -->
                        <?php if(!empty($app_q_reqs)): ?>
                            <div style="margin-top:14px; padding:12px 16px; background:rgba(180, 214, 0, 0.08); border:1px solid rgba(180, 214, 0, 0.3); border-radius:10px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                <div>
                                    <div style="font-size:13px; font-weight:700; color:var(--txt);">📋 Screening Questionnaire Notification</div>
                                    <div style="font-size:12px; color:var(--mut);">The hiring team requested you to complete a brief questionnaire for this role.</div>
                                </div>
                                <?php foreach($app_q_reqs as $q_req): ?>
                                    <?php if($q_req['status'] === 'Pending'): ?>
                                        <a href="answer_questionnaire.php?token=<?= $q_req['id'] ?>" class="btn-primary" style="padding:8px 18px; font-size:12px; text-decoration:none; background:linear-gradient(135deg, #6B8A00, #3B82F6); width:auto;">
                                            Answer Screening Questions &rarr;
                                        </a>
                                    <?php else: ?>
                                        <span style="font-size:12px; color:var(--grn); font-weight:700;">✓ Questionnaire Submitted</span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Expandable AI Evaluation Drawer -->
                        <div id="drawer_<?= $app['id'] ?>" class="ai-drawer">
                            <div style="font-size:13px; font-weight:700; color:var(--txt); margin-bottom:8px;">🤖 AI Resume Match Evaluation Breakdown:</div>
                            
                            <!-- Progress Bar -->
                            <div style="width:100%; height:6px; background:rgba(255,255,255,0.08); border-radius:3px; overflow:hidden; margin-bottom:12px;">
                                <div style="width:<?= $score ?>%; height:100%; background:<?= $score_color ?>; border-radius:3px;"></div>
                            </div>

                            <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                                <span class="chip" style="font-size:11px; background:rgba(59, 130, 246, 0.1); color:var(--acc); border-color:rgba(59, 130, 246, 0.25);">
                                    🎯 Skills Match: <?= (int)$app['skills_match'] ?>%
                                </span>
                                <span class="chip" style="font-size:11px; background:rgba(180, 214, 0, 0.1); color:#6B8A00; border-color:rgba(180, 214, 0, 0.25);">
                                    💼 Experience Match: <?= (int)$app['exp_match'] ?>%
                                </span>
                                <span class="chip" style="font-size:11px; background:rgba(16, 185, 129, 0.1); color:#34D399; border-color:rgba(16, 185, 129, 0.25);">
                                    🎓 Education Match: <?= (int)$app['edu_match'] ?>%
                                </span>
                            </div>

                            <?php if(!empty($app['summary'])): ?>
                                <div style="font-size:12px; color:var(--txt); line-height:1.5; margin-bottom:10px; background:var(--dim); padding:10px 14px; border-radius:8px; border-left:3px solid <?= $score_color ?>;">
                                    "<?= htmlspecialchars($app['summary']) ?>"
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($strengths)): ?>
                                <div style="font-size:11px; font-weight:700; color:var(--grn); margin-bottom:4px;">✨ Key Highlight Evidence:</div>
                                <ul style="margin:0; padding-left:16px; font-size:12px; color:var(--txt); line-height:1.5;">
                                    <?php foreach(array_slice($strengths, 0, 3) as $s): ?>
                                        <li><?= htmlspecialchars(preg_replace('/\[Resume Evidence:.*?\]/i', '', $s)) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <!-- Resume PDF Preview Popup Modal Overlay -->
    <div id="resumePreviewModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); backdrop-filter:blur(10px); z-index:9000; align-items:center; justify-content:center; padding:20px;">
        <div class="panel" style="max-width:920px; width:100%; max-height:92vh; display:flex; flex-direction:column; padding:0; border-radius:18px; overflow:hidden; box-shadow:var(--shadow-lg); border:1px solid var(--bdr); animation:modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1);">
            
            <!-- Modal Header -->
            <div style="padding:16px 24px; background:var(--surf); border-bottom:1px solid var(--bdr); display:flex; justify-content:space-between; align-items:center; gap:16px;">
                <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                    <div style="font-size:24px;">📄</div>
                    <div style="min-width:0;">
                        <div id="resumeModalTitle" style="font-size:15px; font-weight:800; color:var(--txt); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">Candidate Resume Preview</div>
                        <div id="resumeModalFilename" style="font-size:11.5px; color:var(--mut); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">PDF Document</div>
                    </div>
                </div>
                
                <div style="display:flex; align-items:center; gap:10px; flex-shrink:0;">
                    <a id="resumeModalDownloadBtn" href="#" download class="btn-primary" style="padding:8px 16px; font-size:12px; font-weight:700; text-decoration:none; display:inline-flex; align-items:center; gap:6px; border-radius:8px; width:auto;">
                        📥 Download PDF
                    </a>
                    <button type="button" onclick="closeResumeModal()" style="background:none; border:none; color:var(--mut); font-size:24px; cursor:pointer; line-height:1; padding:4px 8px;" title="Close Modal">✕</button>
                </div>
            </div>

            <!-- Modal PDF Viewer Iframe Body -->
            <div style="flex:1; background:#181825; position:relative; min-height:580px; display:flex; align-items:center; justify-content:center;">
                <iframe id="resumeModalIframe" src="" style="width:100%; height:100%; min-height:580px; border:none;"></iframe>
            </div>
        </div>
    </div>

    <script>
        function openResumeModal(pdfUrl, title) {
            const modal = document.getElementById('resumePreviewModal');
            const iframe = document.getElementById('resumeModalIframe');
            const titleEl = document.getElementById('resumeModalTitle');
            const fileEl = document.getElementById('resumeModalFilename');
            const downloadBtn = document.getElementById('resumeModalDownloadBtn');

            if (!modal || !iframe) return;

            titleEl.textContent = title ? ('📄 ' + title) : '📄 Candidate Resume Preview';
            fileEl.textContent = pdfUrl;
            downloadBtn.href = pdfUrl;
            iframe.src = pdfUrl;

            modal.style.display = 'flex';
        }

        function closeResumeModal() {
            const modal = document.getElementById('resumePreviewModal');
            const iframe = document.getElementById('resumeModalIframe');
            if (modal) modal.style.display = 'none';
            if (iframe) iframe.src = '';
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeResumeModal();
        });

        document.getElementById('resumePreviewModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeResumeModal();
        });

        function toggleDrawer(id) {
            const drawer = document.getElementById(id);
            if (drawer) {
                drawer.style.display = (drawer.style.display === 'block') ? 'none' : 'block';
            }
        }

        function toggleNotifDropdown() {
            const dropdown = document.getElementById('notifDropdown');
            if (dropdown) {
                dropdown.classList.toggle('show');
            }
        }

        document.addEventListener('click', function(e) {
            const wrapper = document.querySelector('.notif-bell-wrapper');
            if (wrapper && !wrapper.contains(e.target)) {
                const dropdown = document.getElementById('notifDropdown');
                if (dropdown) dropdown.classList.remove('show');
            }
        });

        function markNotifRead(key) {
            fetch('mark_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ notification_key: key })
            }).catch(err => console.error(err));
        }

        function markAllNotifsRead() {
            fetch('mark_notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mark_all: true })
            }).then(res => res.json()).then(data => {
                if (data.success) {
                    const badge = document.getElementById('notifBadgeCount');
                    if (badge) badge.remove();
                    document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
                    document.querySelectorAll('.unread-dot').forEach(el => el.remove());
                }
            }).catch(err => console.error(err));
        }
    </script>
    <script src="theme.js"></script>
</body>
</html>
