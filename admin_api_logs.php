<?php
require_once 'auth.php';
require_role('admin');
require_once 'gemini_logs_helper.php';
require_once 'notifications_helper.php';

ensure_gemini_api_logs_table_exists($pdo);

// Human-readable labels for each source tag used across ai.php's call sites.
$source_labels = [
    'auto_screen' => 'Auto-screen (candidate page load)',
    'manual_rescreen' => 'Manual "Re-screen with AI"',
    'bulk_upload' => 'Employer bulk resume upload',
    'candidate_application' => 'Candidate job application',
    'resume_checker' => 'AI Resume Checker tool',
    'resume_builder' => 'AI Resume Builder tool',
    'resume_assist' => '"Ask AI" bullet assist',
    'health_check' => 'Admin dashboard health-check widget',
];

// Filter & Search Parameters
$source_filter = trim($_GET['source_filter'] ?? '');
$success_filter = trim($_GET['success_filter'] ?? '');
$search_query = trim($_GET['search'] ?? '');

$sql = "SELECT l.*, u.name AS user_name FROM gemini_api_logs l LEFT JOIN users u ON l.user_id = u.id WHERE 1=1";
$params = [];

if (!empty($source_filter)) {
    $sql .= " AND l.source = ?";
    $params[] = $source_filter;
}
if ($success_filter === 'success') {
    $sql .= " AND l.success = 1";
} elseif ($success_filter === 'failed') {
    $sql .= " AND l.success = 0";
}
if (!empty($search_query)) {
    $sql .= " AND (l.model_requested LIKE ? OR l.error_message LIKE ? OR u.name LIKE ?)";
    $term = "%{$search_query}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY l.created_at DESC LIMIT 300";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$sources_list = $pdo->query("SELECT DISTINCT source FROM gemini_api_logs ORDER BY source ASC")->fetchAll(PDO::FETCH_COLUMN);

// Summary stats
$calls_today = (int) $pdo->query("SELECT COUNT(*) FROM gemini_api_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$calls_week = (int) $pdo->query("SELECT COUNT(*) FROM gemini_api_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$total_calls = (int) $pdo->query("SELECT COUNT(*) FROM gemini_api_logs")->fetchColumn();
$total_failed = (int) $pdo->query("SELECT COUNT(*) FROM gemini_api_logs WHERE success = 0")->fetchColumn();
$failure_rate = $total_calls > 0 ? round(($total_failed / $total_calls) * 100) : 0;

$by_source = $pdo->query("SELECT source, COUNT(*) AS cnt FROM gemini_api_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY source ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
$busiest_source = !empty($by_source) ? $by_source[0]['source'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Usage Logs - Keria Admin</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png?v=<?php echo @filemtime(__DIR__.'/favicon-32x32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="favicon-16x16.png?v=<?php echo @filemtime(__DIR__.'/favicon-16x16.png'); ?>">
    <link rel="shortcut icon" href="favicon.ico?v=<?php echo @filemtime(__DIR__.'/favicon.ico'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png?v=<?php echo @filemtime(__DIR__.'/apple-touch-icon.png'); ?>">
    <style>
        .logs-table-wrapper {
            overflow-x: auto;
            border-radius: 14px;
            border: 1px solid var(--bdr);
            background: var(--surf);
            margin-bottom: 30px;
        }
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .logs-table th {
            background: var(--card);
            padding: 12px 16px;
            text-align: left;
            font-size: 11px;
            font-weight: 800;
            color: var(--mut);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-bottom: 1px solid var(--bdr);
        }
        .logs-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--bdr);
            vertical-align: middle;
        }
        .logs-table tr:last-child td { border-bottom: none; }
        .result-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .result-success { background: rgba(16, 185, 129, 0.15); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.3); }
        .result-failed { background: rgba(255, 77, 106, 0.15); color: var(--red); border: 1px solid rgba(255, 77, 106, 0.3); }
        .stat-card {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 14px;
            padding: 18px 20px;
        }
        .stat-card .stat-value { font-size: 26px; font-weight: 800; color: var(--txt); line-height: 1.1; }
        .stat-card .stat-label { font-size: 12px; color: var(--mut); margin-top: 4px; }
        .header-inner { max-width: 100% !important; padding: 0 36px !important; box-sizing: border-box; }
        main {
            max-width: 100% !important;
            width: 100% !important;
            padding: 24px 36px 40px !important;
            margin: 72px auto 0 !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 1024px) {
            .header-inner { padding: 0 16px !important; }
            main { padding: 16px 16px 32px !important; margin-top: 170px !important; }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

    <header>
        <div class="header-inner">
            <div class="logo-box">🔑</div>
            <div>
                <div style="font-size:15px; font-weight:800; line-height:1">Keria ATS</div>
                <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">GEMINI API USAGE LOGS</div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="admin_dashboard.php">🛡️ Admin Dashboard</a>
                <a href="admin_resumes.php">📄 Resumes & Export</a>
                <a href="admin_logs.php">📜 Audit Logs</a>
                <a href="admin_api_logs.php" class="active">🔑 API Usage Logs</a>
            </nav>

            <div class="header-right-actions">
                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main>
        <div style="margin-bottom:20px;">
            <h1 style="font-size:24px; font-weight:800; color:var(--txt); margin:0;">🔑 Gemini API Usage Logs</h1>
            <p style="font-size:13px; color:var(--mut); margin:4px 0 0 0;">Every real attempt made against the Gemini API, including each model retried in a single action's fallback chain. Logging started <?php
                $earliest = $pdo->query("SELECT MIN(created_at) FROM gemini_api_logs")->fetchColumn();
                echo $earliest ? date('j M Y', strtotime($earliest)) : 'recently';
            ?> — there is no record of calls made before that.</p>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:14px; margin-bottom:24px;">
            <div class="stat-card">
                <div class="stat-value"><?= $calls_today ?></div>
                <div class="stat-label">Calls today</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $calls_week ?></div>
                <div class="stat-label">Calls last 7 days</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color:<?= $failure_rate > 20 ? 'var(--red)' : 'var(--txt)' ?>;"><?= $failure_rate ?>%</div>
                <div class="stat-label">Failure rate (all-time)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="font-size:16px;"><?= $busiest_source ? htmlspecialchars($source_labels[$busiest_source] ?? $busiest_source) : '—' ?></div>
                <div class="stat-label">Busiest feature (7 days)</div>
            </div>
        </div>

        <?php if (!empty($by_source)): ?>
        <div class="panel" style="margin-bottom:24px; padding:18px 20px;">
            <div style="font-size:13px; font-weight:800; color:var(--txt); margin-bottom:12px;">Calls by feature — last 7 days</div>
            <div style="display:flex; flex-direction:column; gap:8px;">
                <?php $max_cnt = max(array_column($by_source, 'cnt')); ?>
                <?php foreach ($by_source as $row): ?>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="width:220px; font-size:12px; color:var(--txt); flex-shrink:0;"><?= htmlspecialchars($source_labels[$row['source']] ?? $row['source']) ?></div>
                        <div style="flex:1; background:var(--dim); border-radius:6px; height:18px; overflow:hidden;">
                            <div style="width:<?= max(4, round(($row['cnt'] / $max_cnt) * 100)) ?>%; background:var(--keria-lime, #D2FF3A); height:100%;"></div>
                        </div>
                        <div style="width:40px; text-align:right; font-size:12px; font-weight:700; color:var(--txt);"><?= $row['cnt'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:16px;">
            <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="🔍 Search model, error, user..." style="padding:8px 12px; font-size:12px; border-radius:8px; border:1px solid var(--bdr); background:var(--surf); color:var(--txt); width:220px;">

                <select name="source_filter" onchange="this.form.submit()" style="padding:8px 12px; font-size:12px; margin:0;">
                    <option value="">All Features</option>
                    <?php foreach ($sources_list as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>" <?= $source_filter === $s ? 'selected' : '' ?>><?= htmlspecialchars($source_labels[$s] ?? $s) ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="success_filter" onchange="this.form.submit()" style="padding:8px 12px; font-size:12px; margin:0;">
                    <option value="">All Results</option>
                    <option value="success" <?= $success_filter === 'success' ? 'selected' : '' ?>>Success Only</option>
                    <option value="failed" <?= $success_filter === 'failed' ? 'selected' : '' ?>>Failed Only</option>
                </select>

                <?php if (!empty($search_query) || !empty($source_filter) || !empty($success_filter)): ?>
                    <a href="admin_api_logs.php" class="btn-secondary" style="padding:8px 12px; font-size:12px; text-decoration:none;">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($logs)): ?>
            <div class="panel" style="text-align:center; padding:60px 20px; border-style:dashed;">
                <div style="font-size:36px; margin-bottom:12px;">📭</div>
                <div style="font-size:18px; font-weight:700; color:var(--txt); margin-bottom:6px;">No API calls logged yet</div>
                <div style="font-size:13px; color:var(--mut);">Every real Gemini API attempt (resume screening, AI tools, the admin health check) will appear here automatically from now on.</div>
            </div>
        <?php else: ?>
            <div class="logs-table-wrapper">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th style="width:150px;">Timestamp</th>
                            <th style="width:200px;">Feature</th>
                            <th style="width:170px;">Model</th>
                            <th style="width:70px;">Attempt</th>
                            <th style="width:90px;">Result</th>
                            <th style="width:90px;">Latency</th>
                            <th>Error (if any)</th>
                            <th style="width:120px;">Triggered By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td style="font-weight:700; color:var(--mut);">#<?= $l['id'] ?></td>
                                <td style="color:var(--mut); white-space:nowrap; font-size:12px;"><?= date('Y-m-d H:i:s', strtotime($l['created_at'])) ?></td>
                                <td style="color:var(--txt);"><?= htmlspecialchars($source_labels[$l['source']] ?? $l['source']) ?></td>
                                <td style="font-family:monospace; font-size:11.5px; color:var(--txt);"><?= htmlspecialchars($l['model_requested']) ?></td>
                                <td style="text-align:center; color:var(--mut);">#<?= (int)$l['attempt_number'] ?></td>
                                <td>
                                    <?php if ($l['success']): ?>
                                        <span class="result-badge result-success">✅ OK</span>
                                    <?php else: ?>
                                        <span class="result-badge result-failed"><?= $l['http_status'] ? htmlspecialchars($l['http_status']) : '✕' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--mut); font-size:12px;"><?= $l['latency_ms'] !== null ? (int)$l['latency_ms'].'ms' : '—' ?></td>
                                <td style="color:var(--mut); font-size:12px; max-width:280px;"><?= $l['error_message'] ? htmlspecialchars($l['error_message']) : '' ?></td>
                                <td style="font-size:12px; color:var(--mut);"><?= $l['user_name'] ? htmlspecialchars($l['user_name']) : ($l['user_id'] ? '#'.$l['user_id'] : '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="font-size:11.5px; color:var(--mut);">Showing the most recent 300 calls matching your filters.</p>
        <?php endif; ?>
    </main>

    <script src="theme.js"></script>
</body>
</html>
