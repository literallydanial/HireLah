<?php
require_once 'auth.php';
require_once 'ai.php';

// AI Resume Builder — candidate fills a guided form (with an optional
// "Ask AI" wording-help assist per role), AI turns it into a polished
// resume, and the candidate can download it as a PDF (via browser print).

if (!is_logged_in() || ($_SESSION['user_role'] ?? '') !== 'candidate') {
    $_SESSION['redirect_after_login'] = "resume_builder.php";
    $_SESSION['toast'] = "Please log in (or create a free candidate account) to use the AI Resume Builder.";
    header("Location: register.php");
    exit;
}

$api_key = get_api_key();
$error = null;
$generated = null;

$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$candidate_email = $stmt->fetchColumn() ?: '';

// ---- View a previously generated resume ----
if (isset($_GET['view'])) {
    $stmt = $pdo->prepare("SELECT * FROM resume_builds WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$_GET['view'], $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if ($row) {
        $generated = json_decode($row['generated_content'], true);
        $generated_meta = ['full_name' => $_SESSION['user_name'] ?? '', 'target_title' => $row['target_title']];
    }
}

// ---- Generate a new resume ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $target_title = trim($_POST['target_title'] ?? '');
    $full_name = trim($_POST['full_name'] ?? ($_SESSION['user_name'] ?? ''));
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $location = trim($_POST['location'] ?? '');

    $experience = [];
    foreach (($_POST['exp_company'] ?? []) as $i => $company) {
        $experience[] = [
            'company' => trim($company),
            'role' => trim($_POST['exp_role'][$i] ?? ''),
            'duration' => trim($_POST['exp_duration'][$i] ?? ''),
            'notes' => trim($_POST['exp_notes'][$i] ?? '')
        ];
    }

    $education = [];
    foreach (($_POST['edu_school'] ?? []) as $i => $school) {
        $education[] = [
            'school' => trim($school),
            'degree' => trim($_POST['edu_degree'][$i] ?? ''),
            'year' => trim($_POST['edu_year'][$i] ?? '')
        ];
    }

    $input = [
        'target_title' => $target_title,
        'experience' => $experience,
        'education' => $education,
        'skills' => trim($_POST['skills'] ?? ''),
        'extra_notes' => trim($_POST['extra_notes'] ?? '')
    ];

    if ($target_title === '' && empty($experience)) {
        $error = "Please fill in at least your target job title and one work experience entry.";
    } else {
        try {
            $generated = generate_resume_document($api_key, $input);
            $generated_meta = ['full_name' => $full_name, 'target_title' => $target_title, 'contact_email' => $contact_email, 'contact_phone' => $contact_phone, 'location' => $location];

            $stmt = $pdo->prepare("INSERT INTO resume_builds (user_id, target_title, raw_input, generated_content) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                $target_title,
                json_encode(array_merge($input, ['full_name' => $full_name, 'contact_email' => $contact_email, 'contact_phone' => $contact_phone, 'location' => $location])),
                json_encode(array_merge($generated, ['full_name' => $full_name, 'contact_email' => $contact_email, 'contact_phone' => $contact_phone, 'location' => $location]))
            ]);
        } catch (Exception $e) {
            error_log("Resume Builder Error: " . $e->getMessage());
            $error = "Something went wrong generating your resume. Please try again.";
        }
    }
}

// If we loaded generated content from DB (view mode), pull contact fields from it too
if ($generated && empty($generated_meta['contact_email']) && !empty($generated['contact_email'])) {
    $generated_meta['contact_email'] = $generated['contact_email'];
    $generated_meta['contact_phone'] = $generated['contact_phone'] ?? '';
    $generated_meta['location'] = $generated['location'] ?? '';
    $generated_meta['full_name'] = $generated['full_name'] ?? $generated_meta['full_name'];
}

// Past builds for this candidate
$past_builds = [];
try {
    $stmt = $pdo->prepare("SELECT id, target_title, created_at FROM resume_builds WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $past_builds = $stmt->fetchAll();
} catch (Exception $e) {
    // table may not exist yet if migration hasn't been run — fail quietly
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Resume Builder - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <style>
        /* Ambient glass backdrop orbs (page already sits on the app's --bg-gradient) */
        .rb-glow {
            position: fixed; border-radius: 50%; pointer-events: none; z-index: 0;
            filter: blur(90px); opacity: 0.55;
        }
        .rb-glow-1 { top: -12%; right: -8%; width: 480px; height: 480px; background: radial-gradient(circle, rgba(217,255,79,0.35) 0%, transparent 70%); }
        .rb-glow-2 { bottom: -15%; left: -10%; width: 520px; height: 520px; background: radial-gradient(circle, rgba(107,138,0,0.18) 0%, transparent 70%); }

        .exp-block, .edu-block {
            background: var(--card);
            border: var(--glass-border);
            border-radius: 18px;
            padding: 18px;
            margin-bottom: 16px;
            position: relative;
            backdrop-filter: var(--glass-blur-sm);
            -webkit-backdrop-filter: var(--glass-blur-sm);
            box-shadow: var(--shadow-sm);
            transition: box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .exp-block:hover, .edu-block:hover { box-shadow: var(--shadow-md); border-color: rgba(180, 214, 0, 0.35); }
        .remove-block {
            position: absolute; top: 12px; right: 12px; width: 24px; height: 24px;
            border-radius: 50%; border: 1px solid rgba(239,68,68,0.25); background: rgba(239,68,68,0.12);
            backdrop-filter: blur(6px); color: var(--red);
            font-size: 13px; cursor: pointer; line-height: 1; transition: transform 0.15s ease, background 0.15s ease;
        }
        .remove-block:hover { background: rgba(239,68,68,0.22); transform: scale(1.08); }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
        .field-row.three { grid-template-columns: 1fr 1fr 1fr; }
        @media (max-width: 640px) { .field-row, .field-row.three { grid-template-columns: 1fr; } }
        label.f { display: block; font-size: 11.5px; font-weight: 700; color: var(--mut); margin-bottom: 4px; letter-spacing: 0.3px; }

        .add-btn {
            background: var(--surf); border: 1.5px dashed rgba(107,138,0,0.4); color: var(--accD); font-weight: 700;
            font-size: 12.5px; padding: 11px; border-radius: 14px; width: 100%; cursor: pointer; margin-top: 4px;
            backdrop-filter: var(--glass-blur-sm); -webkit-backdrop-filter: var(--glass-blur-sm);
            transition: all 0.2s ease;
        }
        .add-btn:hover { background: rgba(107,138,0,0.1); border-color: var(--acc); transform: translateY(-1px); }

        .ai-assist-btn {
            background: linear-gradient(135deg, rgba(217,255,79,0.18), rgba(107,138,0,0.1));
            border: 1px solid rgba(107,138,0,0.3); color: var(--accD);
            font-size: 11.5px; font-weight: 700; padding: 7px 12px; border-radius: 999px; cursor: pointer; margin-top: 8px;
            backdrop-filter: blur(8px); transition: all 0.2s ease;
        }
        .ai-assist-btn:hover { box-shadow: 0 4px 14px rgba(180,214,0,0.25); transform: translateY(-1px); }
        .ai-suggestions { margin-top: 10px; display: none; }
        .ai-suggestions.show { display: block; }
        .ai-suggestion-item {
            font-size: 12px; padding: 9px 12px; border: var(--glass-border); border-radius: 12px;
            margin-bottom: 7px; cursor: pointer; background: var(--surf);
            backdrop-filter: var(--glass-blur-sm); -webkit-backdrop-filter: var(--glass-blur-sm);
            box-shadow: var(--shadow-sm); transition: all 0.18s ease;
        }
        .ai-suggestion-item:hover { border-color: var(--acc); transform: translateX(2px); box-shadow: var(--shadow-md); }

        /* Mode tabs — glass segmented control */
        .mode-tabs {
            display: flex; gap: 6px; margin-bottom: 20px; padding: 6px;
            background: var(--surf); border: var(--glass-border); border-radius: 18px;
            backdrop-filter: var(--glass-blur); -webkit-backdrop-filter: var(--glass-blur);
            box-shadow: var(--shadow-sm);
        }
        .mode-tab-btn {
            flex: 1; padding: 12px; border-radius: 13px; border: none; background: transparent;
            font-weight: 700; font-size: 13px; cursor: pointer; color: var(--mut); text-align: center;
            transition: all 0.2s ease;
        }
        .mode-tab-btn.active {
            background: var(--grad-purple); color: #fff;
            box-shadow: 0 4px 16px rgba(217, 255, 79, 0.35);
        }
        .mode-panel { display: none; animation: rbFadeIn 0.25s ease; }
        .mode-panel.active { display: block; }
        @keyframes rbFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        /* Chat mode — frosted glass */
        .chat-box {
            background: var(--surf); border: var(--glass-border); border-radius: 20px; padding: 20px;
            display: flex; flex-direction: column; gap: 12px; max-height: 480px; overflow-y: auto; margin-bottom: 14px;
            backdrop-filter: var(--glass-blur); -webkit-backdrop-filter: var(--glass-blur);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), var(--shadow-sm);
        }
        .chat-msg {
            max-width: 82%; padding: 11px 15px; border-radius: 16px; font-size: 13px; line-height: 1.5;
            backdrop-filter: blur(10px); box-shadow: var(--shadow-sm); animation: rbFadeIn 0.2s ease;
        }
        .chat-msg.ai {
            background: linear-gradient(135deg, rgba(217,255,79,0.16), rgba(107,138,0,0.08));
            border: 1px solid rgba(107,138,0,0.2);
            color: var(--txt); align-self: flex-start; border-bottom-left-radius: 5px;
        }
        .chat-msg.user {
            background: var(--card); border: var(--glass-border); color: var(--txt);
            align-self: flex-end; border-bottom-right-radius: 5px;
        }
        .chat-input-row { display: flex; gap: 8px; }
        .chat-input-row input { flex: 1 1 auto; min-width: 0; border-radius: 999px !important; padding-left: 18px !important; }
        .chat-input-row button { flex: 0 0 auto; width: auto !important; border-radius: 999px !important; white-space: nowrap; }
        .chat-chip-row { display: flex; gap: 8px; flex-wrap: wrap; }
        .chat-chip-row button {
            border: 1px solid rgba(107,138,0,0.35);
            background: linear-gradient(135deg, rgba(217,255,79,0.2), rgba(107,138,0,0.08));
            color: var(--accD); backdrop-filter: blur(8px);
            font-weight: 700; font-size: 12.5px; padding: 9px 18px; border-radius: 999px; cursor: pointer;
            transition: all 0.2s ease;
        }
        .chat-chip-row button:hover { box-shadow: 0 4px 14px rgba(180,214,0,0.28); transform: translateY(-1px); }

        /* Resume preview — floating paper inside the glass panel */
        .resume-doc {
            background: #fdfdfd; color: #1a1a1a; border-radius: 14px; padding: 36px;
            font-size: 13px; line-height: 1.55; border: 1px solid rgba(0,0,0,0.06);
            box-shadow: 0 12px 40px -8px rgba(0,0,0,0.25), 0 2px 8px rgba(0,0,0,0.08);
        }
        .resume-doc h2 { font-size: 20px; margin: 0 0 2px; }
        .resume-doc .role { color: #555; font-size: 13px; margin-bottom: 4px; }
        .resume-doc .contact-line { font-size: 11.5px; color: #777; margin-bottom: 14px; }
        .resume-doc h4 {
            font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #4a5c00;
            border-bottom: 1.5px solid #6B8A00; padding-bottom: 4px; margin: 18px 0 10px;
        }
        .resume-doc .job-h { font-weight: 700; font-size: 13px; margin-bottom: 1px; }
        .resume-doc .job-meta { color: #666; font-size: 11.5px; margin-bottom: 5px; }
        .resume-doc ul { margin: 0 0 12px; padding-left: 18px; }
        .resume-doc li { margin-bottom: 4px; }

        @media print {
            body * { visibility: hidden; }
            #printArea, #printArea * { visibility: visible; }
            #printArea { position: absolute; top: 0; left: 0; width: 100%; border: none; padding: 0; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <div class="rb-glow rb-glow-1"></div>
    <div class="rb-glow rb-glow-2"></div>

    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Logo"></div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <?php if(($_SESSION['user_role'] ?? '') === 'candidate'): ?>
                    <a href="jobs.php">📋 Job Board</a>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="resume_builder.php" class="active">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Profile Settings</a>
                <?php elseif(($_SESSION['user_role'] ?? '') === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications & Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="resume_builder.php" class="active">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php else: ?>
                    <a href="admin_dashboard.php">🛡️ Control Panel</a>
                    <a href="resume_builder.php" class="active">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php endif; ?>
            </nav>

            <div class="header-right-actions">
                <?php if(isset($_SESSION['user_name'])): ?>
                    <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                    <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main style="max-width:1180px; margin:36px auto; padding:0 24px; position:relative; z-index:2;">

        <div class="panel" style="margin-bottom:24px;">
            <div class="panel-title">🪄 AI Resume Builder</div>
            <div style="font-size:13px; color:var(--mut);">
                Fill in your details (rough notes are fine), and AI will turn them into a polished, professional resume you can download.
            </div>
        </div>

        <?php if(!get_api_key()): ?>
            <div style="background:rgba(255, 140, 66, 0.1); border:1px solid rgba(255, 140, 66, 0.3); border-radius:10px; padding:12px 16px; color:var(--org); font-size:12px; margin-bottom:20px;">
                <strong>Notice:</strong> AI screening is not yet configured on this server. You'll still get a basic auto-formatted resume, but not AI-polished wording.
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div style="background:rgba(239, 68, 68, 0.1); border:1px solid rgba(239, 68, 68, 0.35); border-radius:12px; padding:11px 18px; margin-bottom:20px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if($generated): ?>
            <!-- ===== GENERATED RESUME PREVIEW ===== -->
            <div class="panel" style="margin-bottom:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                    <div class="panel-title" style="margin-bottom:0;">✅ Your Resume is Ready</div>
                    <div style="display:flex; gap:8px;">
                        <button onclick="window.print()" class="btn-primary" style="padding:9px 16px; font-size:12.5px;">⬇ Download as PDF</button>
                        <a href="resume_builder.php" class="btn-secondary" style="padding:9px 16px; font-size:12.5px;">Build Another</a>
                    </div>
                </div>

                <div class="resume-doc" id="printArea">
                    <h2><?= htmlspecialchars($generated_meta['full_name'] ?? '') ?></h2>
                    <div class="role"><?= htmlspecialchars($generated_meta['target_title'] ?? '') ?></div>
                    <div class="contact-line">
                        <?= htmlspecialchars($generated_meta['contact_email'] ?? '') ?>
                        <?= !empty($generated_meta['contact_phone']) ? ' &middot; ' . htmlspecialchars($generated_meta['contact_phone']) : '' ?>
                        <?= !empty($generated_meta['location']) ? ' &middot; ' . htmlspecialchars($generated_meta['location']) : '' ?>
                    </div>

                    <?php if(!empty($generated['summary'])): ?>
                        <h4>Summary</h4>
                        <div><?= htmlspecialchars($generated['summary']) ?></div>
                    <?php endif; ?>

                    <?php if(!empty($generated['experience'])): ?>
                        <h4>Experience</h4>
                        <?php foreach($generated['experience'] as $exp): ?>
                            <div class="job-h"><?= htmlspecialchars(($exp['role'] ?? '') . (!empty($exp['company']) ? ' — ' . $exp['company'] : '')) ?></div>
                            <?php if(!empty($exp['duration'])): ?><div class="job-meta"><?= htmlspecialchars($exp['duration']) ?></div><?php endif; ?>
                            <?php if(!empty($exp['bullets'])): ?>
                                <ul>
                                    <?php foreach($exp['bullets'] as $b): ?><li><?= htmlspecialchars($b) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if(!empty($generated['education'])): ?>
                        <h4>Education</h4>
                        <?php foreach($generated['education'] as $edu): ?>
                            <div class="job-h"><?= htmlspecialchars($edu['degree'] ?? '') ?></div>
                            <div class="job-meta"><?= htmlspecialchars(($edu['school'] ?? '') . (!empty($edu['year']) ? ' · ' . $edu['year'] : '')) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if(!empty($generated['skills'])): ?>
                        <h4>Skills</h4>
                        <div><?= htmlspecialchars(implode(' · ', $generated['skills'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- ===== INPUT MODE: FORM OR CHAT ===== -->
            <form method="POST" id="builderForm">
                <input type="hidden" name="action" value="generate">

                <div class="panel" style="margin-bottom:20px;">
                    <div class="panel-title">👤 Basics</div>
                    <div class="field-row three">
                        <div><label class="f">Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>" required></div>
                        <div><label class="f">Email</label><input type="email" name="contact_email" value="<?= htmlspecialchars($candidate_email) ?>"></div>
                        <div><label class="f">Phone</label><input type="text" name="contact_phone" placeholder="+60 12-345 6789"></div>
                    </div>
                    <div class="field-row">
                        <div><label class="f">Target Job Title</label><input type="text" name="target_title" id="targetTitleInput" placeholder="e.g. Digital Marketing Executive" required></div>
                        <div><label class="f">Location</label><input type="text" name="location" placeholder="e.g. Kuala Lumpur"></div>
                    </div>
                </div>

                <div class="mode-tabs">
                    <button type="button" class="mode-tab-btn active" id="tabFormBtn" onclick="switchMode('form')">📝 Fill In Myself</button>
                    <button type="button" class="mode-tab-btn" id="tabChatBtn" onclick="switchMode('chat')">💬 Chat with AI</button>
                </div>

                <!-- ---- FORM MODE ---- -->
                <div class="mode-panel active" id="formPanel">
                    <div class="panel" style="margin-bottom:20px;">
                        <div class="panel-title">💼 Work Experience</div>
                        <div id="expContainer"></div>
                        <button type="button" class="add-btn" onclick="addExp()">+ Add Work Experience</button>
                    </div>

                    <div class="panel" style="margin-bottom:20px;">
                        <div class="panel-title">🎓 Education</div>
                        <div id="eduContainer"></div>
                        <button type="button" class="add-btn" onclick="addEdu()">+ Add Education</button>
                    </div>

                    <div class="panel" style="margin-bottom:20px;">
                        <div class="panel-title">🛠️ Skills</div>
                        <label class="f">Comma-separated</label>
                        <input type="text" name="skills" id="skillsInputForm" placeholder="e.g. Canva, Meta Ads, Excel, Mandarin">
                    </div>

                    <div class="panel" style="margin-bottom:24px;">
                        <div class="panel-title">📝 Anything Else?</div>
                        <textarea name="extra_notes" rows="3" placeholder="Certifications, achievements, languages — anything you want AI to consider (optional)."></textarea>
                    </div>

                    <button type="submit" class="btn-primary" style="width:100%;">✨ Generate My Resume</button>
                </div>

                <!-- ---- CHAT MODE ---- -->
                <div class="mode-panel" id="chatPanel">
                    <div class="panel">
                        <div class="panel-title">💬 Let's Build It Together</div>
                        <div class="chat-box" id="chatBox"></div>
                        <div class="chat-chip-row" id="chatChipRow" style="display:none; margin-bottom:10px;"></div>
                        <div class="chat-input-row" id="chatInputRow">
                            <input type="text" id="chatTextInput" placeholder="Type your answer…">
                            <button type="button" class="btn-primary" style="padding:0 20px;" onclick="chatSend()">Send</button>
                        </div>
                    </div>
                    <!-- chat mode reuses the same hidden fields as form mode, filled in by JS -->
                    <div id="chatHiddenFields"></div>
                </div>
            </form>
        <?php endif; ?>

        <?php if(!empty($past_builds)): ?>
            <div class="panel" style="margin:24px 0 40px;">
                <div class="panel-title">🕓 Your Previous Resumes</div>
                <?php foreach($past_builds as $pb): ?>
                    <a href="resume_builder.php?view=<?= (int)$pb['id'] ?>" style="display:flex; justify-content:space-between; align-items:center; padding:9px 0; border-bottom:1px solid var(--bdr); font-size:12.5px; text-decoration:none; color:inherit;">
                        <div style="color:var(--txt); font-weight:600;"><?= htmlspecialchars($pb['target_title'] ?: 'Resume') ?></div>
                        <span style="color:var(--mut);"><?= date('d M Y', strtotime($pb['created_at'])) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<template id="expTemplate">
    <div class="exp-block">
        <button type="button" class="remove-block" onclick="this.closest('.exp-block').remove()">&times;</button>
        <div class="field-row three">
            <div><label class="f">Company</label><input type="text" name="exp_company[]" placeholder="e.g. BrightWave Media"></div>
            <div><label class="f">Role</label><input type="text" name="exp_role[]" placeholder="e.g. Marketing Executive"></div>
            <div><label class="f">Duration</label><input type="text" name="exp_duration[]" placeholder="e.g. 2023 – Present"></div>
        </div>
        <label class="f">What did you do there? (rough notes are fine)</label>
        <textarea name="exp_notes[]" class="exp-notes" rows="3" placeholder="e.g. ran social media, grew followers, helped with 2 product launches"></textarea>
        <button type="button" class="ai-assist-btn" onclick="askAi(this)">🤖 Ask AI to help word this</button>
        <div class="ai-suggestions"></div>
    </div>
</template>

<template id="eduTemplate">
    <div class="edu-block">
        <button type="button" class="remove-block" onclick="this.closest('.edu-block').remove()">&times;</button>
        <div class="field-row three">
            <div><label class="f">Degree / Qualification</label><input type="text" name="edu_degree[]" placeholder="e.g. Diploma in Mass Communication"></div>
            <div><label class="f">School</label><input type="text" name="edu_school[]" placeholder="e.g. UiTM"></div>
            <div><label class="f">Year</label><input type="text" name="edu_year[]" placeholder="e.g. 2022"></div>
        </div>
    </div>
</template>

<script>
function addExp() {
    const tpl = document.getElementById('expTemplate').content.cloneNode(true);
    document.getElementById('expContainer').appendChild(tpl);
}
function addEdu() {
    const tpl = document.getElementById('eduTemplate').content.cloneNode(true);
    document.getElementById('eduContainer').appendChild(tpl);
}
// Start with one of each
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('expContainer')) { addExp(); addEdu(); }
});

// ================== MODE SWITCHER ==================
function switchMode(mode) {
    document.getElementById('tabFormBtn').classList.toggle('active', mode === 'form');
    document.getElementById('tabChatBtn').classList.toggle('active', mode === 'chat');
    document.getElementById('formPanel').classList.toggle('active', mode === 'form');
    document.getElementById('chatPanel').classList.toggle('active', mode === 'chat');

    // Only the active mode's fields should actually submit with the form.
    document.querySelectorAll('#formPanel input, #formPanel textarea').forEach(el => el.disabled = (mode === 'chat'));
    document.querySelectorAll('#chatHiddenFields input').forEach(el => el.disabled = (mode === 'form'));

    if (mode === 'chat' && !chatStarted) {
        chatStarted = true;
        chatStart();
    }
}

// ================== CHAT MODE ==================
let chatStarted = false;
let chatStep = null;
let chatExperiences = [];
let chatEducations = [];
let chatCurrentExp = {};
let chatCurrentEdu = {};

function chatAddMsg(text, who) {
    const box = document.getElementById('chatBox');
    const div = document.createElement('div');
    div.className = 'chat-msg ' + who;
    div.innerText = text;
    box.appendChild(div);
    box.scrollTop = box.scrollHeight;
}

function chatShowChips(options) {
    const row = document.getElementById('chatChipRow');
    row.innerHTML = '';
    row.style.display = 'flex';
    document.getElementById('chatInputRow').style.display = 'none';
    options.forEach(opt => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.innerText = opt;
        btn.onclick = function() { chatAddMsg(opt, 'user'); chatShowInput(); chatAdvance(opt); };
        row.appendChild(btn);
    });
}

function chatShowInput() {
    document.getElementById('chatChipRow').style.display = 'none';
    document.getElementById('chatInputRow').style.display = 'flex';
}

function chatSend() {
    const input = document.getElementById('chatTextInput');
    const val = input.value.trim();
    if (!val) return;
    chatAddMsg(val, 'user');
    input.value = '';
    chatAdvance(val);
}
document.addEventListener('DOMContentLoaded', function() {
    const ci = document.getElementById('chatTextInput');
    if (ci) ci.addEventListener('keydown', function(e) { if (e.key === 'Enter') chatSend(); });
});

function chatStart() {
    chatAddMsg("Let's build your resume together! First, tell me about your most recent job — what company did you work at?", 'ai');
    chatStep = 'exp_company';
}

function chatAdvance(answer) {
    switch (chatStep) {
        case 'exp_company':
            chatCurrentExp = { company: answer };
            chatAddMsg('And what was your role or job title there?', 'ai');
            chatStep = 'exp_role';
            break;
        case 'exp_role':
            chatCurrentExp.role = answer;
            chatAddMsg('How long were you there? (e.g. "2023 – Present")', 'ai');
            chatStep = 'exp_duration';
            break;
        case 'exp_duration':
            chatCurrentExp.duration = answer;
            chatAddMsg("What did you actually do day-to-day there? Rough notes are fine — I'll clean it up.", 'ai');
            chatStep = 'exp_notes';
            break;
        case 'exp_notes':
            chatCurrentExp.notes = answer;
            chatExperiences.push(chatCurrentExp);
            chatCurrentExp = {};
            chatAddMsg('Got it! Want to add another job?', 'ai');
            chatStep = 'exp_more';
            chatShowChips(['Yes, add another', "No, that's it"]);
            break;
        case 'exp_more':
            if (answer.toLowerCase().startsWith('yes')) {
                chatAddMsg('Great — what company was that at?', 'ai');
                chatStep = 'exp_company';
            } else {
                chatAddMsg("Now let's cover your education. What's your degree or qualification?", 'ai');
                chatStep = 'edu_degree';
            }
            break;
        case 'edu_degree':
            chatCurrentEdu = { degree: answer };
            chatAddMsg('Which school or university?', 'ai');
            chatStep = 'edu_school';
            break;
        case 'edu_school':
            chatCurrentEdu.school = answer;
            chatAddMsg('What year did you graduate (or expect to)?', 'ai');
            chatStep = 'edu_year';
            break;
        case 'edu_year':
            chatCurrentEdu.year = answer;
            chatEducations.push(chatCurrentEdu);
            chatCurrentEdu = {};
            chatAddMsg('Add another education entry?', 'ai');
            chatStep = 'edu_more';
            chatShowChips(['Yes, add another', "No, that's it"]);
            break;
        case 'edu_more':
            if (answer.toLowerCase().startsWith('yes')) {
                chatAddMsg('Sure — what degree or qualification?', 'ai');
                chatStep = 'edu_degree';
            } else {
                chatAddMsg('Last thing — list your key skills, separated by commas (e.g. "Canva, Excel, Mandarin").', 'ai');
                chatStep = 'skills';
            }
            break;
        case 'skills':
            chatFillHiddenFields(answer);
            chatAddMsg("Perfect — I have everything I need. Generating your resume now… ✨", 'ai');
            chatStep = 'done';
            setTimeout(function() { document.getElementById('builderForm').submit(); }, 700);
            break;
    }
}

function chatFillHiddenFields(skillsAnswer) {
    const container = document.getElementById('chatHiddenFields');
    container.innerHTML = '';

    function addHidden(name, value) {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = name;
        inp.value = value;
        container.appendChild(inp);
    }

    chatExperiences.forEach(exp => {
        addHidden('exp_company[]', exp.company || '');
        addHidden('exp_role[]', exp.role || '');
        addHidden('exp_duration[]', exp.duration || '');
        addHidden('exp_notes[]', exp.notes || '');
    });
    chatEducations.forEach(edu => {
        addHidden('edu_school[]', edu.school || '');
        addHidden('edu_degree[]', edu.degree || '');
        addHidden('edu_year[]', edu.year || '');
    });
    addHidden('skills', skillsAnswer);
}

function askAi(btn) {
    const block = btn.closest('.exp-block');
    const notesEl = block.querySelector('.exp-notes');
    const company = block.querySelector('input[name="exp_company[]"]').value;
    const role = block.querySelector('input[name="exp_role[]"]').value;
    const notes = notesEl.value;
    const suggBox = block.querySelector('.ai-suggestions');

    if (!notes.trim()) {
        alert('Type a few rough notes first, then ask AI to help word them.');
        return;
    }

    btn.disabled = true;
    btn.innerText = 'Thinking…';

    const fd = new FormData();
    fd.append('company', company);
    fd.append('role', role);
    fd.append('notes', notes);

    fetch('resume_ai_assist.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerText = '🤖 Ask AI to help word this';
            suggBox.innerHTML = '';
            if (data.error) {
                suggBox.innerHTML = '<div style="font-size:11.5px; color:var(--red);">' + data.error + '</div>';
                suggBox.classList.add('show');
                return;
            }
            (data.bullets || []).forEach(b => {
                const div = document.createElement('div');
                div.className = 'ai-suggestion-item';
                div.innerText = '+ ' + b;
                div.onclick = function() {
                    notesEl.value = (notesEl.value.trim() ? notesEl.value.trim() + '\n' : '') + b;
                };
                suggBox.appendChild(div);
            });
            suggBox.classList.add('show');
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerText = '🤖 Ask AI to help word this';
            suggBox.innerHTML = '<div style="font-size:11.5px; color:var(--red);">Something went wrong. Please try again.</div>';
            suggBox.classList.add('show');
        });
}
</script>
</main>
<script src="theme.js"></script></body>
</html>
