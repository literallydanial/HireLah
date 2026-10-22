<?php
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';
require_once 'job_description_helper.php';

$job_id = $_GET['id'] ?? null;
if (!$job_id) {
    header("Location: job_dashboard.php");
    exit;
}

$active_company_id = get_active_company_id($pdo);

// Fetch job ensuring it belongs to this employer's company team
if ($active_company_id) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
    $stmt->execute([$job_id, $active_company_id, $_SESSION['user_id']]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
    $stmt->execute([$job_id, $_SESSION['user_id']]);
}
$job = $stmt->fetch();

if (!$job) {
    $_SESSION['toast'] = "Job posting not found or access denied.";
    header("Location: job_dashboard.php");
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['job_title'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $type = trim($_POST['employment_type'] ?? '');
    $mode = trim($_POST['work_mode'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $salary_min = (!empty($_POST['salary_min']) && is_numeric($_POST['salary_min'])) ? (int)$_POST['salary_min'] : null;
    $salary_max = (!empty($_POST['salary_max']) && is_numeric($_POST['salary_max'])) ? (int)$_POST['salary_max'] : null;
    $salary_rate_type = in_array($_POST['salary_rate_type'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_POST['salary_rate_type'] : 'monthly';
    $salary_text = !empty($_POST['salary_text']) ? trim($_POST['salary_text']) : null;
    // The "Company Perks & Benefits" field was removed from this form (Benefits
    // now lives as a section inside the Job Details builder below, where it's
    // actually shown to candidates). Preserve whatever was already saved here
    // rather than erasing it, since the field no longer submits a value.
    $perks = array_key_exists('perks', $_POST) ? (trim($_POST['perks']) ?: null) : ($job['perks'] ?? null);
    $require_video = isset($_POST['require_video']) ? 1 : 0;
    $desc = trim($_POST['description'] ?? '');

    if (empty($title) || empty($desc)) {
        $error = "Job title and description are required.";
    } elseif ($salary_min === null || $salary_max === null) {
        $error = "Please provide both a minimum and maximum salary.";
    } else {
        if ($active_company_id) {
            $update_stmt = $pdo->prepare("UPDATE jobs SET job_title = ?, department = ?, location = ?, employment_type = ?, work_mode = ?, salary_min = ?, salary_max = ?, salary_rate_type = ?, salary_text = ?, perks = ?, status = ?, require_video = ?, description = ? WHERE id = ? AND (company_id = ? OR (company_id IS NULL AND employer_id = ?))");
            $update_ok = $update_stmt->execute([$title, $dept, $location, $type, $mode, $salary_min, $salary_max, $salary_rate_type, $salary_text, $perks, $status, $require_video, $desc, $job_id, $active_company_id, $_SESSION['user_id']]);
        } else {
            $update_stmt = $pdo->prepare("UPDATE jobs SET job_title = ?, department = ?, location = ?, employment_type = ?, work_mode = ?, salary_min = ?, salary_max = ?, salary_rate_type = ?, salary_text = ?, perks = ?, status = ?, require_video = ?, description = ? WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
            $update_ok = $update_stmt->execute([$title, $dept, $location, $type, $mode, $salary_min, $salary_max, $salary_rate_type, $salary_text, $perks, $status, $require_video, $desc, $job_id, $_SESSION['user_id']]);
        }
        if ($update_ok) {
            $_SESSION['toast'] = "Job posting updated successfully!";
            header("Location: job_dashboard.php");
            exit;
        } else {
            $error = "Failed to update job posting. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Job Posting - Keria</title>
    <link rel="stylesheet" href="style.css">
    <style>
    .jd-suggest-chip {
        padding: 6px 12px;
        border-radius: 999px;
        border: 1px solid var(--bdr);
        background: var(--dim);
        cursor: pointer;
        font-size: 12px;
        font-weight: 600;
        color: var(--txt);
    }
    .jd-suggest-chip:hover {
        border-color: var(--acc, #C2F025);
        background: var(--surf);
    }
    .jd-section-card {
        border: 1px solid var(--bdr);
        border-radius: 10px;
        padding: 12px;
        margin-bottom: 10px;
        background: var(--surf);
    }
    .jd-section-title {
        flex: 1;
        padding: 8px 10px;
        font-size: 13px;
        font-weight: 700;
        border-radius: 8px;
        border: 1.5px solid var(--bdr);
        background: var(--card-solid, #fff);
        color: var(--txt);
    }
    .jd-section-remove-btn {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        border: 1px solid var(--bdr);
        background: var(--dim);
        cursor: pointer;
        color: var(--red, #FF4D6A);
        flex-shrink: 0;
    }
    .jd-section-toolbar-btn {
        border-radius: 6px;
        border: 1px solid var(--bdr);
        background: var(--dim);
        cursor: pointer;
        font-size: 11px;
        height: 28px;
    }
    .jd-section-content {
        width: 100%;
        box-sizing: border-box;
        resize: vertical;
        padding: 10px;
        font-size: 13px;
        border-radius: 8px;
        border: 1.5px solid var(--bdr);
        background: var(--card-solid, #fff);
        color: var(--txt);
        font-family: inherit;
    }
    </style>
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding: 40px 20px;">
    <div class="panel" style="max-width:650px; width:100%;">
        <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
            <a href="job_dashboard.php" class="btn-secondary" style="padding:6px 14px; font-size:12px; text-decoration:none;">&larr; Back to Jobs</a>
        </div>
        <div style="font-size:24px; font-weight:800; color:var(--txt); margin-bottom:6px;">Edit Job Posting</div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">Update role requirements, title, or job description.</div>

        <?php if($error): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="editJobForm" onsubmit="return jdValidateBeforeSubmit('edit_jd_sections','edit_description_hidden')">
            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Title</label>
                <input type="text" name="job_title" value="<?= htmlspecialchars($job['job_title']) ?>" required>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Location (State)</label>
                <?php $uni_job_loc = $job['location'] ?? ''; ?>
                <select name="location">
                    <option value="" <?= $uni_job_loc === '' ? 'selected' : '' ?>>Select a state...</option>
                    <option value="Johor" <?= $uni_job_loc === 'Johor' ? 'selected' : '' ?>>Johor</option>
                    <option value="Kedah" <?= $uni_job_loc === 'Kedah' ? 'selected' : '' ?>>Kedah</option>
                    <option value="Kelantan" <?= $uni_job_loc === 'Kelantan' ? 'selected' : '' ?>>Kelantan</option>
                    <option value="Kuala Lumpur" <?= $uni_job_loc === 'Kuala Lumpur' ? 'selected' : '' ?>>Kuala Lumpur</option>
                    <option value="Labuan" <?= $uni_job_loc === 'Labuan' ? 'selected' : '' ?>>Labuan</option>
                    <option value="Melaka" <?= $uni_job_loc === 'Melaka' ? 'selected' : '' ?>>Melaka</option>
                    <option value="Negeri Sembilan" <?= $uni_job_loc === 'Negeri Sembilan' ? 'selected' : '' ?>>Negeri Sembilan</option>
                    <option value="Pahang" <?= $uni_job_loc === 'Pahang' ? 'selected' : '' ?>>Pahang</option>
                    <option value="Penang" <?= $uni_job_loc === 'Penang' ? 'selected' : '' ?>>Penang</option>
                    <option value="Perak" <?= $uni_job_loc === 'Perak' ? 'selected' : '' ?>>Perak</option>
                    <option value="Perlis" <?= $uni_job_loc === 'Perlis' ? 'selected' : '' ?>>Perlis</option>
                    <option value="Putrajaya" <?= $uni_job_loc === 'Putrajaya' ? 'selected' : '' ?>>Putrajaya</option>
                    <option value="Sabah" <?= $uni_job_loc === 'Sabah' ? 'selected' : '' ?>>Sabah</option>
                    <option value="Sarawak" <?= $uni_job_loc === 'Sarawak' ? 'selected' : '' ?>>Sarawak</option>
                    <option value="Selangor" <?= $uni_job_loc === 'Selangor' ? 'selected' : '' ?>>Selangor</option>
                    <option value="Terengganu" <?= $uni_job_loc === 'Terengganu' ? 'selected' : '' ?>>Terengganu</option>
                </select>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Department</label>
                    <input type="text" name="department" value="<?= htmlspecialchars($job['department'] ?? '') ?>">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Employment Type</label>
                    <select name="employment_type">
                        <option value="Full-time" <?= $job['employment_type'] === 'Full-time' ? 'selected' : '' ?>>Full-time</option>
                        <option value="Part-time" <?= $job['employment_type'] === 'Part-time' ? 'selected' : '' ?>>Part-time</option>
                        <option value="Contract" <?= $job['employment_type'] === 'Contract' ? 'selected' : '' ?>>Contract</option>
                        <option value="Internship" <?= $job['employment_type'] === 'Internship' ? 'selected' : '' ?>>Internship</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Work Mode</label>
                    <select name="work_mode">
                        <option value="Remote" <?= $job['work_mode'] === 'Remote' ? 'selected' : '' ?>>Remote</option>
                        <option value="On-site" <?= $job['work_mode'] === 'On-site' ? 'selected' : '' ?>>On-site</option>
                        <option value="Hybrid" <?= $job['work_mode'] === 'Hybrid' ? 'selected' : '' ?>>Hybrid</option>
                    </select>
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Status</label>
                    <select name="status">
                        <option value="Active" <?= $job['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Closed" <?= $job['status'] === 'Closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Rate Type</label>
                <select name="salary_rate_type" id="edit_salary_rate_type" onchange="updateSalaryRateLabels('edit_salary_rate_type', 'edit_min_salary_label', 'edit_max_salary_label')">
                    <option value="monthly" <?= ($job['salary_rate_type'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="daily" <?= ($job['salary_rate_type'] ?? '') === 'daily' ? 'selected' : '' ?>>Daily (part-time)</option>
                    <option value="weekly" <?= ($job['salary_rate_type'] ?? '') === 'weekly' ? 'selected' : '' ?>>Weekly (part-time)</option>
                </select>
            </div>

            <?php
                $edit_rate_unit = ($job['salary_rate_type'] ?? 'monthly') === 'daily' ? 'RM/day' : (($job['salary_rate_type'] ?? 'monthly') === 'weekly' ? 'RM/week' : 'RM/mo');
            ?>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div>
                    <label id="edit_min_salary_label" style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Min Salary (<?= $edit_rate_unit ?>)</label>
                    <input type="number" name="salary_min" value="<?= htmlspecialchars($job['salary_min'] ?? '') ?>" placeholder="e.g. 4000" required>
                </div>
                <div>
                    <label id="edit_max_salary_label" style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Max Salary (<?= $edit_rate_unit ?>)</label>
                    <input type="number" name="salary_max" value="<?= htmlspecialchars($job['salary_max'] ?? '') ?>" placeholder="e.g. 7500" required>
                </div>
            </div>

            <div style="margin-bottom:16px; padding:12px; background:var(--surf); border:1px solid var(--bdr); border-radius:8px; display:flex; align-items:center; gap:10px;">
                <input type="checkbox" name="require_video" id="require_video" value="1" <?= !empty($job['require_video']) ? 'checked' : '' ?> style="width:18px; height:18px; cursor:pointer;">
                <label for="require_video" style="font-size:13px; font-weight:600; color:var(--txt); cursor:pointer;">
                    📹 Require YouTube Video Introduction Link from Applicants
                </label>
            </div>

            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Job Details</label>
                <div id="edit_jd_sections">
                    <?php foreach (parse_job_description_sections($job['description'] ?? '') as $i => $sec): ?>
                    <div class="jd-section-card" id="editJdSection_<?= $i ?>">
                        <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
                            <input type="text" class="jd-section-title" placeholder="Section title, e.g. Responsibilities" value="<?= htmlspecialchars($sec['title']) ?>" oninput="jdSyncDescription('edit_jd_sections','edit_description_hidden')">
                            <button type="button" class="jd-section-remove-btn" title="Remove section" onclick="jdRemoveSection('editJdSection_<?= $i ?>','edit_jd_sections','edit_description_hidden')">✕</button>
                        </div>
                        <div style="display:flex; gap:6px; margin-bottom:6px;">
                            <button type="button" class="jd-section-toolbar-btn" style="width:28px; font-weight:800;" title="Bold" onclick="jdWrapSelection('editJdSection_<?= $i ?>_ta', '**', '**')">B</button>
                            <button type="button" class="jd-section-toolbar-btn" style="padding:0 8px;" title="Bullet list (dash)" onclick="jdPrefixLines('editJdSection_<?= $i ?>_ta', '- ')">&ndash; List</button>
                            <button type="button" class="jd-section-toolbar-btn" style="padding:0 8px;" title="Bullet list (round)" onclick="jdPrefixLines('editJdSection_<?= $i ?>_ta', '• ')">&bull; List</button>
                        </div>
                        <textarea id="editJdSection_<?= $i ?>_ta" class="jd-section-content" rows="4" placeholder="Write this section's content..." oninput="jdSyncDescription('edit_jd_sections','edit_description_hidden')"><?= htmlspecialchars($sec['content']) ?></textarea>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-top:4px;">
                    <button type="button" onclick="jdAddSection('edit_jd_sections','edit_description_hidden')" style="padding:7px 14px; border-radius:8px; border:1.5px dashed var(--bdr); background:var(--surf); cursor:pointer; font-size:12.5px; font-weight:700; color:var(--txt);">+ Add Section</button>
                    <span style="font-size:11px; color:var(--mut);">Quick add:</span>
                    <button type="button" class="jd-suggest-chip" onclick="jdAddSection('edit_jd_sections','edit_description_hidden','Key Responsibilities')">💼 Responsibilities</button>
                    <button type="button" class="jd-suggest-chip" onclick="jdAddSection('edit_jd_sections','edit_description_hidden','Requirements & Qualifications')">🎓 Qualifications</button>
                    <button type="button" class="jd-suggest-chip" onclick="jdAddSection('edit_jd_sections','edit_description_hidden','Benefits')">🎁 Benefits</button>
                    <button type="button" class="jd-suggest-chip" onclick="jdAddSection('edit_jd_sections','edit_description_hidden','Required Skills')">🛠️ Skills</button>
                    <button type="button" class="jd-suggest-chip" onclick="jdAddSection('edit_jd_sections','edit_description_hidden','Nice to Have')">⭐ Nice to Have</button>
                </div>
                <textarea id="edit_description_hidden" name="description" style="display:none;"><?= htmlspecialchars($job['description'] ?? '') ?></textarea>
            </div>

            <div style="display:flex; gap:12px;">
                <button type="submit" class="btn-primary" style="padding:12px 24px; font-size:14px; flex:1;">Save Changes</button>
                <a href="job_dashboard.php" class="btn-secondary" style="padding:12px 24px; font-size:14px; text-decoration:none; text-align:center;">Cancel</a>
            </div>
        </form>
    </div>
    <script>
    // Updates the "Min/Max Salary" field labels to match the chosen rate type.
    function updateSalaryRateLabels(selectId, minLabelId, maxLabelId) {
        var rate = document.getElementById(selectId).value;
        var unit = rate === 'daily' ? 'RM/day' : rate === 'weekly' ? 'RM/week' : 'RM/mo';
        document.getElementById(minLabelId).textContent = 'Min Salary (' + unit + ')';
        document.getElementById(maxLabelId).textContent = 'Max Salary (' + unit + ')';
    }

    // Wraps the selected text in a textarea with before/after markers (e.g. ** **).
    function jdWrapSelection(textareaId, before, after) {
        var ta = document.getElementById(textareaId);
        var start = ta.selectionStart, end = ta.selectionEnd;
        var selected = ta.value.substring(start, end) || 'text';
        ta.value = ta.value.substring(0, start) + before + selected + after + ta.value.substring(end);
        ta.focus();
        ta.selectionStart = start + before.length;
        ta.selectionEnd = start + before.length + selected.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Prefixes every line touched by the current selection with the given
    // marker ("- " or "• " for a bullet). If a line already starts with a
    // DIFFERENT bullet marker, that one is replaced rather than stacking a
    // second marker in front of it (which used to produce "• - text").
    var JD_BULLET_MARKER_RE = /^[-*•]\s+/;
    function jdPrefixLines(textareaId, prefix) {
        var ta = document.getElementById(textareaId);
        var start = ta.selectionStart, end = ta.selectionEnd;
        var value = ta.value;
        var lineStart = value.lastIndexOf('\n', start - 1) + 1;
        var lineEnd = value.indexOf('\n', end);
        if (lineEnd === -1) lineEnd = value.length;
        var block = value.substring(lineStart, lineEnd);
        var lines = block.split('\n').map(function(l) {
            if (l.trim() === '') return l;
            if (JD_BULLET_MARKER_RE.test(l)) return l.replace(JD_BULLET_MARKER_RE, prefix);
            return prefix + l;
        });
        var newBlock = lines.join('\n');
        ta.value = value.substring(0, lineStart) + newBlock + value.substring(lineEnd);
        ta.focus();
        ta.selectionStart = lineStart;
        ta.selectionEnd = lineStart + newBlock.length;
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Job description "section cards" — see job_dashboard.php for the full
    // explanation. Combines every section into the same "## Title\ncontent"
    // text the formatter (job_description_helper.php) already understands.
    var jdSectionCounter = 1000; // offset so ids never collide with the server-rendered editJdSection_N cards

    function jdAddSection(containerId, hiddenFieldId, presetTitle, presetContent) {
        jdSectionCounter++;
        var cardId = 'jdSection_' + jdSectionCounter;
        var taId = cardId + '_ta';
        var container = document.getElementById(containerId);
        var card = document.createElement('div');
        card.className = 'jd-section-card';
        card.id = cardId;

        var titleInput = document.createElement('input');
        titleInput.type = 'text';
        titleInput.className = 'jd-section-title';
        titleInput.placeholder = 'Section title, e.g. Responsibilities';
        titleInput.value = presetTitle || '';
        titleInput.addEventListener('input', function () { jdSyncDescription(containerId, hiddenFieldId); });

        var removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'jd-section-remove-btn';
        removeBtn.title = 'Remove section';
        removeBtn.textContent = '✕';
        removeBtn.addEventListener('click', function () { jdRemoveSection(cardId, containerId, hiddenFieldId); });

        var headerRow = document.createElement('div');
        headerRow.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:8px;';
        headerRow.appendChild(titleInput);
        headerRow.appendChild(removeBtn);

        var boldBtn = document.createElement('button');
        boldBtn.type = 'button';
        boldBtn.className = 'jd-section-toolbar-btn';
        boldBtn.style.cssText = 'width:28px; font-weight:800;';
        boldBtn.title = 'Bold';
        boldBtn.textContent = 'B';
        boldBtn.addEventListener('click', function () { jdWrapSelection(taId, '**', '**'); });

        var dashListBtn = document.createElement('button');
        dashListBtn.type = 'button';
        dashListBtn.className = 'jd-section-toolbar-btn';
        dashListBtn.style.cssText = 'padding:0 8px;';
        dashListBtn.title = 'Bullet list (dash)';
        dashListBtn.innerHTML = '&ndash; List';
        dashListBtn.addEventListener('click', function () { jdPrefixLines(taId, '- '); });

        var dotListBtn = document.createElement('button');
        dotListBtn.type = 'button';
        dotListBtn.className = 'jd-section-toolbar-btn';
        dotListBtn.style.cssText = 'padding:0 8px;';
        dotListBtn.title = 'Bullet list (round)';
        dotListBtn.innerHTML = '&bull; List';
        dotListBtn.addEventListener('click', function () { jdPrefixLines(taId, '• '); });

        var toolbarRow = document.createElement('div');
        toolbarRow.style.cssText = 'display:flex; gap:6px; margin-bottom:6px;';
        toolbarRow.appendChild(boldBtn);
        toolbarRow.appendChild(dashListBtn);
        toolbarRow.appendChild(dotListBtn);

        var contentArea = document.createElement('textarea');
        contentArea.id = taId;
        contentArea.className = 'jd-section-content';
        contentArea.rows = 4;
        contentArea.placeholder = "Write this section's content...";
        contentArea.value = presetContent || '';
        contentArea.addEventListener('input', function () { jdSyncDescription(containerId, hiddenFieldId); });

        card.appendChild(headerRow);
        card.appendChild(toolbarRow);
        card.appendChild(contentArea);
        container.appendChild(card);

        jdSyncDescription(containerId, hiddenFieldId);
        contentArea.focus();
    }

    function jdRemoveSection(cardId, containerId, hiddenFieldId) {
        var card = document.getElementById(cardId);
        if (card) card.remove();
        jdSyncDescription(containerId, hiddenFieldId);
    }

    function jdSyncDescription(containerId, hiddenFieldId) {
        var container = document.getElementById(containerId);
        var cards = container.querySelectorAll('.jd-section-card');
        var combined = '';
        cards.forEach(function (card) {
            var title = card.querySelector('.jd-section-title').value.trim();
            var content = card.querySelector('.jd-section-content').value.trim();
            if (!title && !content) return;
            if (title) combined += '## ' + title + '\n';
            if (content) combined += content + '\n';
            combined += '\n';
        });
        document.getElementById(hiddenFieldId).value = combined.trim();
    }

    function jdValidateBeforeSubmit(containerId, hiddenFieldId) {
        jdSyncDescription(containerId, hiddenFieldId);
        if (!document.getElementById(hiddenFieldId).value.trim()) {
            alert('Please fill in at least one job description section before saving.');
            return false;
        }
        return true;
    }

    // Keep the hidden field in sync with the server-rendered cards right away
    // (covers the case where the employer submits without touching anything).
    document.addEventListener('DOMContentLoaded', function () {
        jdSyncDescription('edit_jd_sections', 'edit_description_hidden');
    });
    </script>
    <script src="theme.js"></script>
</body>
</html>
