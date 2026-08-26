<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

$userNotifs = (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate') ? getCandidateNotifications($pdo, $_SESSION['user_id']) : ['items' => [], 'unread_count' => 0];
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];

$search = $_GET['search'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_mode = $_GET['mode'] ?? '';

$sql = "SELECT j.*, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id WHERE j.status = 'Active'";
$params = [];

if ($search !== '') {
    $sql .= " AND (j.job_title LIKE ? OR j.department LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filter_type !== '') {
    $sql .= " AND j.employment_type = ?";
    $params[] = $filter_type;
}
if ($filter_mode !== '') {
    $sql .= " AND j.work_mode = ?";
    $params[] = $filter_mode;
}

$sql .= " ORDER BY j.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

// Fetch set of job IDs & AI scores that the logged-in candidate has applied to
$applied_job_ids = [];
$candidate_scores = [];
if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'candidate') {
    try {
        $app_stmt = $pdo->prepare("SELECT job_id, overall_score, skills_match, exp_match, edu_match, summary, relevance_label FROM candidates WHERE user_id = ? AND job_id IS NOT NULL ORDER BY created_at DESC");
        $app_stmt->execute([$_SESSION['user_id']]);
        $app_records = $app_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($app_records as $rec) {
            $j_id = $rec['job_id'];
            if (!in_array($j_id, $applied_job_ids)) {
                $applied_job_ids[] = $j_id;
                $candidate_scores[$j_id] = $rec;
            }
        }
    } catch (\Throwable $e) {
        $applied_job_ids = [];
        $candidate_scores = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Board - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .search-bar-panel {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }
        .split-layout {
            display: grid;
            grid-template-columns: 400px 1fr;
            gap: 24px;
            align-items: start;
        }
        .job-list-pane {
            max-height: calc(100vh - 190px);
            overflow-y: auto;
            padding-right: 8px;
        }
        .job-list-pane::-webkit-scrollbar {
            width: 5px;
        }
        .job-list-pane::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 4px;
        }
        .job-list-pane::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 4px;
        }
        .job-list-pane::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.35);
        }
        .job-card-item {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        .job-card-item:hover {
            border-color: rgba(59, 130, 246, 0.5);
            box-shadow: var(--shadow-md);
        }
        .job-card-item.selected-card {
            border-color: var(--acc);
            background: rgba(59, 130, 246, 0.04);
            box-shadow: 0 0 0 1px var(--acc);
        }
        .job-detail-pane {
            position: sticky;
            top: 80px;
        }
        .job-detail-sticky {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 16px;
            padding: 28px 32px;
            box-shadow: var(--shadow-md);
            max-height: calc(100vh - 190px);
            overflow-y: auto;
        }
        .job-detail-sticky::-webkit-scrollbar {
            width: 5px;
        }
        .job-detail-sticky::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 4px;
        }
        .job-detail-sticky::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 4px;
        }
        @media (max-width: 960px) {
            .split-layout {
                grid-template-columns: 1fr;
            }
            .job-list-pane {
                max-height: none;
                overflow-y: visible;
            }
            .job-detail-pane {
                display: none !important;
            }
        }
        @keyframes modalSlideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="jobs.php" class="active">📋 Job Board</a>
                <?php if(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate'): ?>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="profile.php">⚙️ Profile Settings</a>
                <?php elseif(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications & Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php endif; ?>
            </nav>

            <div class="header-right-actions">
                <?php if(isset($_SESSION['user_id'])): ?>
                    <?php if($_SESSION['user_role'] === 'candidate'): ?>
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
                    <?php endif; ?>

                    <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?> (<?= ucfirst($_SESSION['user_role']) ?>)</span>
                    <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; font-weight:700;">🔑 Login</a>
                    <a href="register.php" class="btn-primary" style="padding:6px 14px; font-size:12px; font-weight:700; width:auto; text-decoration:none;">✨ Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <main style="max-width:1300px; padding:24px 20px;">
        <?php if(isset($_SESSION['toast'])): ?>
            <div style="position:fixed; top:20px; right:20px; z-index:3000; background:rgba(0, 232, 122, 0.18); border:1px solid rgba(0, 232, 122, 0.5); border-radius:10px; padding:10px 18px; color:var(--grn); font-size:13px; font-weight:700;">
                <?= htmlspecialchars($_SESSION['toast']) ?>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($_SESSION['error']) ?>
                <?php unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Centered Indeed-Style Search Header Panel -->
        <form method="GET" class="search-bar-panel">
            <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <div style="flex:2; min-width:240px;">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Job title, keywords, or department..." style="margin:0; padding:11px 16px; font-size:13px; border-radius:10px;">
                </div>
                <div style="flex:1; min-width:140px;">
                    <select name="type" style="margin:0; padding:11px 12px; font-size:13px; border-radius:10px;">
                        <option value="">All Job Types</option>
                        <option value="Full-time" <?= $filter_type == 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                        <option value="Part-time" <?= $filter_type == 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                        <option value="Contract" <?= $filter_type == 'Contract' ? 'selected' : '' ?>>Contract</option>
                        <option value="Internship" <?= $filter_type == 'Internship' ? 'selected' : '' ?>>Internship</option>
                    </select>
                </div>
                <div style="flex:1; min-width:140px;">
                    <select name="mode" style="margin:0; padding:11px 12px; font-size:13px; border-radius:10px;">
                        <option value="">All Work Modes</option>
                        <option value="Remote" <?= $filter_mode == 'Remote' ? 'selected' : '' ?>>Remote</option>
                        <option value="On-site" <?= $filter_mode == 'On-site' ? 'selected' : '' ?>>On-site</option>
                        <option value="Hybrid" <?= $filter_mode == 'Hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <button type="submit" class="btn-primary" style="padding:11px 24px; font-size:13px; font-weight:800; width:auto; margin:0; border-radius:10px;">Find Jobs</button>
                <?php if($search || $filter_type || $filter_mode): ?>
                    <a href="jobs.php" class="btn-secondary" style="padding:11px 18px; font-size:13px; text-decoration:none; border-radius:10px;">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if(empty($jobs)): ?>
            <div class="panel" style="text-align:center; padding:60px 20px; color:var(--mut);">
                <div style="font-size:36px; margin-bottom:12px;">🔍</div>
                <div style="font-size:18px; font-weight:800; color:var(--txt); margin-bottom:6px;">No matching positions found</div>
                <div style="font-size:13px;">Try adjusting your keywords, job type, or work mode filters.</div>
            </div>
        <?php else: ?>
            <!-- 2-Column Indeed Split-Pane Orientation -->
            <div class="split-layout">
                
                <!-- Left Pane: Job List Cards -->
                <div class="job-list-pane">
                    <div style="font-size:12px; font-weight:700; color:var(--mut); margin-bottom:12px; text-transform:uppercase; letter-spacing:0.5px;">
                        <?= count($jobs) ?> Active Positions
                    </div>

                    <?php foreach($jobs as $index => $j): 
                        $is_applied = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate' && in_array($j['id'], $applied_job_ids);
                        $has_score = isset($candidate_scores[$j['id']]);
                        $sc = $has_score ? $candidate_scores[$j['id']] : null;
                        $score_num = $has_score ? (int)$sc['overall_score'] : 0;
                        $score_color = $score_num >= 75 ? '#00E87A' : ($score_num >= 50 ? '#F59E0B' : '#FF4D6A');
                    ?>
                        <div class="job-card-item <?= $index === 0 ? 'selected-card' : '' ?>" id="card_<?= $j['id'] ?>" onclick="selectJob(<?= $j['id'] ?>)">
                            <div style="font-size:16px; font-weight:800; color:var(--txt); margin-bottom:4px;"><?= htmlspecialchars($j['job_title']) ?></div>
                            <div style="font-size:12px; color:var(--mut); margin-bottom:10px;">
                                🏢 <strong><?= htmlspecialchars($j['employer_name'] ?? 'HireLah') ?></strong> &bull; 📍 <?= htmlspecialchars($j['department'] ?: 'General') ?>
                            </div>

                            <div style="display:flex; gap:6px; margin-bottom:10px; flex-wrap:wrap;">
                                <span class="chip" style="font-size:10px; background:var(--dim); color:var(--txt); border-color:var(--bdr);"><?= htmlspecialchars($j['employment_type']) ?></span>
                                <span class="chip" style="font-size:10px; background:var(--dim); color:var(--txt); border-color:var(--bdr);"><?= htmlspecialchars($j['work_mode']) ?></span>
                            </div>

                            <?php if($has_score): ?>
                                <div style="display:flex; align-items:center; justify-content:space-between; background:rgba(255,255,255,0.03); border:1px solid var(--bdr); border-radius:8px; padding:6px 10px; margin-top:8px;">
                                    <span style="font-size:10px; font-weight:700; color:var(--mut);">🤖 AI MATCH SCORE:</span>
                                    <span style="font-size:13px; font-weight:800; color:<?= $score_color ?>;"><?= $score_num ?>%</span>
                                </div>
                            <?php endif; ?>

                            <?php if($is_applied): ?>
                                <div style="font-size:11px; font-weight:700; color:var(--grn); margin-top:8px;">✓ Applied</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Right Pane: Sticky Job Details Preview -->
                <div class="job-detail-pane">
                    <div class="job-detail-sticky" id="jobDetailPreview">
                        <!-- Loaded via JS dynamically from JSON below -->
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Mobile Job Details Modal Popup -->
    <div id="mobileJobModal" class="modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); z-index:99999; align-items:center; justify-content:center; padding:16px;">
        <div class="modal" style="background:var(--card); border:1px solid var(--bdr); border-radius:20px; max-width:600px; width:100%; max-height:85vh; overflow-y:auto; padding:24px; position:relative; box-shadow:0 20px 50px rgba(0,0,0,0.5); animation:modalSlideUp 0.25s ease;">
            <button type="button" onclick="closeMobileJobModal()" style="position:absolute; top:16px; right:16px; background:var(--dim); border:1px solid var(--bdr); color:var(--txt); width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:800; font-size:14px; z-index:10;">✕</button>
            <div id="mobileJobModalContent">
                <!-- Populated dynamically on job click in mobile view -->
            </div>
        </div>
    </div>

    <!-- Embedded Job Data for Instant Client-Side Switching -->
    <script>
        const jobsData = <?= json_encode(array_values($jobs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const candidateScores = <?= json_encode($candidate_scores, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const appliedJobIds = <?= json_encode($applied_job_ids, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const userRole = <?= json_encode($_SESSION['user_role'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        function selectJob(jobId) {
            const job = jobsData.find(j => j.id == jobId);
            if (!job) return;

            // Highlight selected card
            document.querySelectorAll('.job-card-item').forEach(c => c.classList.remove('selected-card'));
            const selectedCard = document.getElementById('card_' + jobId);
            if (selectedCard) selectedCard.classList.add('selected-card');

            const isApplied = appliedJobIds.includes(job.id);
            const scoreData = candidateScores[job.id] || null;
            
            let applyButtonHtml = '';
            if (isApplied) {
                applyButtonHtml = `<button disabled class="btn-secondary" style="padding:12px 24px; font-size:14px; opacity:0.7; cursor:not-allowed;">✓ Applied for Position</button>`;
            } else {
                applyButtonHtml = `<a href="apply.php?job_id=${job.id}" class="btn-primary" style="padding:12px 28px; font-size:14px; font-weight:800; text-decoration:none; width:auto; display:inline-block; border-radius:10px;">Apply Now &rarr;</a>`;
            }

            let aiScoreHtml = '';
            if (scoreData) {
                const scoreNum = parseInt(scoreData.overall_score || 0);
                const scoreColor = scoreNum >= 75 ? '#00E87A' : (scoreNum >= 50 ? '#F59E0B' : '#FF4D6A');
                aiScoreHtml = `
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:12px; padding:16px; margin:20px 0;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                            <span style="font-size:12px; font-weight:800; color:var(--mut);">🤖 YOUR AI RESUME MATCH EVALUATION</span>
                            <span style="font-size:22px; font-weight:800; color:${scoreColor};">${scoreNum}%</span>
                        </div>
                        <div style="width:100%; height:6px; background:rgba(255,255,255,0.08); border-radius:3px; overflow:hidden; margin-bottom:10px;">
                            <div style="width:${scoreNum}%; height:100%; background:${scoreColor}; border-radius:3px;"></div>
                        </div>
                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <span class="chip" style="font-size:10px; background:rgba(59,130,246,0.1); color:var(--acc); border-color:rgba(59,130,246,0.25);">🎯 Skills: ${parseInt(scoreData.skills_match || 0)}%</span>
                            <span class="chip" style="font-size:10px; background:rgba(180, 214, 0, 0.1); color:#6B8A00; border-color:rgba(180, 214, 0, 0.25);">💼 Experience: ${parseInt(scoreData.exp_match || 0)}%</span>
                            <span class="chip" style="font-size:10px; background:rgba(16,185,129,0.1); color:#34D399; border-color:rgba(16,185,129,0.25);">🎓 Education: ${parseInt(scoreData.edu_match || 0)}%</span>
                        </div>
                    </div>
                `;
            }

            const detailHtml = `
                <div style="border-bottom:1px solid var(--bdr); padding-bottom:20px; margin-bottom:20px;">
                    <h2 style="font-size:22px; font-weight:800; color:var(--txt); margin:0 0 8px 0;">${escapeHtml(job.job_title)}</h2>
                    <div style="font-size:13px; color:var(--mut); margin-bottom:14px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <span>🏢 <strong>${escapeHtml(job.employer_name || 'HireLah')}</strong></span>
                        <span>&bull;</span>
                        <span>📍 ${escapeHtml(job.department || 'General')}</span>
                    </div>
                    <div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
                        <span class="chip" style="font-size:11px; background:var(--dim); color:var(--txt); border-color:var(--bdr);">${escapeHtml(job.employment_type || 'Full-time')}</span>
                        <span class="chip" style="font-size:11px; background:var(--dim); color:var(--txt); border-color:var(--bdr);">${escapeHtml(job.work_mode || 'On-site')}</span>
                    </div>
                    <div>
                        ${applyButtonHtml}
                    </div>
                </div>

                ${aiScoreHtml}

                <div style="margin-bottom:20px;">
                    <h3 style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:10px;">Position Details</h3>
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; background:var(--dim); padding:14px; border-radius:10px; font-size:12px;">
                        <div><span style="color:var(--mut);">Department:</span> <strong>${escapeHtml(job.department || 'General')}</strong></div>
                        <div><span style="color:var(--mut);">Employment Type:</span> <strong>${escapeHtml(job.employment_type || 'Full-time')}</strong></div>
                        <div><span style="color:var(--mut);">Work Mode:</span> <strong>${escapeHtml(job.work_mode || 'On-site')}</strong></div>
                        <div><span style="color:var(--mut);">Status:</span> <strong style="color:var(--grn);">${escapeHtml(job.status || 'Active')}</strong></div>
                    </div>
                </div>

                <div>
                    <h3 style="font-size:15px; font-weight:800; color:var(--txt); margin-bottom:12px;">Full Job Description</h3>
                    <div style="font-size:13px; color:var(--txt); line-height:1.65; white-space:pre-wrap;">${escapeHtml(job.description || 'No description provided.')}</div>
                </div>
            `;

            // Always update desktop inline preview
            const preview = document.getElementById('jobDetailPreview');
            if (preview) {
                preview.innerHTML = detailHtml;
            }

            // On Mobile view (screen width <= 960), pop up modal instead of scrolling down!
            if (window.innerWidth <= 960) {
                const mobileContent = document.getElementById('mobileJobModalContent');
                const mobileModal = document.getElementById('mobileJobModal');
                if (mobileContent && mobileModal) {
                    mobileContent.innerHTML = detailHtml;
                    mobileModal.style.display = 'flex';
                }
            }
        }

        function closeMobileJobModal() {
            const mobileModal = document.getElementById('mobileJobModal');
            if (mobileModal) mobileModal.style.display = 'none';
        }

        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeMobileJobModal();
        });

        document.addEventListener('click', function(e) {
            const mobileModal = document.getElementById('mobileJobModal');
            if (mobileModal && e.target === mobileModal) {
                closeMobileJobModal();
            }
        });

        function escapeHtml(str) {
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
