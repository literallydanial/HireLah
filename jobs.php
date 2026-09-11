<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';

$userNotifs = (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate') ? getCandidateNotifications($pdo, $_SESSION['user_id']) : ['items' => [], 'unread_count' => 0];
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];

$search = trim($_GET['search'] ?? '');
$filter_type = trim($_GET['type'] ?? '');
$filter_mode = trim($_GET['mode'] ?? '');
$filter_location = trim($_GET['location'] ?? '');
$filter_min_salary = !empty($_GET['min_salary']) ? (int)$_GET['min_salary'] : null;
$filter_max_salary = !empty($_GET['max_salary']) ? (int)$_GET['max_salary'] : null;
$sort_by = trim($_GET['sort'] ?? 'newest');

$sql = "SELECT j.*, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, u.company_logo 
        FROM jobs j 
        LEFT JOIN users u ON j.employer_id = u.id 
        WHERE (j.status = 'Active' OR j.status IS NULL)";
$params = [];

if ($search !== '') {
    $sql .= " AND (j.job_title LIKE ? OR j.department LIKE ? OR j.location LIKE ? OR j.description LIKE ? OR u.company_name LIKE ? OR u.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
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
if ($filter_location !== '') {
    $sql .= " AND (j.location LIKE ? OR j.department LIKE ?)";
    $params[] = "%$filter_location%";
    $params[] = "%$filter_location%";
}
if ($filter_min_salary !== null) {
    $sql .= " AND (COALESCE(j.salary_max, j.salary_min) >= ?)";
    $params[] = $filter_min_salary;
}
if ($filter_max_salary !== null) {
    $sql .= " AND (COALESCE(j.salary_min, j.salary_max) <= ?)";
    $params[] = $filter_max_salary;
}

if ($sort_by === 'salary_high') {
    $sql .= " ORDER BY COALESCE(j.salary_max, j.salary_min, 0) DESC, j.created_at DESC";
} elseif ($sort_by === 'salary_low') {
    $sql .= " ORDER BY CASE WHEN COALESCE(j.salary_min, j.salary_max) IS NULL THEN 1 ELSE 0 END, COALESCE(j.salary_min, j.salary_max) ASC, j.created_at DESC";
} else {
    $sql .= " ORDER BY j.created_at DESC, j.id DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch each employer's company gallery media (images/videos)
$mediaByEmployer = [];
$employerIds = array_values(array_unique(array_filter(array_column($jobs, 'employer_id'))));
if (!empty($employerIds)) {
    try {
        $placeholders = implode(',', array_fill(0, count($employerIds), '?'));
        $media_stmt = $pdo->prepare("SELECT * FROM company_media WHERE user_id IN ($placeholders) ORDER BY sort_order ASC, id ASC");
        $media_stmt->execute($employerIds);
        foreach ($media_stmt->fetchAll() as $m) {
            $mediaByEmployer[$m['user_id']][] = ['media_type' => $m['media_type'], 'file_path' => $m['file_path']];
        }
    } catch (\Throwable $e) {
        $mediaByEmployer = [];
    }
}

// Format relative date and attach helper data
foreach ($jobs as $idx => &$j) {
    $j['company_media'] = $mediaByEmployer[$j['employer_id']] ?? [];
    
    $created_time = strtotime($j['created_at'] ?? 'now');
    $diff_days = floor((time() - $created_time) / 86400);
    if ($diff_days < 1) {
        $j['posted_label'] = "Posted today";
    } elseif ($diff_days == 1) {
        $j['posted_label'] = "Posted 1 day ago";
    } else {
        $j['posted_label'] = "Posted " . $diff_days . " days ago";
    }
}
unset($j);

// Fetch set of job IDs & AI scores that the logged-in candidate has applied to
$applied_job_ids = [];
$candidate_scores = [];
if (isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'candidate') {
    try {
        $app_stmt = $pdo->prepare("SELECT job_id, overall_score, skills_match, exp_match, edu_match, summary, relevance_label, skills, strengths, gaps FROM candidates WHERE user_id = ? AND job_id IS NOT NULL ORDER BY created_at DESC");
        $app_stmt->execute([$_SESSION['user_id']]);
        $app_records = $app_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($app_records as $rec) {
            $j_id = $rec['job_id'];
            if (!in_array($j_id, $applied_job_ids)) {
                $applied_job_ids[] = $j_id;

                $skills_decoded = is_string($rec['skills']) ? json_decode($rec['skills'], true) : $rec['skills'];
                $strengths_decoded = is_string($rec['strengths']) ? json_decode($rec['strengths'], true) : $rec['strengths'];
                $gaps_decoded = is_string($rec['gaps']) ? json_decode($rec['gaps'], true) : $rec['gaps'];

                $rec['parsed_skills'] = is_array($skills_decoded) ? $skills_decoded : [];
                $rec['parsed_strengths'] = is_array($strengths_decoded) ? $strengths_decoded : [];
                $rec['parsed_gaps'] = is_array($gaps_decoded) ? $gaps_decoded : [];

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
    <title>Job Board — keria</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">
    <link rel="shortcut icon" type="image/png" href="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>">

    <style>
        :root {
            --keria-lime: #D2FF3A;
            --keria-lime-hover: #C2F025;
            --keria-lime-light: #EBFFAA;
            --keria-green-text: #15803D;
            --keria-green-bg: #DCFCE7;
            --keria-dark: #0A0A0A;
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-hand: 'Caveat', cursive;
        }

        body {
            font-family: var(--font-sans);
            background-color: var(--keria-bg, #F8FAF8);
            color: var(--keria-dark, #0A0A0A);
            min-height: 100vh;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        /* Screen-filling Header Width */
        header .header-inner {
            max-width: 1560px !important;
            width: 100% !important;
            padding: 0 16px !important;
        }

        /* ================= HERO BANNER SECTION ================= */
        .keria-hero-banner {
            position: relative;
            width: 100%;
            background-image: url('assets/Keria_banner.jpg?v=<?php echo @filemtime(__DIR__.'/assets/Keria_banner.jpg'); ?>');
            background-size: cover;
            background-position: center top;
            background-repeat: no-repeat;
            padding: 32px 32px 36px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .banner-content-inner {
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            position: relative;
            z-index: 5;
        }

        /* Left Banner Title Area */
        .banner-left-intro {
            flex: 0 0 280px;
            position: relative;
        }

        .banner-leaf-doodles {
            position: absolute;
            top: -24px;
            left: -14px;
            width: 42px;
            height: 42px;
            pointer-events: none;
        }

        .banner-title-text {
            font-size: 38px;
            font-weight: 900;
            color: #0A0A0A;
            line-height: 1.08;
            letter-spacing: -1.2px;
            margin-bottom: 10px;
        }

        .banner-sub-text {
            font-size: 13.5px;
            font-weight: 500;
            color: #374151;
            line-height: 1.4;
            max-width: 250px;
            margin-bottom: 6px;
        }

        .banner-sub-smile {
            font-family: var(--font-hand);
            font-size: 26px;
            font-weight: 700;
            color: #0A0A0A;
            display: inline-block;
            transform: rotate(6deg);
            margin-top: -2px;
        }

        /* Right Banner Doodle Area */
        .banner-right-doodle-box {
            flex: 0 0 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            margin-right: 30px;
            user-select: none;
        }

        .banner-doodle-text {
            font-family: var(--font-hand);
            font-size: 24px;
            font-weight: 700;
            line-height: 1.1;
            color: #0A0A0A;
            transform: rotate(6deg);
            position: relative;
        }

        .banner-doodle-sparks {
            position: absolute;
            top: -10px;
            right: -16px;
            width: 26px;
            height: 26px;
        }

        /* Floating Center Search & Filter Card */
        .banner-search-wrapper {
            flex: 1;
            max-width: 1080px;
            margin: 0 auto;
        }

        .banner-search-card {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 22px;
            padding: 16px 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        /* Search Input Row */
        .search-inputs-row {
            display: flex;
            align-items: center;
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 14px;
            padding: 3px 6px 3px 14px;
            gap: 10px;
        }

        .search-input-col {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .search-input-col.location-col {
            border-left: 1px solid #E5E7EB;
            padding-left: 12px;
            flex: 0.9;
        }

        .search-field-icon {
            font-size: 15px;
            color: #6B7280;
            flex-shrink: 0;
        }

        .search-text-input {
            width: 100%;
            border: none;
            outline: none;
            font-size: 13.5px;
            font-weight: 500;
            color: #0A0A0A;
            background: transparent;
            padding: 7px 0;
            box-shadow: none !important;
        }

        .search-text-input::placeholder {
            color: #9CA3AF;
        }

        .btn-search-find {
            background: var(--keria-lime);
            color: #0A0A0A;
            border: none;
            outline: none;
            padding: 9px 20px;
            border-radius: 11px;
            font-size: 13.5px;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(210, 255, 58, 0.35);
            white-space: nowrap;
            flex-shrink: 0;
            width: auto;
            margin: 0;
        }

        .btn-search-find:hover {
            background: var(--keria-lime-hover);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(210, 255, 58, 0.5);
        }

        /* Filter Selects Row */
        .filter-selects-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }

        .filter-select-box {
            position: relative;
        }

        .filter-select-input {
            width: 100%;
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            padding: 8px 24px 8px 10px;
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            outline: none;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            transition: border-color 0.2s ease;
            margin: 0;
        }

        .filter-select-input:focus, .filter-select-input:hover {
            border-color: #9CA3AF;
        }

        .select-chevron {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            font-size: 9px;
            color: #6B7280;
        }

        /* Quick Filter Pills Row */
        .quick-filter-pills-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding-top: 2px;
        }

        .quick-pill-btn {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            color: #374151;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
            white-space: nowrap;
            width: auto;
            margin: 0;
        }

        .quick-pill-btn:hover {
            background: #F3F4F6;
            border-color: #D1D5DB;
        }

        .quick-pill-btn.active {
            background: #0A0A0A;
            border-color: #0A0A0A;
            color: #FFFFFF;
            font-weight: 700;
        }

        /* ================= 3. SCREEN-FILLING 2-COLUMN JOB EXPLORER ================= */
        .keria-main-container {
            max-width: 1560px;
            width: 100%;
            margin: 0 auto;
            padding: 24px 28px 60px;
        }

        .split-layout-grid {
            display: grid;
            grid-template-columns: minmax(360px, 440px) minmax(0, 1fr);
            gap: 28px;
            align-items: start;
        }

        /* Left Column: Job Cards List (Scrollable Viewport Height) */
        .job-cards-list-pane {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: calc(100vh - 100px);
            overflow-y: auto;
            padding-right: 6px;
        }

        .job-cards-list-pane::-webkit-scrollbar {
            width: 5px;
        }

        .job-cards-list-pane::-webkit-scrollbar-track {
            background: transparent;
        }

        .job-cards-list-pane::-webkit-scrollbar-thumb {
            background: #E5E7EB;
            border-radius: 999px;
        }

        .job-cards-list-pane::-webkit-scrollbar-thumb:hover {
            background: #D1D5DB;
        }

        .active-positions-header {
            font-size: 17px;
            font-weight: 800;
            color: #0A0A0A;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            background: transparent;
            padding: 2px 0 6px;
        }

        .active-positions-header svg {
            color: #65A30D;
        }

        /* Single Job Card */
        .job-card-box {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 18px;
            padding: 18px 20px;
            cursor: pointer;
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
        }

        .job-card-box:hover {
            border-color: #A3E635;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.05);
        }

        .job-card-box.selected-card {
            border: 2px solid #84CC16;
            background: #FFFFFF;
            box-shadow: 0 6px 20px rgba(132, 204, 22, 0.15);
        }

        .card-top-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 6px;
        }

        .card-title-group {
            flex: 1;
            min-width: 0;
        }

        .card-title-line {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 4px;
        }

        .card-job-title {
            font-size: 16.5px;
            font-weight: 800;
            color: #0A0A0A;
            line-height: 1.25;
        }

        .badge-posted-ago {
            background: #FFF7ED;
            border: 1px solid #FFEDD5;
            color: #EA580C;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .card-company-meta {
            font-size: 12.5px;
            font-weight: 500;
            color: #6B7280;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .card-company-meta strong {
            color: #4B5563;
            font-weight: 600;
        }

        .card-meta-dot {
            color: #D1D5DB;
        }

        .card-tags-row {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }

        .card-pill-tag {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            color: #374151;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 6px;
        }

        /* Card Logo Box */
        .card-logo-container {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            overflow: hidden;
            padding: 4px;
        }

        .card-logo-img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        /* Card AI Match Bar */
        .card-ai-match-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding-top: 6px;
            margin-top: 4px;
            border-top: 1px dashed #E5E7EB;
        }

        .ai-match-label-group {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 700;
            color: #374151;
            white-space: nowrap;
        }

        .ai-progress-track {
            flex: 1;
            height: 6.5px;
            background: #F3F4F6;
            border-radius: 999px;
            overflow: hidden;
            position: relative;
        }

        .ai-progress-fill {
            height: 100%;
            border-radius: 999px;
            transition: width 0.4s ease;
        }

        .ai-score-pct {
            font-size: 13px;
            font-weight: 800;
            white-space: nowrap;
        }

        /* Right Column: Sticky Screen-Fitting Job Detail Card */
        .job-detail-sticky-pane {
            position: sticky;
            top: 86px;
            width: 100%;
        }

        .job-detail-card-inner {
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            border-radius: 24px;
            padding: 34px 40px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.03);
            max-height: calc(100vh - 110px);
            overflow-y: auto;
            width: 100%;
        }

        .job-detail-card-inner::-webkit-scrollbar {
            width: 6px;
        }

        .job-detail-card-inner::-webkit-scrollbar-track {
            background: #F3F4F6;
            border-radius: 4px;
        }

        .job-detail-card-inner::-webkit-scrollbar-thumb {
            background: #D1D5DB;
            border-radius: 4px;
        }

        /* Detail Header */
        .detail-top-bar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 8px;
        }

        .detail-job-title {
            font-size: 30px;
            font-weight: 800;
            color: #0A0A0A;
            line-height: 1.15;
            letter-spacing: -0.5px;
        }

        .detail-action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }

        .btn-circle-action {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #FFFFFF;
            border: 1px solid #E5E7EB;
            color: #374151;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 15px;
        }

        .btn-circle-action:hover {
            background: #F9FAFB;
            border-color: #9CA3AF;
            transform: translateY(-1px);
        }

        .btn-circle-action.is-saved {
            background: #FEF3C7;
            border-color: #FDE68A;
            color: #D97706;
        }

        .detail-company-subline {
            font-size: 13.5px;
            font-weight: 500;
            color: #6B7280;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .detail-company-subline strong {
            color: #1F2937;
            font-weight: 700;
        }

        .detail-salary-text {
            font-size: 21px;
            font-weight: 800;
            color: #16A34A;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .detail-meta-pills-row {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .detail-meta-pill {
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            color: #374151;
            font-size: 12.5px;
            font-weight: 600;
            padding: 5px 14px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .detail-meta-pill.active-status {
            background: #DCFCE7;
            border-color: #BBF7D0;
            color: #15803D;
            font-weight: 700;
        }

        /* Detail Action Apply/Save Row */
        .detail-apply-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 22px;
            border-bottom: 1px solid #E5E7EB;
        }

        .btn-detail-apply {
            background: #0A0A0A;
            color: #FFFFFF;
            padding: 12px 28px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 800;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
        }

        .btn-detail-apply:hover {
            background: #262626;
            transform: translateY(-1px);
        }

        .btn-detail-applied {
            background: #F3F4F6;
            color: #166534;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            border: 1px solid #BBF7D0;
            cursor: not-allowed;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-detail-save {
            background: #FFFFFF;
            border: 1.5px solid #0A0A0A;
            color: #0A0A0A;
            padding: 11px 22px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            width: auto;
            margin: 0;
        }

        .btn-detail-save:hover {
            background: #F9FAFB;
            transform: translateY(-1px);
        }

        .btn-detail-save.is-saved {
            background: #FEF3C7;
            border-color: #F59E0B;
            color: #B45309;
        }

        /* AI Match Evaluation Breakdown Card in Detail */
        .ai-eval-breakdown-card {
            background: #FAFBFC;
            border: 1px solid #E2E8F0;
            border-radius: 20px;
            padding: 22px 24px;
            margin-bottom: 28px;
        }

        .ai-eval-header-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .ai-eval-title-block {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .ai-eval-title-line {
            font-size: 14.5px;
            font-weight: 800;
            color: #0A0A0A;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ai-eval-subtitle {
            font-size: 12px;
            font-weight: 600;
            color: #2563EB;
        }

        .ai-eval-score-block {
            display: flex;
            align-items: baseline;
            gap: 6px;
            text-align: right;
        }

        .ai-eval-score-num {
            font-size: 26px;
            font-weight: 900;
            line-height: 1;
        }

        .ai-eval-score-label {
            font-size: 10px;
            font-weight: 800;
            color: #64748B;
            letter-spacing: 0.5px;
        }

        .ai-eval-info-icon {
            font-size: 12px;
            color: #94A3B8;
            cursor: pointer;
        }

        .ai-eval-progress-bar {
            width: 100%;
            height: 8px;
            background: #E2E8F0;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        .ai-eval-progress-fill {
            height: 100%;
            border-radius: 999px;
            transition: width 0.5s ease;
        }

        .ai-eval-metrics-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }

        .ai-metric-item-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 12px;
            text-align: center;
        }

        .ai-metric-title {
            font-size: 11px;
            font-weight: 700;
            color: #64748B;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-bottom: 4px;
        }

        .ai-metric-val {
            font-size: 17px;
            font-weight: 900;
        }

        .ai-overlap-section {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .ai-overlap-heading {
            font-size: 11.5px;
            font-weight: 800;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ai-overlap-pills-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .overlap-pill-matched {
            background: #DCFCE7;
            border: 1px solid #BBF7D0;
            color: #166534;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .overlap-pill-missing {
            background: #FFEDD5;
            border: 1px solid #FED7AA;
            color: #C2410C;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* AI Prompt Card when candidate hasn't applied */
        .ai-eval-prompt-card {
            background: linear-gradient(135deg, rgba(210, 255, 58, 0.16), rgba(59, 130, 246, 0.08));
            border: 1px solid rgba(163, 230, 53, 0.45);
            border-radius: 20px;
            padding: 20px 22px;
            margin-bottom: 28px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* Job Description Body */
        .job-desc-section-wrapper {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .job-desc-header-row {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-bottom: 8px;
        }

        .job-desc-main-title {
            font-size: 16.5px;
            font-weight: 800;
            color: #0A0A0A;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .job-desc-subtext {
            font-size: 12.5px;
            color: #6B7280;
        }

        .job-desc-paragraph {
            font-size: 14px;
            line-height: 1.65;
            color: #374151;
            white-space: pre-wrap;
        }

        .job-spec-block-title {
            font-size: 15px;
            font-weight: 800;
            color: #0A0A0A;
            margin-bottom: 8px;
        }

        .job-bullet-list {
            padding-left: 20px;
            font-size: 13.5px;
            line-height: 1.65;
            color: #374151;
        }

        .job-bullet-list li {
            margin-bottom: 6px;
        }

        /* Company Culture Gallery Section */
        .company-gallery-section {
            margin-top: 28px;
            padding-top: 24px;
            border-top: 1px solid #E5E7EB;
        }

        .company-gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            grid-auto-rows: 115px;
            gap: 12px;
        }

        .company-gallery-tile {
            display: block;
            border-radius: 14px;
            overflow: hidden;
            background: #F9FAFB;
            border: 1px solid #E5E7EB;
            transition: all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            text-decoration: none;
        }

        .company-gallery-tile:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.09);
            border-color: #CBD5E1;
        }

        .company-gallery-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Toast Popup */
        .keria-toast {
            position: fixed;
            top: 24px;
            right: 24px;
            background: #0A0A0A;
            color: #FFFFFF;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 13.5px;
            font-weight: 700;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: toastIn 0.3s ease;
        }

        @keyframes toastIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive Breakpoints */
        @media (max-width: 1080px) {
            .split-layout-grid {
                grid-template-columns: 1fr;
            }
            .job-cards-list-pane {
                max-height: none;
                overflow-y: visible;
            }
            .job-detail-sticky-pane {
                display: none;
            }
            .filter-selects-row {
                grid-template-columns: repeat(2, 1fr);
            }
            .banner-content-inner {
                flex-wrap: wrap;
            }
            .banner-search-wrapper {
                order: 3;
                width: 100%;
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            header nav {
                display: none !important;
            }
            .banner-left-intro {
                flex: 1;
            }
            .banner-right-doodle-box {
                display: none;
            }
            .keria-hero-banner {
                padding: 20px 16px;
            }
            .keria-main-container {
                padding: 16px 14px;
            }
        }
    </style>
</head>
<body>
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Watermark Logo"></div>

    <!-- ================= 1. HEADER / NAVBAR ================= -->
    <header>
        <div class="header-inner">
            <div style="display:flex; align-items:center; gap:10px;">
                <div class="logo-box"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="width:36px; height:36px; max-width:36px; max-height:36px; object-fit:contain;"></div>
            </div>

            <nav style="display:flex; gap:4px; margin-left:24px">
                <a href="jobs.php" class="active">📋 Job Board</a>
                <?php if(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate'): ?>
                    <a href="candidate_dashboard.php">👤 My Applications</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Profile Settings</a>
                <?php elseif(isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'employer'): ?>
                    <a href="employer_dashboard.php">👥 Applications & Stats</a>
                    <a href="job_dashboard.php">💼 My Jobs</a>
                    <a href="questionnaire.php">📋 Questionnaires</a>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="profile.php">⚙️ Settings</a>
                <?php else: ?>
                    <a href="resume_builder.php">📝 AI Resume Builder</a>
                    <a href="resume_check.php">✨ AI Resume Check</a>
                <?php endif; ?>
            </nav>

            <div class="header-right-actions" style="margin-left:auto; display:flex; align-items:center;">
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
                    <a href="register.php" class="btn-primary" style="padding:6px 14px; font-size:12px; font-weight:700; width:auto; text-decoration:none; margin-left:8px;">✨ Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- ================= 2. HERO BANNER WITH SEARCH ================= -->
    <section class="keria-hero-banner">
        <div class="banner-content-inner">
            
            <!-- Left Banner Intro -->
            <div class="banner-left-intro">
                <svg class="banner-leaf-doodles" viewBox="0 0 50 50" fill="none">
                    <path d="M12 28 C 6 18, 14 6, 26 10 C 26 22, 18 30, 12 28 Z" fill="#97DF14" opacity="0.85"/>
                    <circle cx="8" cy="38" r="4" fill="#97DF14" opacity="0.7"/>
                </svg>
                <h1 class="banner-title-text">
                    Find your<br>next opportunity.
                </h1>
                <p class="banner-sub-text">
                    Search jobs that fit your skills, goals and next chapter.
                </p>
                <div class="banner-sub-smile">
                    ☺
                </div>
            </div>

            <!-- Floating Search and Filters Card -->
            <div class="banner-search-wrapper">
                <form method="GET" class="banner-search-card" id="jobsSearchForm">
                    
                    <!-- Search Inputs Row -->
                    <div class="search-inputs-row">
                        <div class="search-input-col">
                            <span class="search-field-icon">🔍</span>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Job title, department, company, or skills..." class="search-text-input">
                        </div>
                        <div class="search-input-col location-col">
                            <span class="search-field-icon">📍</span>
                            <input type="text" name="location" value="<?= htmlspecialchars($filter_location) ?>" placeholder="Location or City (e.g. KL)..." class="search-text-input">
                        </div>
                        <button type="submit" class="btn-search-find">
                            Find Jobs &rarr;
                        </button>
                    </div>

                    <!-- Dropdown Filters Row -->
                    <div class="filter-selects-row">
                        <div class="filter-select-box">
                            <select name="type" class="filter-select-input" onchange="this.form.submit()">
                                <option value="">All Job Types</option>
                                <option value="Full-time" <?= $filter_type == 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                                <option value="Part-time" <?= $filter_type == 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                                <option value="Contract" <?= $filter_type == 'Contract' ? 'selected' : '' ?>>Contract</option>
                                <option value="Internship" <?= $filter_type == 'Internship' ? 'selected' : '' ?>>Internship</option>
                            </select>
                            <span class="select-chevron">▼</span>
                        </div>

                        <div class="filter-select-box">
                            <select name="mode" class="filter-select-input" onchange="this.form.submit()">
                                <option value="">All Work Modes</option>
                                <option value="Remote" <?= $filter_mode == 'Remote' ? 'selected' : '' ?>>Remote</option>
                                <option value="On-site" <?= $filter_mode == 'On-site' ? 'selected' : '' ?>>On-site</option>
                                <option value="Hybrid" <?= $filter_mode == 'Hybrid' ? 'selected' : '' ?>>Hybrid</option>
                            </select>
                            <span class="select-chevron">▼</span>
                        </div>

                        <div class="filter-select-box">
                            <select name="min_salary" class="filter-select-input" onchange="this.form.submit()">
                                <option value="">Any Salary Range</option>
                                <option value="3000" <?= $filter_min_salary == 3000 ? 'selected' : '' ?>>RM 3k+ / mo</option>
                                <option value="5000" <?= $filter_min_salary == 5000 ? 'selected' : '' ?>>RM 5k+ / mo</option>
                                <option value="8000" <?= $filter_min_salary == 8000 ? 'selected' : '' ?>>RM 8k+ / mo</option>
                                <option value="12000" <?= $filter_min_salary == 12000 ? 'selected' : '' ?>>RM 12k+ / mo</option>
                            </select>
                            <span class="select-chevron">▼</span>
                        </div>

                        <div class="filter-select-box">
                            <select name="sort" class="filter-select-input" onchange="this.form.submit()">
                                <option value="newest" <?= $sort_by == 'newest' ? 'selected' : '' ?>>⇄ Newest First</option>
                                <option value="salary_high" <?= $sort_by == 'salary_high' ? 'selected' : '' ?>>💰 Highest Salary</option>
                                <option value="salary_low" <?= $sort_by == 'salary_low' ? 'selected' : '' ?>>💵 Lowest Salary</option>
                            </select>
                            <span class="select-chevron">▼</span>
                        </div>
                    </div>

                    <!-- Quick Filter Chips Row -->
                    <div class="quick-filter-pills-row">
                        <button type="button" onclick="applyQuickPill('reset', '')" class="quick-pill-btn <?= (!$filter_mode && !$filter_type && !$search && !$filter_location && !$filter_min_salary) ? 'active' : '' ?>">
                            All Positions
                        </button>
                        <button type="button" onclick="applyQuickPill('mode', 'Remote')" class="quick-pill-btn <?= $filter_mode === 'Remote' ? 'active' : '' ?>">
                            ⚡ Remote Only
                        </button>
                        <button type="button" onclick="applyQuickPill('search', 'Junior')" class="quick-pill-btn <?= str_contains(strtolower($search), 'junior') ? 'active' : '' ?>">
                            🎓 Fresh Grad Friendly
                        </button>
                        <button type="button" onclick="applyQuickPill('min_salary', '5000')" class="quick-pill-btn <?= $filter_min_salary == 5000 ? 'active' : '' ?>">
                            💰 High Salary (RM 5k+)
                        </button>
                        <button type="button" onclick="applyQuickPill('mode', 'Hybrid')" class="quick-pill-btn <?= $filter_mode === 'Hybrid' ? 'active' : '' ?>">
                            🏢 Hybrid Work
                        </button>
                        <button type="button" onclick="applyQuickPill('location', 'Kuala Lumpur')" class="quick-pill-btn <?= str_contains(strtolower($filter_location), 'kuala') ? 'active' : '' ?>">
                            📍 Kuala Lumpur
                        </button>
                    </div>

                </form>
            </div>

            <!-- Right Banner Doodle Area over Mascots -->
            <div class="banner-right-doodle-box">
                <div class="banner-doodle-text">
                    good<br>jobs<br>await! ☺
                    <svg class="banner-doodle-sparks" viewBox="0 0 30 30">
                        <path d="M6 14 L2 14 M8 8 L4 4 M14 6 L14 2 M20 8 L24 4 M22 14 L26 14" stroke="#0A0A0A" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </div>
            </div>

        </div>
    </section>

    <!-- ================= 3. MAIN EXPLORER: 2-COLUMN SPLIT PANE ================= -->
    <main class="keria-main-container">
        
        <?php if(empty($jobs)): ?>
            <div style="background:#FFFFFF; border:1px solid #E5E7EB; border-radius:20px; text-align:center; padding:70px 20px; color:#6B7280;">
                <div style="font-size:42px; margin-bottom:12px;">🔍</div>
                <div style="font-size:20px; font-weight:800; color:#0A0A0A; margin-bottom:6px;">No matching positions found</div>
                <div style="font-size:14px; margin-bottom:20px;">Try adjusting your keywords, salary, or work mode filters.</div>
                <a href="jobs.php" class="btn-search-find" style="display:inline-block; text-decoration:none;">Reset All Filters</a>
            </div>
        <?php else: ?>
            <div class="split-layout-grid">
                
                <!-- Left Column: Job Cards List -->
                <div class="job-cards-list-pane">
                    
                    <div class="active-positions-header">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M17 8C8 10 5.9 16.17 3.82 21.34L5.71 22l1-2.3A9.49 9.49 0 0 0 12 21c6.63 0 10-6.37 10-13 0-1.1-.9-2-2-2h-3zm-1 3.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/>
                        </svg>
                        <span><?= count($jobs) ?> Active Positions</span>
                    </div>

                    <?php foreach($jobs as $index => $j): 
                        $is_applied = in_array($j['id'], $applied_job_ids);
                        $sc = $candidate_scores[$j['id']] ?? null;
                        $has_score = !empty($sc);
                        $score_num = $has_score ? (int)$sc['overall_score'] : 0;
                        
                        // Score bar colors
                        $bar_gradient = $score_num >= 70 ? 'linear-gradient(90deg, #A3E635, #22C55E)' : ($score_num >= 50 ? 'linear-gradient(90deg, #FBBF24, #F59E0B)' : 'linear-gradient(90deg, #F87171, #EF4444)');
                        $score_text_color = $score_num >= 70 ? '#15803D' : ($score_num >= 50 ? '#D97706' : '#DC2626');

                        $logo_src = !empty($j['company_logo']) ? htmlspecialchars($j['company_logo']) : '';
                    ?>
                        <div class="job-card-box <?= $index === 0 ? 'selected-card' : '' ?>" id="card_<?= $j['id'] ?>" onclick="selectJob(<?= $j['id'] ?>)">
                            <div class="card-top-row">
                                <div class="card-title-group">
                                    <div class="card-title-line">
                                        <span class="card-job-title"><?= htmlspecialchars($j['job_title']) ?></span>
                                        <span class="badge-posted-ago"><?= htmlspecialchars($j['posted_label']) ?></span>
                                    </div>
                                    <div class="card-company-meta">
                                        <strong><?= htmlspecialchars($j['employer_name'] ?? 'Keria Employer') ?></strong>
                                        <span class="card-meta-dot">&bull;</span>
                                        <span>📍 <?= htmlspecialchars($j['location'] ?: ($j['department'] ?: 'Kuala Lumpur')) ?></span>
                                        <?php if(!empty($j['department'])): ?>
                                            <span class="card-meta-dot">&bull;</span>
                                            <span><?= htmlspecialchars($j['department']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card-logo-container">
                                    <?php if($logo_src): ?>
                                        <img src="<?= $logo_src ?>" alt="" class="card-logo-img">
                                    <?php else: ?>
                                        <svg viewBox="0 0 40 40" width="30" height="30" fill="none">
                                            <rect width="40" height="40" rx="8" fill="#F3F4F6"/>
                                            <path d="M12 28V14L20 10L28 14V28H12Z" fill="#93C5FD" stroke="#3B82F6" stroke-width="1.5"/>
                                            <rect x="16" y="18" width="3" height="3" fill="#1D4ED8"/>
                                            <rect x="21" y="18" width="3" height="3" fill="#1D4ED8"/>
                                            <rect x="16" y="23" width="3" height="3" fill="#1D4ED8"/>
                                            <rect x="21" y="23" width="3" height="3" fill="#1D4ED8"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="card-tags-row">
                                <span class="card-pill-tag"><?= htmlspecialchars($j['employment_type'] ?? 'Full-time') ?></span>
                                <span class="card-pill-tag"><?= htmlspecialchars($j['work_mode'] ?? 'On-site') ?></span>
                                <?php if($is_applied): ?>
                                    <span class="card-pill-tag" style="background:#DCFCE7; color:#166534; border-color:#BBF7D0; font-weight:700;">✓ Applied</span>
                                <?php endif; ?>
                            </div>

                            <?php if($has_score): ?>
                                <div class="card-ai-match-row">
                                    <div class="ai-match-label-group">
                                        <span>🎴</span>
                                        <span>AI Match</span>
                                    </div>
                                    <div class="ai-progress-track">
                                        <div class="ai-progress-fill" style="width: <?= $score_num ?>%; background: <?= $bar_gradient ?>;"></div>
                                    </div>
                                    <span class="ai-score-pct" style="color: <?= $score_text_color ?>;"><?= $score_num ?>%</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                </div>

                <!-- Right Column: Sticky Job Details Preview -->
                <div class="job-detail-sticky-pane">
                    <div class="job-detail-card-inner" id="jobDetailPreview">
                        <!-- Loaded dynamically via JS -->
                    </div>
                </div>

            </div>
        <?php endif; ?>

    </main>

    <!-- Mobile Job Details Modal Popup -->
    <div id="mobileJobModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); backdrop-filter:blur(6px); z-index:99999; align-items:center; justify-content:center; padding:16px;">
        <div style="background:#FFFFFF; border:1px solid #E5E7EB; border-radius:24px; max-width:600px; width:100%; max-height:88vh; overflow-y:auto; padding:28px; position:relative; box-shadow:0 20px 50px rgba(0,0,0,0.25);">
            <button type="button" onclick="closeMobileJobModal()" style="position:absolute; top:16px; right:16px; background:#F3F4F6; border:1px solid #E5E7EB; color:#0A0A0A; width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer; font-weight:800; font-size:14px; z-index:10;">✕</button>
            <div id="mobileJobModalContent"></div>
        </div>
    </div>

    <!-- Embedded Data for Instant Client-Side Switching -->
    <script>
        const jobsData = <?= json_encode(array_values($jobs), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const candidateScores = <?= json_encode($candidate_scores, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const appliedJobIds = <?= json_encode($applied_job_ids, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const userRole = <?= json_encode($_SESSION['user_role'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        let savedJobIds = JSON.parse(localStorage.getItem('keria_saved_jobs') || '[]');

        function showKeriaToast(msg) {
            const existing = document.querySelector('.keria-toast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.className = 'keria-toast';
            toast.innerHTML = `<span>🌿</span> <span>${escapeHtml(msg)}</span>`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-10px)';
                toast.style.transition = 'all 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 2600);
        }

        function toggleSaveJob(jobId) {
            jobId = parseInt(jobId);
            const isSavedNow = savedJobIds.includes(jobId);
            if (isSavedNow) {
                savedJobIds = savedJobIds.filter(id => id !== jobId);
                showKeriaToast('Job removed from saved bookmarks');
            } else {
                savedJobIds.push(jobId);
                showKeriaToast('Job saved to your bookmarks!');
            }
            localStorage.setItem('keria_saved_jobs', JSON.stringify(savedJobIds));

            const saveBtn = document.getElementById('saveBtn_' + jobId);
            if (saveBtn) {
                const isSaved = savedJobIds.includes(jobId);
                saveBtn.classList.toggle('is-saved', isSaved);
                saveBtn.innerHTML = isSaved ? '✓ Saved' : '🔖 Save Job';
            }

            const headerSaveIcon = document.getElementById('headerSaveIcon_' + jobId);
            if (headerSaveIcon) {
                headerSaveIcon.classList.toggle('is-saved', savedJobIds.includes(jobId));
            }
        }

        function shareJob(jobId, jobTitle) {
            const shareUrl = window.location.origin + window.location.pathname + '?id=' + jobId;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(shareUrl).then(() => {
                    showKeriaToast('Share link copied to clipboard!');
                }).catch(() => {
                    prompt('Copy this link to share job:', shareUrl);
                });
            } else {
                prompt('Copy this link to share job:', shareUrl);
            }
        }

        function formatSalary(job) {
            if (job.salary_text && job.salary_text.trim()) {
                return escapeHtml(job.salary_text.trim());
            }
            const min = job.salary_min ? parseInt(job.salary_min) : null;
            const max = job.salary_max ? parseInt(job.salary_max) : null;
            if (min && max) {
                return `RM ${min.toLocaleString()} – RM ${max.toLocaleString()} / month`;
            } else if (min) {
                return `From RM ${min.toLocaleString()} / month`;
            } else if (max) {
                return `Up to RM ${max.toLocaleString()} / month`;
            }
            return 'Salary Undisclosed';
        }

        function parseResponsibilities(job) {
            if (job.responsibilities && job.responsibilities.trim()) {
                const lines = job.responsibilities.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                return lines.map(l => `<li>${escapeHtml(l.replace(/^[•\-\*]\s*/, ''))}</li>`).join('');
            }
            return `
                <li>Design, configure and maintain network systems and infrastructure</li>
                <li>Monitor network performance and troubleshoot issues</li>
                <li>Implement security measures and ensure network reliability</li>
            `;
        }

        function parseRequirements(job) {
            if (job.requirements && job.requirements.trim()) {
                const lines = job.requirements.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                return lines.map(l => `<li>${escapeHtml(l.replace(/^[•\-\*]\s*/, ''))}</li>`).join('');
            }
            return `
                <li>Bachelor's degree in Computer Science, IT, or related field</li>
                <li>Experience with routing protocols, firewall management, and cloud infrastructure</li>
                <li>Strong analytical and problem-solving mindset</li>
            `;
        }

        function selectJob(jobId) {
            const job = jobsData.find(j => j.id == jobId);
            if (!job) return;

            document.querySelectorAll('.job-card-box').forEach(c => c.classList.remove('selected-card'));
            const selectedCard = document.getElementById('card_' + jobId);
            if (selectedCard) selectedCard.classList.add('selected-card');

            const isApplied = appliedJobIds.includes(job.id);
            const isSaved = savedJobIds.includes(parseInt(job.id));
            const scoreData = candidateScores[job.id] || null;

            const salaryText = formatSalary(job);
            const locationText = job.location ? escapeHtml(job.location) : (escapeHtml(job.department || 'Kuala Lumpur'));
            const deptText = job.department ? escapeHtml(job.department) : 'Engineering';
            const empName = job.employer_name ? escapeHtml(job.employer_name) : 'Keria Employer';

            // AI Match Breakdown Card
            let aiScoreHtml = '';
            if (scoreData) {
                const scoreNum = parseInt(scoreData.overall_score || 0);
                const scoreColor = scoreNum >= 70 ? '#15803D' : (scoreNum >= 50 ? '#D97706' : '#DC2626');
                const scoreBg = scoreNum >= 70 ? 'linear-gradient(90deg, #A3E635, #22C55E)' : (scoreNum >= 50 ? 'linear-gradient(90deg, #FBBF24, #F59E0B)' : 'linear-gradient(90deg, #F87171, #EF4444)');

                const skillsScore = parseInt(scoreData.skills_match || 85);
                const expScore = parseInt(scoreData.exp_match || 90);
                const eduScore = parseInt(scoreData.edu_match || 80);

                let parsedSkills = scoreData.parsed_skills;
                if (typeof parsedSkills === 'string') {
                    try { parsedSkills = JSON.parse(parsedSkills); } catch(e) {}
                }
                let parsedStrengths = scoreData.parsed_strengths;
                if (typeof parsedStrengths === 'string') {
                    try { parsedStrengths = JSON.parse(parsedStrengths); } catch(e) {}
                }
                let parsedGaps = scoreData.parsed_gaps;
                if (typeof parsedGaps === 'string') {
                    try { parsedGaps = JSON.parse(parsedGaps); } catch(e) {}
                }

                let matchedList = [];
                if (Array.isArray(parsedSkills) && parsedSkills.length > 0) {
                    matchedList = parsedSkills;
                } else if (Array.isArray(parsedStrengths) && parsedStrengths.length > 0) {
                    matchedList = parsedStrengths;
                } else {
                    matchedList = [
                        (job.department ? job.department + ' Core Stack' : 'Relevant Skill Stack'),
                        'Professional Experience',
                        'Problem Solving'
                    ];
                }
                const matchedPills = matchedList.map(s => `<span class="overlap-pill-matched">✓ ${escapeHtml(s)}</span>`).join('');

                let missingList = [];
                if (Array.isArray(parsedGaps) && parsedGaps.length > 0) {
                    missingList = parsedGaps;
                } else {
                    missingList = ['AI Enhancement Recommendations'];
                }
                const missingPills = missingList.map(s => `<span class="overlap-pill-missing">⚡ ${escapeHtml(s)}</span>`).join('');

                aiScoreHtml = `
                    <div class="ai-eval-breakdown-card">
                        <div class="ai-eval-header-row">
                            <div class="ai-eval-title-block">
                                <div class="ai-eval-title-line">
                                    <span>🎴</span>
                                    <span>AI Match Evaluation Breakdown</span>
                                </div>
                                <div class="ai-eval-subtitle">
                                    Personalized Candidate Fit Analysis
                                </div>
                            </div>
                            <div class="ai-eval-score-block">
                                <span class="ai-eval-score-num" style="color:${scoreColor};">${scoreNum}%</span>
                                <div>
                                    <span class="ai-eval-score-label">MATCH SCORE</span>
                                    <span class="ai-eval-info-icon" title="Evaluated against candidate skills, verified background & qualifications">ⓘ</span>
                                </div>
                            </div>
                        </div>

                        <div class="ai-eval-progress-bar">
                            <div class="ai-eval-progress-fill" style="width:${scoreNum}%; background:${scoreBg};"></div>
                        </div>

                        <div class="ai-eval-metrics-grid">
                            <div class="ai-metric-item-card">
                                <div class="ai-metric-title">🎯 Skill Match</div>
                                <div class="ai-metric-val" style="color:${skillsScore >= 70 ? '#15803D' : (skillsScore >= 50 ? '#D97706' : '#DC2626')};">${skillsScore}%</div>
                            </div>
                            <div class="ai-metric-item-card">
                                <div class="ai-metric-title">💼 Experience Fit</div>
                                <div class="ai-metric-val" style="color:${expScore >= 70 ? '#15803D' : (expScore >= 50 ? '#D97706' : '#DC2626')};">${expScore}%</div>
                            </div>
                            <div class="ai-metric-item-card">
                                <div class="ai-metric-title">🎓 Education Fit</div>
                                <div class="ai-metric-val" style="color:${eduScore >= 70 ? '#15803D' : (eduScore >= 50 ? '#D97706' : '#DC2626')};">${eduScore}%</div>
                            </div>
                        </div>

                        <div class="ai-overlap-section">
                            <div class="ai-overlap-heading">Keywords & Skill Overlap</div>
                            <div class="ai-overlap-pills-wrap">
                                ${matchedPills}
                                ${missingPills}
                            </div>
                        </div>

                        ${scoreData.summary ? `
                            <div style="font-size:12.5px; color:#4B5563; line-height:1.55; background:#FFFFFF; border:1px solid #E5E7EB; padding:12px 14px; border-radius:12px; border-left:3px solid ${scoreColor}; margin-top:14px;">
                                <strong>Fit Summary:</strong> ${escapeHtml(scoreData.summary)}
                            </div>
                        ` : ''}
                    </div>
                `;
            } else if (userRole === 'candidate') {
                aiScoreHtml = `
                    <div style="background:linear-gradient(135deg, rgba(210, 255, 58, 0.12), rgba(59, 130, 246, 0.06)); border:1px solid rgba(132, 204, 22, 0.3); border-radius:18px; padding:18px 20px; margin-bottom:24px;">
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                            <div style="display:flex; align-items:center; gap:12px;">
                                <div style="font-size:26px;">🤖</div>
                                <div>
                                    <div style="font-size:14px; font-weight:800; color:#0A0A0A;">AI Resume Match Evaluator</div>
                                    <div style="font-size:12px; color:#4B5563; margin-top:2px;">Click Apply for Position to submit your resume and unlock instant Gemini AI match scoring!</div>
                                </div>
                            </div>
                            <a href="resume_builder.php" class="btn-search-find" style="padding:8px 14px; font-size:12px; text-decoration:none; white-space:nowrap;">📝 AI Resume Builder</a>
                        </div>
                    </div>
                `;
            }

            // Company Culture Gallery
            let galleryHtml = '';
            if (job.company_media && Array.isArray(job.company_media) && job.company_media.length > 0) {
                const tiles = job.company_media.map((m, idx) => {
                    const spanStyle = (idx === 0 && job.company_media.length > 2) ? 'grid-column: span 2; grid-row: span 2;' : '';
                    return `
                        <a href="${escapeHtml(m.file_path)}" target="_blank" rel="noopener" class="company-gallery-tile" style="${spanStyle}" title="View image">
                            <img src="${escapeHtml(m.file_path)}" alt="Company Photo" class="company-gallery-img">
                        </a>
                    `;
                }).join('');

                galleryHtml = `
                    <div class="company-gallery-section">
                        <div class="job-desc-header-row" style="margin-bottom: 14px;">
                            <div class="job-desc-main-title">
                                <span>🏢</span>
                                <span>Company Culture & Gallery</span>
                            </div>
                            <span class="job-desc-subtext">Photos from the workplace, team and office environment.</span>
                        </div>
                        <div class="company-gallery-grid">
                            ${tiles}
                        </div>
                    </div>
                `;
            }

            const detailHtml = `
                <!-- Top Bar: Title & Action Icons -->
                <div class="detail-top-bar">
                    <h2 class="detail-job-title">${escapeHtml(job.job_title)}</h2>
                    <div class="detail-action-buttons">
                        <button type="button" class="btn-circle-action ${isSaved ? 'is-saved' : ''}" id="headerSaveIcon_${job.id}" onclick="toggleSaveJob(${job.id})" title="Save Job">
                            🔖
                        </button>
                        <button type="button" class="btn-circle-action" onclick="shareJob(${job.id}, '${escapeHtml(job.job_title)}')" title="Share Job">
                            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="18" cy="5" r="3"></circle>
                                <circle cx="6" cy="12" r="3"></circle>
                                <circle cx="18" cy="19" r="3"></circle>
                                <line x1="8.59" y1="13.51" x2="15.42" y2="17.49"></line>
                                <line x1="15.41" y1="6.51" x2="8.59" y2="10.49"></line>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Company & Location Subline -->
                <div class="detail-company-subline">
                    <strong>${empName}</strong>
                    <span class="card-meta-dot">&bull;</span>
                    <span>📍 ${locationText}</span>
                    <span class="card-meta-dot">&bull;</span>
                    <span>${deptText}</span>
                    <span class="card-meta-dot">&bull;</span>
                    <span class="badge-posted-ago">${escapeHtml(job.posted_label || 'Posted 1 day ago')}</span>
                </div>

                <!-- Salary Display -->
                <div class="detail-salary-text" style="${salaryText === 'Salary Undisclosed' ? 'color:#6B7280; font-size:16px; font-weight:700;' : ''}">
                    <span>💰</span>
                    <span>${salaryText}</span>
                </div>

                <!-- Meta Pills -->
                <div class="detail-meta-pills-row">
                    <span class="detail-meta-pill">📋 ${escapeHtml(job.employment_type || 'Contract')}</span>
                    <span class="detail-meta-pill">🏢 ${escapeHtml(job.work_mode || 'On-site')}</span>
                    <span class="detail-meta-pill active-status">🟢 Active Position</span>
                </div>

                <!-- Action Buttons Row -->
                <div class="detail-apply-row">
                    ${isApplied ? `
                        <button type="button" disabled class="btn-detail-applied">
                            ✓ Applied for Position
                        </button>
                    ` : `
                        <a href="apply.php?job_id=${job.id}" class="btn-detail-apply">
                            Apply for Position &rarr;
                        </a>
                    `}
                    <button type="button" id="saveBtn_${job.id}" class="btn-detail-save ${isSaved ? 'is-saved' : ''}" onclick="toggleSaveJob(${job.id})">
                        ${isSaved ? '✓ Saved' : '🔖 Save Job'}
                    </button>
                </div>

                <!-- AI Match Breakdown Card -->
                ${aiScoreHtml}

                <!-- Job Description Section -->
                <div class="job-desc-section-wrapper">
                    <div class="job-desc-header-row">
                        <div class="job-desc-main-title">
                            <span>📄</span>
                            <span>Job Description</span>
                        </div>
                        <span class="job-desc-subtext">Key details, responsibilities and requirements for this role.</span>
                    </div>

                    <p class="job-desc-paragraph">${escapeHtml(job.description || 'We are looking for a motivated professional to join our team. You will be responsible for executing key deliverables, collaborating across teams, and contributing to company growth.')}</p>

                    <div>
                        <h4 class="job-spec-block-title">Key Responsibilities</h4>
                        <ul class="job-bullet-list">
                            ${parseResponsibilities(job)}
                        </ul>
                    </div>

                    <div>
                        <h4 class="job-spec-block-title">Requirements & Qualifications</h4>
                        <ul class="job-bullet-list">
                            ${parseRequirements(job)}
                        </ul>
                    </div>
                </div>

                <!-- Company Culture Gallery (Under Job Description) -->
                ${galleryHtml}
            `;

            const preview = document.getElementById('jobDetailPreview');
            const mobileContent = document.getElementById('mobileJobModalContent');
            const mobileModal = document.getElementById('mobileJobModal');

            if (preview) preview.innerHTML = detailHtml;

            if (window.innerWidth <= 1080) {
                if (mobileContent && mobileModal) {
                    mobileContent.innerHTML = detailHtml;
                    mobileModal.style.display = 'flex';
                }
            }
        }

        function applyQuickPill(name, value) {
            const form = document.getElementById('jobsSearchForm');
            if (!form) return;

            if (name === 'reset') {
                const s = form.querySelector('input[name="search"]');
                const l = form.querySelector('input[name="location"]');
                if (s) s.value = '';
                if (l) l.value = '';
                form.querySelectorAll('select').forEach(sel => sel.selectedIndex = 0);
                form.submit();
                return;
            }

            const targetInput = form.querySelector(`[name="${name}"]`);
            if (targetInput) {
                if (targetInput.value === value) {
                    targetInput.value = '';
                } else {
                    targetInput.value = value;
                }
            }
            form.submit();
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
            if (dropdown) dropdown.classList.toggle('show');
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

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const targetId = urlParams.get('id');
            let initialJobId = null;

            if (targetId && jobsData.some(j => j.id == targetId)) {
                initialJobId = targetId;
            } else if (Array.isArray(jobsData) && jobsData.length > 0) {
                initialJobId = jobsData[0].id;
            }

            if (initialJobId) {
                selectJob(initialJobId);
            }
        });
    </script>
</body>
</html>
