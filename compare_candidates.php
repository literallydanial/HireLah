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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Comparison Matrix - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png">
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
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png" alt="HireLah Watermark Logo"></div>

    <header>
        <div class="header-inner">
            <div class="logo-box">⚖️</div>
            <div>
                <div style="font-size:15px; font-weight:800; line-height:1">HireLah ATS</div>
                <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">CANDIDATE COMPARISON MATRIX</div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="employer_dashboard.php?job_id=<?= $selected_job_id ?>">&larr; Back to Applicants</a>
                <a href="job_dashboard.php">💼 My Jobs</a>
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
            <!-- Interactive Candidate Filter Toggles -->
            <div class="panel" style="margin-bottom:20px; padding:14px 18px;">
                <div style="font-size:12px; font-weight:800; color:var(--txt); margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">
                    <span>Filter Candidates to Compare (<?= count($filtered_candidates) ?> of <?= count($candidates) ?> shown)</span>
                    <?php if(!empty($selected_ids)): ?>
                        <a href="compare_candidates.php?job_id=<?= $selected_job_id ?>&sort=<?= $sort_by ?>&order=<?= $sort_order ?>" style="font-size:11px; color:var(--acc); font-weight:700; text-decoration:none;">Reset Filter (Show All)</a>
                    <?php endif; ?>
                </div>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <?php foreach($candidates as $cand_opt): 
                        $is_checked = empty($selected_ids) || in_array($cand_opt['id'], $selected_ids);
                    ?>
                        <label style="display:inline-flex; align-items:center; gap:6px; background:var(--surf); border:1px solid <?= $is_checked ? 'var(--acc)' : 'var(--bdr)' ?>; padding:6px 12px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; color:<?= $is_checked ? 'var(--txt)' : 'var(--mut)' ?>;">
                            <input type="checkbox" onchange="toggleCandidateFilter('<?= $cand_opt['id'] ?>')" <?= $is_checked ? 'checked' : '' ?> style="margin:0;">
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
                            <?php foreach($filtered_candidates as $c): ?>
                                <th class="cand-col-header">
                                    <div style="display:flex; flex-direction:column; align-items:center;">
                                        <?php if(!empty($c['profile_picture']) && file_exists($c['profile_picture'])): ?>
                                            <img src="<?= htmlspecialchars($c['profile_picture']) ?>" alt="<?= htmlspecialchars($c['name']) ?>" style="width:52px; height:52px; border-radius:50%; object-fit:cover; border:2px solid var(--acc); margin-bottom:8px;">
                                        <?php else: ?>
                                            <div style="width:52px; height:52px; border-radius:50%; background:linear-gradient(135deg, #6B8A00, #00E87A); display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:800; color:#fff; margin-bottom:8px;">
                                                <?= strtoupper(substr($c['name'] ?: 'C', 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div style="font-size:15px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($c['name']) ?></div>
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
                            <?php foreach($filtered_candidates as $c): 
                                $score = (int)($c['overall_score'] ?? 0);
                                $color = $score >= 80 ? 'var(--grn)' : ($score >= 60 ? 'var(--acc)' : ($score >= 40 ? 'var(--org)' : 'var(--red)'));
                            ?>
                                <td style="text-align:center;">
                                    <div class="score-circle-sm" style="border:3px solid <?= $color ?>; color:<?= $color ?>; box-shadow:0 0 8px <?= $color ?>40;">
                                        <?= $score ?>%
                                    </div>
                                </td>
                            <?php endforeach; ?>
                        </tr>

                        <!-- AI Recommendation & Status Row -->
                        <tr>
                            <td class="feature-label">💡 Recommendation & Status</td>
                            <?php foreach($filtered_candidates as $c): 
                                $rec = $c['recommendation'] ?? 'Pending';
                                $rec_color = ($rec == 'Strong Hire' || $rec == 'Hire') ? 'var(--grn)' : ($rec == 'Maybe' ? 'var(--org)' : 'var(--red)');
                            ?>
                                <td style="text-align:center;">
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
                            <?php foreach($filtered_candidates as $c): 
                                $v = (int)($c['skills_match'] ?? 0);
                                $vc = $v >= 70 ? 'var(--grn)' : ($v >= 45 ? 'var(--acc)' : 'var(--org)');
                            ?>
                                <td>
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
                            <?php foreach($filtered_candidates as $c): 
                                $v = (int)($c['exp_match'] ?? 0);
                                $vc = $v >= 70 ? 'var(--grn)' : ($v >= 45 ? 'var(--acc)' : 'var(--org)');
                            ?>
                                <td>
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
                            <?php foreach($filtered_candidates as $c): 
                                $v = (int)($c['edu_match'] ?? 0);
                                $vc = $v >= 70 ? 'var(--grn)' : ($v >= 45 ? 'var(--acc)' : 'var(--org)');
                            ?>
                                <td>
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
                            <?php foreach($filtered_candidates as $c): 
                                $strengths = !empty($c['strengths']) ? (json_decode($c['strengths'], true) ?: []) : [];
                            ?>
                                <td>
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
                            <?php foreach($filtered_candidates as $c): 
                                $gaps = !empty($c['gaps']) ? (json_decode($c['gaps'], true) ?: []) : [];
                            ?>
                                <td>
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
                            <?php foreach($filtered_candidates as $c): ?>
                                <td style="text-align:center;">
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
    function toggleCandidateFilter(candId) {
        const checkboxes = document.querySelectorAll('input[type="checkbox"]');
        const selected = [];
        checkboxes.forEach(cb => {
            if (cb.checked) {
                const match = cb.getAttribute('onchange').match(/'([^']+)'/);
                if (match) selected.push(match[1]);
            }
        });
        
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('selected_ids', selected.join(','));
        window.location.search = urlParams.toString();
    }
    </script>
    <script src="theme.js"></script>
</body>
</html>
