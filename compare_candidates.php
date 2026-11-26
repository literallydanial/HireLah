<?php
require_once 'auth.php';
require_role('employer');
require_once 'notifications_helper.php';
require_once 'company_helpers.php';

$employer_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';
$active_company_id = get_active_company_id($pdo);

// Fetch all jobs for this employer's company team to populate job selector
if ($user_role === 'admin') {
    $jobs_stmt = $pdo->query("SELECT id, job_title, department FROM jobs ORDER BY created_at DESC");
} elseif ($active_company_id) {
    $jobs_stmt = $pdo->prepare("SELECT id, job_title, department FROM jobs WHERE company_id = ? OR (company_id IS NULL AND employer_id = ?) ORDER BY created_at DESC");
    $jobs_stmt->execute([$active_company_id, $employer_id]);
} else {
    $jobs_stmt = $pdo->prepare("SELECT id, job_title, department FROM jobs WHERE employer_id = ? OR employer_id IS NULL ORDER BY created_at DESC");
    $jobs_stmt->execute([$employer_id]);
}
$jobs = $jobs_stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_job_id = (int)($_GET['job_id'] ?? ($jobs[0]['id'] ?? 0));
$sort_by = $_GET['sort'] ?? 'overall_score';
$sort_order = $_GET['order'] ?? 'desc';

$candidates = [];
$job_title = '';

if ($selected_job_id > 0) {
    // Get job details
    $j_stmt = $pdo->prepare("SELECT job_title, department FROM jobs WHERE id = ?");
    $j_stmt->execute([$selected_job_id]);
    $job_info = $j_stmt->fetch(PDO::FETCH_ASSOC);
    $job_title = $job_info['job_title'] ?? 'Job';

    // Allowed sort columns for SQL safety
    $allowed_sorts = ['overall_score', 'skills_match', 'exp_match', 'edu_match', 'name', 'created_at'];
    if (!in_array($sort_by, $allowed_sorts)) {
        $sort_by = 'overall_score';
    }
    $sort_dir = strtolower($sort_order) === 'asc' ? 'ASC' : 'DESC';

    // Fetch candidates applied for this job
    $c_stmt = $pdo->prepare("SELECT c.*, u.profile_picture 
                             FROM candidates c 
                             LEFT JOIN users u ON (c.user_id = u.id OR (c.user_id IS NULL AND c.email = u.email AND c.email IS NOT NULL AND c.email != '')) 
                             WHERE c.job_id = ? 
                             ORDER BY c.{$sort_by} {$sort_dir}");
    $c_stmt->execute([$selected_job_id]);
    $candidates = $c_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// "Pick exactly N" custom export mode: while active, candidates start
// unchecked (instead of the normal "show all by default") so HR has to
// deliberately pick exactly N before the Export PDF button unlocks.
$target_count = isset($_GET['target_count']) ? max(0, (int)$_GET['target_count']) : 0;
if ($target_count > count($candidates)) $target_count = count($candidates);

// Check selected candidates filter
$selected_ids = $_GET['selected_ids'] ?? [];
if (!is_array($selected_ids)) {
    $selected_ids = explode(',', $selected_ids);
}
$selected_ids = array_filter($selected_ids);

// Filter candidates if selection exists
$filtered_candidates = $candidates;
if (!empty($selected_ids)) {
    $filtered_candidates = array_filter($candidates, function($cand) use ($selected_ids) {
        return in_array($cand['id'], $selected_ids);
    });
}

// Export readiness: always ready in normal mode (exports whatever's
// currently filtered/shown); in "pick exactly N" mode, only once the
// explicit checked count matches N exactly.
$current_selected_count = count($selected_ids);
$export_ready = ($target_count === 0) || ($current_selected_count === $target_count);

// Top 5 / Top 10 quick-select: always ranked by Overall AI Match Score,
// independent of whatever sort is currently active on screen.
$by_score = $candidates;
usort($by_score, function($a, $b) {
    return ((int)($b['overall_score'] ?? 0)) <=> ((int)($a['overall_score'] ?? 0));
});
$top5_ids = array_slice(array_column($by_score, 'id'), 0, 5);
$top10_ids = array_slice(array_column($by_score, 'id'), 0, 10);

// Whether each candidate starts checked/visible -- used both for the filter
// checkboxes and the table columns below, so the two always agree on load.
// After load, toggling a checkbox hides/shows that candidate's cells
// client-side (see toggleCandidateFilter()) instead of reloading the page.
$checked_map = [];
foreach ($candidates as $cand_opt) {
    $checked_map[$cand_opt['id']] = $target_count > 0
        ? in_array($cand_opt['id'], $selected_ids)
        : (empty($selected_ids) || in_array($cand_opt['id'], $selected_ids));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Comparison Matrix - Keria</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
    <style>
        .compare-table-wrapper {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid var(--bdr);
            background: var(--surf);
            margin-bottom: 30px;
        }
        .compare-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        .compare-table th, .compare-table td {
            padding: 16px;
            border-bottom: 1px solid var(--bdr);
            border-right: 1px solid var(--bdr);
            vertical-align: top;
        }
        .compare-table th:last-child, .compare-table td:last-child {
            border-right: none;
        }
        .compare-table tr:last-child td {
            border-bottom: none;
        }
        .feature-label {
            font-size: 12px;
            font-weight: 800;
            color: var(--mut);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            width: 180px;
            min-width: 180px;
            background: var(--card);
            position: sticky;
            left: 0;
            z-index: 5;
        }
        .cand-col-header {
            text-align: center;
            min-width: 220px;
            background: var(--surf);
        }
        .score-circle-sm {
            width: 58px; height: 58px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 800; font-family: monospace;
            margin: 0 auto 8px auto;
        }
        .cand-hidden { display: none; }
        .export-disabled { opacity: .45; cursor: not-allowed; pointer-events: none; }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png" alt="Keria Watermark Logo"></div>

    <header>
        <div class="header-inner">
            <div class="logo-box">⚖️</div>
            <div>
                <div style="font-size:15px; font-weight:800; line-height:1">Keria ATS</div>
                <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">CANDIDATE COMPARISON MATRIX</div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php?job_id=<?= $selected_job_id ?>">&larr; Back to Applicants</a>
                <a href="job_dashboard.php">💼 My Jobs</a>
                <a href="upload.php?job_id=<?= $selected_job_id ?>">📤 Upload Resume</a>
            </nav>
            <?php include 'company_switcher.php'; ?>

            <div class="header-right-actions">
                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main style="max-width:1200px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size:24px; font-weight:800; color:var(--txt); margin:0;">⚖️ Comparing Candidates: <?= htmlspecialchars($job_title ?: 'Position') ?></h1>
                <p style="font-size:13px; color:var(--mut); margin:4px 0 0 0;">Comparing applicants who applied for <strong><?= htmlspecialchars($job_title ?: 'this job') ?></strong>.</p>
            </div>

            <!-- Job Selector & Sort Controls -->
            <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
                <label style="font-size:12px; font-weight:700; color:var(--mut);">Position:</label>
                <select name="job_id" onchange="this.form.submit()" style="padding:8px 14px; font-size:13px; margin:0;">
                    <?php if(empty($jobs)): ?>
                        <option value="0">No Job Positions Found</option>
                    <?php else: ?>
                        <?php foreach($jobs as $j): ?>
                            <option value="<?= $j['id'] ?>" <?= $selected_job_id == $j['id'] ? 'selected' : '' ?>>
                                💼 <?= htmlspecialchars($j['job_title']) ?> <?= $j['department'] ? '('.htmlspecialchars($j['department']).')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>

                <label style="font-size:12px; font-weight:700; color:var(--mut); margin-left:6px;">Sort By:</label>
                <select name="sort" onchange="this.form.submit()" style="padding:8px 12px; font-size:13px; margin:0;">
                    <option value="overall_score" <?= $sort_by === 'overall_score' ? 'selected' : '' ?>>Overall AI Score</option>
                    <option value="skills_match" <?= $sort_by === 'skills_match' ? 'selected' : '' ?>>Skills Alignment</option>
                    <option value="exp_match" <?= $sort_by === 'exp_match' ? 'selected' : '' ?>>Experience Match</option>
                    <option value="edu_match" <?= $sort_by === 'edu_match' ? 'selected' : '' ?>>Education Match</option>
                    <option value="name" <?= $sort_by === 'name' ? 'selected' : '' ?>>Candidate Name</option>
                </select>
                <select name="order" onchange="this.form.submit()" style="padding:8px 10px; font-size:13px; margin:0;">
                    <option value="desc" <?= strtolower($sort_order) === 'desc' ? 'selected' : '' ?>>High &rarr; Low</option>
                    <option value="asc" <?= strtolower($sort_order) === 'asc' ? 'selected' : '' ?>>Low &rarr; High</option>
                </select>
            </form>
        </div>

        <?php if(empty($candidates)): ?>
            <div class="panel" style="text-align:center; padding:60px 20px; border-style:dashed;">
                <div style="font-size:36px; margin-bottom:12px;">📂</div>
                <div style="font-size:18px; font-weight:700; color:var(--txt); margin-bottom:6px;">No candidates applied for this position yet</div>
                <div style="font-size:13px; color:var(--mut);">Select another position from the dropdown above or check back once candidates submit applications.</div>
            </div>
        <?php else: ?>
            <!-- Export Comparison as PDF -->
            <div class="panel" style="margin-bottom:20px; padding:14px 18px;">
                <div style="font-size:12px; font-weight:800; color:var(--txt); margin-bottom:10px;">📄 Export Comparison as PDF</div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                    <a href="compare_candidates.php?job_id=<?= $selected_job_id ?>&sort=overall_score&order=desc&selected_ids=<?= urlencode(implode(',', $top5_ids)) ?>" class="btn-secondary" style="padding:8px 14px; font-size:12px; text-decoration:none;">🏆 Top 5</a>
                    <a href="compare_candidates.php?job_id=<?= $selected_job_id ?>&sort=overall_score&order=desc&selected_ids=<?= urlencode(implode(',', $top10_ids)) ?>" class="btn-secondary" style="padding:8px 14px; font-size:12px; text-decoration:none;">🏆 Top 10</a>

                    <?php if ($target_count === 0): ?>
                        <form method="GET" style="display:flex; gap:6px; align-items:center; margin:0;">
                            <input type="hidden" name="job_id" value="<?= $selected_job_id ?>">
                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>">
                            <input type="hidden" name="order" value="<?= htmlspecialchars($sort_order) ?>">
                            <label style="font-size:12px; color:var(--mut); font-weight:600;">Pick exactly:</label>
                            <input type="number" name="target_count" min="1" max="<?= count($candidates) ?>" placeholder="#" style="width:56px; padding:6px 8px; font-size:12px;" required>
                            <button type="submit" class="btn-secondary" style="padding:6px 12px; font-size:12px;">Start Selecting</button>
                        </form>
                    <?php else: ?>
                        <span id="targetStatusText" style="font-size:12px; font-weight:800; color:<?= $export_ready ? 'var(--grn)' : 'var(--org)' ?>;">
                            Selected <?= $current_selected_count ?> of <?= $target_count ?>
                        </span>
                        <a href="compare_candidates.php?job_id=<?= $selected_job_id ?>&sort=<?= htmlspecialchars($sort_by) ?>&order=<?= htmlspecialchars($sort_order) ?>" style="font-size:11px; color:var(--mut); text-decoration:underline;">Cancel</a>
                    <?php endif; ?>

                    <button type="button" id="exportPdfBtn" onclick="goExportPdf()" class="btn-primary <?= ($export_ready && !empty($filtered_candidates)) ? '' : 'export-disabled' ?>" style="padding:8px 16px; font-size:12px; margin-left:auto; border:none;">📄 Export PDF</button>
                </div>
            </div>

            <!-- Interactive Candidate Filter Toggles -->
            <div class="panel" style="margin-bottom:20px; padding:14px 18px;">
                <div style="font-size:12px; font-weight:800; color:var(--txt); margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
                    <span id="filterShownLabel">Filter Candidates to Compare (<?= count($filtered_candidates) ?> of <?= count($candidates) ?> shown)</span>
                    <?php if(!empty($selected_ids)): ?>
                        <a href="javascript:void(0)" onclick="resetCandidateFilter()" style="font-size:11px; color:var(--acc); font-weight:700; text-decoration:none;">Reset Filter (Show All)</a>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <?php foreach($candidates as $cand_opt):
                        $is_checked = $checked_map[$cand_opt['id']];
                    ?>
                        <label data-cid="<?= htmlspecialchars($cand_opt['id']) ?>" style="display:inline-flex; align-items:center; gap:6px; background:var(--surf); border:1px solid <?= $is_checked ? 'var(--acc)' : 'var(--bdr)' ?>; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; color:<?= $is_checked ? 'var(--txt)' : 'var(--mut)' ?>;">
                            <input type="checkbox" class="cand-filter-checkbox" data-cid="<?= htmlspecialchars($cand_opt['id']) ?>" onchange="toggleCandidateFilter('<?= htmlspecialchars($cand_opt['id'], ENT_QUOTES) ?>')" <?= $is_checked ? 'checked' : '' ?> style="margin:0;">
                            <?= htmlspecialchars($cand_opt['name']) ?> (<?= (int)$cand_opt['overall_score'] ?>%)
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Comparison Table Matrix -->
            <div class="compare-table-wrapper">
                <table class="compare-table">
                    <thead>
                        <tr>
                            <th class="feature-label">Candidate</th>
                            <?php foreach($candidates as $c): ?>
                                <th class="cand-col-header<?= $checked_map[$c['id']] ? '' : ' cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>">
                                    <div style="display:flex; flex-direction:column; align-items:center;">
                                        <?php if(!empty($c['profile_picture']) && file_exists($c['profile_picture'])): ?>
                                            <img src="<?= htmlspecialchars($c['profile_picture']) ?>" alt="<?= htmlspecialchars($c['name']) ?>" style="width:52px; height:52px; border-radius:50%; object-fit:cover; border:2px solid var(--acc); margin-bottom:8px;">
                                        <?php else: ?>
                                            <div style="width:52px; height:52px; border-radius:50%; background:linear-gradient(135deg, #6B8A00, #00E87A); display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:800; color:#fff; margin-bottom:8px;">
                                                <?= strtoupper(substr($c['name'] ?: 'C', 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div style="font-size:15px; font-weight:800; color:var(--txt); display:flex; align-items:center; gap:6px;">
                                            <?= htmlspecialchars($c['name']) ?>
                                            <?php if(empty($c['user_id'])): ?>
                                                <span title="Added manually by HR — applied outside Keria" style="flex-shrink:0; font-size:9px; font-weight:800; color:var(--pur); background:rgba(155,89,255,0.12); border:1px solid rgba(155,89,255,0.3); border-radius:4px; padding:1px 5px;">🧑‍💼 HR Added</span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="font-size:11px; color:var(--mut); margin-top:2px;"><?= htmlspecialchars($c['email'] ?? '') ?></div>
                                        
                                        <div style="margin-top:10px;">
                                            <a href="candidate.php?id=<?= $c['id'] ?>" class="btn-primary" style="padding:6px 14px; font-size:11px; text-decoration:none; width:auto;">📄 Full Profile</a>
                                        </div>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Overall Match Score Row -->
                        <tr>
                            <td class="feature-label">🤖 Overall AI Match Score</td>
                            <?php foreach($candidates as $c):
                                $score = (int)($c['overall_score'] ?? 0);
                                $color = $score >= 80 ? 'var(--grn)' : ($score >= 60 ? 'var(--acc)' : ($score >= 40 ? 'var(--org)' : 'var(--red)'));
                            ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>" style="text-align:center;">
                                    <div class="score-circle-sm" style="border:3px solid <?= $color ?>; color:<?= $color ?>; box-shadow:0 0 8px <?= $color ?>40;">
                                        <?= $score ?>%
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- AI Recommendation & Status Row -->
                        <tr>
                            <td class="feature-label">💡 Recommendation & Status</td>
                            <?php foreach($candidates as $c):
                                $rec = $c['recommendation'] ?? 'Pending';
                                $rec_color = ($rec == 'Strong Hire' || $rec == 'Hire') ? 'var(--grn)' : ($rec == 'Maybe' ? 'var(--org)' : 'var(--red)');
                            ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>" style="text-align:center;">
                                    <div style="display:flex; flex-direction:column; gap:6px; align-items:center;">
                                        <span class="chip" style="font-size:11px; font-weight:800; background:rgba(255,255,255,0.04); color:<?= $rec_color ?>; border-color:<?= $rec_color ?>;">
                                            💡 <?= htmlspecialchars($rec) ?>
                                        </span>
                                        <span class="chip chip-<?= strtolower($c['status']) ?>" style="font-size:10px;">
                                            Status: <?= htmlspecialchars($c['status']) ?>
                                        </span>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Skills Alignment Match Row -->
                        <tr>
                            <td class="feature-label">🔧 Skills Alignment</td>
                            <?php foreach($candidates as $c):
                                $v = (int)($c['skills_match'] ?? 0);
                                $vc = $v >= 70 ? 'var(--grn)' : ($v >= 45 ? 'var(--acc)' : 'var(--org)');
                            ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div class="bar-bg">
                                            <div class="bar-fill" style="width:<?= $v ?>%; background:<?= $vc ?>;"></div>
                                        </div>
                                        <span style="color:<?= $vc ?>; font-size:12px; font-weight:700; font-family:monospace;"><?= $v ?>%</span>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Experience Match Row -->
                        <tr>
                            <td class="feature-label">💼 Experience Match</td>
                            <?php foreach($candidates as $c):
                                $v = (int)($c['exp_match'] ?? 0);
                                $vc = $v >= 70 ? 'var(--grn)' : ($v >= 45 ? 'var(--acc)' : 'var(--org)');
                            ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div class="bar-bg">
                                            <div class="bar-fill" style="width:<?= $v ?>%; background:<?= $vc ?>;"></div>
                                        </div>
                                        <span style="color:<?= $vc ?>; font-size:12px; font-weight:700; font-family:monospace;"><?= $v ?>%</span>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Education Match Row -->
                        <tr>
                            <td class="feature-label">🎓 Education Match</td>
                            <?php foreach($candidates as $c):
                                $v = (int)($c['edu_match'] ?? 0);
                                $vc = $v >= 70 ? 'var(--grn)' : ($v >= 45 ? 'var(--acc)' : 'var(--org)');
                            ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>">
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div class="bar-bg">
                                            <div class="bar-fill" style="width:<?= $v ?>%; background:<?= $vc ?>;"></div>
                                        </div>
                                        <span style="color:<?= $vc ?>; font-size:12px; font-weight:700; font-family:monospace;"><?= $v ?>%</span>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Key Strengths / Pros Row -->
                        <tr>
                            <td class="feature-label">✔ Key Strengths (Pros)</td>
                            <?php foreach($candidates as $c):
                                $strengths = !empty($c['strengths']) ? (json_decode($c['strengths'], true) ?: []) : [];
                            ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>">
                                    <?php if(empty($strengths)): ?>
                                        <span style="font-size:12px; color:var(--mut);">-</span>
                                    <?php else: ?>
                                        <ul style="margin:0; padding-left:16px; font-size:12px; color:var(--txt); line-height:1.45;">
                                            <?php foreach(array_slice($strengths, 0, 3) as $s): ?>
                                                <li style="margin-bottom:4px;"><?= htmlspecialchars(preg_replace('/\[Resume Evidence:.*\]/s', '', $s)) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Key Gaps / Cons Row -->
                        <tr>
                            <td class="feature-label">⚠️ Key Gaps (Cons)</td>
                            <?php foreach($candidates as $c):
                                $gaps = !empty($c['gaps']) ? (json_decode($c['gaps'], true) ?: []) : [];
                            ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>">
                                    <?php if(empty($gaps)): ?>
                                        <span style="font-size:12px; color:var(--mut);">-</span>
                                    <?php else: ?>
                                        <ul style="margin:0; padding-left:16px; font-size:12px; color:var(--txt); line-height:1.45;">
                                            <?php foreach(array_slice($gaps, 0, 3) as $g): ?>
                                                <li style="margin-bottom:4px;"><?= htmlspecialchars(preg_replace('/\[Resume Evidence:.*\]/s', '', $g)) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- Action Row -->
                        <tr>
                            <td class="feature-label">⚡ Quick Actions</td>
                            <?php foreach($candidates as $c): ?>
                                <td class="<?= $checked_map[$c['id']] ? '' : 'cand-hidden' ?>" data-cid="<?= htmlspecialchars($c['id']) ?>" style="text-align:center;">
                                    <div style="display:flex; gap:6px; justify-content:center; flex-wrap:wrap;">
                                        <a href="candidate.php?id=<?= $c['id'] ?>#discussion" class="btn-secondary" style="padding:6px 10px; font-size:11px; text-decoration:none;">💬 Chat</a>
                                        <a href="candidate.php?id=<?= $c['id'] ?>" class="btn-primary" style="padding:6px 12px; font-size:11px; text-decoration:none;">📅 Schedule</a>
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <script>
    // Checking/unchecking a candidate used to set `selected_ids` in the URL
    // and reload the whole page just to show/hide a few table columns --
    // that full navigation is what caused the jarring "jump" (white flash +
    // scroll position resetting to the top) on every single click. Now it
    // just hides/shows that candidate's cells (marked with data-cid) in
    // place; the URL is still kept in sync via replaceState (no reload) so
    // refreshing or copying the link still works the same as before.
    var TARGET_COUNT = <?= (int)$target_count ?>;
    var JOB_ID = <?= (int)$selected_job_id ?>;
    var SORT_BY = <?= json_encode($sort_by) ?>;
    var SORT_ORDER = <?= json_encode($sort_order) ?>;

    function cssEscape(id) {
        return (window.CSS && CSS.escape) ? CSS.escape(id) : id;
    }

    function getCheckedIds() {
        var ids = [];
        document.querySelectorAll('.cand-filter-checkbox').forEach(function(cb) {
            if (cb.checked) ids.push(cb.getAttribute('data-cid'));
        });
        return ids;
    }

    function updateUrlSelectedIds(selectedIds, totalCount) {
        var urlParams = new URLSearchParams(window.location.search);
        if (selectedIds.length === totalCount) {
            urlParams.delete('selected_ids');
        } else {
            urlParams.set('selected_ids', selectedIds.join(','));
        }
        history.replaceState(null, '', window.location.pathname + '?' + urlParams.toString());
    }

    function refreshExportAndCounters(selectedCount, totalCount) {
        var shownLabel = document.getElementById('filterShownLabel');
        if (shownLabel) shownLabel.textContent = 'Filter Candidates to Compare (' + selectedCount + ' of ' + totalCount + ' shown)';

        var statusEl = document.getElementById('targetStatusText');
        if (statusEl) {
            statusEl.textContent = 'Selected ' + selectedCount + ' of ' + TARGET_COUNT;
            statusEl.style.color = (selectedCount === TARGET_COUNT) ? 'var(--grn)' : 'var(--org)';
        }

        var exportReady = (TARGET_COUNT === 0) ? (selectedCount > 0) : (selectedCount === TARGET_COUNT);
        var exportBtn = document.getElementById('exportPdfBtn');
        if (exportBtn) exportBtn.classList.toggle('export-disabled', !exportReady);
    }

    function toggleCandidateFilter(candId) {
        var checkboxes = document.querySelectorAll('.cand-filter-checkbox');
        var changedCb = null;
        var selected = [];
        checkboxes.forEach(function(cb) {
            if (cb.getAttribute('data-cid') === candId) changedCb = cb;
            if (cb.checked) selected.push(cb.getAttribute('data-cid'));
        });

        if (TARGET_COUNT > 0 && selected.length > TARGET_COUNT) {
            if (changedCb) changedCb.checked = false;
            alert('You already selected ' + TARGET_COUNT + ' candidate(s). Remove one before adding another.');
            return;
        }

        var isChecked = !!(changedCb && changedCb.checked);
        document.querySelectorAll('[data-cid="' + cssEscape(candId) + '"]').forEach(function(el) {
            el.classList.toggle('cand-hidden', !isChecked);
        });

        var label = changedCb ? changedCb.closest('label') : null;
        if (label) {
            label.style.borderColor = isChecked ? 'var(--acc)' : 'var(--bdr)';
            label.style.color = isChecked ? 'var(--txt)' : 'var(--mut)';
        }

        refreshExportAndCounters(selected.length, checkboxes.length);
        updateUrlSelectedIds(selected, checkboxes.length);
    }

    function resetCandidateFilter() {
        var checkboxes = document.querySelectorAll('.cand-filter-checkbox');
        checkboxes.forEach(function(cb) {
            cb.checked = true;
            var label = cb.closest('label');
            if (label) { label.style.borderColor = 'var(--acc)'; label.style.color = 'var(--txt)'; }
        });
        document.querySelectorAll('.cand-hidden').forEach(function(el) { el.classList.remove('cand-hidden'); });

        refreshExportAndCounters(checkboxes.length, checkboxes.length);
        updateUrlSelectedIds(getCheckedIds(), checkboxes.length);
    }

    function goExportPdf() {
        var exportBtn = document.getElementById('exportPdfBtn');
        if (exportBtn && exportBtn.classList.contains('export-disabled')) return;

        var ids = getCheckedIds();
        if (!ids.length) return;

        var urlParams = new URLSearchParams(window.location.search);
        var url = 'export_comparison_pdf.php'
            + '?job_id=' + encodeURIComponent(urlParams.get('job_id') || JOB_ID)
            + '&sort=' + encodeURIComponent(urlParams.get('sort') || SORT_BY)
            + '&order=' + encodeURIComponent(urlParams.get('order') || SORT_ORDER)
            + '&ids=' + encodeURIComponent(ids.join(','));
        window.location.href = url;
    }
    </script>
    <script src="theme.js"></script>
</body>
</html>
