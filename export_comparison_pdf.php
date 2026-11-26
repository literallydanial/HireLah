<?php
// Generates a landscape PDF of the candidate comparison matrix, grouped 5
// candidates per page (same content as compare_candidates.php's on-screen
// table, minus the interactive Quick Actions row). Dompdf (already used by
// the Resume Builder's PDF export) renders it -- no new dependency.
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$job_id = (int)($_GET['job_id'] ?? 0);
$ids = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['ids'] ?? '')))));

$allowed_sorts = ['overall_score', 'skills_match', 'exp_match', 'edu_match', 'name', 'created_at'];
$sort_by = $_GET['sort'] ?? 'overall_score';
if (!in_array($sort_by, $allowed_sorts, true)) $sort_by = 'overall_score';
$sort_dir = strtolower($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

// Same job-ownership scoping as compare_candidates.php / upload.php, so HR
// can't export a comparison for a job they don't own.
$active_company_id = get_active_company_id($pdo);
if ($active_company_id) {
    $job_stmt = $pdo->prepare("SELECT job_title FROM jobs WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
    $job_stmt->execute([$job_id, $active_company_id, $_SESSION['user_id']]);
} else {
    $job_stmt = $pdo->prepare("SELECT job_title FROM jobs WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
    $job_stmt->execute([$job_id, $_SESSION['user_id']]);
}
$job = $job_stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) {
    http_response_code(403);
    die('Invalid or inaccessible job posting.');
}

if (empty($ids)) {
    http_response_code(400);
    die('No candidates selected to export.');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = array_merge([$job_id], $ids);
$cand_stmt = $pdo->prepare("SELECT id, name, email, user_id, status, overall_score, recommendation,
        skills_match, exp_match, edu_match, strengths, gaps
    FROM candidates WHERE job_id = ? AND id IN ($placeholders) ORDER BY {$sort_by} {$sort_dir}");
$cand_stmt->execute($params);
$candidates = $cand_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($candidates)) {
    http_response_code(400);
    die('No matching candidates found for this job.');
}

// Dompdf doesn't resolve CSS custom properties (var(--acc) etc.), so the
// site's theme colors are hardcoded here instead -- same light-theme palette
// defined in style.css's :root block.
$COLOR = [
    'acc' => '#6B8A00', 'grn' => '#10B981', 'red' => '#EF4444', 'org' => '#F97316',
    'pur' => '#4F3FF0', 'txt' => '#0A0A0A', 'mut' => '#64748B', 'bdr' => '#E2E8F0', 'surf' => '#F8FAFC',
];

function pdf_strip_evidence($text) {
    return htmlspecialchars(preg_replace('/\[Resume Evidence:.*\]/s', '', (string)$text));
}
function pdf_score_color($score, $c) {
    $score = (int)$score;
    if ($score >= 80) return $c['grn'];
    if ($score >= 60) return $c['acc'];
    if ($score >= 40) return $c['org'];
    return $c['red'];
}
function pdf_pct_color($v, $c) {
    $v = (int)$v;
    if ($v >= 70) return $c['grn'];
    if ($v >= 45) return $c['acc'];
    return $c['org'];
}

// One <table> per 5 candidates, each with its own <thead> (candidate names +
// overall score) so Dompdf automatically reprints that header if this
// group's rows run tall enough to spill onto a continuation page.
$groups = array_chunk($candidates, 5);
$body = '';

foreach ($groups as $gi => $group) {
    $style = $gi > 0 ? ' style="page-break-before: always;"' : '';
    $body .= '<table class="cmp-table"' . $style . '>';

    // Name, email and overall score all share ONE row (instead of a
    // decorative avatar circle + name in one row, then a separate row just
    // for the score) -- tried putting the score badge beside the name to
    // save even more space, but with real data that reserved horizontal
    // width made long multi-word names wrap onto 3-4 lines instead of 1-2,
    // costing more height than it saved. Keeping full column width for the
    // name and placing the (now smaller) score circle underneath avoids that.
    $body .= '<thead><tr><td class="label-col">Candidate</td>';
    foreach ($group as $c) {
        $score = (int)($c['overall_score'] ?? 0);
        $sc = pdf_score_color($score, $COLOR);
        $hr_tag = empty($c['user_id'])
            ? '<div style="font-size:8px; font-weight:800; color:' . $COLOR['pur'] . '; margin-top:3px;">HR ADDED</div>'
            : '';
        $body .= '<td class="cand-col">'
            . '<div style="font-size:11.5px; font-weight:800; color:' . $COLOR['txt'] . ';">' . htmlspecialchars($c['name']) . '</div>'
            . '<div style="font-size:9px; color:' . $COLOR['mut'] . '; margin-bottom:5px;">' . htmlspecialchars($c['email'] ?? '') . '</div>'
            . '<div style="width:30px; height:30px; border-radius:50%; border:2px solid ' . $sc . '; color:' . $sc . '; font-size:10.5px; font-weight:800; text-align:center; line-height:26px; font-family:monospace; margin:0 auto;">' . $score . '%</div>'
            . '<div style="font-size:7.5px; font-weight:700; color:' . $COLOR['mut'] . '; text-transform:uppercase; margin-top:3px;">Overall AI Match Score</div>'
            . $hr_tag
            . '</td>';
    }
    $body .= '</tr></thead>';

    $body .= '<tbody>';

    $body .= '<tr><td class="label-col">Recommendation &amp; Status</td>';
    foreach ($group as $c) {
        $rec = $c['recommendation'] ?: 'Pending';
        $rec_color = in_array($rec, ['Strong Hire', 'Hire'], true) ? $COLOR['grn'] : ($rec === 'Maybe' ? $COLOR['org'] : $COLOR['red']);
        $body .= '<td class="cand-col">'
            . '<div style="font-size:11px; font-weight:800; color:' . $rec_color . ';">' . htmlspecialchars($rec) . '</div>'
            . '<div style="font-size:9.5px; color:' . $COLOR['mut'] . '; margin-top:4px;">Status: ' . htmlspecialchars($c['status'] ?? '') . '</div>'
            . '</td>';
    }
    $body .= '</tr>';

    foreach ([['skills_match', 'Skills Alignment'], ['exp_match', 'Experience Match'], ['edu_match', 'Education Match']] as $row) {
        [$field, $label] = $row;
        $body .= '<tr><td class="label-col">' . $label . '</td>';
        foreach ($group as $c) {
            $v = (int)($c[$field] ?? 0);
            $vc = pdf_pct_color($v, $COLOR);
            $body .= '<td><div style="font-size:11px; font-weight:800; color:' . $vc . '; font-family:monospace; text-align:center;">' . $v . '%</div></td>';
        }
        $body .= '</tr>';
    }

    foreach ([['strengths', 'Key Strengths (Pros)'], ['gaps', 'Key Gaps (Cons)']] as $row) {
        [$field, $label] = $row;
        $body .= '<tr><td class="label-col">' . $label . '</td>';
        foreach ($group as $c) {
            $items = !empty($c[$field]) ? (json_decode($c[$field], true) ?: []) : [];
            $items = array_slice($items, 0, 3);
            if (empty($items)) {
                $body .= '<td><span style="font-size:11px; color:' . $COLOR['mut'] . ';">-</span></td>';
            } else {
                $body .= '<td><ul style="margin:0; padding-left:14px; font-size:10.5px; color:' . $COLOR['txt'] . '; line-height:1.4;">';
                foreach ($items as $it) {
                    $body .= '<li style="margin-bottom:3px;">' . pdf_strip_evidence($it) . '</li>';
                }
                $body .= '</ul></td>';
            }
        }
        $body .= '</tr>';
    }

    $body .= '</tbody></table>';
}

$job_title = htmlspecialchars($job['job_title'] ?? 'Job');
$export_date = date('d M Y, H:i');

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; color: ' . $COLOR['txt'] . '; margin:0; }
    .pdf-header { margin-bottom:16px; }
    .pdf-header h1 { font-size:16px; margin:0 0 2px 0; }
    .pdf-header p { font-size:10px; color:' . $COLOR['mut'] . '; margin:0; }
    table.cmp-table { width:100%; border-collapse:collapse; margin-bottom:18px; table-layout:fixed; }
    table.cmp-table th, table.cmp-table td { border:1px solid ' . $COLOR['bdr'] . '; padding:8px; vertical-align:top; }
    .label-col { width:130px; font-size:10px; font-weight:800; color:' . $COLOR['mut'] . '; text-transform:uppercase; background:' . $COLOR['surf'] . '; }
    .cand-col { text-align:center; background:' . $COLOR['surf'] . '; }
</style></head><body>
    <div class="pdf-header">
        <h1>Candidate Comparison &mdash; ' . $job_title . '</h1>
        <p>Exported ' . $export_date . ' &middot; ' . count($candidates) . ' candidate(s)</p>
    </div>
    ' . $body . '
</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

$filename = 'Comparison-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $job['job_title'] ?? 'Job') . '-' . date('Y-m-d') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo $dompdf->output();
exit;
