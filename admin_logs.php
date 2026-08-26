<?php
require_once 'auth.php';
require_role('admin');
require_once 'admin_logs_helper.php';
require_once 'notifications_helper.php';

ensure_admin_logs_table_exists($pdo);

// Filter & Search Parameters
$action_filter = trim($_GET['action_filter'] ?? '');
$search_query = trim($_GET['search'] ?? '');

$sql = "SELECT * FROM admin_logs WHERE 1=1";
$params = [];

if (!empty($action_filter)) {
    $sql .= " AND action = ?";
    $params[] = $action_filter;
}

if (!empty($search_query)) {
    $sql .= " AND (admin_name LIKE ? OR details LIKE ? OR ip_address LIKE ? OR target_type LIKE ?)";
    $term = "%{$search_query}%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY created_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Unique action types for dropdown filter
$actions_list = $pdo->query("SELECT DISTINCT action FROM admin_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Audit Logs - HireLah Admin</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
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
        .logs-table tr:last-child td {
            border-bottom: none;
        }
        .action-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .act-delete_user, .act-delete_job {
            background: rgba(255, 77, 106, 0.15); color: var(--red); border: 1px solid rgba(255, 77, 106, 0.3);
        }
        .act-add_user {
            background: rgba(0, 232, 122, 0.15); color: var(--grn); border: 1px solid rgba(0, 232, 122, 0.3);
        }
        .act-change_role, .act-toggle_verify {
            background: rgba(217, 255, 79, 0.15); color: var(--acc); border: 1px solid rgba(217, 255, 79, 0.3);
        }
        .act-toggle_job_status {
            background: rgba(245, 158, 11, 0.15); color: var(--gold); border: 1px solid rgba(245, 158, 11, 0.3);
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>

    <header>
        <div class="header-inner">
            <div class="logo-box">📜</div>
            <div>
                <div style="font-size:15px; font-weight:800; line-height:1">HireLah ATS</div>
                <div style="font-size:9px; color:var(--mut); letter-spacing:0.8px">SYSTEM AUDIT & ACTIVITY LOGS</div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="admin_dashboard.php">&larr; Admin Dashboard</a>
                <a href="admin_logs.php" class="active">📜 Audit Logs</a>
            </nav>

            <div class="header-right-actions">
                <span class="user-info-text" style="font-size:12px; color:var(--mut); margin-right:10px;">Logged in as <?= htmlspecialchars($_SESSION['user_name']) ?></span>
                <a href="logout.php" class="btn-secondary" style="padding:6px 14px; font-size:12px;">Logout</a>
            </div>
        </div>
    </header>

    <main style="max-width:1200px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:16px;">
            <div>
                <h1 style="font-size:24px; font-weight:800; color:var(--txt); margin:0;">📜 Read-Only System Audit Logs</h1>
                <p style="font-size:13px; color:var(--mut); margin:4px 0 0 0;">Immutable trail of all administrative operations, user additions, role changes, and deletions.</p>
            </div>

            <!-- Filter Controls Form -->
            <form method="GET" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0;">
                <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="🔍 Search admin, details, IP..." style="padding:8px 12px; font-size:12px; border-radius:8px; border:1px solid var(--bdr); background:var(--surf); color:var(--txt); width:200px;">
                
                <select name="action_filter" onchange="this.form.submit()" style="padding:8px 12px; font-size:12px; margin:0;">
                    <option value="">All Action Types</option>
                    <?php foreach($actions_list as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $action_filter === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars(strtoupper($act)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if(!empty($search_query) || !empty($action_filter)): ?>
                    <a href="admin_logs.php" class="btn-secondary" style="padding:8px 12px; font-size:12px; text-decoration:none;">Clear Filters</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if(empty($logs)): ?>
            <div class="panel" style="text-align:center; padding:60px 20px; border-style:dashed;">
                <div style="font-size:36px; margin-bottom:12px;">📭</div>
                <div style="font-size:18px; font-weight:700; color:var(--txt); margin-bottom:6px;">No audit log records found</div>
                <div style="font-size:13px; color:var(--mut);">State-changing admin actions performed from the admin dashboard will automatically be recorded here.</div>
            </div>
        <?php else: ?>
            <div class="logs-table-wrapper">
                <table class="logs-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th style="width:160px;">Timestamp</th>
                            <th style="width:160px;">Admin User</th>
                            <th style="width:140px;">Action Type</th>
                            <th style="width:120px;">Target</th>
                            <th>Action Details</th>
                            <th style="width:130px;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $l): ?>
                            <tr>
                                <td style="font-weight:700; color:var(--mut);">#<?= $l['id'] ?></td>
                                <td style="color:var(--mut); white-space:nowrap; font-size:12px;">
                                    <?= date('Y-m-d H:i:s', strtotime($l['created_at'])) ?>
                                </td>
                                <td>
                                    <div style="font-weight:700; color:var(--txt);"><?= htmlspecialchars($l['admin_name']) ?></div>
                                    <div style="font-size:10px; color:var(--mut);">ID: #<?= (int)$l['admin_id'] ?></div>
                                </td>
                                <td>
                                    <span class="action-badge act-<?= htmlspecialchars($l['action']) ?>">
                                        <?= htmlspecialchars(strtoupper($l['action'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="chip" style="font-size:10px;">
                                        <?= htmlspecialchars($l['target_type']) ?> <?= $l['target_id'] ? '#'.$l['target_id'] : '' ?>
                                    </span>
                                </td>
                                <td style="color:var(--txt); line-height:1.4;">
                                    <?= htmlspecialchars($l['details']) ?>
                                </td>
                                <td style="font-family:monospace; color:var(--mut); font-size:11px;">
                                    <?= htmlspecialchars($l['ip_address']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </main>

    <script src="theme.js"></script>
</body>
</html>
