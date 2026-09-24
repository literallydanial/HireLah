<?php
/**
 * Helper library for generating and streaming PDF resumes from AI Resume Builder builds.
 * Uses Dompdf to render clean, ATS-compliant, beautifully styled PDF documents.
 */

require_once __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Safely parse and normalize resume builder data from raw/generated JSON or DB arrays.
 */
function get_resume_builder_data($build, $user = null) {
    $gen = [];
    if (!empty($build['generated_content'])) {
        $gen = is_array($build['generated_content']) ? $build['generated_content'] : (json_decode($build['generated_content'], true) ?: []);
    }
    $raw = [];
    if (!empty($build['raw_input'])) {
        $raw = is_array($build['raw_input']) ? $build['raw_input'] : (json_decode($build['raw_input'], true) ?: []);
    }

    $full_name = trim($gen['full_name'] ?? ($raw['full_name'] ?? ($user['name'] ?? ($build['candidate_name'] ?? ($build['user_name'] ?? ($build['full_name'] ?? 'Candidate'))))));
    if ($full_name === '') $full_name = 'Candidate';

    $target_title = trim($gen['target_title'] ?? ($raw['target_title'] ?? ($build['target_title'] ?? 'Professional Resume')));
    if ($target_title === '') $target_title = 'Professional Resume';

    $email = trim($gen['contact_email'] ?? ($raw['contact_email'] ?? ($user['email'] ?? ($build['candidate_email'] ?? ($build['user_email'] ?? ($build['email'] ?? ''))))));
    $phone = trim($gen['contact_phone'] ?? ($raw['contact_phone'] ?? ($user['phone'] ?? ($build['phone'] ?? ''))));
    $location = trim($gen['location'] ?? ($raw['location'] ?? ($build['location'] ?? '')));

    $is_fresh_grad = !empty($raw['is_fresh_grad']) || !empty($gen['is_fresh_grad']) || !empty($build['is_fresh_grad']);

    $summary = trim($gen['summary'] ?? ($raw['summary'] ?? ($build['summary'] ?? '')));

    $experience = $gen['experience'] ?? ($raw['experience'] ?? ($build['experience'] ?? []));
    if (is_string($experience)) $experience = json_decode($experience, true) ?: [];

    $education = $gen['education'] ?? ($raw['education'] ?? ($build['education'] ?? []));
    if (is_string($education)) $education = json_decode($education, true) ?: [];

    $skills = $gen['skills'] ?? ($raw['skills'] ?? ($build['skills'] ?? []));
    if (is_string($skills)) {
        $decoded = json_decode($skills, true);
        if (is_array($decoded)) $skills = $decoded;
    }

    $achievements = $gen['achievements'] ?? ($raw['achievements'] ?? ($build['achievements'] ?? []));
    if (is_string($achievements)) $achievements = json_decode($achievements, true) ?: [];

    return [
        'full_name' => $full_name,
        'target_title' => $target_title,
        'email' => $email,
        'phone' => $phone,
        'location' => $location,
        'is_fresh_grad' => $is_fresh_grad,
        'summary' => $summary,
        'experience' => is_array($experience) ? $experience : [],
        'education' => is_array($education) ? $education : [],
        'skills' => $skills,
        'achievements' => is_array($achievements) ? $achievements : [],
        'created_at' => $build['created_at'] ?? date('Y-m-d H:i:s'),
    ];
}

/**
 * Renders the clean, printable HTML structure styled specifically for Dompdf PDF generation.
 */
function render_resume_builder_html($build, $user = null) {
    $data = get_resume_builder_data($build, $user);

    $contacts = [];
    if (!empty($data['email'])) $contacts[] = htmlspecialchars($data['email']);
    if (!empty($data['phone'])) $contacts[] = htmlspecialchars($data['phone']);
    if (!empty($data['location'])) $contacts[] = htmlspecialchars($data['location']);
    $contact_line = implode(' &nbsp;&bull;&nbsp; ', $contacts);

    $skills_list = [];
    if (is_array($data['skills'])) {
        foreach ($data['skills'] as $s) {
            $s = trim($s);
            if ($s !== '') $skills_list[] = htmlspecialchars($s);
        }
    } elseif (is_string($data['skills']) && trim($data['skills']) !== '') {
        $parts = explode(',', $data['skills']);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $skills_list[] = htmlspecialchars($p);
        }
    }

    $exp_html = '';
    if (!empty($data['experience'])) {
        $exp_html .= '<div class="section-title">Work Experience</div>';
        foreach ($data['experience'] as $exp) {
            $role = trim($exp['role'] ?? '');
            $comp = trim($exp['company'] ?? '');
            $title = htmlspecialchars($role . ($role && $comp ? ' — ' : '') . $comp);
            $dur = htmlspecialchars(trim($exp['duration'] ?? ''));

            $exp_html .= '<div class="entry-block">';
            $exp_html .= '<div class="entry-header-table">';
            $exp_html .= '<div class="entry-title">' . ($title ?: 'Experience Entry') . '</div>';
            if ($dur) {
                $exp_html .= '<div class="entry-meta">' . $dur . '</div>';
            }
            $exp_html .= '</div>';

            if (!empty($exp['bullets']) && is_array($exp['bullets'])) {
                $exp_html .= '<ul class="entry-bullets">';
                foreach ($exp['bullets'] as $b) {
                    $b = trim($b);
                    if ($b !== '') $exp_html .= '<li>' . htmlspecialchars($b) . '</li>';
                }
                $exp_html .= '</ul>';
            } elseif (!empty($exp['notes'])) {
                $exp_html .= '<div class="entry-notes">' . nl2br(htmlspecialchars($exp['notes'])) . '</div>';
            }
            $exp_html .= '</div>';
        }
    }

    $edu_html = '';
    if (!empty($data['education'])) {
        $edu_html .= '<div class="section-title">Education & Credentials</div>';
        foreach ($data['education'] as $edu) {
            $deg = trim($edu['degree'] ?? '');
            $sch = trim($edu['school'] ?? '');
            $yr = trim($edu['year'] ?? '');

            $edu_html .= '<div class="entry-block">';
            $edu_html .= '<div class="entry-header-table">';
            $edu_html .= '<div class="entry-title">' . htmlspecialchars($deg ?: 'Degree / Qualification') . '</div>';
            if ($yr) {
                $edu_html .= '<div class="entry-meta">' . htmlspecialchars($yr) . '</div>';
            }
            $edu_html .= '</div>';
            if ($sch) {
                $edu_html .= '<div class="entry-sub">' . htmlspecialchars($sch) . '</div>';
            }
            $edu_html .= '</div>';
        }
    }

    $body_content = '';
    if (!empty($data['summary'])) {
        $body_content .= '<div class="section-title">Professional Summary</div>';
        $body_content .= '<div class="summary-paragraph">' . nl2br(htmlspecialchars($data['summary'])) . '</div>';
    }

    // Fresh graduates highlight education first
    if ($data['is_fresh_grad']) {
        $body_content .= $edu_html . $exp_html;
    } else {
        $body_content .= $exp_html . $edu_html;
    }

    if (!empty($skills_list)) {
        $body_content .= '<div class="section-title">Core Skills & Competencies</div>';

        $total_skills = count($skills_list);
        $max_len = 0;
        foreach ($skills_list as $sk_item) {
            $len = strlen(strip_tags($sk_item));
            if ($len > $max_len) $max_len = $len;
        }

        $cols = 1;
        if ($total_skills >= 6 && $max_len <= 20) {
            $cols = 3;
        } elseif ($total_skills >= 2) {
            $cols = 2;
        }

        if ($cols > 1) {
            $chunk_size = ceil($total_skills / $cols);
            $chunks = array_chunk($skills_list, $chunk_size);
            $col_width = round(100 / count($chunks));

            $body_content .= '<table class="skills-table"><tr>';
            foreach ($chunks as $chunk) {
                $body_content .= '<td style="vertical-align:top; width:' . $col_width . '%;">';
                $body_content .= '<ul class="entry-bullets skills-bullets">';
                foreach ($chunk as $sk) {
                    $body_content .= '<li>' . $sk . '</li>';
                }
                $body_content .= '</ul></td>';
            }
            $body_content .= '</tr></table>';
        } else {
            $body_content .= '<ul class="entry-bullets skills-bullets">';
            foreach ($skills_list as $sk) {
                $body_content .= '<li>' . $sk . '</li>';
            }
            $body_content .= '</ul>';
        }
    }

    if (!empty($data['achievements'])) {
        $body_content .= '<div class="section-title">Key Achievements & Certifications</div>';
        $body_content .= '<ul class="entry-bullets">';
        foreach ($data['achievements'] as $ach) {
            $ach = trim($ach);
            if ($ach !== '') $body_content .= '<li>' . htmlspecialchars($ach) . '</li>';
        }
        $body_content .= '</ul>';
    }

    // Fallback if no structured sections were found
    if (empty($data['summary']) && empty($data['experience']) && empty($data['education'])) {
        $fallback_raw = !empty($build['generated_content']) && is_string($build['generated_content']) 
            ? $build['generated_content'] 
            : (!empty($build['raw_input']) && is_string($build['raw_input']) ? $build['raw_input'] : '');
        if ($fallback_raw) {
            $body_content .= '<div class="section-title">Resume Overview</div>';
            $body_content .= '<div class="summary-paragraph">' . nl2br(htmlspecialchars($fallback_raw)) . '</div>';
        }
    }

    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>' . htmlspecialchars($data['full_name'] . ' - ' . $data['target_title']) . '</title>
<style>
    @page {
        margin: 26px 34px 28px 34px;
        size: A4 portrait;
    }
    * {
        box-sizing: border-box;
    }
    body {
        font-family: Helvetica, Arial, sans-serif;
        color: #1F2421;
        font-size: 10pt;
        line-height: 1.5;
        margin: 0;
        padding: 0;
        background: #FFFFFF;
    }
    .resume-header {
        border-bottom: 2.5px solid #6B8A00;
        padding-bottom: 12px;
        margin-bottom: 14px;
    }
    .candidate-name {
        font-size: 22pt;
        font-weight: bold;
        color: #0E0F12;
        margin: 0 0 3px 0;
        line-height: 1.15;
    }
    .target-title {
        font-size: 12pt;
        font-weight: bold;
        color: #6B8A00;
        margin: 0 0 5px 0;
        letter-spacing: 0.3px;
    }
    .contact-bar {
        font-size: 9pt;
        color: #4B5563;
        margin-top: 3px;
    }
    .section-title {
        font-size: 10.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: #384800;
        border-bottom: 1px solid #D6E4A8;
        padding-bottom: 3px;
        margin-top: 13px;
        margin-bottom: 8px;
    }
    .summary-paragraph {
        font-size: 9.5pt;
        color: #374151;
        line-height: 1.55;
        margin-bottom: 8px;
        text-align: justify;
    }
    .entry-block {
        margin-bottom: 8px;
        page-break-inside: avoid;
    }
    .entry-header-table {
        margin-bottom: 2px;
    }
    .entry-title {
        font-size: 10pt;
        font-weight: bold;
        color: #111827;
        display: inline-block;
    }
    .entry-meta {
        font-size: 8.5pt;
        color: #6B7280;
        float: right;
        margin-top: 1px;
    }
    .entry-sub {
        font-size: 9pt;
        color: #4B5563;
        font-style: italic;
        margin-bottom: 2px;
    }
    .entry-bullets {
        margin: 3px 0 5px 18px;
        padding: 0;
    }
    .entry-bullets li {
        font-size: 9pt;
        color: #374151;
        line-height: 1.45;
        margin-bottom: 2px;
    }
    .entry-notes {
        font-size: 9pt;
        color: #374151;
        line-height: 1.45;
    }
    .skills-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 2px;
        margin-bottom: 6px;
    }
    .skills-table td {
        padding: 0 10px 0 0;
    }
    .skills-bullets {
        margin: 2px 0 4px 18px;
        padding: 0;
    }
    .skills-bullets li {
        font-size: 9pt;
        color: #374151;
        line-height: 1.45;
        margin-bottom: 2px;
    }
    .footer-stamp {
        position: fixed;
        bottom: 0px;
        left: 0;
        right: 0;
        text-align: center;
        font-size: 7.5pt;
        color: #9CA3AF;
        border-top: 1px solid #E5E7EB;
        padding-top: 4px;
    }
</style>
</head>
<body>
    <div class="resume-header">
        <div class="candidate-name">' . htmlspecialchars($data['full_name']) . '</div>
        <div class="target-title">' . htmlspecialchars($data['target_title']) . '</div>
        <div class="contact-bar">' . $contact_line . '</div>
    </div>

    ' . $body_content . '

    <div class="footer-stamp">
        Generated via Keria AI Resume Builder &bull; ' . date('F j, Y', strtotime($data['created_at'])) . '
    </div>
</body>
</html>';

    return $html;
}

/**
 * Builds and returns the binary PDF string using Dompdf.
 */
function generate_resume_builder_pdf($build, $user = null) {
    if (!class_exists('\\DOMDocument')) {
        throw new \RuntimeException("The PHP DOM extension (php-xml) is not installed on this server. Run 'sudo apt-get install php-xml' to enable native PDF rendering.");
    }
    if (!extension_loaded('mbstring') && !function_exists('mb_strlen')) {
        throw new \RuntimeException("The PHP mbstring extension is not installed on this server. Run 'sudo apt-get install php-mbstring' to enable native PDF rendering.");
    }

    $html = render_resume_builder_html($build, $user);

    // Setup guaranteed writable font cache directory inside uploads
    $font_dir = __DIR__ . '/uploads/dompdf_font_cache';
    if (!is_dir($font_dir)) {
        @mkdir($font_dir, 0777, true);
    }
    @chmod($font_dir, 0777);

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');

    if (is_dir($font_dir) && is_writable($font_dir)) {
        $options->setFontDir($font_dir);
        $options->setFontCache($font_dir);
    } else {
        $temp = sys_get_temp_dir();
        $options->setFontDir($temp);
        $options->setFontCache($temp);
    }
    $options->setTempDir(sys_get_temp_dir());
    $options->setChroot([__DIR__, sys_get_temp_dir()]);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

/**
 * Sanitizes a string for use in PDF download filename.
 */
function sanitize_resume_filename($str) {
    $clean = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $str);
    $clean = preg_replace('/\s+/', '_', trim($clean));
    return $clean ?: 'Resume';
}

/**
 * Streams the generated PDF directly to the browser (either inline or download attachment).
 */
function stream_resume_builder_pdf($build, $user = null, $download = false) {
    $data = get_resume_builder_data($build, $user);
    $name_part = sanitize_resume_filename($data['full_name']);
    $title_part = sanitize_resume_filename($data['target_title']);
    $filename = "Resume_{$name_part}_{$title_part}.pdf";

    $pdf_content = generate_resume_builder_pdf($build, $user);

    // Ensure output buffer is clean before sending binary stream
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    echo $pdf_content;
    exit;
}

/**
 * Renders a full-screen, high-fidelity printable resume fallback view.
 * When server-side Dompdf is unavailable or encounters an environment exception,
 * this view provides an immediate, identical document with native 1-click browser Print-to-PDF.
 */
function render_resume_builder_printable_fallback($build, $error_msg = null) {
    $data = get_resume_builder_data($build);

    $contacts = [];
    if (!empty($data['email'])) $contacts[] = htmlspecialchars($data['email']);
    if (!empty($data['phone'])) $contacts[] = htmlspecialchars($data['phone']);
    if (!empty($data['location'])) $contacts[] = htmlspecialchars($data['location']);
    $contact_line = implode(' &nbsp;&bull;&nbsp; ', $contacts);

    $skills_list = [];
    if (is_array($data['skills'])) {
        foreach ($data['skills'] as $s) {
            $s = trim($s);
            if ($s !== '') $skills_list[] = htmlspecialchars($s);
        }
    } elseif (is_string($data['skills']) && trim($data['skills']) !== '') {
        $parts = explode(',', $data['skills']);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') $skills_list[] = htmlspecialchars($p);
        }
    }

    // Clean output buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($data['full_name'] . ' - ' . $data['target_title']) ?> (Resume Document)</title>
    <style>
        * { box-sizing: border-box; }
        body {
            background: #18191E;
            color: #1F2421;
            margin: 0;
            padding: 24px 16px 40px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
        }
        .top-action-bar {
            max-width: 820px;
            margin: 0 auto 16px auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #242730;
            padding: 12px 20px;
            border-radius: 14px;
            border: 1px solid #373C49;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            flex-wrap: wrap;
            gap: 12px;
        }
        .top-action-bar .title {
            color: #FFFFFF;
            font-weight: 800;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .top-action-bar .sub {
            color: #9CA3AF;
            font-size: 11.5px;
            margin-top: 2px;
        }
        .btn-print {
            background: #B4D600;
            color: #0A0A0A;
            font-weight: 800;
            border: none;
            padding: 9px 18px;
            border-radius: 10px;
            font-size: 13px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-print:hover {
            background: #C4E61A;
            transform: translateY(-1px);
        }
        .btn-back {
            background: rgba(255,255,255,0.08);
            color: #E5E7EB;
            border: 1px solid rgba(255,255,255,0.15);
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        .btn-back:hover {
            background: rgba(255,255,255,0.15);
        }
        .resume-sheet {
            background: #FFFFFF;
            max-width: 820px;
            margin: 0 auto;
            padding: 48px 52px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.4);
            border-radius: 14px;
            font-size: 10pt;
            line-height: 1.5;
            color: #1F2421;
        }
        .resume-header {
            border-bottom: 2.5px solid #6B8A00;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }
        .candidate-name {
            font-size: 24pt;
            font-weight: 800;
            color: #0E0F12;
            margin: 0 0 4px 0;
            line-height: 1.15;
            letter-spacing: -0.3px;
        }
        .target-title {
            font-size: 13pt;
            font-weight: 700;
            color: #6B8A00;
            margin: 0 0 6px 0;
            letter-spacing: 0.2px;
        }
        .contact-bar {
            font-size: 9.5pt;
            color: #4B5563;
        }
        .section-title {
            font-size: 11pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #384800;
            border-bottom: 1.5px solid #D6E4A8;
            padding-bottom: 4px;
            margin-top: 18px;
            margin-bottom: 10px;
        }
        .summary-paragraph {
            font-size: 9.5pt;
            color: #374151;
            line-height: 1.6;
            margin-bottom: 10px;
        }
        .entry-block {
            margin-bottom: 12px;
            page-break-inside: avoid;
        }
        .entry-header-table {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 2px;
        }
        .entry-title {
            font-size: 10.5pt;
            font-weight: 700;
            color: #111827;
        }
        .entry-meta {
            font-size: 9pt;
            color: #6B7280;
        }
        .entry-sub {
            font-size: 9.5pt;
            color: #4B5563;
            font-style: italic;
            margin-bottom: 3px;
        }
        .entry-bullets {
            margin: 4px 0 6px 20px;
            padding: 0;
        }
        .entry-bullets li {
            font-size: 9.5pt;
            color: #374151;
            line-height: 1.5;
            margin-bottom: 3px;
        }
        .skills-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 4px 20px;
            margin: 6px 0 10px 20px;
            padding: 0;
        }
        .skills-grid li {
            font-size: 9.5pt;
            color: #374151;
            line-height: 1.45;
        }
        .footer-stamp {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px solid #E5E7EB;
            text-align: center;
            font-size: 8pt;
            color: #9CA3AF;
        }

        @media print {
            .no-print { display: none !important; }
            body { background: #FFFFFF !important; padding: 0 !important; margin: 0 !important; }
            .resume-sheet {
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                max-width: 100% !important;
                margin: 0 !important;
            }
            @page {
                size: A4 portrait;
                margin: 12mm 14mm;
            }
        }
    </style>
</head>
<body>
    <div class="top-action-bar no-print">
        <div>
            <div class="title">📄 <?= htmlspecialchars($data['full_name']) ?> — <?= htmlspecialchars($data['target_title']) ?></div>
            <div class="sub">Generated AI Resume &bull; A4 Document Preview</div>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <button onclick="window.print()" class="btn-print">
                📥 Save as PDF / Print
            </button>
            <a href="admin_resumes.php" class="btn-back">
                ✕ Back to Hub
            </a>
        </div>
    </div>

    <?php if(!empty($error_msg)): ?>
        <div class="no-print" style="max-width:820px; margin:0 auto 14px auto; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.3); border-radius:10px; padding:10px 16px; font-size:12px; color:#F59E0B;">
            ℹ️ Notice for Administrator: Server PDF stream switched to direct printable view (<?= htmlspecialchars($error_msg) ?>). You can view the full document below and click <strong>Save as PDF</strong>.
        </div>
    <?php endif; ?>

    <div class="resume-sheet">
        <div class="resume-header">
            <div class="candidate-name"><?= htmlspecialchars($data['full_name']) ?></div>
            <div class="target-title"><?= htmlspecialchars($data['target_title']) ?></div>
            <div class="contact-bar"><?= $contact_line ?></div>
        </div>

        <?php if(!empty($data['summary'])): ?>
            <div class="section-title">Professional Summary</div>
            <div class="summary-paragraph"><?= nl2br(htmlspecialchars($data['summary'])) ?></div>
        <?php endif; ?>

        <?php
        $exp_block = function() use ($data) {
            if (empty($data['experience'])) return;
            echo '<div class="section-title">Work Experience</div>';
            foreach ($data['experience'] as $exp) {
                $role = trim($exp['role'] ?? '');
                $comp = trim($exp['company'] ?? '');
                $title = htmlspecialchars($role . ($role && $comp ? ' — ' : '') . $comp);
                $dur = htmlspecialchars(trim($exp['duration'] ?? ''));

                echo '<div class="entry-block">';
                echo '<div class="entry-header-table">';
                echo '<div class="entry-title">' . ($title ?: 'Experience Entry') . '</div>';
                if ($dur) echo '<div class="entry-meta">' . $dur . '</div>';
                echo '</div>';

                if (!empty($exp['bullets']) && is_array($exp['bullets'])) {
                    echo '<ul class="entry-bullets">';
                    foreach ($exp['bullets'] as $b) {
                        $b = trim($b);
                        if ($b !== '') echo '<li>' . htmlspecialchars($b) . '</li>';
                    }
                    echo '</ul>';
                } elseif (!empty($exp['notes'])) {
                    echo '<div class="summary-paragraph">' . nl2br(htmlspecialchars($exp['notes'])) . '</div>';
                }
                echo '</div>';
            }
        };

        $edu_block = function() use ($data) {
            if (empty($data['education'])) return;
            echo '<div class="section-title">Education & Credentials</div>';
            foreach ($data['education'] as $edu) {
                $deg = trim($edu['degree'] ?? '');
                $sch = trim($edu['school'] ?? '');
                $yr = trim($edu['year'] ?? '');

                echo '<div class="entry-block">';
                echo '<div class="entry-header-table">';
                echo '<div class="entry-title">' . htmlspecialchars($deg ?: 'Degree / Qualification') . '</div>';
                if ($yr) echo '<div class="entry-meta">' . htmlspecialchars($yr) . '</div>';
                echo '</div>';
                if ($sch) echo '<div class="entry-sub">' . htmlspecialchars($sch) . '</div>';
                echo '</div>';
            }
        };

        if ($data['is_fresh_grad']) {
            $edu_block();
            $exp_block();
        } else {
            $exp_block();
            $edu_block();
        }
        ?>

        <?php if(!empty($skills_list)): ?>
            <div class="section-title">Core Skills & Competencies</div>
            <ul class="skills-grid">
                <?php foreach($skills_list as $sk): ?>
                    <li><?= $sk ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if(!empty($data['achievements'])): ?>
            <div class="section-title">Key Achievements & Certifications</div>
            <ul class="entry-bullets">
                <?php foreach($data['achievements'] as $ach): ?>
                    <?php $ach = trim($ach); if($ach !== ''): ?>
                        <li><?= htmlspecialchars($ach) ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="footer-stamp">
            Generated via Keria AI Resume Builder &bull; <?= date('F j, Y', strtotime($data['created_at'])) ?>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}
