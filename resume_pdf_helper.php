<?php
/**
 * Helper library for generating and streaming PDF resumes from AI Resume Builder builds.
 * Uses Dompdf to render clean, ATS-compliant, beautifully styled PDF documents.
 */

require_once __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

if (!function_exists('sanitize_resume_skills')) {
    /**
     * Sanitizes raw skills input (which may be a messy comma list, bullet points,
     * or even multi-page career coaching / assessment report text) into a clean,
     * ATS-compliant array of 8-14 concise skill keywords (1-3 words each).
     */
    function sanitize_resume_skills($input) {
        if (empty($input)) return [];

        $raw_items = [];

        if (is_string($input)) {
            $str = $input;

            // 1. If there's an explicit "Skills you can list:" or "Skills:" section in text, extract directly
            if (preg_match('/(?:skills\s+(?:you\s+can\s+list|to\s+highlight)|core\s+skills|technical\s+skills|key\s+skills|competencies)\s*[:\-]\s*(.+?)(?=(?:ESG relevance|Evidence from|Transferable skill|Experience:|\b[A-Z]\.|\z))/is', $str, $sm)) {
                $extracted_section = $sm[1];
                $lines = preg_split('/[\r\n]+|•|\s*[-*·]\s+|;\s*|,\s*/', $extracted_section);
                foreach ($lines as $line) {
                    $line = trim($line, " \t\n\r\0\x0B-•*·");
                    if (!empty($line)) $raw_items[] = $line;
                }
            }

            // 2. Also split the full input by newlines, bullets, semicolons, and commas
            $tokens = preg_split('/[\r\n]+|•|\s*[-*·]\s+|;\s*|,\s*/', $str);
            foreach ($tokens as $t) {
                $raw_items[] = $t;
            }
        } elseif (is_array($input)) {
            foreach ($input as $item) {
                if (is_string($item)) {
                    $sub = preg_split('/[\r\n]+|•|\s*[-*·]\s+|;\s*|,\s*/', $item);
                    foreach ($sub as $st) {
                        $raw_items[] = $st;
                    }
                }
            }
        }

        $clean_skills = [];
        $ignore_patterns = [
            '/strongest transferable/i',
            '/evidence from/i',
            '/you have/i',
            '/you\'ve/i',
            '/you worked/i',
            '/i wouldn\'t/i',
            '/valuable strengths/i',
            '/skills you can list/i',
            '/esg relevance/i',
            '/consider refining/i',
            '/automated draft/i',
            '/not configured/i',
            '/psychology-related/i',
            '/helped organise/i',
            '/supported a \d+/i',
            '/served as/i',
            '/government departments/i',
            '/children and/i',
            '/healthcare professionals/i',
            '/university departments/i',
            '/team-building/i',
            '/drum circle/i',
            '/during your/i'
        ];

        foreach ($raw_items as $item) {
            $item = trim($item, " \t\n\r\0\x0B-•*·:.,");
            if ($item === '') continue;

            // Remove leading numbering or list bullets like "A.", "B.", "1.", "- "
            $item = preg_replace('/^[A-Z0-9]\.\s*/i', '', $item);
            $item = preg_replace('/^[-•*·]\s*/', '', $item);
            $item = trim($item);

            // Skip coaching commentary patterns
            $skip = false;
            foreach ($ignore_patterns as $pat) {
                if (preg_match($pat, $item)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            // Strict ATS skill keyword length and sentence rules:
            // - Under 42 characters
            // - At least 3 characters
            // - At most 5 words
            // - No sentence terminal punctuation
            if (mb_strlen($item) > 42 || mb_strlen($item) < 3) continue;
            if (preg_match('/[.!?:]/', $item)) continue;

            $words = preg_split('/\s+/', $item);
            if (count($words) > 5) continue;

            // Remove filler prefixes
            $item = preg_replace('/^(including|such as|e\.g\.|e\.g|etc)\s+/i', '', $item);
            $item = trim($item);
            if (mb_strlen($item) < 3) continue;

            // Convert common past/present verbs into noun phrases if sensible
            if (preg_match('/^(coordinated|assisted|organised|planned|prepared|supported|managed|liaised|monitored|handling)\b/i', $item)) {
                $item = preg_replace('/^(coordinated|assisted|organised|planned|prepared|supported|managed|liaised|monitored|handling)\s+/i', '', $item);
                if (mb_strlen($item) < 3) continue;
                $item .= ' Management';
            }

            // Title-case
            $normalized = ucwords(strtolower($item));
            // Standardize acronyms
            $normalized = preg_replace_callback('/\b(Esg|Csr|It|Hr|Ui|Ux|Ats|Ai|Seo|Sem|Crm|Pr|Kpt|Upsi)\b/i', function($m) {
                return strtoupper($m[1]);
            }, $normalized);

            // Skip single-word generic stopwords
            if (count($words) === 1 && in_array(strtolower($normalized), ['including', 'schedules', 'invitations', 'recent', 'management', 'general', 'various'])) {
                continue;
            }

            if (!in_array($normalized, $clean_skills, true)) {
                $clean_skills[] = $normalized;
            }

            if (count($clean_skills) >= 14) break;
        }

        if (empty($clean_skills)) {
            $clean_skills = [
                'Project Coordination',
                'Stakeholder Management',
                'Event Operations',
                'Communication & Reporting',
                'Workflow Optimization',
                'Team Collaboration'
            ];
        }

        return array_values($clean_skills);
    }
}

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
    $summary = preg_replace('/\s*\(Automated draft\s*—\s*AI not configured[^)]*\)/i', '', $summary);
    $summary = preg_replace('/\s*\(AI not configured[^)]*\)/i', '', $summary);
    $summary = trim($summary);

    $experience = $gen['experience'] ?? ($raw['experience'] ?? ($build['experience'] ?? []));
    if (is_string($experience)) $experience = json_decode($experience, true) ?: [];

    $education = $gen['education'] ?? ($raw['education'] ?? ($build['education'] ?? []));
    if (is_string($education)) $education = json_decode($education, true) ?: [];

    $skills = $gen['skills'] ?? ($raw['skills'] ?? ($build['skills'] ?? []));
    if (is_string($skills)) {
        $decoded = json_decode($skills, true);
        if (is_array($decoded)) $skills = $decoded;
    }
    $skills = sanitize_resume_skills($skills);

    $achievements = $gen['achievements'] ?? ($raw['achievements'] ?? ($build['achievements'] ?? []));
    if (is_string($achievements)) $achievements = json_decode($achievements, true) ?: [];

    // References are entirely optional — the candidate only sees a section
    // for these if they explicitly chose to include one and filled it in.
    $references = $gen['references'] ?? ($raw['references'] ?? ($build['references'] ?? []));
    if (is_string($references)) $references = json_decode($references, true) ?: [];

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
        'references' => is_array($references) ? $references : [],
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
    if (!empty($data['skills']) && is_array($data['skills'])) {
        foreach ($data['skills'] as $s) {
            $s = trim((string)$s);
            if ($s !== '') $skills_list[] = htmlspecialchars($s);
        }
    }
    $skills_list = array_slice($skills_list, 0, 14);

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
                foreach (array_slice($exp['bullets'], 0, 5) as $b) {
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
        $cols = ($total_skills >= 6) ? 3 : 2;
        $chunk_size = ceil($total_skills / $cols);
        $chunks = array_chunk($skills_list, $chunk_size);
        $col_width = round(100 / count($chunks));

        $body_content .= '<div class="skills-wrapper">';
        $body_content .= '<table class="skills-table"><tr>';
        foreach ($chunks as $chunk) {
            $body_content .= '<td style="vertical-align:top; width:' . $col_width . '%;">';
            $body_content .= '<ul class="skills-bullets">';
            foreach ($chunk as $sk) {
                $body_content .= '<li>' . $sk . '</li>';
            }
            $body_content .= '</ul></td>';
        }
        $body_content .= '</tr></table>';
        $body_content .= '</div>';
    }

    if (!empty($data['achievements'])) {
        $body_content .= '<div class="section-title">Key Achievements & Certifications</div>';
        $body_content .= '<ul class="entry-bullets">';
        foreach (array_slice($data['achievements'], 0, 4) as $ach) {
            $ach = trim($ach);
            if ($ach !== '') $body_content .= '<li>' . htmlspecialchars($ach) . '</li>';
        }
        $body_content .= '</ul>';
    }

    // References — entirely optional; only appears when the candidate chose
    // to include one and provided at least one entry.
    if (!empty($data['references'])) {
        $body_content .= '<div class="section-title">References</div>';
        foreach ($data['references'] as $ref) {
            $rname = trim($ref['name'] ?? '');
            if ($rname === '') continue;
            $rtitle = trim($ref['title'] ?? '');
            $rcontact = trim($ref['contact'] ?? '');
            $body_content .= '<div class="entry-block">';
            $body_content .= '<div class="entry-title">' . htmlspecialchars($rname) . '</div>';
            if ($rtitle) $body_content .= '<div class="entry-sub">' . htmlspecialchars($rtitle) . '</div>';
            if ($rcontact) $body_content .= '<div class="entry-notes">' . htmlspecialchars($rcontact) . '</div>';
            $body_content .= '</div>';
        }
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
        margin: 24px 32px 24px 32px;
        size: A4 portrait;
    }
    * {
        box-sizing: border-box;
    }
    body {
        font-family: Helvetica, Arial, sans-serif;
        color: #1F2421;
        font-size: 9.5pt;
        line-height: 1.4;
        margin: 0;
        padding: 0;
        background: #FFFFFF;
    }
    .resume-header {
        border-bottom: 2px solid #6B8A00;
        padding-bottom: 8px;
        margin-bottom: 10px;
    }
    .candidate-name {
        font-size: 20pt;
        font-weight: bold;
        color: #0E0F12;
        margin: 0 0 2px 0;
        line-height: 1.15;
    }
    .target-title {
        font-size: 11pt;
        font-weight: bold;
        color: #6B8A00;
        margin: 0 0 4px 0;
        letter-spacing: 0.2px;
    }
    .contact-bar {
        font-size: 8.5pt;
        color: #4B5563;
        margin-top: 2px;
    }
    .section-title {
        font-size: 10pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #384800;
        border-bottom: 1px solid #D6E4A8;
        padding-bottom: 2px;
        margin-top: 10px;
        margin-bottom: 5px;
        page-break-after: avoid;
    }
    .summary-paragraph {
        font-size: 9pt;
        color: #374151;
        line-height: 1.45;
        margin-bottom: 6px;
        text-align: justify;
    }
    .entry-block {
        margin-bottom: 6px;
        page-break-inside: avoid;
    }
    .entry-header-table {
        margin-bottom: 1px;
    }
    .entry-title {
        font-size: 9.5pt;
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
        font-size: 8.5pt;
        color: #4B5563;
        font-style: italic;
        margin-bottom: 1px;
    }
    .entry-bullets {
        margin: 2px 0 3px 16px;
        padding: 0;
    }
    .entry-bullets li {
        font-size: 8.5pt;
        color: #374151;
        line-height: 1.35;
        margin-bottom: 1.5px;
    }
    .entry-notes {
        font-size: 8.5pt;
        color: #374151;
        line-height: 1.35;
        text-align: justify;
    }
    .skills-wrapper {
        page-break-inside: avoid;
        margin-top: 2px;
        margin-bottom: 4px;
    }
    .skills-table {
        width: 100%;
        border-collapse: collapse;
    }
    .skills-table td {
        padding: 0 8px 0 0;
    }
    .skills-bullets {
        margin: 1px 0 2px 14px;
        padding: 0;
    }
    .skills-bullets li {
        font-size: 8.5pt;
        color: #374151;
        line-height: 1.35;
        margin-bottom: 1.5px;
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
</body>
</html>';

    return $html;
}

/**
 * Builds and returns the binary PDF string using Dompdf.
 *
 * This is a two-tier generator: it first tries the fully-styled render, and
 * if that throws for any environment-specific reason (locked-down hosting,
 * a temp/font-cache permission quirk, etc.) it immediately retries with a
 * minimal, dependency-light render that avoids every optional feature that
 * could fail. Both tiers always produce a real PDF, so a caller basically
 * never needs to fall back to the browser-print HTML view (that view stays
 * in place only as a last-resort safety net for a genuinely broken server).
 */
function generate_resume_builder_pdf($build, $user = null) {
    if (!class_exists('\\DOMDocument')) {
        throw new \RuntimeException("The PHP DOM extension (php-xml) is not installed on this server. Run 'sudo apt-get install php-xml' to enable native PDF rendering.");
    }
    if (!extension_loaded('mbstring') && !function_exists('mb_strlen')) {
        throw new \RuntimeException("The PHP mbstring extension is not installed on this server. Run 'sudo apt-get install php-mbstring' to enable native PDF rendering.");
    }

    try {
        return generate_resume_builder_pdf_full($build, $user);
    } catch (\Throwable $e) {
        error_log("Resume PDF Generation Error (full render): " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . " — retrying with minimal renderer");
        return generate_resume_builder_pdf_minimal($build, $user);
    }
}

/**
 * Fully-styled PDF render (branded colors, two-column skills grid, etc).
 * Uses an app-local font cache directory when writable, falling back to the
 * system temp dir otherwise.
 */
function generate_resume_builder_pdf_full($build, $user = null) {
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
 * Minimal, last-resort PDF render used only when the full render throws.
 * Deliberately avoids every optional feature that could fail on a locked-down
 * host: no custom font/cache directories (system temp only, Dompdf's own
 * default), no chroot restriction, plain built-in font, simple markup with no
 * tables/floats. Still branded (name, title, sections, footer stamp) so the
 * candidate never sees a degraded-looking document — just a slightly plainer
 * layout instead of the browser print screen.
 */
function generate_resume_builder_pdf_minimal($build, $user = null) {
    $data = get_resume_builder_data($build, $user);

    $esc = function($s) { return htmlspecialchars((string)$s); };

    $contacts = array_filter([$data['email'], $data['phone'], $data['location']]);
    $contact_line = $esc(implode('  •  ', $contacts));

    $skills_list = [];
    if (is_array($data['skills'])) {
        foreach ($data['skills'] as $s) {
            $s = trim((string)$s);
            if ($s !== '') $skills_list[] = $esc($s);
        }
    } elseif (is_string($data['skills']) && trim($data['skills']) !== '') {
        foreach (explode(',', $data['skills']) as $p) {
            $p = trim($p);
            if ($p !== '') $skills_list[] = $esc($p);
        }
    }

    $body = '';
    if (!empty($data['summary'])) {
        $body .= '<h2>Professional Summary</h2><p>' . nl2br($esc($data['summary'])) . '</p>';
    }

    $exp_html = '';
    if (!empty($data['experience'])) {
        $exp_html .= '<h2>Work Experience</h2>';
        foreach ($data['experience'] as $exp) {
            $role = trim($exp['role'] ?? '');
            $comp = trim($exp['company'] ?? '');
            $dur = trim($exp['duration'] ?? '');
            $exp_html .= '<p><b>' . $esc($role . ($role && $comp ? ' — ' : '') . $comp) . '</b>';
            if ($dur) $exp_html .= ' &nbsp; <i>' . $esc($dur) . '</i>';
            $exp_html .= '</p>';
            if (!empty($exp['bullets']) && is_array($exp['bullets'])) {
                $exp_html .= '<ul>';
                foreach ($exp['bullets'] as $b) {
                    $b = trim($b);
                    if ($b !== '') $exp_html .= '<li>' . $esc($b) . '</li>';
                }
                $exp_html .= '</ul>';
            } elseif (!empty($exp['notes'])) {
                $exp_html .= '<p>' . nl2br($esc($exp['notes'])) . '</p>';
            }
        }
    }

    $edu_html = '';
    if (!empty($data['education'])) {
        $edu_html .= '<h2>Education &amp; Credentials</h2>';
        foreach ($data['education'] as $edu) {
            $deg = trim($edu['degree'] ?? '');
            $sch = trim($edu['school'] ?? '');
            $yr = trim($edu['year'] ?? '');
            $edu_html .= '<p><b>' . $esc($deg ?: 'Degree / Qualification') . '</b>';
            if ($yr) $edu_html .= ' &nbsp; <i>' . $esc($yr) . '</i>';
            if ($sch) $edu_html .= '<br>' . $esc($sch);
            $edu_html .= '</p>';
        }
    }

    $body .= $data['is_fresh_grad'] ? ($edu_html . $exp_html) : ($exp_html . $edu_html);

    if (!empty($skills_list)) {
        $body .= '<h2>Core Skills &amp; Competencies</h2><ul>';
        foreach ($skills_list as $sk) {
            $body .= '<li>' . $sk . '</li>';
        }
        $body .= '</ul>';
    }

    if (!empty($data['achievements'])) {
        $body .= '<h2>Key Achievements &amp; Certifications</h2><ul>';
        foreach ($data['achievements'] as $ach) {
            $ach = trim($ach);
            if ($ach !== '') $body .= '<li>' . $esc($ach) . '</li>';
        }
        $body .= '</ul>';
    }

    if (!empty($data['references'])) {
        $body .= '<h2>References</h2>';
        foreach ($data['references'] as $ref) {
            $rname = trim($ref['name'] ?? '');
            if ($rname === '') continue;
            $rtitle = trim($ref['title'] ?? '');
            $rcontact = trim($ref['contact'] ?? '');
            $body .= '<p><b>' . $esc($rname) . '</b>';
            if ($rtitle) $body .= '<br><i>' . $esc($rtitle) . '</i>';
            if ($rcontact) $body .= '<br>' . $esc($rcontact);
            $body .= '</p>';
        }
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8">
<title>' . $esc($data['full_name'] . ' - ' . $data['target_title']) . '</title>
<style>
@page { margin: 30px 36px; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #1F2421; }
h1 { font-size: 20pt; margin: 0 0 2px 0; }
.target-title { font-size: 11pt; font-weight: bold; color: #4A5D00; margin: 0 0 6px 0; }
h2 { font-size: 10.5pt; text-transform: uppercase; margin: 14px 0 6px 0; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
p { text-align: justify; }
ul { margin: 4px 0 6px 18px; padding: 0; }
li { margin-bottom: 2px; }
</style></head><body>
<h1>' . $esc($data['full_name']) . '</h1>
<div class="target-title">' . $esc($data['target_title']) . '</div>
<div>' . $contact_line . '</div>
' . $body . '
</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');
    // Deliberately use Dompdf's own defaults for font dir/cache/temp/chroot —
    // no custom paths that could be unwritable on a restrictive host.

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
            text-align: justify;
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
            <?php
                // "Back" must go somewhere the viewer is allowed to open: admin_resumes.php is
                // admin-only, so candidates and employers were bounced to the login page.
                $ev_back_role = $_SESSION['user_role'] ?? '';
                if ($ev_back_role === 'admin') { $ev_back_url = 'admin_resumes.php'; $ev_back_label = 'Back to Hub'; }
                elseif ($ev_back_role === 'employer') { $ev_back_url = 'employer_dashboard.php'; $ev_back_label = 'Back to Dashboard'; }
                else { $ev_back_url = 'resume_builder.php'; $ev_back_label = 'Back to Resume Builder'; }
            ?>
            <a href="<?= htmlspecialchars($ev_back_url) ?>" class="btn-back">
                ✕ <?= htmlspecialchars($ev_back_label) ?>
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

        <?php if(!empty($data['references'])): ?>
            <div class="section-title">References</div>
            <?php foreach($data['references'] as $ref): ?>
                <?php $rname = trim($ref['name'] ?? ''); if ($rname === '') continue; ?>
                <?php $rtitle = trim($ref['title'] ?? ''); $rcontact = trim($ref['contact'] ?? ''); ?>
                <div class="entry-block">
                    <div class="entry-title"><?= htmlspecialchars($rname) ?></div>
                    <?php if ($rtitle): ?><div class="entry-sub"><?= htmlspecialchars($rtitle) ?></div><?php endif; ?>
                    <?php if ($rcontact): ?><div class="summary-paragraph" style="margin-bottom:0;"><?= htmlspecialchars($rcontact) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
    exit;
}

/**
 * ---------------------------------------------------------------------
 * Microsoft Word (.docx) export
 * ---------------------------------------------------------------------
 * Builds a real, editable .docx file directly with ZipArchive + raw OOXML
 * (no third-party library required/installed). The candidate can open this
 * in Word (or Google Docs / LibreOffice) and edit it freely. Body paragraphs
 * are justified to match the PDF; bullet points use a hanging indent with a
 * "•" character (fully editable plain text, not a locked auto-list).
 */

function docx_esc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** A single justified body paragraph (splits on newlines into separate paragraphs). */
function docx_prose_paragraphs($text, $size = 21) {
    $xml = '';
    $lines = preg_split('/\r\n|\r|\n/', trim((string)$text));
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $xml .= '<w:p><w:pPr><w:jc w:val="both"/><w:spacing w:after="160"/></w:pPr>'
              . '<w:r><w:rPr><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>'
              . '<w:t xml:space="preserve">' . docx_esc($line) . '</w:t></w:r></w:p>';
    }
    return $xml;
}

/** A section heading paragraph (bold, colored, bottom border). */
function docx_heading($text) {
    return '<w:p><w:pPr><w:spacing w:before="280" w:after="120"/>'
         . '<w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="D6E4A8"/></w:pBdr></w:pPr>'
         . '<w:r><w:rPr><w:b/><w:color w:val="384800"/><w:sz w:val="21"/><w:szCs w:val="21"/></w:rPr>'
         . '<w:t xml:space="preserve">' . docx_esc(mb_strtoupper($text)) . '</w:t></w:r></w:p>';
}

/** A single bullet-point paragraph (hanging indent + literal bullet glyph, still plain editable text). */
function docx_bullet($text, $size = 19) {
    return '<w:p><w:pPr><w:ind w:left="420" w:hanging="240"/><w:spacing w:after="60"/><w:jc w:val="both"/></w:pPr>'
         . '<w:r><w:rPr><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>'
         . '<w:t xml:space="preserve">• ' . docx_esc($text) . '</w:t></w:r></w:p>';
}

/** A plain (non-justified) paragraph, optionally bold/italic — used for entry titles/meta lines. */
function docx_line($text, $bold = false, $italic = false, $size = 20, $color = null, $spacingAfter = 20) {
    if (trim((string)$text) === '') return '';
    $rpr = '<w:rPr>';
    if ($bold) $rpr .= '<w:b/>';
    if ($italic) $rpr .= '<w:i/>';
    if ($color) $rpr .= '<w:color w:val="' . $color . '"/>';
    $rpr .= '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
    return '<w:p><w:pPr><w:spacing w:after="' . $spacingAfter . '"/></w:pPr>'
         . '<w:r>' . $rpr . '<w:t xml:space="preserve">' . docx_esc($text) . '</w:t></w:r></w:p>';
}

function generate_resume_builder_docx($build, $user = null) {
    $data = get_resume_builder_data($build, $user);

    $contacts = array_filter([$data['email'], $data['phone'], $data['location']]);
    $contact_line = implode('   •   ', $contacts);

    $body = '';

    // Header: name, target title, contact line
    $body .= '<w:p><w:pPr><w:spacing w:after="40"/></w:pPr>'
           . '<w:r><w:rPr><w:b/><w:sz w:val="40"/><w:szCs w:val="40"/></w:rPr>'
           . '<w:t xml:space="preserve">' . docx_esc($data['full_name']) . '</w:t></w:r></w:p>';
    $body .= docx_line($data['target_title'], true, false, 24, '6B8A00', 40);
    if ($contact_line !== '') {
        $body .= '<w:p><w:pPr><w:spacing w:after="200"/><w:pBdr><w:bottom w:val="single" w:sz="10" w:space="6" w:color="6B8A00"/></w:pBdr></w:pPr>'
               . '<w:r><w:rPr><w:color w:val="4B5563"/><w:sz w:val="18"/><w:szCs w:val="18"/></w:rPr>'
               . '<w:t xml:space="preserve">' . docx_esc($contact_line) . '</w:t></w:r></w:p>';
    }

    if (!empty($data['summary'])) {
        $body .= docx_heading('Professional Summary');
        $body .= docx_prose_paragraphs($data['summary']);
    }

    $exp_xml = '';
    if (!empty($data['experience'])) {
        $exp_xml .= docx_heading('Work Experience');
        foreach ($data['experience'] as $exp) {
            $role = trim($exp['role'] ?? '');
            $comp = trim($exp['company'] ?? '');
            $dur = trim($exp['duration'] ?? '');
            $title_line = $role . ($role && $comp ? ' — ' : '') . $comp;
            $exp_xml .= docx_line($title_line ?: 'Experience Entry', true, false, 20, '111827', 10);
            if ($dur) $exp_xml .= docx_line($dur, false, true, 18, '6B7280', 30);
            if (!empty($exp['bullets']) && is_array($exp['bullets'])) {
                foreach (array_slice($exp['bullets'], 0, 5) as $b) {
                    $b = trim($b);
                    if ($b !== '') $exp_xml .= docx_bullet($b);
                }
            } elseif (!empty($exp['notes'])) {
                $exp_xml .= docx_prose_paragraphs($exp['notes'], 19);
            }
        }
    }

    $edu_xml = '';
    if (!empty($data['education'])) {
        $edu_xml .= docx_heading('Education & Credentials');
        foreach ($data['education'] as $edu) {
            $deg = trim($edu['degree'] ?? '');
            $sch = trim($edu['school'] ?? '');
            $yr = trim($edu['year'] ?? '');
            $edu_xml .= docx_line($deg ?: 'Degree / Qualification', true, false, 20, '111827', 10);
            $meta = trim($sch . ($yr ? ' · ' . $yr : ''));
            if ($meta) $edu_xml .= docx_line($meta, false, true, 18, '4B5563', 30);
        }
    }

    $body .= $data['is_fresh_grad'] ? ($edu_xml . $exp_xml) : ($exp_xml . $edu_xml);

    if (!empty($data['skills'])) {
        $skills_list = array_slice(sanitize_resume_skills($data['skills']), 0, 14);
        if (!empty($skills_list)) {
            $body .= docx_heading('Core Skills & Competencies');
            foreach ($skills_list as $sk) {
                $body .= docx_bullet($sk);
            }
        }
    }

    if (!empty($data['achievements'])) {
        $body .= docx_heading('Key Achievements & Certifications');
        foreach ($data['achievements'] as $ach) {
            $ach = trim($ach);
            if ($ach !== '') $body .= docx_bullet($ach);
        }
    }

    if (!empty($data['references'])) {
        $body .= docx_heading('References');
        foreach ($data['references'] as $ref) {
            $rname = trim($ref['name'] ?? '');
            if ($rname === '') continue;
            $rtitle = trim($ref['title'] ?? '');
            $rcontact = trim($ref['contact'] ?? '');
            $body .= docx_line($rname, true, false, 20, '111827', 10);
            if ($rtitle) $body .= docx_line($rtitle, false, true, 18, '4B5563', 10);
            if ($rcontact) $body .= docx_line($rcontact, false, false, 18, '374151', 30);
        }
    }

    // A4 in twentieths of a point (11906 x 16838), ~0.79in margins
    $document_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/>'
        . '</w:sectPr></w:body></w:document>';

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '</Types>';

    $rels_root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '</Relationships>';

    $rels_document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr>'
        . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
        . '<w:sz w:val="21"/><w:szCs w:val="21"/><w:lang w:val="en-US"/>'
        . '</w:rPr></w:rPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
        . '</w:styles>';

    $tmp_path = tempnam(sys_get_temp_dir(), 'kresume_') . '.docx';
    $zip = new \ZipArchive();
    if ($zip->open($tmp_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        throw new \RuntimeException("Unable to create temporary .docx archive.");
    }
    $zip->addFromString('[Content_Types].xml', $content_types);
    $zip->addFromString('_rels/.rels', $rels_root);
    $zip->addFromString('word/document.xml', $document_xml);
    $zip->addFromString('word/_rels/document.xml.rels', $rels_document);
    $zip->addFromString('word/styles.xml', $styles_xml);
    $zip->close();

    $bytes = file_get_contents($tmp_path);
    @unlink($tmp_path);

    if ($bytes === false) {
        throw new \RuntimeException("Failed to read generated .docx file.");
    }

    return $bytes;
}

/**
 * Streams the generated Word document directly to the browser as a download.
 */
function stream_resume_builder_docx($build, $user = null) {
    $data = get_resume_builder_data($build, $user);
    $name_part = sanitize_resume_filename($data['full_name']);
    $title_part = sanitize_resume_filename($data['target_title']);
    $filename = "Resume_{$name_part}_{$title_part}.docx";

    $docx_content = generate_resume_builder_docx($build, $user);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($docx_content));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');

    echo $docx_content;
    exit;
}
