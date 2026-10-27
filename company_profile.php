<?php
session_start();
require_once 'db.php';
require_once 'notifications_helper.php';
require_once 'company_helpers.php';
require_once 'job_description_helper.php';

$userNotifs = (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'candidate') 
    ? getCandidateNotifications($pdo, $_SESSION['user_id']) 
    : ['items' => [], 'unread_count' => 0];
$notifItems = $userNotifs['items'];
$unreadCount = $userNotifs['unread_count'];

// Resolve company to display
$company_id = !empty($_GET['id']) ? (int)$_GET['id'] : (!empty($_GET['company_id']) ? (int)$_GET['company_id'] : null);
$employer_id = !empty($_GET['employer_id']) ? (int)$_GET['employer_id'] : null;
$job_id = !empty($_GET['job_id']) ? (int)$_GET['job_id'] : null;

// Fallback to logged-in employer's active company if no specific parameter given
if (!$company_id && !$employer_id && !$job_id && isset($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'employer') {
    $company_id = get_active_company_id($pdo);
    if (!$company_id) {
        $employer_id = (int)$_SESSION['user_id'];
    }
}

// Fallback to first company in database if still empty (e.g. previewing)
if (!$company_id && !$employer_id && !$job_id) {
    $first_c = $pdo->query("SELECT id FROM companies ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($first_c) {
        $company_id = (int)$first_c;
    }
}

$company = resolve_company_profile($pdo, $company_id, $employer_id, $job_id);

if (!$company) {
    // Graceful fallback dummy company matching design mockup
    $company = [
        'id' => null,
        'name' => 'ABC Technology Sdn. Bhd.',
        'ssm_number' => '202301012345 (1234567-X)',
        'business_type' => 'Private Limited (Sdn. Bhd.)',
        'industry' => 'Information Technology',
        'website' => 'https://www.example.com',
        'contact_email' => 'hr@example.com',
        'contact_number' => '+60 3-1234 5678',
        'address' => 'Cyberjaya, Selangor, Malaysia',
        'company_size' => '11-50 employees',
        'logo' => null,
        'is_verified' => 1
    ];
}

// Normalise defaults for any missing company fields so the profile is always complete
$comp_name = !empty($company['name']) ? $company['name'] : 'ABC Technology Sdn. Bhd.';
$comp_ssm = !empty($company['ssm_number']) ? $company['ssm_number'] : '202301012345 (1234567-X)';
$comp_biz_type = !empty($company['business_type']) ? $company['business_type'] : 'Private Limited (Sdn. Bhd.)';
$comp_industry = !empty($company['industry']) ? $company['industry'] : 'Information Technology';
$comp_website = !empty($company['website']) ? $company['website'] : 'www.example.com';
$comp_email = !empty($company['contact_email']) ? $company['contact_email'] : 'hr@example.com';
$comp_phone = !empty($company['contact_number']) ? $company['contact_number'] : '+60 3-1234 5678';
$comp_address = !empty($company['address']) ? $company['address'] : 'Cyberjaya, Selangor, Malaysia';
$comp_size = !empty($company['company_size']) ? $company['company_size'] : '11-50 employees';
$comp_logo = !empty($company['logo']) ? $company['logo'] : null;

// Clean website url for display
$clean_website_display = preg_replace('#^https?://#i', '', rtrim($comp_website, '/'));
$website_href = preg_match('#^https?://#i', $comp_website) ? $comp_website : 'https://' . $comp_website;

// Fetch active job postings by this employer or company
$active_jobs = [];
try {
    if (!empty($company['id'])) {
        $j_stmt = $pdo->prepare("SELECT * FROM jobs WHERE (company_id = ? OR employer_id = ?) AND (status = 'Active' OR status IS NULL) ORDER BY created_at DESC");
        $j_stmt->execute([$company['id'], $company['created_by'] ?? 0]);
        $active_jobs = $j_stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif (!empty($employer_id)) {
        $j_stmt = $pdo->prepare("SELECT * FROM jobs WHERE employer_id = ? AND (status = 'Active' OR status IS NULL) ORDER BY created_at DESC");
        $j_stmt->execute([$employer_id]);
        $active_jobs = $j_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (\Throwable $t) {
    $active_jobs = [];
}

// Fetch company gallery media if any
$gallery_items = [];
$media_uid = $company['created_by'] ?? $employer_id;
if ($media_uid) {
    try {
        $m_stmt = $pdo->prepare("SELECT * FROM company_media WHERE user_id = ? ORDER BY sort_order ASC, id ASC");
        $m_stmt->execute([(int)$media_uid]);
        $gallery_items = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $t) {}
}

$page_title = htmlspecialchars($comp_name) . " — Employer Company Profile | Keria";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <meta name="description" content="View verified company registration details, SSM registration number, address, contact information, and open job roles for <?= htmlspecialchars($comp_name) ?> on Keria.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">

    <style>
        :root {
            --keria-lime: #D2FF3A;
            --keria-lime-hover: #C2F025;
            --keria-dark: #0A0A0A;
            --font-sans: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            font-family: var(--font-sans);
            background: var(--bg, #F9FAF8);
            color: var(--txt, #0A0A0A);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }

        .profile-page-wrapper {
            max-width: 960px;
            width: 100%;
            margin: 36px auto 60px auto;
            padding: 0 20px;
            box-sizing: border-box;
        }

        .back-nav-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background: var(--card-solid, #FFFFFF);
            border: 1px solid var(--bdr, #E2E8F0);
            border-radius: 10px;
            color: var(--txt, #0A0A0A);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .btn-back-link:hover {
            border-color: var(--txt, #0A0A0A);
            transform: translateX(-2px);
        }

        .page-eyebrow-title {
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: var(--mut, #64748B);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Main Employer Company Profile Card */
        .company-profile-card {
            background: var(--card-solid, #FFFFFF);
            border: 1px solid var(--bdr, #E5E7EB);
            border-radius: 20px;
            padding: 36px 40px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04);
            margin-bottom: 32px;
            position: relative;
        }

        @media (max-width: 640px) {
            .company-profile-card {
                padding: 24px 20px;
                border-radius: 16px;
            }
        }

        /* Card Header */
        .company-header-block {
            display: flex;
            align-items: center;
            gap: 22px;
            padding-bottom: 26px;
            border-bottom: 1px solid var(--bdr, #E5E7EB);
        }

        .company-logo-frame {
            width: 76px;
            height: 76px;
            border-radius: 16px;
            background: var(--surf, #F3F4F6);
            border: 1px solid var(--bdr, #E5E7EB);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
        }

        .company-logo-frame img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #FFFFFF;
        }

        .company-title-info {
            flex: 1;
            min-width: 0;
        }

        .company-primary-name {
            font-size: 24px;
            font-weight: 800;
            color: var(--txt, #0A0A0A);
            margin: 0 0 8px 0;
            line-height: 1.25;
            word-break: break-word;
        }

        @media (max-width: 640px) {
            .company-primary-name {
                font-size: 20px;
            }
            .company-header-block {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
        }

        .company-status-badges-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .verified-employer-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #DCFCE7;
            color: #15803D;
            border: 1px solid #86EFAC;
            border-radius: 9999px;
            padding: 3px 10px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .industry-label-chip {
            font-size: 13px;
            font-weight: 600;
            color: var(--mut, #4B5563);
        }

        /* Structured Key-Value Table */
        .company-details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 0 0;
        }

        .company-details-table tr {
            border-bottom: 1px solid var(--bdr, #F3F4F6);
        }

        .company-details-table tr:last-child {
            border-bottom: none;
        }

        .company-details-table td {
            padding: 15px 0;
            vertical-align: top;
            font-size: 14px;
        }

        .company-details-table .col-label {
            width: 240px;
            color: var(--mut, #4B5563);
            font-weight: 500;
            padding-right: 20px;
        }

        .company-details-table .col-value {
            color: var(--txt, #0A0A0A);
            font-weight: 600;
        }

        @media (max-width: 640px) {
            .company-details-table,
            .company-details-table tbody,
            .company-details-table tr,
            .company-details-table td {
                display: block;
                width: 100%;
            }
            .company-details-table td {
                padding: 6px 0;
            }
            .company-details-table .col-label {
                font-size: 12px;
                color: var(--mut, #6B7280);
                padding-right: 0;
            }
            .company-details-table .col-value {
                font-size: 14px;
                margin-bottom: 12px;
            }
            .company-details-table tr {
                padding: 10px 0;
                border-bottom: 1px solid var(--bdr, #E5E7EB);
            }
        }

        .value-ssm-highlight {
            font-family: var(--font-sans);
            font-weight: 700;
            letter-spacing: 0.02em;
            color: var(--txt, #0A0A0A);
        }

        .comp-link-text {
            color: #2563EB;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .comp-link-text:hover {
            color: #1D4ED8;
            text-decoration: underline;
        }

        /* Disclaimer Footer Note */
        .company-disclaimer-note {
            font-size: 11px;
            color: var(--mut, #9CA3AF);
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--bdr, #E5E7EB);
            line-height: 1.5;
            font-style: italic;
        }

        /* Active Openings Section */
        .jobs-section-heading {
            font-size: 18px;
            font-weight: 800;
            color: var(--txt, #0A0A0A);
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .company-jobs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }

        .company-job-item-card {
            background: var(--card-solid, #FFFFFF);
            border: 1px solid var(--bdr, #E5E7EB);
            border-radius: 14px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .company-job-item-card:hover {
            border-color: var(--txt, #0A0A0A);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
        }

        .job-card-title {
            font-size: 16px;
            font-weight: 800;
            color: var(--txt, #0A0A0A);
            margin-bottom: 6px;
        }

        .job-card-meta {
            font-size: 12.5px;
            color: var(--mut, #6B7280);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .job-card-salary {
            font-size: 13.5px;
            font-weight: 700;
            color: #15803D;
            margin-bottom: 14px;
        }

        .btn-view-job-pill {
            background: var(--dim, #F3F4F6);
            color: var(--txt, #0A0A0A);
            font-size: 12px;
            font-weight: 700;
            padding: 7px 14px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid var(--bdr, #E5E7EB);
            transition: background 0.15s ease;
        }
        .company-job-item-card:hover .btn-view-job-pill {
            background: var(--keria-lime, #D2FF3A);
            border-color: #B5E51B;
        }
    </style>
</head>
<body>

    <!-- Header Navigation Bar -->
    <header style="background:var(--card-solid, #FFFFFF); border-bottom:1px solid var(--bdr, #E5E7EB); position:sticky; top:0; z-index:100;">
        <div style="max-width:1440px; margin:0 auto; padding:12px 20px; display:flex; align-items:center; justify-content:space-between;">
            <div style="display:flex; align-items:center; gap:20px;">
                <a href="index.php" style="display:flex; align-items:center;">
                    <img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="Keria Logo" style="height:34px; object-fit:contain;">
                </a>
                <nav style="display:flex; gap:16px;">
                    <a href="jobs.php" style="color:var(--txt); font-weight:700; text-decoration:none; font-size:13.5px;">📋 Job Board</a>
                    <a href="resume_builder.php" style="color:var(--mut); text-decoration:none; font-size:13.5px;">📝 AI Resume Builder</a>
                    <a href="resume_check.php" style="color:var(--mut); text-decoration:none; font-size:13.5px;">✨ AI Resume Check</a>
                </nav>
            </div>

            <div style="display:flex; align-items:center; gap:12px;">
                <a href="jobs.php" class="btn-secondary" style="padding:7px 14px; font-size:12.5px; text-decoration:none;">&larr; Back to Jobs</a>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="<?= ($_SESSION['user_role'] === 'employer') ? 'employer_dashboard.php' : 'candidate_dashboard.php' ?>" class="btn-primary" style="padding:7px 14px; font-size:12.5px; text-decoration:none; width:auto;">My Dashboard</a>
                <?php else: ?>
                    <a href="login.php" class="btn-primary" style="padding:7px 14px; font-size:12.5px; text-decoration:none; width:auto;">Login</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="profile-page-wrapper">
        
        <!-- Breadcrumb / Eyebrow Header -->
        <div class="back-nav-row">
            <div class="page-eyebrow-title">
                <span>KERIA</span>
                <span>&mdash;</span>
                <span>Employer Company Profile</span>
            </div>

            <a href="jobs.php" class="btn-back-link">
                <span>&larr;</span> <span>Back to Job Listings</span>
            </a>
        </div>

        <!-- Master Company Profile Card -->
        <div class="company-profile-card">
            
            <!-- Card Header: Logo, Name, Verified Badge & Industry -->
            <div class="company-header-block">
                <div class="company-logo-frame">
                    <?php if($comp_logo): ?>
                        <img src="<?= htmlspecialchars($comp_logo) ?>" alt="<?= htmlspecialchars($comp_name) ?>">
                    <?php else: ?>
                        <svg viewBox="0 0 40 40" width="38" height="38" fill="none">
                            <rect width="40" height="40" rx="8" fill="#F3F4F6"/>
                            <path d="M12 28V14L20 10L28 14V28H12Z" fill="#93C5FD" stroke="#3B82F6" stroke-width="1.5"/>
                            <rect x="16" y="18" width="3" height="3" fill="#1D4ED8"/>
                            <rect x="21" y="18" width="3" height="3" fill="#1D4ED8"/>
                            <rect x="16" y="23" width="3" height="3" fill="#1D4ED8"/>
                            <rect x="21" y="23" width="3" height="3" fill="#1D4ED8"/>
                        </svg>
                    <?php endif; ?>
                </div>

                <div class="company-title-info">
                    <h1 class="company-primary-name"><?= htmlspecialchars($comp_name) ?></h1>
                    
                    <div class="company-status-badges-row">
                        <span class="verified-employer-pill">
                            <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            Verified Employer
                        </span>
                        <span class="industry-label-chip"><?= htmlspecialchars($comp_industry) ?></span>
                    </div>
                </div>
            </div>

            <!-- Structured Detail Rows Table -->
            <table class="company-details-table">
                <tbody>
                    <tr>
                        <td class="col-label">Company Name</td>
                        <td class="col-value"><?= htmlspecialchars($comp_name) ?></td>
                    </tr>
                    <tr>
                        <td class="col-label">SSM Registration No.</td>
                        <td class="col-value value-ssm-highlight"><?= htmlspecialchars($comp_ssm) ?></td>
                    </tr>
                    <tr>
                        <td class="col-label">Business Type</td>
                        <td class="col-value"><?= htmlspecialchars($comp_biz_type) ?></td>
                    </tr>
                    <tr>
                        <td class="col-label">Industry</td>
                        <td class="col-value"><?= htmlspecialchars($comp_industry) ?></td>
                    </tr>
                    <tr>
                        <td class="col-label">Company Website</td>
                        <td class="col-value">
                            <a href="<?= htmlspecialchars($website_href) ?>" target="_blank" rel="noopener noreferrer" class="comp-link-text">
                                <?= htmlspecialchars($clean_website_display) ?> ↗
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="col-label">Official Email</td>
                        <td class="col-value">
                            <a href="mailto:<?= htmlspecialchars($comp_email) ?>" class="comp-link-text">
                                <?= htmlspecialchars($comp_email) ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="col-label">Contact Number</td>
                        <td class="col-value">
                            <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $comp_phone)) ?>" class="comp-link-text">
                                <?= htmlspecialchars($comp_phone) ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="col-label">Office Address</td>
                        <td class="col-value"><?= nl2br(htmlspecialchars($comp_address)) ?></td>
                    </tr>
                    <tr>
                        <td class="col-label">Company Size</td>
                        <td class="col-value"><?= htmlspecialchars($comp_size) ?></td>
                    </tr>
                </tbody>
            </table>

            <!-- Illustrative verification note -->
            <div class="company-disclaimer-note">
                Illustrative company details only. Verification badge is a design example, not an actual verification.
            </div>

        </div>

        <!-- Optional: Company Workplace Culture & Photos -->
        <?php if(!empty($gallery_items)): ?>
            <div style="margin-bottom:36px;">
                <div class="jobs-section-heading">
                    <span>🏢 Workplace Culture & Photos</span>
                </div>
                <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:12px;">
                    <?php foreach($gallery_items as $photo): ?>
                        <a href="<?= htmlspecialchars($photo['file_path']) ?>" target="_blank" rel="noopener" style="display:block; height:150px; border-radius:12px; overflow:hidden; border:1px solid var(--bdr);">
                            <img src="<?= htmlspecialchars($photo['file_path']) ?>" alt="Workplace" style="width:100%; height:100%; object-fit:cover;">
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Active Open Vacancies from this Employer -->
        <div>
            <div class="jobs-section-heading">
                <span>Active Job Openings from <?= htmlspecialchars($comp_name) ?></span>
                <span style="font-size:13px; font-weight:600; color:var(--mut);"><?= count($active_jobs) ?> open role<?= count($active_jobs) === 1 ? '' : 's' ?></span>
            </div>

            <?php if(empty($active_jobs)): ?>
                <div style="background:var(--card-solid); border:1px dashed var(--bdr); border-radius:16px; padding:32px 20px; text-align:center; color:var(--mut);">
                    <div style="font-size:32px; margin-bottom:8px;">💼</div>
                    <div style="font-size:15px; font-weight:700; color:var(--txt); margin-bottom:4px;">No active openings right now</div>
                    <div style="font-size:13px;">This employer currently has no other published positions on Keria.</div>
                </div>
            <?php else: ?>
                <div class="company-jobs-grid">
                    <?php foreach($active_jobs as $aj): 
                        $aj_salary = 'Salary Undisclosed';
                        if (!empty($aj['salary_text'])) {
                            $aj_salary = htmlspecialchars($aj['salary_text']);
                        } elseif (!empty($aj['salary_min']) && !empty($aj['salary_max'])) {
                            $aj_unit = job_salary_unit_label($aj['salary_rate_type'] ?? null);
                            $aj_salary = 'RM ' . number_format($aj['salary_min']) . ' – RM ' . number_format($aj['salary_max']) . ' ' . $aj_unit;
                        } elseif (!empty($aj['salary_min'])) {
                            $aj_unit = job_salary_unit_label($aj['salary_rate_type'] ?? null);
                            $aj_salary = 'From RM ' . number_format($aj['salary_min']) . ' ' . $aj_unit;
                        }
                    ?>
                        <a href="jobs.php?id=<?= $aj['id'] ?>" class="company-job-item-card">
                            <div>
                                <div class="job-card-title"><?= htmlspecialchars($aj['job_title']) ?></div>
                                <div class="job-card-meta">
                                    <span>📍 <?= htmlspecialchars($aj['location'] ?: ($aj['department'] ?: 'Kuala Lumpur')) ?></span>
                                    <span>&bull;</span>
                                    <span><?= htmlspecialchars($aj['employment_type'] ?: 'Full-time') ?></span>
                                    <span>&bull;</span>
                                    <span><?= htmlspecialchars($aj['work_mode'] ?: 'On-site') ?></span>
                                </div>
                                <div class="job-card-salary">
                                    <span>💰</span> <?= $aj_salary ?>
                                </div>
                            </div>
                            <div class="btn-view-job-pill">
                                View Position & Apply &rarr;
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Footer -->
    <footer style="margin-top:auto; background:var(--card-solid); border-top:1px solid var(--bdr); padding:24px 20px; text-align:center; font-size:13px; color:var(--mut);">
        <p style="margin:0 0 6px 0;">&copy; <?= date('Y') ?> Keria &bull; Connecting talent with verified Malaysian employers.</p>
        <p style="margin:0; font-size:12px; opacity:0.8;">Illustrative company details only. Verification badge is a design example, not an actual verification.</p>
    </footer>

    <script src="theme.js"></script>
</body>
</html>
