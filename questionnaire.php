<?php
require_once 'auth.php';
require_role('employer');
require_once 'notifications_helper.php';
require_once 'questionnaire_helpers.php';
require_once 'company_helpers.php';

$empNotifs = getEmployerNotifications($pdo, $_SESSION['user_id']);
$notifItems = $empNotifs['items'];
$unreadCount = $empNotifs['unread_count'];

$employer_id = $_SESSION['user_id'];
$active_company_id = get_active_company_id($pdo);

// Handle Questionnaire Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_questionnaire') {
    $del_id = $_POST['questionnaire_id'] ?? null;
    if ($del_id) {
        if ($active_company_id) {
            $del_stmt = $pdo->prepare("DELETE FROM questionnaires WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
            $del_stmt->execute([$del_id, $active_company_id, $employer_id]);
        } else {
            $del_stmt = $pdo->prepare("DELETE FROM questionnaires WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
            $del_stmt->execute([$del_id, $employer_id]);
        }
        $_SESSION['toast'] = "Questionnaire template deleted.";
        header("Location: questionnaire.php");
        exit;
    }
}

// Handle Questionnaire Save (Insert or Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_questionnaire') {
    $q_id = $_POST['questionnaire_id'] ?? null;
    $title = trim($_POST['title'] ?? 'Screening Questionnaire');
    $job_id = !empty($_POST['job_id']) ? (int)$_POST['job_id'] : null;
    $status = ($_POST['status'] ?? 'Active') === 'Draft' ? 'Draft' : 'Active';
    $description = trim($_POST['description'] ?? '');

    $valid_types = ['short_text', 'long_text', 'multiple_choice', 'checkbox', 'rating_scale', 'yes_no', 'number'];
    $raw_questions = json_decode($_POST['questions_data'] ?? '[]', true) ?: [];

    $clean_questions = [];
    foreach ($raw_questions as $q) {
        $q_text = trim($q['text'] ?? '');
        if ($q_text === '') continue;

        $q_type = in_array($q['type'] ?? '', $valid_types) ? $q['type'] : 'short_text';
        $q_required = !empty($q['required']);

        $question = [
            'text' => $q_text,
            'type' => $q_type,
            'required' => $q_required,
        ];

        if (in_array($q_type, ['multiple_choice', 'checkbox'])) {
            $clean_options = [];
            foreach (($q['options'] ?? []) as $opt) {
                $opt_text = trim($opt);
                if ($opt_text !== '') $clean_options[] = $opt_text;
            }
            $question['options'] = $clean_options;
        }

        $clean_questions[] = $question;
    }

    if (empty($clean_questions)) {
        $error = "Please add at least one question to the questionnaire.";
    } else {
        $json = json_encode($clean_questions);
        try {
            if ($q_id) {
                if ($active_company_id) {
                    $update_stmt = $pdo->prepare("UPDATE questionnaires SET title = ?, job_id = ?, status = ?, description = ?, questions_json = ? WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
                    $update_stmt->execute([$title, $job_id, $status, $description, $json, $q_id, $active_company_id, $employer_id]);
                } else {
                    $update_stmt = $pdo->prepare("UPDATE questionnaires SET title = ?, job_id = ?, status = ?, description = ?, questions_json = ? WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
                    $update_stmt->execute([$title, $job_id, $status, $description, $json, $q_id, $employer_id]);
                }
                $_SESSION['toast'] = "Questionnaire updated successfully!";
            } else {
                $insert_stmt = $pdo->prepare("INSERT INTO questionnaires (job_id, employer_id, company_id, title, status, description, questions_json) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->execute([$job_id, $employer_id, $active_company_id, $title, $status, $description, $json]);
                $_SESSION['toast'] = "New Questionnaire template saved successfully!";
            }
            header("Location: questionnaire.php");
            exit;
        } catch (\Throwable $e) {
            // Without this, a DB-level failure (e.g. a NOT NULL column rejecting
            // a template saved with no job assigned) is an uncaught exception —
            // with display_errors off in production that renders as a blank
            // "HTTP ERROR 500" page instead of a usable error message.
            error_log("Questionnaire Save Error: " . $e->getMessage());
            $error = "Could not save this questionnaire template. Please try again, or contact support if the problem continues.";
        }
    }
}

// Fetch all jobs for optional assignment dropdown
if ($active_company_id) {
    $jobs_stmt = $pdo->prepare("SELECT id, job_title FROM jobs WHERE company_id = ? OR (company_id IS NULL AND employer_id = ?) ORDER BY created_at DESC");
    $jobs_stmt->execute([$active_company_id, $employer_id]);
} else {
    $jobs_stmt = $pdo->prepare("SELECT id, job_title FROM jobs WHERE employer_id = ? OR employer_id IS NULL ORDER BY created_at DESC");
    $jobs_stmt->execute([$employer_id]);
}
$my_jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all saved questionnaires for this employer's company team
if ($active_company_id) {
    $q_stmt = $pdo->prepare("SELECT q.*, j.job_title FROM questionnaires q LEFT JOIN jobs j ON q.job_id = j.id WHERE q.company_id = ? OR (q.company_id IS NULL AND q.employer_id = ?) ORDER BY q.created_at DESC");
    $q_stmt->execute([$active_company_id, $employer_id]);
} else {
    $q_stmt = $pdo->prepare("SELECT q.*, j.job_title FROM questionnaires q LEFT JOIN jobs j ON q.job_id = j.id WHERE q.employer_id = ? OR q.employer_id IS NULL ORDER BY q.created_at DESC");
    $q_stmt->execute([$employer_id]);
}
$questionnaires = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

// If editing a specific questionnaire via GET param
$editing_q = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id) {
    foreach ($questionnaires as $q) {
        if ($q['id'] == $edit_id) {
            $editing_q = $q;
            break;
        }
    }
}

// If pre-selecting for a specific job via GET param
$job_id_param = $_GET['job_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Questionnaires - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .q-item {
            background: var(--surf);
            border: 1px solid var(--bdr);
            padding: 10px 12px;
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex !important;
            flex-direction: row !important;
            gap: 10px !important;
            align-items: center !important;
        }
        .q-item input {
            flex: 1 !important;
            min-width: 0 !important;
            margin: 0 !important;
        }
        .q-item button {
            flex-shrink: 0 !important;
            width: 32px !important;
            height: 32px !important;
            min-height: 32px !important;
            padding: 0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            border-radius: 8px !important;
        }

        .swipe-hint {
            display: none;
        }

        .template-card {
            background: var(--card);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.2s ease;
        }
        .template-card:hover {
            border-color: var(--acc);
            box-shadow: var(--shadow-md);
        }

        @media (max-width: 900px) {
            html, body {
                overflow-x: hidden !important;
                width: 100% !important;
                max-width: 100vw !important;
            }
            .swipe-hint {
                display: inline-block !important;
            }
            .grid-2 {
                grid-template-columns: 1fr !important;
                gap: 20px !important;
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .templates-wrapper-col {
                width: 100% !important;
                max-width: 100% !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }
            .templates-slider-container {
                display: flex !important;
                overflow-x: auto !important;
                overflow-y: hidden !important;
                scroll-snap-type: x mandatory !important;
                gap: 12px !important;
                padding: 4px 0 14px 0 !important;
                -webkit-overflow-scrolling: touch !important;
                width: 100% !important;
                max-width: 100% !important;
                box-sizing: border-box !important;
            }
            .templates-slider-container::-webkit-scrollbar {
                height: 4px;
            }
            .templates-slider-container::-webkit-scrollbar-thumb {
                background: var(--bdr);
                border-radius: 4px;
            }
            .templates-slider-container .template-card {
                flex: 0 0 85% !important;
                min-width: 260px !important;
                max-width: 85% !important;
                width: 85% !important;
                scroll-snap-align: center !important;
                margin-bottom: 0 !important;
                padding: 16px !important;
                box-sizing: border-box !important;
            }
            .page-title-row {
                flex-direction: column !important;
                align-items: stretch !important;
            }
            .page-title-btn {
                width: 100% !important;
                text-align: center !important;
            }
        }

        @media (max-width: 500px) {
            main {
                padding: 16px 12px !important;
            }
            .panel {
                padding: 18px 14px !important;
            }
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
                <a href="employer_dashboard.php">👥 Applications & Stats</a>
                <a href="job_dashboard.php">💼 My Jobs</a>
                <a href="questionnaire.php" class="active">📋 Questionnaires</a>
                <a href="profile.php">⚙️ Settings</a>
            </nav>
            <?php include 'company_switcher.php'; ?>

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
                            <span style="font-weight:800; font-size:13px; color:var(--txt);">Employer Notifications</span>
                            <?php if($unreadCount > 0): ?>
                                <button type="button" class="notif-mark-all" onclick="markAllNotifsRead()">Mark all as read</button>
                            <?php endif; ?>
                        </div>

                        <div class="notif-list">
                            <?php if(empty($notifItems)): ?>
                                <div style="padding:24px; text-align:center; color:var(--mut); font-size:12px;">
                                    ✨ No new application notifications
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

                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?> (<?= ucfirst($_SESSION['user_role'] ?? 'Employer') ?>)</span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main style="max-width:1100px;">
        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if(isset($error)): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="page-title-row" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size:26px; font-weight:800; color:var(--txt); margin:0;">📋 Saved Questionnaire Templates</h1>
                <p style="font-size:13px; color:var(--mut); margin-top:4px; margin-bottom:0;">Build reusable questionnaire sets to dispatch to candidates with 1 click.</p>
            </div>
            <button type="button" onclick="openQuestionnaireModal()" class="btn-primary page-title-btn" style="padding:10px 20px; font-size:14px; width:auto; border-radius:10px;">+ Create New Questionnaire</button>
        </div>

        <div class="templates-wrapper-col" style="width:100%;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <div style="font-size:16px; font-weight:800; color:var(--txt);">📁 Saved Templates Library (<?= count($questionnaires) ?>)</div>
                <div class="swipe-hint" style="font-size:11px; color:var(--acc); font-weight:600;">Swipe cards 👈 👉</div>
            </div>

            <?php if(empty($questionnaires)): ?>
                <div class="panel" style="text-align:center; padding:40px 20px; border-style:dashed;">
                    <div style="font-size:36px; margin-bottom:10px;">📋</div>
                    <div style="font-size:16px; font-weight:700; color:var(--txt); margin-bottom:4px;">No Questionnaires Saved Yet</div>
                    <p style="font-size:12px; color:var(--mut); margin-bottom:0;">Click "+ Create New Questionnaire" above to save your first question template.</p>
                </div>
            <?php else: ?>
                <div class="templates-slider-container">
                    <?php foreach($questionnaires as $q): ?>
                        <?php
                            $q_list = json_decode($q['questions_json'], true) ?: [];
                        ?>
                        <div class="template-card">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <div>
                                    <div style="font-size:17px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($q['title']) ?></div>
                                    <div style="font-size:11px; color:var(--mut); margin-top:2px;">
                                        <?= !empty($q['job_title']) ? '💼 ' . htmlspecialchars($q['job_title']) : '🌐 Reusable Template (All Jobs)' ?>
                                    </div>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                    <span class="chip" style="background:rgba(59, 130, 246, 0.1); color:var(--acc); border-color:rgba(59, 130, 246, 0.3);">
                                        <?= count($q_list) ?> Questions
                                    </span>
                                    <?php if (($q['status'] ?? 'Active') === 'Draft'): ?>
                                        <span class="chip" style="background:rgba(255,140,66,0.15); color:var(--org); border-color:transparent; font-size:10px;">📝 Draft</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:8px; padding:10px 14px; margin-top:12px; margin-bottom:14px; max-height:110px; overflow-y:auto;">
                                <?php foreach($q_list as $idx => $q_item): ?>
                                    <?php $q_norm = normalize_question($q_item); ?>
                                    <div style="font-size:12px; color:var(--txt); margin-bottom:4px;">
                                        <strong style="color:var(--acc);">Q<?= $idx + 1 ?>:</strong> <?= htmlspecialchars($q_norm['text']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="display:flex; gap:8px; justify-content:flex-end; align-items:center;">
                                <a href="questionnaire.php?edit=<?= $q['id'] ?>" class="btn-secondary" style="padding:5px 12px; font-size:11px; text-decoration:none;">✏️ Edit Template</a>

                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this questionnaire template?');" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_questionnaire">
                                    <input type="hidden" name="questionnaire_id" value="<?= $q['id'] ?>">
                                    <button type="submit" class="btn-secondary" style="padding:5px 12px; font-size:11px; color:var(--red); border-color:rgba(255,77,106,0.3);">🗑️ Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Create / Edit Questionnaire Modal -->
        <div id="questionnaireModal" style="display:<?= $editing_q ? 'flex' : 'none' ?>; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:var(--card); border:1px solid var(--bdr); border-radius:18px; max-width:650px; width:100%; padding:28px; box-shadow:var(--shadow-lg); max-height:90vh; overflow-y:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <div class="panel-title" id="formTitle" style="margin:0;">
                        <?= $editing_q ? '✏️ Edit Questionnaire Template' : '📋 New Questionnaire' ?>
                    </div>
                    <button type="button" onclick="closeQuestionnaireModal()" style="background:var(--dim); border:1px solid var(--bdr); color:var(--txt); font-size:16px; font-weight:800; width:32px; height:32px; border-radius:50%; cursor:pointer;">✕</button>
                </div>

                <form method="POST" onsubmit="return validateAndSerializeQuestionnaireForm();">
                    <input type="hidden" name="action" value="save_questionnaire">
                    <input type="hidden" name="questionnaire_id" id="questionnaire_id" value="<?= $editing_q['id'] ?? '' ?>">

                    <div style="font-size:11px; font-weight:800; color:var(--acc); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:12px;">Basic Info</div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Title *</label>
                        <input type="text" name="title" id="qTitle" value="<?= htmlspecialchars($editing_q['title'] ?? 'Pre-Interview Screening Questions') ?>" placeholder="e.g. Senior Engineer Technical Assessment" required>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Related Job Title</label>
                            <select name="job_id" id="qJobId">
                                <option value="">🌐 General Template (Reusable for any Job)</option>
                                <?php foreach($my_jobs as $jb): ?>
                                    <option value="<?= $jb['id'] ?>" <?= (($editing_q['job_id'] ?? $job_id_param) == $jb['id']) ? 'selected' : '' ?>>
                                        💼 <?= htmlspecialchars($jb['job_title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Status</label>
                            <select name="status" id="qStatus">
                                <option value="Active" <?= (($editing_q['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>Active</option>
                                <option value="Draft" <?= (($editing_q['status'] ?? 'Active') === 'Draft') ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Description / Instructions</label>
                        <textarea name="description" id="qDescription" rows="2" placeholder="Optional intro shown to candidates at the top of the form..."><?= htmlspecialchars($editing_q['description'] ?? '') ?></textarea>
                    </div>

                    <div style="border-top:1px dashed var(--bdr); padding-top:16px; margin-bottom:18px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <label style="font-size:13px; color:var(--txt); font-weight:700;">Questions (<span id="questionsCount">0</span>)</label>
                            <button type="button" onclick="addQuestion()" class="btn-secondary" style="padding:4px 12px; font-size:11px;">+ Add Question</button>
                        </div>

                        <input type="hidden" name="questions_data" id="questionsDataInput" value="">
                        <div id="questionsContainer"></div>
                    </div>

                    <div style="display:flex; gap:10px; margin-top:24px;">
                        <button type="button" onclick="closeQuestionnaireModal()" class="btn-secondary" style="flex:1; padding:11px;">Cancel</button>
                        <button type="submit" class="btn-primary" style="flex:1; padding:11px; border-radius:10px;">💾 Save Questionnaire Template</button>
                    </div>
                </form>
            </div>
        </div>

        <?php
            $edit_questions_for_js = [];
            if ($editing_q) {
                $decoded_edit_questions = json_decode($editing_q['questions_json'], true) ?: [];
                foreach ($decoded_edit_questions as $q_raw) {
                    $edit_questions_for_js[] = normalize_question($q_raw);
                }
            } else {
                $edit_questions_for_js = [
                    ['text' => "What is your expected salary and notice period?", 'type' => 'short_text', 'required' => true, 'options' => []],
                    ['text' => "Why are you interested in joining our team?", 'type' => 'long_text', 'required' => true, 'options' => []],
                    ['text' => "What relevant technical experience do you bring to this role?", 'type' => 'long_text', 'required' => true, 'options' => []],
                ];
            }
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const initialQuestions = <?= json_encode($edit_questions_for_js) ?>;
                initialQuestions.forEach(function(q) { addQuestion(q.text, q.type, q.required, q.options); });
                <?php if ($editing_q): ?>
                    document.getElementById('questionnaireModal').style.display = 'flex';
                <?php endif; ?>
            });
        </script>
    </main>

    <script>
        function escapeAttr(str) {
            return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        const QUESTION_TYPES = [
            ['short_text', '📝 Short Text'],
            ['long_text', '📄 Long Text'],
            ['multiple_choice', '⚪ Multiple Choice'],
            ['checkbox', '☑️ Checkbox'],
            ['rating_scale', '⭐ Rating Scale'],
            ['yes_no', '👍 Yes / No'],
            ['number', '🔢 Number'],
        ];

        function questionTypeOptionsHtml(selected) {
            return QUESTION_TYPES.map(function(pair) {
                return '<option value="' + pair[0] + '"' + (pair[0] === selected ? ' selected' : '') + '>' + pair[1] + '</option>';
            }).join('');
        }

        function needsOptions(type) {
            return type === 'multiple_choice' || type === 'checkbox';
        }

        function addQuestion(text = '', type = 'short_text', required = true, options = []) {
            const container = document.getElementById('questionsContainer');
            const count = container.children.length + 1;
            const div = document.createElement('div');
            div.className = 'q-item';
            div.style.cssText = 'flex-direction:column; align-items:stretch;';
            div.innerHTML = `
                <div style="display:flex; gap:10px; align-items:center; width:100%;">
                    <span style="font-size:12px; font-weight:700; color:var(--acc); flex-shrink:0;" class="q-num">Q${count}.</span>
                    <input type="text" class="q-text" value="${escapeAttr(text)}" placeholder="Enter question text..." style="margin:0; flex:1; font-size:13px;">
                    <select class="q-type" onchange="onQuestionTypeChange(this)" style="margin:0; width:150px; font-size:12px;">
                        ${questionTypeOptionsHtml(type)}
                    </select>
                    <button type="button" onclick="removeQuestion(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
                </div>
                <div style="display:flex; align-items:center; gap:6px; margin-top:8px; margin-left:26px;">
                    <input type="checkbox" class="q-required" ${required ? 'checked' : ''} style="width:auto; margin:0;">
                    <label style="font-size:11px; color:var(--mut);">Required</label>
                </div>
                <div class="q-options-wrap" style="display:${needsOptions(type) ? 'block' : 'none'}; margin-top:10px; margin-left:26px; background:var(--surf); border:1px solid var(--bdr); border-radius:8px; padding:10px;">
                    <div style="font-size:11px; font-weight:700; color:var(--mut); margin-bottom:8px;">Options</div>
                    <div class="q-options-list"></div>
                    <button type="button" onclick="addOption(this)" class="btn-secondary" style="padding:3px 10px; font-size:11px;">+ Add Option</button>
                </div>
            `;
            container.appendChild(div);
            const optAddBtn = div.querySelector('.q-options-wrap button');
            const opts = options.length > 0 ? options : (needsOptions(type) ? ['', ''] : []);
            opts.forEach(function(opt) { addOption(optAddBtn, opt); });
            updateQuestionsCount();
        }

        function onQuestionTypeChange(select) {
            const qItem = select.closest('.q-item');
            const optionsWrap = qItem.querySelector('.q-options-wrap');
            const show = needsOptions(select.value);
            optionsWrap.style.display = show ? 'block' : 'none';
            if (show && optionsWrap.querySelector('.q-options-list').children.length === 0) {
                const addBtn = optionsWrap.querySelector('button');
                addOption(addBtn, '');
                addOption(addBtn, '');
            }
        }

        function addOption(btn, value = '') {
            const wrap = btn.closest('.q-options-wrap');
            const list = wrap.querySelector('.q-options-list');
            const div = document.createElement('div');
            div.className = 'q-option-item';
            div.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:6px;';
            div.innerHTML = `
                <input type="text" class="q-option-input" value="${escapeAttr(value)}" placeholder="Option text" style="margin:0; flex:1; font-size:12px; padding:6px 10px;">
                <button type="button" onclick="removeOption(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:4px 8px; border-radius:6px; cursor:pointer; font-size:11px;">✕</button>
            `;
            list.appendChild(div);
        }

        function removeOption(btn) {
            btn.closest('.q-option-item').remove();
        }

        function removeQuestion(btn) {
            btn.closest('.q-item').remove();
            reindexQuestions();
            updateQuestionsCount();
        }

        function validateAndSerializeQuestionnaireForm() {
            const container = document.getElementById('questionsContainer');
            const items = Array.from(container.getElementsByClassName('q-item'));
            const data = items.map(function(item) {
                const text = item.querySelector('.q-text').value.trim();
                const type = item.querySelector('.q-type').value;
                const required = item.querySelector('.q-required').checked;
                const optionsWrap = item.querySelector('.q-options-wrap');
                let options = [];
                if (optionsWrap.style.display !== 'none') {
                    options = Array.from(optionsWrap.querySelectorAll('.q-option-input'))
                        .map(function(o) { return o.value.trim(); })
                        .filter(function(o) { return o !== ''; });
                }
                return { text: text, type: type, required: required, options: options };
            }).filter(function(q) { return q.text !== ''; });

            if (data.length === 0) {
                alert('Please add at least one question with text before saving.');
                return false;
            }
            document.getElementById('questionsDataInput').value = JSON.stringify(data);
            return true;
        }

        function reindexQuestions() {
            const container = document.getElementById('questionsContainer');
            const nums = container.getElementsByClassName('q-num');
            for (let i = 0; i < nums.length; i++) {
                nums[i].innerText = 'Q' + (i + 1) + '.';
            }
        }

        function updateQuestionsCount() {
            const countEl = document.getElementById('questionsCount');
            if (countEl) countEl.innerText = document.getElementById('questionsContainer').children.length;
        }

        function openQuestionnaireModal() {
            resetForm();
            document.getElementById('questionnaireModal').style.display = 'flex';
        }

        function closeQuestionnaireModal() {
            document.getElementById('questionnaireModal').style.display = 'none';
        }

        function resetForm() {
            document.getElementById('questionnaire_id').value = '';
            document.getElementById('qTitle').value = 'Pre-Interview Screening Questions';
            document.getElementById('qJobId').value = '';
            document.getElementById('qStatus').value = 'Active';
            document.getElementById('qDescription').value = '';
            document.getElementById('formTitle').innerText = '📋 New Questionnaire';
            document.getElementById('questionsContainer').innerHTML = '';
            addQuestion('What is your expected salary and notice period?', 'short_text', true, []);
            addQuestion('Why are you interested in joining our team?', 'long_text', true, []);
            addQuestion('What relevant technical experience do you bring to this role?', 'long_text', true, []);
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

