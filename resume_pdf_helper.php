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

    $full_name = trim($gen['full_name'] ?? ($raw['full_name'] ?? ($user['name'] ?? ($build['candidate_name'] ?? ($build['user_name'] ?? 'Candidate')))));
    if ($full_name === '') $full_name = 'Candidate';

    $target_title = trim($gen['target_title'] ?? ($raw['target_title'] ?? ($build['target_title'] ?? 'Professional Resume')));
    if ($target_title === '') $target_title = 'Professional Resume';

    $email = trim($gen['contact_email'] ?? ($raw['contact_email'] ?? ($user['email'] ?? ($build['candidate_email'] ?? ($build['user_email'] ?? '')))));
    $phone = trim($gen['contact_phone'] ?? ($raw['contact_phone'] ?? ($user['phone'] ?? ($build['phone'] ?? ''))));
    $location = trim($gen['location'] ?? ($raw['location'] ?? ''));

    $is_fresh_grad = !empty($raw['is_fresh_grad']) || !empty($gen['is_fresh_grad']);

    $summary = trim($gen['summary'] ?? ($raw['summary'] ?? ''));
    $experience = $gen['experience'] ?? ($raw['experience'] ?? []);
    $education = $gen['education'] ?? ($raw['education'] ?? []);
    $skills = $gen['skills'] ?? ($raw['skills'] ?? []);
    $achievements = $gen['achievements'] ?? ($raw['achievements'] ?? []);

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
    $html = render_resume_builder_html($build, $user);

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Helvetica');

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

    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    echo $pdf_content;
    exit;
}
